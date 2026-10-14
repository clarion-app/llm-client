<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\Integration\Harness\RecordingRichChannelDelivery;

/**
 * However long an agent asks to wait, a question waits long enough for
 * someone away from their device — and never longer than the installation
 * allows — and everyone involved knows the deadline.
 *
 * With the shipped bounds (a default of one day, at least an hour, at most
 * a week), four questions that each assume an answer are asked at the same
 * moment, each in its own conversation (a conversation holds one waiting
 * question at a time): one asking for no particular period, one for five
 * minutes, one for ten hours and one for about two weeks. None is refused.
 * They wait one day, one hour, ten hours and seven days respectively; each
 * says so where it is shown — the stored question, the chat's buttons
 * message and its plain fallback, the web screen's view — in its own
 * words. Changing the installation's settings while they wait moves none
 * of them. A minute before each deadline the sweep leaves that question
 * waiting; a minute after, it proceeds on its assumption, and the agent is
 * told the period that was actually applied.
 *
 * Separately, an installation whose configured default lies outside its
 * own bounds has the default brought inside them.
 *
 * Both channels and both execution paths: a messaging conversation on the
 * synchronous path and a web conversation on the streaming path. Real
 * wiring throughout — controllers, the agent loop, the validator, the
 * question service, the sweep command, the resumer and its job (worked the
 * way a queue worker does) — with the model scripted at the streaming and
 * HTTP boundaries and recording adapters standing in for both channels.
 */
class WaitingPeriodJourneyTest extends AssembledSystemTestCase
{
    private const API = '/api/clarion-app/llm-client';

    private const MESSAGE_ENDPOINT = self::API.'/message';

    private const AGENT_ENDPOINT = self::API.'/agent';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const QUESTION = 'Which test framework should I set up?';

    private const CHOICES = ['PHPUnit', 'Pest'];

    /** Every question is asked at this instant; the clock is frozen. */
    private const ASKED_AT = '2026-10-12 09:00:00';

    /** The question as typed replies answer it, before the statement. */
    private const PLAIN = "Which test framework should I set up?\n"
        ."\n"
        ."1. PHPUnit\n"
        ."2. Pest\n"
        ."\n"
        .'Reply with the number of your choice, or answer in your own words.';

    /** The question as shown alongside its choices as buttons, before the statement. */
    private const WITH_OPTIONS = "Which test framework should I set up?\n"
        ."\n"
        ."1. PHPUnit\n"
        ."2. Pest\n"
        ."\n"
        .'Choose an option below, or reply in your own words.';

    private RecordingChannelDelivery $web;

    private RecordingRichChannelDelivery $telegram;

    /** @var array<int, true> resume jobs already worked, by object id */
    private array $worked = [];

    /** @var list<string> every error or warning logged */
    private array $problemsLogged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::ASKED_AT, 'UTC'));

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.conversation.inactivity_threshold_hours' => 4]);

        // The shipped bounds, stated rather than assumed: a default of one
        // day, at least an hour, at most a week.
        $this->assertSame(1440, (int) config('llm-client.agent_questions.expiry_minutes'), 'precondition: the shipped default');
        $this->assertSame(60, (int) config('llm-client.agent_questions.min_expiry_minutes'), 'precondition: the shipped minimum');
        $this->assertSame(10080, (int) config('llm-client.agent_questions.max_expiry_minutes'), 'precondition: the shipped maximum');

        // Resume jobs are held on the queue until a worker picks them up, so
        // the test sees exactly what each sweep queued.
        Queue::fake([AgentStreamStepJob::class, ResumeAfterQuestionJob::class]);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
        ]);

        // The resume job reports a failed continuation only in the log.
        Event::listen(MessageLogged::class, function (MessageLogged $logged) {
            if (in_array($logged->level, ['error', 'warning', 'critical', 'alert', 'emergency'], true)) {
                $this->problemsLogged[] = $logged->level.': '.$logged->message;
            }
        });

        $this->web = new RecordingChannelDelivery('web');
        $this->telegram = new RecordingRichChannelDelivery('telegram');
        $registry = $this->app->make(ConversationChannelRegistry::class);
        $registry->register($this->web);
        $registry->register($this->telegram);
    }

    // ---------------------------------------------------------------
    // The four requested periods
    // ---------------------------------------------------------------

    /**
     * In the order they are asked. Each: the period requested (null = none),
     * the period applied, the deadline (UTC), and how the deadline is
     * written for the user.
     *
     * @return array<string, array{requested: ?int, effective: int, deadline: string, deadline_text: string}>
     */
    private static function periods(): array
    {
        return [
            'none requested' => [
                'requested' => null,
                'effective' => 1440,
                'deadline' => '2026-10-13 09:00:00',
                'deadline_text' => '09:00 UTC on 13 Oct 2026 (1 day after this was asked)',
            ],
            'five minutes, below the minimum' => [
                'requested' => 5,
                'effective' => 60,
                'deadline' => '2026-10-12 10:00:00',
                'deadline_text' => '10:00 UTC on 12 Oct 2026 (1 hour after this was asked)',
            ],
            'ten hours, within the bounds' => [
                'requested' => 600,
                'effective' => 600,
                'deadline' => '2026-10-12 19:00:00',
                'deadline_text' => '19:00 UTC on 12 Oct 2026 (10 hours after this was asked)',
            ],
            'about two weeks, above the maximum' => [
                'requested' => 20000,
                'effective' => 10080,
                'deadline' => '2026-10-19 09:00:00',
                'deadline_text' => '09:00 UTC on 19 Oct 2026 (7 days after this was asked)',
            ],
        ];
    }

    private static function statement(string $deadlineText): string
    {
        return 'If there is no answer by '.$deadlineText.', the agent will continue assuming: 1. PHPUnit';
    }

    private static function assumedMessage(int $minutes): string
    {
        return "No answer arrived within {$minutes} minutes. Continue using the answer you said you would assume. "
            .'This is your own assumption, not the user\'s answer: never treat it as the user\'s confirmation, approval or '
            .'authorization of any action. The user has been told what was assumed.';
    }

    private function askArguments(?int $requested): array
    {
        $arguments = ['question' => self::QUESTION, 'choices' => self::CHOICES, 'assumed_choices' => [1]];
        if ($requested !== null) {
            $arguments['wait_minutes'] = $requested;
        }

        return $arguments;
    }

    // ---------------------------------------------------------------
    // Time and the sweep
    // ---------------------------------------------------------------

    private function at(string $instant): void
    {
        $this->travelTo(Carbon::parse($instant, 'UTC'));
    }

    private function sweep(): void
    {
        $this->assertSame(0, Artisan::call(self::SWEEP), 'the sweep succeeds');
    }

    /** Work every newly queued resume the way a queue worker does. */
    private function workQueuedResumes(): int
    {
        $worked = 0;
        foreach (Queue::pushed(ResumeAfterQuestionJob::class)->all() as $job) {
            if (isset($this->worked[spl_object_id($job)])) {
                continue;
            }
            $this->worked[spl_object_id($job)] = true;
            $this->app->call([$job, 'handle']);
            $worked++;
        }

        return $worked;
    }

    // ---------------------------------------------------------------
    // Conversations and the two paths
    // ---------------------------------------------------------------

    /** @return list<Conversation> $count conversations of the fixture user, on $channel */
    private function conversations(int $count, string $channel): array
    {
        $first = $this->fixture()->build()->conversation;
        $first->update(['channel' => $channel]);
        $conversations = [$first->fresh()];

        while (count($conversations) < $count) {
            $conversations[] = Conversation::create([
                'id' => (string) Str::uuid(),
                'user_id' => $first->user_id,
                'server_id' => $first->server_id,
                'model' => $first->model,
                'title' => 'Another task '.count($conversations),
                'channel' => $channel,
            ]);
        }

        return $conversations;
    }

    /** @return list<string> SSE chunks for one streamed model turn */
    private function turnChunks(string $text, array $calls): array
    {
        $chunks = [];
        if ($text !== '') {
            $chunks[] = 'data: '.json_encode(['choices' => [['delta' => ['content' => $text], 'finish_reason' => null]]])."\n\n";
        }
        foreach (array_values($calls) as $index => $call) {
            $chunks[] = 'data: '.json_encode(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => $index,
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => ['name' => $call['name'], 'arguments' => $call['arguments']],
                ]]],
                'finish_reason' => null,
            ]]])."\n\n";
        }
        $chunks[] = 'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => $calls === [] ? 'stop' : 'tool_calls']]])."\n\n";

        return $chunks;
    }

    private function playTurn(int $slot, string $text, array $calls = []): void
    {
        $stream = $this->stream();
        $stream->extractDispatchedJobs();
        $this->assertGreaterThan($slot, $stream->turnCount(), "a streaming request #{$slot} must have been dispatched");
        $stream->setCurrentSlot($slot);
        $stream->emit($this->turnChunks($text, $calls));
        $stream->finish();
    }

    private function turnCount(): int
    {
        return $this->stream()->extractDispatchedJobs()->turnCount();
    }

    /** Model requests made so far on $path. */
    private function requestCount(string $path): int
    {
        return $path === 'stream' ? $this->turnCount() : count($this->transport()->capturedChatPayloads());
    }

    /** @return list<array<string, mixed>> the messages of model request #$index on $path */
    private function requestMessages(string $path, int $index): array
    {
        $payloads = $path === 'stream'
            ? $this->stream()->extractDispatchedJobs()->capturedPayloads()
            : $this->transport()->capturedChatPayloads();
        $this->assertArrayHasKey($index, $payloads, "no model request #{$index} was made");

        /** @var CapturedPayload $payload */
        $payload = $payloads[$index];

        return json_decode(json_encode($payload->messages), true);
    }

    private function toolResultFor(array $messages, string $toolCallId): ?array
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $toolCallId) {
                $content = $message['content'] ?? null;

                return json_decode(is_string($content) ? $content : json_encode($content), true);
            }
        }

        return null;
    }

    /** The agent asks in $conversation, on $path; the run waits for the user. */
    private function ask(Conversation $conversation, ?int $requested, string $path): AgentQuestion
    {
        $before = $this->requestCount($path);

        if ($path === 'stream') {
            $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
                'conversation_id' => $conversation->id,
                'content' => 'Set up the tests for this project.',
            ])->assertStatus(201);
            $this->playTurn($before, '', [['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => json_encode($this->askArguments($requested))]]);
        } else {
            $this->script()->toolRequest('ask_user', $this->askArguments($requested));
            $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, [
                'message' => 'Set up the tests for this project.',
                'channel' => 'telegram',
                'conversation_id' => $conversation->id,
            ])->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        }

        $this->assertSame($before + 1, $this->requestCount($path), 'the ask was not refused: the run waits for the user, no further model request');

        $question = AgentQuestion::where('conversation_id', $conversation->id)->latest('asked_at')->first();
        $this->assertNotNull($question, 'the ask was not refused: it opened a question');
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertSame($path, $question->resume_mode);
        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $question->unanswered_policy);

        return $question;
    }

    /** @return array<string, mixed> the question view on the question's display message, as its owner's screen loads it */
    private function questionView(AgentQuestion $question): array
    {
        $listed = $this->actingAs($this->fixture()->user, 'api')
            ->getJson(self::API."/conversation/{$question->conversation_id}/message")
            ->assertStatus(200)
            ->json();

        foreach ($listed as $message) {
            if (($message['id'] ?? null) === $question->display_message_id) {
                $this->assertIsArray($message['question'] ?? null, 'the display message carries a question view');

                return $message['question'];
            }
        }
        $this->fail('the question\'s display message is not listed');
    }

    /** The columns, as stored, that fix the question's deadline. */
    private function deadlineColumns(AgentQuestion $question): array
    {
        return (array) DB::table('agent_questions')->where('id', $question->id)->first([
            'requested_wait_minutes', 'effective_wait_minutes', 'asked_at', 'expires_at',
        ]);
    }

    /**
     * The question waits the period applied and says so, in its own words,
     * wherever it is shown.
     *
     * @param array{requested: ?int, effective: int, deadline: string, deadline_text: string} $period
     */
    private function assertWaitsAndSaysSo(AgentQuestion $question, array $period, string $path): void
    {
        $question = $question->fresh();
        $statement = self::statement($period['deadline_text']);

        $this->assertSame($period['requested'], $question->requested_wait_minutes, 'the period asked for is kept as asked');
        $this->assertSame($period['effective'], $question->effective_wait_minutes, 'the period applied');
        $this->assertSame(self::ASKED_AT, $question->asked_at->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($period['deadline'], $question->expires_at->copy()->utc()->format('Y-m-d H:i:s'), 'the deadline is the period applied after the ask');

        // The stored question, which every plain-text rendering is built from.
        $this->assertSame(self::PLAIN."\n\n".$statement, Message::findOrFail($question->display_message_id)->content);

        // Where the user is.
        if ($path === 'stream') {
            $this->assertSame([self::PLAIN."\n\n".$statement], $this->web->textsFor($question->conversation_id), 'delivered on the web with its deadline');
        } else {
            $presentations = $this->telegram->presentationsFor($question->conversation_id);
            $this->assertCount(1, $presentations, 'shown in the chat with its choices as buttons');
            $this->assertSame(self::WITH_OPTIONS."\n\n".$statement, $presentations[0]->bodyText, 'the chat message states its deadline');
            $this->assertSame(self::PLAIN."\n\n".$statement, $presentations[0]->plainText, 'and so does its plain fallback');
        }

        // The web screen's view of it.
        $view = $this->questionView($question);
        $this->assertSame('pending', $view['state']);
        $this->assertSame($statement, $view['expiry_statement'], 'the web screen shows the same deadline');
        $this->assertSame(str_replace(' ', 'T', $period['deadline']).'Z', $view['expires_at']);
    }

    /**
     * Four questions, asked at the same moment, each waiting the period the
     * bounds allow; then each deadline in turn.
     */
    private function fourRequestedPeriods(string $path, string $channel): void
    {
        $periods = self::periods();
        $conversations = $this->conversations(count($periods), $channel);

        /** @var array<string, AgentQuestion> $questions */
        $questions = [];
        foreach (array_keys($periods) as $i => $label) {
            $questions[$label] = $this->ask($conversations[$i], $periods[$label]['requested'], $path);
        }

        foreach ($periods as $label => $period) {
            $this->assertWaitsAndSaysSo($questions[$label], $period, $path);
        }
        $asked = array_map(fn (AgentQuestion $q) => $this->deadlineColumns($q), $questions);

        // The installation's settings change while they wait. A deadline
        // re-derived from the new settings would fall within two minutes of
        // the ask; every question must keep the one it was given.
        config([
            'llm-client.agent_questions.expiry_minutes' => 2,
            'llm-client.agent_questions.min_expiry_minutes' => 1,
            'llm-client.agent_questions.max_expiry_minutes' => 2,
        ]);

        // Earliest deadline first.
        $byDeadline = $periods;
        uasort($byDeadline, fn (array $a, array $b) => strcmp($a['deadline'], $b['deadline']));

        $expired = [];
        foreach ($byDeadline as $label => $period) {
            $question = $questions[$label];
            $deadline = Carbon::parse($period['deadline'], 'UTC');

            // A minute before its deadline, nothing has expired that is not yet due.
            $this->at($deadline->copy()->subMinute()->format('Y-m-d H:i:s'));
            $this->sweep();
            $this->assertSame(QuestionState::Pending, $question->fresh()->state, "{$label}: still waiting a minute before its deadline");
            foreach ($questions as $other => $q) {
                if (! in_array($other, $expired, true)) {
                    $this->assertSame(QuestionState::Pending, $q->fresh()->state, "{$other}: still waiting while {$label} is not yet due");
                }
            }
            $this->assertCount(count($expired), Queue::pushed(ResumeAfterQuestionJob::class), "{$label}: nothing new queued before its deadline");

            // A minute after, it proceeds on its assumption — and only it.
            $this->at($deadline->copy()->addMinute()->format('Y-m-d H:i:s'));
            $this->sweep();
            $this->assertSame(QuestionState::ExpiredAssumed, $question->fresh()->state, "{$label}: proceeds a minute after its deadline");
            $expired[] = $label;
            foreach ($questions as $other => $q) {
                if (! in_array($other, $expired, true)) {
                    $this->assertSame(QuestionState::Pending, $q->fresh()->state, "{$other}: unaffected by {$label}'s deadline");
                }
            }
            $this->assertCount(count($expired), Queue::pushed(ResumeAfterQuestionJob::class), "{$label}: exactly one resume queued");

            // The agent is told the period that was actually applied.
            $before = $this->requestCount($path);
            if ($path === 'sync') {
                $this->script()->finalAnswer('Setting up PHPUnit.');
            }
            $this->assertSame(1, $this->workQueuedResumes());
            $this->assertSame($before + 1, $this->requestCount($path), "{$label}: the resume makes exactly one continuation request");

            $this->assertSame(
                [
                    'outcome' => 'expired_assumed',
                    'question_id' => $question->id,
                    'waiting_period_minutes' => $period['effective'],
                    'answer_source' => 'assumed',
                    'assumed_answer' => ['raw_reply' => '1', 'is_selection' => true, 'selected' => [['number' => 1, 'text' => 'PHPUnit']]],
                    'message' => self::assumedMessage($period['effective']),
                ],
                $this->toolResultFor($this->requestMessages($path, $before), (string) $question->tool_call_id),
                "{$label}: the agent learns the period applied, not the one it asked for",
            );

            if ($path === 'stream') {
                $this->playTurn($before, 'Setting up PHPUnit.');
            }
            $this->assertSame(
                'completed',
                DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'),
                "{$label}: the continued run completes",
            );
        }

        // Nothing about any question's deadline moved after it was asked.
        foreach ($questions as $label => $question) {
            $this->assertSame($asked[$label], $this->deadlineColumns($question), "{$label}: the deadline fixed at ask time never moved");
        }
        $this->assertSame([], $this->problemsLogged, 'nothing failed along the way');
    }

    #[Test]
    public function in_the_chat_each_requested_period_is_brought_within_the_bounds_stated_and_reported(): void
    {
        $this->scenario = 'waiting_period_four_requests_telegram_sync';
        $this->entryPath = 'sync';

        $this->fourRequestedPeriods('sync', 'telegram');

        $this->assertSame([], $this->web->deliveries, 'nothing went to the web');
    }

    #[Test]
    public function on_the_web_each_requested_period_is_brought_within_the_bounds_stated_and_reported(): void
    {
        $this->scenario = 'waiting_period_four_requests_web_stream';
        $this->entryPath = 'stream';

        $this->fourRequestedPeriods('stream', 'web');

        $this->assertSame([], $this->telegram->deliveries, 'nothing went to the chat');
    }

    // ---------------------------------------------------------------
    // A configured default outside the configured bounds
    // ---------------------------------------------------------------

    /** @return array<string, array{int, int, string, string}> configured default, period applied, deadline, deadline text */
    public static function defaultsOutsideTheBounds(): array
    {
        return [
            'a default below the minimum' => [30, 60, '2026-10-12 10:00:00', '10:00 UTC on 12 Oct 2026 (1 hour after this was asked)'],
            'a default above the maximum' => [20000, 10080, '2026-10-19 09:00:00', '09:00 UTC on 19 Oct 2026 (7 days after this was asked)'],
        ];
    }

    #[Test]
    #[DataProvider('defaultsOutsideTheBounds')]
    public function a_configured_default_outside_the_bounds_is_brought_within_them(int $configuredDefault, int $effective, string $deadline, string $deadlineText): void
    {
        $this->scenario = 'waiting_period_default_outside_bounds';
        $this->entryPath = 'sync';

        config(['llm-client.agent_questions.expiry_minutes' => $configuredDefault]);

        $conversation = $this->conversations(1, 'telegram')[0];
        $question = $this->ask($conversation, null, 'sync');

        $this->assertWaitsAndSaysSo($question, [
            'requested' => null,
            'effective' => $effective,
            'deadline' => $deadline,
            'deadline_text' => $deadlineText,
        ], 'sync');

        $due = Carbon::parse($deadline, 'UTC');

        $this->at($due->copy()->subMinute()->format('Y-m-d H:i:s'));
        $this->sweep();
        $this->assertSame(QuestionState::Pending, $question->fresh()->state, 'still waiting a minute before the period applied has passed');
        $this->assertCount(0, Queue::pushed(ResumeAfterQuestionJob::class));

        $this->at($due->copy()->addMinute()->format('Y-m-d H:i:s'));
        $this->sweep();
        $this->assertSame(QuestionState::ExpiredAssumed, $question->fresh()->state, 'proceeds a minute after it');

        $this->script()->finalAnswer('Setting up PHPUnit.');
        $this->assertSame(1, $this->workQueuedResumes());
        $result = $this->toolResultFor($this->requestMessages('sync', 1), (string) $question->tool_call_id);
        $this->assertSame('expired_assumed', $result['outcome'] ?? null);
        $this->assertSame($effective, $result['waiting_period_minutes'] ?? null, 'the agent is told the period applied, not the configured default');
        $this->assertSame(self::assumedMessage($effective), $result['message'] ?? null);

        $this->assertSame([], $this->problemsLogged, 'nothing failed along the way');
    }
}
