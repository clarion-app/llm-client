<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Controllers\ConversationController;
use ClarionApp\LlmClient\Controllers\MessageController;
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
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingChannelDelivery;

/**
 * Every way a pending question can be resolved races every other, and
 * exactly one wins.
 *
 * Three things can resolve a question — the user's answer
 * (`POST message`), expiry (the every-minute sweep and the resume job it
 * queues) and withdrawal (`POST conversation/{id}/end`). For each pair, one
 * of them is started through its real entry point and the other arrives at
 * one of three moments of the first one's progress:
 *
 * - before the first one's compare-and-set — it has already read the
 *   question as pending, and the other resolves it first;
 * - between that compare-and-set and the consumption of the paused turn —
 *   the first one has won and is taking its resume claim;
 * - after the paused turn was consumed.
 *
 * Every trial must end with exactly one terminal state, exactly one resume
 * (or, for a withdrawal, exactly one close), and the outcome the agent
 * receives equal to what the user is shown: the display message's state
 * and any notice. A reply that did not win is an ordinary message.
 *
 * Two further races: a second reply arriving while the first one's resume
 * is in flight is stored as an ordinary message and never starts a second,
 * concurrent run; and two asks landing in one conversation at once leave
 * exactly one pending question.
 *
 * Real wiring throughout, with the model scripted at the streaming boundary
 * and a recording adapter standing in for the web broadcast. An interloper
 * runs the same endpoint code its request would (the controller action or
 * the sweep command), invoked from a database or model event at the moment
 * being tested.
 */
class AgentQuestionRaceJourneyTest extends AssembledSystemTestCase
{
    private const MESSAGE_ENDPOINT = '/api/clarion-app/llm-client/message';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const TRIALS = 20;

    private const REPLY = '2';

    private const STATE_FOR = [
        'answer' => 'answered',
        'expire' => 'expired',
        'withdraw' => 'withdrawn',
    ];

    private RecordingChannelDelivery $web;

    /** Resume jobs already run by the simulated worker. */
    private int $workedJobs = 0;

    /** The armed interleaving: which moment, which question, what runs then. */
    private ?string $hookBoundary = null;

    private ?string $hookQuestionId = null;

    private ?\Closure $interloper = null;

    private bool $hookFired = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scenario = 'question_resolution_races';
        $this->entryPath = 'stream';

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.agent_questions.expiry_minutes' => 1]);
        // The period under test is shorter than the shipped minimum.
        config(['llm-client.agent_questions.min_expiry_minutes' => 1]);

        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class]);

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
        $this->app->make(ConversationChannelRegistry::class)->register($this->web);

        // "Before the compare-and-set": the moment the first resolver has
        // read the question as a model, and has not yet tried to resolve it.
        Event::listen('eloquent.retrieved: '.AgentQuestion::class, function (AgentQuestion $question) {
            if ($this->hookBoundary === 'before_cas' && $question->id === $this->hookQuestionId) {
                $this->fireInterloper();
            }
        });

        // "Between the compare-and-set and the consumption": the winner is
        // taking its one-time resume claim; the paused turn is not consumed.
        DB::listen(function (QueryExecuted $query) {
            if ($this->hookBoundary === 'between_cas_and_consume'
                && preg_match('/^update "agent_questions" set .*"resumed_at" = \?/', $query->sql) === 1
                && in_array($this->hookQuestionId, $query->bindings, true)
            ) {
                $this->fireInterloper();
            }
        });

        $this->fixture()->build();
    }

    private function fireInterloper(): void
    {
        if ($this->hookFired || $this->interloper === null) {
            return;
        }

        $this->hookFired = true;
        ($this->interloper)();
    }

    private function arm(string $boundary, string $questionId, \Closure $interloper): void
    {
        $this->hookBoundary = $boundary;
        $this->hookQuestionId = $questionId;
        $this->interloper = $interloper;
        $this->hookFired = false;
    }

    private function disarm(): void
    {
        $this->hookBoundary = null;
        $this->hookQuestionId = null;
        $this->interloper = null;
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
            'channel' => 'web',
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

    /** @return array<string, mixed> the dispatch data of streamed request $slot */
    private function slotData(int $slot): array
    {
        return $this->stream()->extractDispatchedJobs()->capturedData()[$slot];
    }

    /** @return list<array<string, mixed>> the messages of streamed request $slot */
    private function slotMessages(int $slot): array
    {
        /** @var CapturedPayload $payload */
        $payload = $this->stream()->extractDispatchedJobs()->capturedPayloads()[$slot];

        return json_decode(json_encode($payload->messages), true);
    }

    /** The user asks for work and the model's first streamed turn asks a question. */
    private function ask(Conversation $conversation): AgentQuestion
    {
        $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $conversation->id,
            'content' => 'Help me tidy up the project.',
        ])->assertStatus(201);

        $slots = $this->slotsFor($conversation);
        $this->assertCount(1, $slots, 'the message started one streamed request');

        $stream = $this->stream();
        $stream->setCurrentSlot($slots[0]);
        $stream->emit($this->turnChunks([[
            'id' => 'call_ask',
            'name' => 'ask_user',
            'arguments' => AgentQuestionWebJourneyTest::threeChoiceAskArguments(),
        ]]));
        $stream->finish();

        $question = AgentQuestion::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($question, 'precondition: the ask opened a question');
        $this->assertSame(QuestionState::Pending, $question->state);

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

    /** Resolve through the operation's real entry point, as its own request or tick. */
    private function viaEntryPoint(string $operation, Conversation $conversation): void
    {
        match ($operation) {
            'answer' => $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
                'conversation_id' => $conversation->id,
                'content' => self::REPLY,
            ])->assertStatus(201),
            'withdraw' => $this->actingAs($this->fixture()->user, 'api')
                ->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/end")
                ->assertStatus(200),
            'expire' => $this->assertSame(0, Artisan::call(self::SWEEP)),
        };
    }

    /**
     * Resolve from inside another resolver's progress, running the code the
     * operation's own request would run (the controller action, or the
     * sweep command).
     */
    private function asInterloper(string $operation, Conversation $conversation, string $content = self::REPLY): void
    {
        $user = $this->fixture()->user;
        $this->actingAs($user, 'api');

        match ($operation) {
            'answer' => $this->assertSame(201, $this->app->make(MessageController::class)->store(
                Request::create(self::MESSAGE_ENDPOINT, 'POST', ['conversation_id' => $conversation->id, 'content' => $content]),
            )->getStatusCode()),
            'withdraw' => $this->assertSame(200, $this->app->call(
                [$this->app->make(ConversationController::class), 'end'],
                ['id' => $conversation->id],
            )->getStatusCode()),
            'expire' => $this->assertSame(0, Artisan::call(self::SWEEP)),
        };
    }

    // ---------------------------------------------------------------
    // One outcome
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

    private function assertOneOutcome(AgentQuestion $question, Conversation $conversation, string $winner, array $operations, string $label): void
    {
        $state = self::STATE_FOR[$winner];
        $fresh = AgentQuestion::findOrFail($question->id);

        // Exactly one terminal state, and the latch released.
        $this->assertSame($state, $fresh->state->value, "{$label}: exactly the expected resolution takes effect");
        $this->assertNull($fresh->pending_conversation_id, "{$label}: the slot is released");
        $this->assertNotNull($fresh->resumed_at, "{$label}: the paused turn is consumed");

        // The agent's outcome is the user's outcome.
        $stored = $this->storedAskResult($fresh);
        $this->assertNotNull($stored, "{$label}: the paused ask_user call is answered");
        $this->assertSame($state, $stored['outcome'] ?? null, "{$label}: the agent receives the winning outcome");
        $this->assertSame($fresh->id, $stored['question_id'] ?? null);
        $this->assertSame(
            $state,
            Message::findOrFail($fresh->display_message_id)->tool_data['state'] ?? null,
            "{$label}: the question is shown with the outcome the agent received",
        );

        $notices = Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'agent_question_notice')
            ->values();
        if ($state === 'answered') {
            $this->assertCount(0, $notices, "{$label}: an answered question shows no notice");
            $this->assertSame([AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES], $this->web->textsFor($conversation->id), "{$label}: only the question was delivered");
        } else {
            $this->assertCount(1, $notices, "{$label}: exactly one notice is shown");
            $this->assertSame($state, $notices[0]->tool_data['state'] ?? null, "{$label}: the notice tells the outcome the agent received");
            $this->assertSame(
                [AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES, $notices[0]->content],
                $this->web->textsFor($conversation->id),
                "{$label}: the question, then the one notice, were delivered",
            );
        }

        // Exactly one resume — or, for a withdrawal, exactly one close.
        $slots = $this->slotsFor($conversation);
        $continuations = array_values(array_filter(
            array_slice($slots, 1),
            fn (int $slot) => ($this->slotData($slot)['run_id'] ?? null) === $fresh->run_id,
        ));
        $run = DB::table('agent_runs')->where('id', $fresh->run_id)->first();
        $action = DB::table('agent_run_actions')->where('id', $fresh->action_id)->first();

        if ($state === 'withdrawn') {
            $this->assertCount(0, $continuations, "{$label}: a withdrawn run is never continued");
            $this->assertSame('stopped_early', $run->end_state, "{$label}: the withdrawn run is closed");
            $this->assertSame('question withdrawn', $run->end_reason);
        } else {
            $this->assertCount(1, $continuations, "{$label}: the run is resumed exactly once");
            $this->assertSame($stored, $this->toolResultIn($this->slotMessages($continuations[0]), $fresh->tool_call_id), "{$label}: the continuation carries the stored outcome");
            $this->assertSame('in_progress', $run->end_state, "{$label}: the resumed run carries on");
        }

        if ($state === 'answered') {
            $this->assertSame('success', $action->outcome);
        } else {
            $this->assertSame('failure', $action->outcome);
            $this->assertSame("question {$state}", $action->failure_reason);
        }

        // The reply is the answer only if the answer won; otherwise it is an
        // ordinary message.
        $replies = Message::where('conversation_id', $conversation->id)->where('role', 'user')->where('content', self::REPLY)->get();
        if ($winner === 'answer') {
            $this->assertCount(1, $replies);
            $this->assertSame('agent_question_answer', $replies[0]->tool_data['kind'] ?? null);
            $this->assertSame(self::REPLY, $fresh->answer_raw);
            $this->assertSame($replies[0]->id, $fresh->answer_message_id);
        } else {
            $this->assertNull($fresh->answer_raw, "{$label}: the losing reply is never applied to the question");
            $this->assertNull($fresh->answer_message_id);
            if (in_array('answer', $operations, true)) {
                $this->assertCount(1, $replies, "{$label}: the losing reply is kept");
                $this->assertNull($replies[0]->tool_data, "{$label}: the losing reply is an ordinary message");
            }
        }
    }

    // ---------------------------------------------------------------
    // Pairwise races
    // ---------------------------------------------------------------

    /** @return array<string, array{string, string, string}> */
    public static function races(): array
    {
        $rows = [];
        foreach ([['answer', 'expire'], ['answer', 'withdraw'], ['expire', 'withdraw']] as [$a, $b]) {
            foreach (['before_cas', 'between_cas_and_consume', 'after_consume'] as $boundary) {
                $rows["{$a} vs {$b}, {$boundary}"] = [$a, $b, $boundary];
            }
        }

        return $rows;
    }

    #[Test]
    #[DataProvider('races')]
    public function exactly_one_resolution_takes_effect_and_matches_what_the_user_sees(string $a, string $b, string $boundary): void
    {
        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            // Alternate which operation starts first.
            [$first, $second] = $trial % 2 === 0 ? [$a, $b] : [$b, $a];
            $label = "trial {$trial}: {$second} arriving {$boundary} of {$first}";

            $conversation = $this->newConversation();
            $question = $this->ask($conversation);
            if (in_array('expire', [$a, $b], true)) {
                // A question asked before unanswered policies existed: it expires as it always did.
                DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
            }

            // Past the answerable period, not yet swept: all three
            // resolutions are possible.
            $this->travel(2)->minutes();

            if ($boundary === 'after_consume') {
                $this->viaEntryPoint($first, $conversation);
                $this->workQueuedResumes();
                $this->assertNotNull($question->fresh()->resumed_at, "{$label}: precondition — the first resolution was consumed");

                $this->viaEntryPoint($second, $conversation);
                $this->workQueuedResumes();

                $winner = $first;
            } else {
                $this->arm($boundary, $question->id, fn () => $this->asInterloper($second, $conversation));

                $this->viaEntryPoint($first, $conversation);
                $this->workQueuedResumes();

                $this->assertTrue($this->hookFired, "{$label}: the {$second} must have arrived {$boundary} of the {$first}");
                $this->disarm();
                $this->workQueuedResumes();

                $winner = $boundary === 'before_cas' ? $second : $first;
            }

            $this->assertOneOutcome($question, $conversation, $winner, [$first, $second], $label);
        }
    }

    // ---------------------------------------------------------------
    // Double send
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_reply_during_the_answers_resume_is_an_ordinary_message_and_starts_no_second_run(): void
    {
        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $conversation = $this->newConversation();
            $question = $this->ask($conversation);

            // The second reply lands after the first reply's compare-and-set,
            // while its resume is taking the claim.
            $this->arm('between_cas_and_consume', $question->id, fn () => $this->asInterloper('answer', $conversation, '3'));
            $this->viaEntryPoint('answer', $conversation);
            $this->assertTrue($this->hookFired, "trial {$trial}: the second reply must have arrived during the resume");
            $this->disarm();

            $this->assertDoubleSendHandled($question, $conversation, $trial);
            $this->assertSame('answered', $question->fresh()->state->value);
        }
    }

    #[Test]
    public function a_reply_after_expiry_but_before_its_resume_is_an_ordinary_message_and_starts_no_second_run(): void
    {
        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $conversation = $this->newConversation();
            $question = $this->ask($conversation);
            // A question asked before unanswered policies existed: it expires as it always did.
            DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
            $this->travel(2)->minutes();

            // The sweep has expired the question and queued its resume; no
            // worker has run it yet when the reply arrives.
            $this->viaEntryPoint('expire', $conversation);
            $this->assertSame('expired', $question->fresh()->state->value, "trial {$trial}: precondition");
            $this->assertNull($question->fresh()->resumed_at, "trial {$trial}: precondition — not resumed yet");

            $reply = $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
                'conversation_id' => $conversation->id,
                'content' => '3',
            ]);
            $reply->assertStatus(201);
            $this->assertNull($reply->json('tool_data'), "trial {$trial}: the reply is an ordinary message");
            $this->assertCount(1, $this->slotsFor($conversation), "trial {$trial}: the reply starts no run while the resume is pending");

            $this->workQueuedResumes();

            $this->assertDoubleSendHandled($question, $conversation, $trial);
            $this->assertSame('expired', $question->fresh()->state->value);
        }
    }

    /**
     * The reply that did not resolve the question was stored as an ordinary
     * message, and the conversation got exactly one continuation and no
     * second, concurrent run.
     */
    private function assertDoubleSendHandled(AgentQuestion $question, Conversation $conversation, int $trial): void
    {
        $late = Message::where('conversation_id', $conversation->id)->where('role', 'user')->where('content', '3')->get();
        $this->assertCount(1, $late, "trial {$trial}: the second reply is stored");
        $this->assertNull($late[0]->tool_data, "trial {$trial}: the second reply is an ordinary message, not an answer");
        $this->assertNotSame('3', $question->fresh()->answer_raw);

        $slots = $this->slotsFor($conversation);
        $this->assertCount(2, $slots, "trial {$trial}: the ask and exactly one continuation — no second run was started");
        $this->assertSame($question->run_id, $this->slotData($slots[1])['run_id'] ?? null, "trial {$trial}: the one continuation is the question's run");
        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), "trial {$trial}: one run in the conversation");
        $this->assertTrue((bool) $conversation->fresh()->is_processing, "trial {$trial}: the resumed run is processing");
    }

    // ---------------------------------------------------------------
    // Two asks at once
    // ---------------------------------------------------------------

    #[Test]
    public function two_asks_landing_at_once_in_one_conversation_leave_exactly_one_pending_question(): void
    {
        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $conversation = $this->newConversation();

            $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
                'conversation_id' => $conversation->id,
                'content' => 'Help me tidy up the project.',
            ])->assertStatus(201);
            $slots = $this->slotsFor($conversation);

            // A second agent turn in the same conversation asks while the
            // first ask is being written: right after its question row was
            // inserted, before its transaction completes.
            $concurrent = null;
            $fired = false;
            DB::listen(function (QueryExecuted $query) use ($conversation, &$concurrent, &$fired) {
                if ($fired || !str_starts_with($query->sql, 'insert into "agent_questions"') || !in_array($conversation->id, $query->bindings, true)) {
                    return;
                }
                $fired = true;

                $handler = $this->app->make(AgentLoopStreamHandler::class);
                $handler->toolCalls = [[
                    'id' => 'call_concurrent',
                    'type' => 'function',
                    'function' => ['name' => 'ask_user', 'arguments' => json_encode(['question' => 'Should I also update the docs?', 'choices' => ['Yes', 'No']])],
                ]];
                $handler->message = Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'user' => 'Clarion',
                    'content' => '',
                    'responseTime' => 0,
                ]);
                $handler->finish(json_encode(['conversation_id' => $conversation->id, 'iteration' => 1]), 1.0);
                $concurrent = $handler->message->fresh();
            });

            $stream = $this->stream();
            $stream->setCurrentSlot($slots[0]);
            $stream->emit($this->turnChunks([[
                'id' => 'call_ask',
                'name' => 'ask_user',
                'arguments' => AgentQuestionWebJourneyTest::threeChoiceAskArguments(),
            ]]));
            $stream->finish();

            $this->assertTrue($fired, "trial {$trial}: the second ask must have landed during the first");
            $this->assertNotNull($concurrent);

            $questions = AgentQuestion::where('conversation_id', $conversation->id)->get();
            $this->assertCount(1, $questions, "trial {$trial}: exactly one question exists");
            $this->assertSame('call_ask', $questions[0]->tool_call_id, "trial {$trial}: the first ask holds the slot");
            $this->assertSame(QuestionState::Pending, $questions[0]->state);
            $this->assertSame($conversation->id, $questions[0]->pending_conversation_id);

            $refused = null;
            foreach ((array) ($concurrent->tool_data['tool_results'] ?? []) as $result) {
                if (($result['tool_call_id'] ?? null) === 'call_concurrent') {
                    $refused = json_decode((string) $result['content'], true);
                }
            }
            $this->assertSame(
                ['outcome' => 'refused', 'code' => 'question_already_pending', 'reason' => "A question is already waiting for the user's answer in this conversation. Wait for it to be resolved before asking another."],
                $refused,
                "trial {$trial}: the second ask is refused because one is pending",
            );
            $this->assertSame(
                [AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES],
                $this->web->textsFor($conversation->id),
                "trial {$trial}: the user sees one question",
            );
        }
    }
}
