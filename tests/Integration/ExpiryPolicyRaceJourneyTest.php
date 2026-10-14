<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Controllers\ConversationController;
use ClarionApp\LlmClient\Controllers\MessageController;
use ClarionApp\LlmClient\Controllers\QuestionController;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\QuestionViewBuilder;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerOutcome;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\QuestionOutcomeView;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Http\Request;
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
 * Every race an unanswered-question policy adds has exactly one outcome,
 * and the outcome the agent receives is the one the user is shown.
 *
 * The pairs:
 *
 * - an answer against the deadline, for a question that proceeds on an
 *   assumption — either the answer is used and nothing is assumed, or the
 *   assumption is used and the answer is a late reply (an ordinary message,
 *   with the user told what was assumed);
 * - an answer against the deadline, for a question that stops — either it
 *   is answered in time, or the work stops and that same answer, arriving a
 *   moment later, resumes it as a late answer: never lost, never beside a
 *   second run;
 * - two late answers to stopped work (typed, and a tapped option) — exactly
 *   one resumes it;
 * - a late answer against ending or deleting the conversation — either the
 *   work resumes with the answer, or it is discarded and the answer is not
 *   applied;
 * - the deadline against ending or deleting the conversation — either the
 *   question is withdrawn, or the deadline takes effect first (and a
 *   stopped question is then discarded).
 *
 * Each pair runs in both orders: one side starts through its real entry
 * point and the other arrives after the first has read the question and
 * before its compare-and-set, running the code its own request or tick
 * would. Every order is repeated twenty times. Each trial checks that one
 * outcome took effect and that the agent's result for its paused `ask_user`
 * call (read from the consumed paused turn and from the continuation it was
 * sent in) agrees with every surface the user sees: the notices in the
 * conversation and on its channel, the question's view, and what the chat
 * was told about the question's buttons.
 *
 * Real wiring throughout, with the model scripted at the streaming boundary
 * and recording adapters standing in for the chat (which shows the
 * question's options as buttons) and the web.
 */
class ExpiryPolicyRaceJourneyTest extends AssembledSystemTestCase
{
    private const API = '/api/clarion-app/llm-client';

    private const MESSAGE_ENDPOINT = self::API.'/message';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const TRIALS = 20;

    private const QUESTION = 'Which test framework should I set up?';

    private const CHOICES = ['PHPUnit', 'Pest'];

    private const CHOICE_TEXT = [1 => 'PHPUnit', 2 => 'Pest'];

    /** What the typed reply, the tap and the form each answer. */
    private const RAW = ['answer' => '2', 'tap' => '1', 'form' => '2'];

    private const METHOD = ['answer' => 'typed_reply', 'tap' => 'messaging_option', 'form' => 'web_form'];

    private const ASSUMED_OUTCOME = 'Expired — no answer arrived in time; the agent continued assuming: 1. PHPUnit';

    private const WITHDRAWN_OUTCOME = 'This question was withdrawn and is no longer awaiting a reply.';

    private const DISCARDED_OUTCOME = 'Discarded — the conversation was ended or deleted while work was stopped.';

    private RecordingChannelDelivery $web;

    private RecordingRichChannelDelivery $telegram;

    /** Resume jobs already run by the simulated worker. */
    private int $workedJobs = 0;

    private ?string $hookQuestionId = null;

    private ?\Closure $interloper = null;

    private bool $hookFired = false;

    /** What the tap returned, when a tap took part. */
    private ?InteractiveAnswerOutcome $tapOutcome = null;

    /** The status of the web form's response, when the form took part. */
    private ?int $formStatus = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scenario = 'expiry_policy_races';
        $this->entryPath = 'stream';

        config(['llm-client.run_trace.enabled' => true]);

        Queue::fake([AgentStreamStepJob::class, ResumeAfterQuestionJob::class]);

        // Ending a conversation also captures its episodic memory through a
        // model call of its own; that capture is not what these races are
        // about, so its trigger is held back.
        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
            ConversationEnded::class,
        ]);

        $this->web = new RecordingChannelDelivery('web');
        $this->telegram = new RecordingRichChannelDelivery('telegram');
        $registry = $this->app->make(ConversationChannelRegistry::class);
        $registry->register($this->web);
        $registry->register($this->telegram);

        // "Before the compare-and-set": the moment the first side has read
        // the question as a model, and has not yet tried to change it.
        Event::listen('eloquent.retrieved: '.AgentQuestion::class, function (AgentQuestion $question) {
            if ($this->interloper !== null && !$this->hookFired && $question->id === $this->hookQuestionId) {
                $this->hookFired = true;
                ($this->interloper)();
            }
        });

        $this->fixture()->build();
    }

    // ---------------------------------------------------------------
    // The races
    // ---------------------------------------------------------------

    /**
     * Each race: the policy the question is asked with, whether its work has
     * already stopped when the race starts, and the two sides.
     */
    private const RACES = [
        'answer vs expiry, proceed' => ['proceed', false, 'answer', 'expire'],
        'answer vs expiry, stop' => ['stop', false, 'answer', 'expire'],
        'late answer vs late answer, stopped' => ['stop', true, 'answer', 'tap'],
        'late answer vs end, stopped' => ['stop', true, 'answer', 'end'],
        'late answer vs delete, stopped' => ['stop', true, 'form', 'delete'],
        'expiry vs end, stop' => ['stop', false, 'expire', 'end'],
        'expiry vs delete, stop' => ['stop', false, 'expire', 'delete'],
        'expiry vs end, proceed' => ['proceed', false, 'expire', 'end'],
    ];

    /** @return array<string, array{string, string}> each race in both orders */
    public static function races(): array
    {
        $rows = [];
        foreach (self::RACES as $race => [, , $a, $b]) {
            $rows["{$race}: {$b} arrives before {$a}'s compare-and-set"] = [$race, $a];
            $rows["{$race}: {$a} arrives before {$b}'s compare-and-set"] = [$race, $b];
        }

        return $rows;
    }

    /**
     * The one outcome a race must end in, given which side started first
     * (the other arrived before its compare-and-set).
     *
     * @return array{
     *     state: string, late: bool, by: ?string, outcome: string, outcome_text: string,
     *     notices: list<string>, resolutions: list<array{string, bool}>, continues: bool, end_reason: ?string
     * }
     */
    private function expected(string $race, string $first): array
    {
        $answeredBy = fn (string $by, bool $late, array $notices, array $resolutions) => [
            'state' => 'answered',
            'late' => $late,
            'by' => $by,
            'outcome' => $late ? 'answered_late' : 'answered',
            'outcome_text' => ($late ? 'Answered after work stopped: ' : 'Answered: ').self::RAW[$by].'. '.self::CHOICE_TEXT[(int) self::RAW[$by]],
            'notices' => $notices,
            'resolutions' => $resolutions,
            'continues' => true,
            'end_reason' => null,
        ];
        $withdrawn = fn (bool $discarded, array $notices, array $resolutions) => [
            'state' => 'withdrawn',
            'late' => $discarded,
            'by' => null,
            'outcome' => 'withdrawn',
            'outcome_text' => $discarded ? self::DISCARDED_OUTCOME : self::WITHDRAWN_OUTCOME,
            'notices' => $notices,
            'resolutions' => $resolutions,
            'continues' => false,
            'end_reason' => $discarded ? 'stopped question discarded' : 'question withdrawn',
        ];
        $assumed = fn (array $notices) => [
            'state' => 'expired_assumed',
            'late' => false,
            'by' => null,
            'outcome' => 'expired_assumed',
            'outcome_text' => self::ASSUMED_OUTCOME,
            'notices' => $notices,
            'resolutions' => [['expired_assumed', false]],
            'continues' => true,
            'end_reason' => null,
        ];
        $stoppedThen = fn (string $final) => [['stopped', true], [$final, false]];

        return match ("{$race} / {$first} first") {
            // The deadline takes effect first: the reply is late — an
            // ordinary message, and the user is told what was assumed.
            'answer vs expiry, proceed / answer first' => $assumed(['expired_assumed', 'late_reply']),
            'answer vs expiry, proceed / expire first' => $answeredBy('answer', false, [], [['answered', false]]),
            // The work stops first, and the same answer then resumes it.
            'answer vs expiry, stop / answer first' => $answeredBy('answer', true, ['stopped'], $stoppedThen('answered')),
            'answer vs expiry, stop / expire first' => $answeredBy('answer', false, [], [['answered', false]]),
            'late answer vs late answer, stopped / answer first' => $answeredBy('tap', true, ['stopped'], $stoppedThen('answered')),
            'late answer vs late answer, stopped / tap first' => $answeredBy('answer', true, ['stopped'], $stoppedThen('answered')),
            'late answer vs end, stopped / answer first' => $withdrawn(true, ['stopped', 'withdrawn'], $stoppedThen('withdrawn')),
            'late answer vs end, stopped / end first' => $answeredBy('answer', true, ['stopped'], $stoppedThen('answered')),
            'late answer vs delete, stopped / form first' => $withdrawn(true, ['stopped', 'withdrawn'], $stoppedThen('withdrawn')),
            'late answer vs delete, stopped / delete first' => $answeredBy('form', true, ['stopped'], $stoppedThen('answered')),
            'expiry vs end, stop / expire first', 'expiry vs delete, stop / expire first' => $withdrawn(false, ['withdrawn'], [['withdrawn', false]]),
            // The work stops first, and is then discarded.
            'expiry vs end, stop / end first', 'expiry vs delete, stop / delete first' => $withdrawn(true, ['stopped', 'withdrawn'], $stoppedThen('withdrawn')),
            'expiry vs end, proceed / expire first' => $withdrawn(false, ['withdrawn'], [['withdrawn', false]]),
            'expiry vs end, proceed / end first' => $assumed(['expired_assumed']),
        };
    }

    #[Test]
    #[DataProvider('races')]
    public function exactly_one_outcome_takes_effect_and_the_agent_and_the_user_agree_on_it(string $race, string $first): void
    {
        [$policy, $stoppedFirst, $a, $b] = self::RACES[$race];
        $second = $first === $a ? $b : $a;

        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $label = "{$race}, trial {$trial}: {$second} arriving before {$first}'s compare-and-set";
            $this->tapOutcome = null;
            $this->formStatus = null;

            $askedAt = Carbon::parse('2026-10-12 00:00:00', 'UTC')->addHours(3 * $trial);
            $this->travelTo($askedAt);
            $conversation = $this->newConversation();
            $question = $this->ask($conversation, $policy);

            // Past the deadline; the sweep has not run yet.
            $this->travelTo($askedAt->copy()->addMinutes(61));
            if ($stoppedFirst) {
                $this->assertSame(0, Artisan::call(self::SWEEP));
                $this->assertSame(QuestionState::Stopped, $question->fresh()->state, "{$label}: precondition — the work stopped");
                $this->travelTo($askedAt->copy()->addMinutes(90));
            }

            $this->hookQuestionId = $question->id;
            $this->hookFired = false;
            $this->interloper = fn () => $this->asInterloper($second, $question, $conversation);

            $this->viaEntryPoint($first, $question, $conversation);

            $this->assertTrue($this->hookFired, "{$label}: the {$second} must have arrived before the {$first}'s compare-and-set");
            $this->interloper = null;
            $this->workQueuedResumes();

            $this->assertOneAgreedOutcome($question, $conversation, $policy, $this->expected($race, $first), [$first, $second], $label);
        }
    }

    // ---------------------------------------------------------------
    // Driving the system
    // ---------------------------------------------------------------

    private function newConversation(): Conversation
    {
        return Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->fixture()->user->id,
            'server_id' => $this->fixture()->server->id,
            'model' => 'gpt-4',
            'title' => 'Race conversation',
            'channel' => 'telegram',
        ]);
    }

    /** @return list<string> SSE chunks for one streamed model turn */
    private function turnChunks(array $calls): array
    {
        $chunks = [];
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

    /** @return list<int> the streamed request slots dispatched for $conversation, in order */
    private function slotsFor(Conversation $conversation): array
    {
        $data = $this->stream()->extractDispatchedJobs()->capturedData();

        return array_keys(array_filter($data, fn (array $d) => ($d['conversation_id'] ?? null) === $conversation->id));
    }

    /** @return array<string, mixed> */
    private function slotData(int $slot): array
    {
        return $this->stream()->extractDispatchedJobs()->capturedData()[$slot];
    }

    /** @return list<array<string, mixed>> */
    private function slotMessages(int $slot): array
    {
        /** @var CapturedPayload $payload */
        $payload = $this->stream()->extractDispatchedJobs()->capturedPayloads()[$slot];

        return json_decode(json_encode($payload->messages), true);
    }

    /** The user asks for work in the chat's conversation, and the agent's first streamed turn asks. */
    private function ask(Conversation $conversation, string $policy): AgentQuestion
    {
        $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $conversation->id,
            'content' => 'Set up the tests for this project.',
        ])->assertStatus(201);

        $slots = $this->slotsFor($conversation);
        $this->assertCount(1, $slots, 'the message started one streamed request');

        $arguments = ['question' => self::QUESTION, 'choices' => self::CHOICES, 'wait_minutes' => 60]
            + ($policy === 'proceed' ? ['assumed_choices' => [1]] : []);
        $stream = $this->stream();
        $stream->setCurrentSlot($slots[0]);
        $stream->emit($this->turnChunks([['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => json_encode($arguments)]]));
        $stream->finish();

        $question = AgentQuestion::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($question, 'precondition: the ask opened a question');
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertSame($policy === 'proceed' ? 'proceed_on_assumption' : 'stop', $question->unanswered_policy?->value);
        $this->assertCount(1, $this->telegram->presentationsFor($conversation->id), 'precondition: shown in the chat with its options');

        return $question;
    }

    /** Run every queued resume job not yet run, as a worker would. */
    private function workQueuedResumes(): void
    {
        $jobs = Queue::pushed(ResumeAfterQuestionJob::class)->values()->all();

        while ($this->workedJobs < count($jobs)) {
            $this->app->call([$jobs[$this->workedJobs], 'handle']);
            $this->workedJobs++;
            $jobs = Queue::pushed(ResumeAfterQuestionJob::class)->values()->all();
        }
    }

    private function formRequest(AgentQuestion $question): Request
    {
        return Request::create(
            self::API."/question/{$question->id}/answer",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['selected' => [(int) self::RAW['form']]]),
        );
    }

    /** One side, through its real entry point, as its own request or tick. */
    private function viaEntryPoint(string $operation, AgentQuestion $question, Conversation $conversation): void
    {
        $user = $this->fixture()->user;

        match ($operation) {
            'answer' => $this->actingAs($user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
                'conversation_id' => $conversation->id,
                'content' => self::RAW['answer'],
            ])->assertStatus(201),
            'tap' => $this->tapOutcome = $this->telegram->tap($question->id, (string) $user->id, [(int) self::RAW['tap']]),
            'form' => $this->formStatus = $this->actingAs($user, 'api')
                ->postJson(self::API."/question/{$question->id}/answer", ['selected' => [(int) self::RAW['form']]])
                ->status(),
            'expire' => $this->assertSame(0, Artisan::call(self::SWEEP)),
            'end' => $this->actingAs($user, 'api')->postJson(self::API."/conversation/{$conversation->id}/end")->assertStatus(200),
            'delete' => $this->actingAs($user, 'api')->deleteJson(self::API."/conversation/{$conversation->id}")->assertStatus(204),
        };
    }

    /** The other side, from inside the first one's progress, running the code its own request or tick would. */
    private function asInterloper(string $operation, AgentQuestion $question, Conversation $conversation): void
    {
        $user = $this->fixture()->user;
        $this->actingAs($user, 'api');

        match ($operation) {
            'answer' => $this->assertSame(201, $this->app->make(MessageController::class)->store(
                Request::create(self::MESSAGE_ENDPOINT, 'POST', ['conversation_id' => $conversation->id, 'content' => self::RAW['answer']]),
            )->getStatusCode()),
            'tap' => $this->tapOutcome = $this->telegram->tap($question->id, (string) $user->id, [(int) self::RAW['tap']]),
            'form' => $this->formStatus = $this->app->make(QuestionController::class)->answer($this->formRequest($question), $question->id)->getStatusCode(),
            'expire' => $this->assertSame(0, Artisan::call(self::SWEEP)),
            'end' => $this->assertSame(200, $this->app->call(
                [$this->app->make(ConversationController::class), 'end'],
                ['id' => $conversation->id],
            )->getStatusCode()),
            'delete' => $this->assertSame(204, $this->app->make(ConversationController::class)->destroy($conversation->id)->getStatusCode()),
        };
    }

    // ---------------------------------------------------------------
    // One outcome, agreed everywhere
    // ---------------------------------------------------------------

    /** @return array<string, mixed>|null the decoded result stored for the paused ask_user call */
    private function storedAskResult(AgentQuestion $question): ?array
    {
        $paused = Message::findOrFail($question->paused_message_id);

        foreach ((array) ($paused->tool_data['tool_results'] ?? []) as $result) {
            if (($result['tool_call_id'] ?? null) === $question->tool_call_id) {
                return json_decode((string) ($result['content'] ?? ''), true);
            }
        }

        return null;
    }

    private function toolResultIn(array $messages, string $toolCallId): ?array
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $toolCallId) {
                $content = $message['content'] ?? null;

                return json_decode(is_string($content) ? $content : json_encode($content), true);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $expect */
    private function assertOneAgreedOutcome(AgentQuestion $question, Conversation $conversation, string $policy, array $expect, array $sides, string $label): void
    {
        $fresh = AgentQuestion::findOrFail($question->id);

        // Exactly the expected outcome took effect, and the question no
        // longer holds the conversation.
        $this->assertSame($expect['state'], $fresh->state->value, "{$label}: exactly one outcome takes effect");
        $this->assertNull($fresh->pending_conversation_id, "{$label}: the slot is released");
        $this->assertNotNull($fresh->resumed_at, "{$label}: the paused turn is consumed");
        $this->assertSame($expect['late'], $fresh->stopped_at !== null, "{$label}: the work had stopped first, or not");
        if ($expect['by'] !== null) {
            $this->assertSame(self::RAW[$expect['by']], $fresh->answer_raw, "{$label}: the winning answer is the one recorded");
            $this->assertSame(self::METHOD[$expect['by']], $fresh->answer_method?->value);
        } else {
            $this->assertNull($fresh->answer_raw, "{$label}: no answer is applied");
            $this->assertNull($fresh->answer_message_id);
        }
        $this->assertSame($policy === 'proceed' ? '1' : null, $fresh->assumed_answer_raw, "{$label}: the assumption stated when asked is never changed");

        // The agent's result for its paused call.
        $stored = $this->storedAskResult($fresh);
        $this->assertNotNull($stored, "{$label}: the paused ask_user call is answered");
        $this->assertSame($expect['outcome'], $stored['outcome'] ?? null, "{$label}: the agent receives the outcome that took effect");
        $this->assertSame($fresh->id, $stored['question_id'] ?? null);
        if ($expect['by'] !== null) {
            $this->assertSame(self::RAW[$expect['by']], $stored['answer']['raw_reply'] ?? null, "{$label}: the agent receives the winning answer");
        }
        if ($expect['outcome'] === 'expired_assumed') {
            $this->assertSame([['number' => 1, 'text' => 'PHPUnit']], $stored['assumed_answer']['selected'] ?? null);
            $this->assertArrayNotHasKey('answer', $stored, "{$label}: an assumption is never passed off as an answer");
        }

        // What the user sees: the question as shown, its view, the chat's
        // buttons, and the notices — in the conversation and on its channel.
        $this->assertSame($expect['state'], Message::findOrFail($fresh->display_message_id)->tool_data['state'] ?? null, "{$label}: the question is shown with that outcome");

        $view = $this->app->make(QuestionViewBuilder::class)->forQuestion($fresh);
        $this->assertSame($expect['state'], $view['state'], "{$label}: the web view shows that outcome");
        $this->assertFalse($view['answerable'], "{$label}: and takes no further answer");
        $this->assertSame($expect['outcome_text'], $view['outcome_text'], "{$label}: described as what the agent received");

        $resolutions = $this->telegram->resolutionsFor($fresh->id);
        $this->assertSame(
            $expect['resolutions'],
            array_map(fn (QuestionOutcomeView $o) => [$o->state->value, $o->answerable], $resolutions),
            "{$label}: the chat's buttons were updated once per change, ending with that outcome",
        );
        $this->assertSame($expect['outcome_text'], end($resolutions)->summary, "{$label}: the chat says what the web says");

        $notices = Message::where('conversation_id', $conversation->id)->orderBy('id')->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'agent_question_notice')
            ->values();
        $this->assertSame(
            $expect['notices'],
            $notices->map(fn (Message $m) => ($m->tool_data['notice'] ?? null) === 'late_reply' ? 'late_reply' : ($m->tool_data['state'] ?? null))->all(),
            "{$label}: the user is told exactly what happened",
        );
        $this->assertSame(
            $notices->pluck('content')->all(),
            $this->telegram->textsFor($conversation->id),
            "{$label}: each notice reached the conversation's channel once, in order",
        );
        $this->assertSame([], $this->web->textsFor($conversation->id), "{$label}: nothing is sent elsewhere");

        // Exactly one continuation of the run — or, when it was withdrawn,
        // the run closed — and never a second run beside a continuing one.
        $slots = $this->slotsFor($conversation);
        $continuations = array_values(array_filter(
            array_slice($slots, 1),
            fn (int $slot) => ($this->slotData($slot)['run_id'] ?? null) === $fresh->run_id,
        ));
        $run = DB::table('agent_runs')->where('id', $fresh->run_id)->first();

        if ($expect['continues'] && $run->cancelled_at !== null && $continuations === []) {
            // The losing end still cancelled the run before its continuation
            // started; cancellation wins over resuming.
            $this->assertSame('stopped_early', $run->end_state, "{$label}: the cancelled run is closed, not continued");
            $this->assertSame(app(\ClarionApp\LlmClient\Services\TaskResumeText::class)->cancelledReason(), $run->end_reason);
        } elseif ($expect['continues']) {
            $this->assertCount(1, $continuations, "{$label}: the run is continued exactly once");
            $this->assertSame($stored, $this->toolResultIn($this->slotMessages($continuations[0]), $fresh->tool_call_id), "{$label}: the continuation carries that outcome");
            $this->assertCount(2, $slots, "{$label}: no second run starts beside the continuing one");
            $this->assertSame('in_progress', $run->end_state);
        } else {
            $this->assertCount(0, $continuations, "{$label}: a withdrawn run is never continued");
            $this->assertSame('stopped_early', $run->end_state, "{$label}: the withdrawn run is closed");
            $this->assertSame($expect['end_reason'], $run->end_reason);
        }

        // The side that lost.
        $replies = Message::where('conversation_id', $conversation->id)->where('role', 'user')->where('content', self::RAW['answer'])->get();
        if (in_array('answer', $sides, true)) {
            $this->assertCount(1, $replies, "{$label}: the typed reply is kept");
            $this->assertSame(
                $expect['by'] === 'answer' ? 'agent_question_answer' : null,
                $replies[0]->tool_data['kind'] ?? null,
                "{$label}: the typed reply is the answer only if it won; otherwise an ordinary message",
            );
        }
        if (in_array('tap', $sides, true)) {
            $this->assertNotNull($this->tapOutcome);
            $this->assertSame(
                $expect['by'] === 'tap' ? InteractiveAnswerStatus::Answered : InteractiveAnswerStatus::NotPending,
                $this->tapOutcome->status,
                "{$label}: the tap applied only if it won",
            );
        }
        if (in_array('form', $sides, true)) {
            $this->assertSame($expect['by'] === 'form' ? 201 : 409, $this->formStatus, "{$label}: the form applied only if it won");
        }
    }
}
