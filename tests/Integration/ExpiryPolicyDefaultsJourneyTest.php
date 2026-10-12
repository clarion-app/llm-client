<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingChannelDelivery;

/**
 * What happens to an unanswered question is chosen when it is asked, and
 * stopping is the default.
 *
 * One agent asks three questions in turn — one naming the proceed policy
 * with an assumed answer, one naming the stop policy, one naming neither —
 * and each question carries, from the moment it is asked, the policy it
 * will follow: proceed, stop, stop. Malformed asks never reach the user on
 * either channel: the agent is told exactly why, and nothing is written or
 * delivered. The same ask made on the web and on a messaging channel is
 * stored identically: the asking side carries nothing about where the user
 * is.
 *
 * And when nobody answers, each question does what it said it would: in
 * one conversation, the question that named an assumption proceeds on it
 * at its deadline, while the question that named stop and the one that
 * named nothing each stop the work — no model call, no tool call — until
 * the user answers them late.
 *
 * Real wiring throughout — controllers, agent loop, validator, question
 * service, intake, resumer — with the model scripted at the streaming and
 * HTTP boundaries and recording adapters standing in for both channels.
 */
class ExpiryPolicyDefaultsJourneyTest extends AssembledSystemTestCase
{
    private const MESSAGE_ENDPOINT = '/api/clarion-app/llm-client/message';

    private const AGENT_ENDPOINT = '/api/clarion-app/llm-client/agent';

    private const QUESTION = 'Which test framework should I set up?';

    private const CHOICES = ['PHPUnit', 'Pest', 'Codeception'];

    private RecordingChannelDelivery $web;

    private RecordingChannelDelivery $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.conversation.inactivity_threshold_hours' => 4]);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
        ]);

        $this->web = new RecordingChannelDelivery('web');
        $this->app->make(ConversationChannelRegistry::class)->register($this->web);
        $this->telegram = new RecordingChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->telegram);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function ask(array $policyArguments, string $question = self::QUESTION): array
    {
        return ['question' => $question, 'choices' => self::CHOICES] + $policyArguments;
    }

    private function telegramConversation(): Conversation
    {
        $fixture = $this->fixture()->build();
        $fixture->conversation->update(['channel' => 'telegram']);

        return $fixture->conversation->fresh();
    }

    private function send(Conversation $conversation, string $message): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, [
            'message' => $message,
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ]);
    }

    private function latestQuestionIn(Conversation $conversation): AgentQuestion
    {
        $question = AgentQuestion::where('conversation_id', $conversation->id)->latest('asked_at')->first();
        $this->assertNotNull($question, 'the agent\'s ask must have opened a question');

        return $question;
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

    /** @return list<array<string, mixed>> */
    private function streamPayloadMessages(int $slot): array
    {
        $payloads = $this->stream()->extractDispatchedJobs()->capturedPayloads();
        $this->assertArrayHasKey($slot, $payloads, "no streaming request #{$slot} was dispatched");

        /** @var CapturedPayload $payload */
        $payload = $payloads[$slot];

        return json_decode(json_encode($payload->messages), true);
    }

    /** @return list<array<string, mixed>> */
    private function chatPayloadMessages(int $index): array
    {
        $payloads = $this->transport()->capturedChatPayloads();
        $this->assertArrayHasKey($index, $payloads, "no model request #{$index} was made");

        /** @var CapturedPayload $payload */
        $payload = $payloads[$index];

        return json_decode(json_encode($payload->messages), true);
    }

    /** The decoded result of the only `ask_user` call in $messages. */
    private function askResultIn(array $messages): ?array
    {
        $askCallIds = [];
        foreach ($messages as $message) {
            foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                if (($call['function']['name'] ?? null) === 'ask_user') {
                    $askCallIds[] = $call['id'] ?? null;
                }
            }
        }
        $this->assertCount(1, $askCallIds, 'the payload carries exactly one ask_user call');

        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $askCallIds[0]) {
                $content = $message['content'] ?? null;

                return json_decode(is_string($content) ? $content : json_encode($content), true);
            }
        }

        return null;
    }

    /** The columns that fix, at ask time, what happens if nobody answers. */
    private function policyColumns(AgentQuestion $question): array
    {
        $row = (array) DB::table('agent_questions')->where('id', $question->id)->first([
            'unanswered_policy', 'consequential', 'assumed_answer_raw', 'assumed_answer_selected',
            'requested_wait_minutes', 'effective_wait_minutes', 'asked_at', 'expires_at',
        ]);
        $row['consequential'] = (bool) $row['consequential'];
        $row['assumed_answer_selected'] = $row['assumed_answer_selected'] === null ? null : json_decode($row['assumed_answer_selected'], true);
        $row['period_seconds'] = strtotime((string) $row['expires_at']) - strtotime((string) $row['asked_at']);
        unset($row['asked_at'], $row['expires_at']);

        return $row;
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function malformedAsks(): array
    {
        return [
            'proceed with no assumption' => [
                ['if_unanswered' => 'proceed_on_assumption'],
                'if_unanswered is "proceed_on_assumption" but no assumed answer was given',
            ],
            'an assumed choice that does not exist' => [
                ['assumed_choices' => [4]],
                'assumed choice 4 does not exist (the question has 3 choices)',
            ],
            'two assumed choices on a single-selection question' => [
                ['assumed_choices' => [1, 2]],
                'a single-selection question can assume only one choice',
            ],
            'stop with an assumed answer' => [
                ['if_unanswered' => 'stop', 'assumed_text' => 'PHPUnit'],
                'if_unanswered is "stop" but an assumed answer was given; a question that stops when unanswered cannot also assume an answer',
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Three questions in turn: proceed, stop, and stop by default
    // ---------------------------------------------------------------

    public function test_each_question_carries_the_policy_chosen_when_it_was_asked(): void
    {
        $this->scenario = 'expiry_policy_three_asks_in_turn';
        $this->entryPath = 'sync';
        $conversation = $this->telegramConversation();

        $this->script()
            ->toolRequest('ask_user', $this->ask(['if_unanswered' => 'proceed_on_assumption', 'assumed_choices' => [1]]))
            ->finalAnswer('Setting up PHPUnit.')
            ->toolRequest('ask_user', $this->ask(['if_unanswered' => 'stop'], 'Should the tests run on every push?'))
            ->finalAnswer('They will run on every push.')
            ->toolRequest('ask_user', $this->ask([], 'Which directory should the tests live in?'))
            ->finalAnswer('Using the tests directory.');

        // First: proceed on an assumption, named explicitly.
        $this->send($conversation, 'Set up the tests.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $proceed = $this->latestQuestionIn($conversation);
        $this->assertSame(QuestionState::Pending, $proceed->state);
        $this->send($conversation, '1')->assertStatus(200)->assertJson(['status' => 'completed', 'content' => 'Setting up PHPUnit.']);

        // Second: stop, named explicitly.
        $this->send($conversation, 'And the pipeline?')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $stop = $this->latestQuestionIn($conversation);
        $this->assertNotSame($proceed->id, $stop->id);
        $this->send($conversation, 'Yes, every push')->assertStatus(200)->assertJson(['status' => 'completed']);

        // Third: no policy and no assumption.
        $this->send($conversation, 'Where do they go?')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $neither = $this->latestQuestionIn($conversation);
        $this->assertNotSame($stop->id, $neither->id);
        $this->send($conversation, 'tests')->assertStatus(200)->assertJson(['status' => 'completed']);

        $proceed->refresh();
        $stop->refresh();
        $neither->refresh();

        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $proceed->unanswered_policy);
        $this->assertSame('1', $proceed->assumed_answer_raw);
        $this->assertSame([['number' => 1, 'text' => 'PHPUnit']], $proceed->assumed_answer_selected);

        $this->assertSame(UnansweredPolicy::Stop, $stop->unanswered_policy);
        $this->assertNull($stop->assumed_answer_raw);

        $this->assertSame(UnansweredPolicy::Stop, $neither->unanswered_policy, 'naming nothing stops: proceeding never happens by omission');
        $this->assertNull($neither->assumed_answer_raw);
        $this->assertNull($neither->assumed_answer_selected);

        foreach ([$proceed, $stop, $neither] as $question) {
            $this->assertFalse($question->consequential);
            $this->assertNull($question->requested_wait_minutes);
            $this->assertSame(1440, $question->effective_wait_minutes, 'no period requested: the installation default');
            $this->assertSame(QuestionState::Answered, $question->state, 'each was answered on time, so the policy never applied');
        }

        // Every question was shown on the chat; nothing on the web.
        $this->assertCount(3, $this->telegram->textsFor($conversation->id));
        $this->assertSame([], $this->web->deliveries);
    }

    // ---------------------------------------------------------------
    // Nobody answers: each question does what it was asked to do
    // ---------------------------------------------------------------

    /** @var array<int, true> resume jobs already worked, by object id */
    private array $worked = [];

    private function sweepAfterTheDeadline(): void
    {
        // Every question here waits the installation default, one day.
        $this->travel(1441)->minutes();
        $this->assertSame(0, Artisan::call('llm-client:resolve-expired-questions'), 'the sweep succeeds');
    }

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

    /** The result of call $toolCallId in model request #$index. */
    private function toolResultIn(int $index, string $toolCallId): ?array
    {
        foreach ($this->chatPayloadMessages($index) as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $toolCallId) {
                $content = $message['content'] ?? null;

                return json_decode(is_string($content) ? $content : json_encode($content), true);
            }
        }

        return null;
    }

    public function test_unanswered_the_proceed_question_proceeds_and_the_stop_and_default_questions_stop(): void
    {
        $this->scenario = 'expiry_policy_three_expiries_in_turn';
        $this->entryPath = 'sync';
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'UTC'));
        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class]);
        $conversation = $this->telegramConversation();

        $this->script()
            ->toolRequest('ask_user', $this->ask(['if_unanswered' => 'proceed_on_assumption', 'assumed_choices' => [1]]))
            ->toolRequest('ask_user', $this->ask(['if_unanswered' => 'stop'], 'Should the tests run on every push?'))
            ->toolRequest('ask_user', $this->ask([], 'Which directory should the tests live in?'))
            ->finalAnswer('PHPUnit, on every push, in the tests directory.');

        // The proceed question: nobody answers, and the agent goes on with
        // its assumption — straight into the second question.
        $this->send($conversation, 'Set up the tests.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $proceed = $this->latestQuestionIn($conversation);
        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $proceed->unanswered_policy, 'precondition');

        $this->sweepAfterTheDeadline();
        $this->assertSame(QuestionState::ExpiredAssumed, $proceed->fresh()->state, 'the proceed question proceeds on its assumption');
        $this->assertCount(1, Queue::pushed(ResumeAfterQuestionJob::class), 'its run is queued to continue');
        $this->assertSame(1, $this->workQueuedResumes());
        $this->assertCount(2, $this->transport()->capturedChatPayloads(), 'the run continued with one model request');
        $proceedResult = $this->toolResultIn(1, (string) $proceed->tool_call_id);
        $this->assertSame('expired_assumed', $proceedResult['outcome'] ?? null);
        $this->assertSame('assumed', $proceedResult['answer_source'] ?? null);
        $this->assertSame([['number' => 1, 'text' => 'PHPUnit']], $proceedResult['assumed_answer']['selected'] ?? null);

        // The stop question: nobody answers, and the work stops.
        $stop = $this->latestQuestionIn($conversation);
        $this->assertNotSame($proceed->id, $stop->id, 'the continuation asked the second question');
        $this->assertSame(UnansweredPolicy::Stop, $stop->unanswered_policy, 'precondition');

        $this->sweepAfterTheDeadline();
        $this->assertSame(QuestionState::Stopped, $stop->fresh()->state, 'the stop question stops');
        $this->assertCount(2, $this->transport()->capturedChatPayloads(), 'no model call after the stop');
        $this->assertCount(1, Queue::pushed(ResumeAfterQuestionJob::class), 'nothing is queued for a stopped question');
        $this->assertSame(0, $this->workQueuedResumes());

        // Answered late, it resumes — straight into the third question.
        $this->send($conversation, 'Yes, every push')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $this->assertSame('answered_late', $this->toolResultIn(2, (string) $stop->tool_call_id)['outcome'] ?? null);

        // The question naming neither: it stops too.
        $neither = $this->latestQuestionIn($conversation);
        $this->assertNotSame($stop->id, $neither->id, 'the continuation asked the third question');
        $this->assertSame(UnansweredPolicy::Stop, $neither->unanswered_policy, 'precondition');

        $this->sweepAfterTheDeadline();
        $this->assertSame(QuestionState::Stopped, $neither->fresh()->state, 'naming nothing stops: proceeding never happens by omission');
        $this->assertCount(3, $this->transport()->capturedChatPayloads(), 'no model call after the stop');
        $this->assertCount(1, Queue::pushed(ResumeAfterQuestionJob::class), 'nothing is queued for a stopped question');

        $this->send($conversation, 'tests')
            ->assertStatus(200)
            ->assertJson(['status' => 'completed', 'content' => 'PHPUnit, on every push, in the tests directory.']);
        $this->assertSame('answered_late', $this->toolResultIn(3, (string) $neither->tool_call_id)['outcome'] ?? null);

        // The user was told what happened to each, in the chat.
        $notices = Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'agent_question_notice')
            ->map(fn (Message $m) => [$m->tool_data['question_id'] ?? null, $m->tool_data['state'] ?? null])
            ->values()
            ->all();
        $this->assertSame(
            [[$proceed->id, 'expired_assumed'], [$stop->id, 'stopped'], [$neither->id, 'stopped']],
            $notices,
        );
    }

    // ---------------------------------------------------------------
    // Malformed asks reach nobody
    // ---------------------------------------------------------------

    #[DataProvider('malformedAsks')]
    public function test_a_malformed_ask_on_a_messaging_channel_is_refused_and_nothing_is_delivered(array $policyArguments, string $reason): void
    {
        $this->scenario = 'expiry_policy_malformed_ask_messaging';
        $this->entryPath = 'sync';
        $conversation = $this->telegramConversation();

        $this->script()
            ->toolRequest('ask_user', $this->ask($policyArguments))
            ->finalAnswer('I will decide on my own.');

        $this->send($conversation, 'Set up the tests.')
            ->assertStatus(200)
            ->assertJson(['status' => 'completed', 'content' => 'I will decide on my own.']);

        $this->assertSame(
            ['outcome' => 'refused', 'code' => 'invalid_question', 'reason' => $reason],
            $this->askResultIn($this->chatPayloadMessages(1)),
            'the agent is told exactly why, in the next request of the same run',
        );
        $this->assertSame(0, AgentQuestion::count(), 'nothing is written');
        $this->assertSame([], $this->telegram->deliveries, 'nothing is delivered on the messaging channel');
        $this->assertSame([], $this->web->deliveries, 'nothing is delivered on the web');
        $this->assertSame(
            0,
            Message::where('conversation_id', $conversation->id)->get()
                ->filter(fn (Message $m) => in_array($m->tool_data['kind'] ?? null, ['agent_question', 'agent_question_notice'], true))
                ->count(),
            'no question is shown in the conversation',
        );
    }

    #[DataProvider('malformedAsks')]
    public function test_a_malformed_ask_on_the_web_is_refused_and_nothing_is_delivered(array $policyArguments, string $reason): void
    {
        $this->scenario = 'expiry_policy_malformed_ask_web';
        $this->entryPath = 'stream';
        $fixture = $this->fixture()->build();
        $conversation = $fixture->conversation;

        $this->actingAs($fixture->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $conversation->id,
            'content' => 'Set up the tests.',
        ])->assertStatus(201);
        $this->playTurn(0, '', [[
            'id' => 'call_ask',
            'name' => 'ask_user',
            'arguments' => json_encode($this->ask($policyArguments)),
        ]]);

        $this->assertSame(2, $this->turnCount(), 'the refusal goes straight back to the model: the run does not wait');
        $this->assertSame(
            ['outcome' => 'refused', 'code' => 'invalid_question', 'reason' => $reason],
            $this->askResultIn($this->streamPayloadMessages(1)),
        );
        $this->playTurn(1, 'I will decide on my own.');

        $this->assertSame(0, AgentQuestion::count(), 'nothing is written');
        $this->assertSame([], $this->web->deliveries, 'nothing is delivered on the web');
        $this->assertSame([], $this->telegram->deliveries, 'nothing is delivered on the messaging channel');

        $listed = $this->actingAs($fixture->user, 'api')
            ->getJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/message")
            ->assertStatus(200)
            ->json();
        $this->assertSame(
            [],
            array_values(array_filter($listed, fn (array $m) => isset($m['question']) || ($m['tool_data']['kind'] ?? null) === 'agent_question')),
            'no question is listed in the conversation',
        );
    }

    // ---------------------------------------------------------------
    // The same ask on either channel is stored the same way
    // ---------------------------------------------------------------

    public function test_the_same_ask_on_the_web_and_on_a_messaging_channel_is_stored_identically(): void
    {
        $this->scenario = 'expiry_policy_channel_independent';
        $this->entryPath = 'stream';
        $fixture = $this->fixture()->build();
        $web = $fixture->conversation;
        $telegram = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $web->user_id,
            'server_id' => $web->server_id,
            'model' => $web->model,
            'title' => 'The same task over Telegram',
            'channel' => 'telegram',
        ]);
        $arguments = $this->ask(['assumed_choices' => [2], 'wait_minutes' => 120]);

        // On the web, through the streaming path.
        $this->actingAs($fixture->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $web->id,
            'content' => 'Set up the tests.',
        ])->assertStatus(201);
        $this->playTurn(0, '', [['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => json_encode($arguments)]]);
        $onWeb = $this->latestQuestionIn($web);
        $this->assertSame('stream', $onWeb->resume_mode);
        $this->assertCount(1, $this->web->textsFor($web->id), 'precondition: the web question was delivered through the recorded adapter');

        // On the messaging channel, through the synchronous path.
        $this->script()->toolRequest('ask_user', $arguments);
        $this->send($telegram, 'Set up the tests.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $onTelegram = $this->latestQuestionIn($telegram);
        $this->assertSame('sync', $onTelegram->resume_mode);

        $expected = [
            'unanswered_policy' => 'proceed_on_assumption',
            'consequential' => false,
            'assumed_answer_raw' => '2',
            'assumed_answer_selected' => [['number' => 2, 'text' => 'Pest']],
            'requested_wait_minutes' => 120,
            'effective_wait_minutes' => 120,
            'period_seconds' => 120 * 60,
        ];
        $this->assertEquals($expected, $this->policyColumns($onWeb), 'the web ask stores the policy it chose');
        $this->assertEquals($expected, $this->policyColumns($onTelegram), 'the messaging ask stores the same');
        $this->assertSame($this->policyColumns($onWeb), $this->policyColumns($onTelegram), 'identical, column for column');

        // The agent's ask itself names neither channel.
        $askedOnWeb = Message::findOrFail($onWeb->paused_message_id)->tool_data['tool_calls'][0]['function']['arguments'] ?? null;
        $askedOnTelegram = Message::findOrFail($onTelegram->paused_message_id)->tool_data['tool_calls'][0]['function']['arguments'] ?? null;
        $this->assertSame(json_decode((string) $askedOnWeb, true), json_decode((string) $askedOnTelegram, true));
    }
}
