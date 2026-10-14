<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\QuestionAnswerIntake;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\RunResumer;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\ServesHelpers;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;
use Tests\Support\EffectLedger;
use Tests\Support\InjectedFault;
use Tests\Support\ScriptedProvider;
use Tests\Support\SnapshotFaultInjector;
use Tests\Support\TravelsPastLease;

/**
 * The synchronous path, killed at a fault point, leaves the checkpoint in a
 * resumable (or outcome-unknown) state (research D21). The
 * SnapshotFaultInjector stands in for a SIGKILLed worker: it snapshots the
 * ledger tables at the armed point and throws; the test restores them, so
 * the database is exactly what the kill left it.
 *
 * The contract pinned here, at each sync site, is the exactly-once state
 * across the kill boundary:
 *   - killed at `before_tool_started` — the start marker (C3) is not yet
 *     written, so the call is `not_started` and the effect has not fired;
 *   - killed at `after_tool_effect` — the effect has fired but the result
 *     is not yet recorded (C4), so the call is `started` with no result and
 *     the effect fired once (the `killed_after_effect_before_record` state,
 *     which quickstart M1 — recording the result before the effect — turns
 *     red);
 *   - killed at `after_tool_recorded` — the result is recorded, so the call
 *     is `completed` with its result and the effect fired once.
 *
 * The sites: T1 the general tool loop, T2 a delegate batch, T3 an unattended
 * execute_operation whose transport fails transiently (the whole retry loop
 * is one call, so C3 and C4 are each written once), T4 a continueSyncLoop()
 * continuation after a question answer, and T6 a resumeSync() approved call.
 */
class ResumeFaultInjectionSyncTest extends AssembledSystemTestCase
{
    use ServesHelpers;
    use TaskBudgetHelperJourneys;
    use TravelsPastLease;

    private const USAGE = [10, 10];

    private EffectLedger $effectLedger;

    private ScriptedProvider $provider;

    private SnapshotFaultInjector $injector;

    /** Bind the executor with a fixed token, seed the ledger operations, bind the provider's handler stack (so its failure middleware is live), and bind the snapshot injector. */
    private function setupJourney(): void
    {
        $this->effectLedger = new EffectLedger();
        $this->app->singleton(
            McpToolExecutor::class,
            fn () => new McpToolExecutor($this->app->make(McpToolRegistry::class), null, fn ($user) => 'test-token-'.$user->id),
        );
        $this->app->instance(Generator::class, new class extends Generator
        {
            public function __construct()
            {
            }

            public function __invoke(?GeneratorConfig $config = null): array
            {
                return ['paths' => []];
            }
        });
        $this->provider = new ScriptedProvider($this->transport, $this->stream);
        $this->provider->seedLedgerOperations($this->effectLedger);
        // Bind the provider's stack (the transport's stack plus its failure
        // middleware) so scripted transient failures take effect; with no
        // failure armed the middleware is a pass-through.
        $this->app->bind('llm-client.http_handler', fn () => $this->provider->handlerStack());

        $this->bindInjector();
    }

    /** Bind the snapshot fault injector (the dead-worker stand-in). */
    private function bindInjector(): void
    {
        $this->injector = new SnapshotFaultInjector();
        $this->app->instance(\ClarionApp\LlmClient\Contracts\FaultInjector::class, $this->injector);
    }

    private function syncConversation(): Conversation
    {
        return $this->fixture()->build()->conversation;
    }

    /** @return object the run row for the conversation's single run */
    private function runRowOf(Conversation $conversation): object
    {
        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderByDesc('started_at')->first();
        $this->assertNotNull($row, 'the run exists');

        return $row;
    }

    /** The call's entry in the step's checkpoint (the step at $position). */
    private function callAt(object $run, int $position, string $callId): ?array
    {
        $step = DB::table('agent_run_steps')->where('run_id', $run->id)->where('position', $position)->first();
        $this->assertNotNull($step, "step {$position} exists");
        $checkpoint = json_decode((string) $step->checkpoint, true);

        return $checkpoint['calls'][$callId] ?? null;
    }

    /**
     * Drive the run, catching the injected fault. Returns true when the
     * fault fired (and the tables are restored); fails the test when the run
     * errored before the fault point, or when the fault never fired (the
     * funnel is not in place — the red reason).
     */
    private function killAt(string $point, int $occurrence, callable $drive): void
    {
        // The kill interrupts the run before it can consume its remaining
        // scripted steps, so a leftover is expected — suppress the check.
        $this->expectInterruptedRun = true;
        $this->injector->arm($point, $occurrence, ['agent_runs', 'agent_run_steps']);
        $thrown = null;
        try {
            $drive();
        } catch (InjectedFault $e) {
            $thrown = $e;
            $this->injector->restore();
        } catch (\Throwable $e) {
            $this->fail("the run failed before the fault point ({$point}): {$e->getMessage()}");
        }
        $this->assertNotNull($thrown, "the fault at {$point} (occurrence {$occurrence}) was never reached — the funnel is not in place");
    }

    /**
     * The resume half: expire the run's lease (travel window + 1 s), run the
     * interruption sweep, and run the job the sweep pushed. The resumer
     * re-drives the loop through the bound handler, so the disposition
     * executes and the ledger count is observable.
     */
    private function resumeRun(Conversation $conversation): object
    {
        $this->travelPastLease($conversation);

        Queue::fake([AgentStreamStepJob::class, ResumeInterruptedRunJob::class]);
        $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs'), 'the sweep ran');

        $captured = null;
        Queue::assertPushed(ResumeInterruptedRunJob::class, function ($job) use (&$captured) {
            $captured = $job;
            return true;
        });
        $this->assertNotNull($captured, 'the sweep pushed a resume job');

        $captured->handle(app(RunResumer::class));

        return $this->runRowOf($conversation);
    }

    /** @return Message|null the task_resume_notice of $noticeType for the conversation */
    private function resumeNotice(Conversation $conversation, string $noticeType): ?Message
    {
        return Message::where('conversation_id', $conversation->id)->get()->first(
            fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'task_resume_notice'
                && ($m->tool_data['notice'] ?? null) === $noticeType
        );
    }

    /** The tool result for $callId in the conversation's step messages. */
    private function toolResult(Conversation $conversation, string $callId): ?array
    {
        $message = Message::where('conversation_id', $conversation->id)->get()->first(
            function (Message $m) use ($callId) {
                $results = $m->tool_data['tool_results'] ?? [];
                return is_array($results) && collect($results)->contains(fn ($r) => ($r['tool_call_id'] ?? null) === $callId);
            }
        );
        if ($message === null) {
            return null;
        }
        $results = $message->tool_data['tool_results'] ?? [];

        return collect($results)->first(fn ($r) => ($r['tool_call_id'] ?? null) === $callId);
    }

    // ---------------------------------------------------------------
    // T1 — the general tool loop (ledger.write)
    // ---------------------------------------------------------------

    private function scriptWriteThenReply(): void
    {
        $this->script()
            ->toolRequests([['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']]])
            ->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);
    }

    #[Test]
    public function a_killed_general_tool_call_is_not_started_and_the_effect_never_fired(): void
    {
        $this->scenario = 'fault_sync_t1_before_started';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->scriptWriteThenReply();

        $this->killAt('before_tool_started', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.'));

        $run = $this->runRowOf($conversation);
        $call = $this->callAt($run, 1, 'call_write');
        $this->assertSame('not_started', $call['state'] ?? null, 'killed before the start marker: the call is not_started');
        $this->assertArrayNotHasKey('result', $call, 'no result is recorded');
        $this->assertSame(0, $this->effectLedger->count('ledger.write'), 'the effect never fired');
        $this->assertSame('in_progress', $run->end_state, 'the run is left in progress, not closed as failed');
    }

    #[Test]
    public function a_killed_general_tool_call_after_the_effect_is_started_without_result(): void
    {
        $this->scenario = 'fault_sync_t1_after_effect';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->scriptWriteThenReply();

        $this->killAt('after_tool_effect', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.'));

        $run = $this->runRowOf($conversation);
        $call = $this->callAt($run, 1, 'call_write');
        $this->assertSame('started', $call['state'] ?? null, 'killed after the effect: the call is started');
        $this->assertArrayNotHasKey('result', $call, 'the result is not yet recorded (C4 not written)');
        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the effect fired exactly once');
        $this->assertSame('in_progress', $run->end_state, 'the run is left in progress, not closed as failed');
    }

    #[Test]
    public function a_killed_general_tool_call_after_the_record_is_completed(): void
    {
        $this->scenario = 'fault_sync_t1_after_recorded';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->scriptWriteThenReply();

        $this->killAt('after_tool_recorded', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.'));

        $run = $this->runRowOf($conversation);
        $call = $this->callAt($run, 1, 'call_write');
        $this->assertSame('completed', $call['state'] ?? null, 'killed after the record: the call is completed');
        $this->assertArrayHasKey('result', $call, 'the result is recorded (C4 written)');
        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the effect fired exactly once');
        $this->assertSame('in_progress', $run->end_state, 'the run is left in progress, not closed as failed');
    }

    // ---------------------------------------------------------------
    // T2 — a 3-call delegate_to_helper batch
    // ---------------------------------------------------------------

    #[Test]
    public function a_killed_delegate_batch_has_every_call_started_before_the_batch_is_entered(): void
    {
        $this->scenario = 'fault_sync_t2_batch';
        $this->setUpHelperJourney();
        $this->bindInjector();
        $this->priceModel();
        $this->fixtureWithModel();
        $root = $this->agent('root');
        $a = $this->agent('a');
        $b = $this->agent('b');
        $c = $this->agent('c');
        $this->assignHelper($root, $a);
        $this->assignHelper($root, $b);
        $this->assignHelper($root, $c);

        $conversation = $this->rootConversation('sync', $root);
        $this->script()->toolRequests([
            ['id' => 'call_to_a', 'name' => 'delegate_to_helper', 'arguments' => ['helper_agent_id' => $a->id, 'task' => 'Part one.']],
            ['id' => 'call_to_b', 'name' => 'delegate_to_helper', 'arguments' => ['helper_agent_id' => $b->id, 'task' => 'Part two.']],
            ['id' => 'call_to_c', 'name' => 'delegate_to_helper', 'arguments' => ['helper_agent_id' => $c->id, 'task' => 'Part three.']],
        ])->usage(...self::USAGE);
        $this->scriptHelperAnswer('Part one done.', self::USAGE);
        $this->scriptHelperAnswer('Part two done.', self::USAGE);
        $this->scriptHelperAnswer('Part three done.', self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        // The kill lands inside delegateBatch() (a delegation row has just
        // been created). By this point the funnel's start half must have
        // marked every batch call `started` (it runs before delegateBatch()
        // is entered), so all three are `started` in the snapshot. Driven
        // through run() (not the HTTP endpoint, whose blanket catch would
        // swallow the simulated kill into a 500).
        $this->killAt('after_delegation_row', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Do all three parts.'));

        $run = $this->runRowOf($conversation);
        foreach (['call_to_a', 'call_to_b', 'call_to_c'] as $callId) {
            $call = $this->callAt($run, 1, $callId);
            $this->assertSame('started', $call['state'] ?? null, "killed inside delegateBatch(): {$callId} is started (the start half ran before the batch was entered)");
        }
        $this->tearDownTaskBudgetJourney();
    }

    // ---------------------------------------------------------------
    // T3 — an unattended execute_operation whose transport fails
    // transiently twice: the whole retry loop is one call
    // ---------------------------------------------------------------

    #[Test]
    public function a_retried_unattended_execute_operation_writes_C3_and_C4_once(): void
    {
        $this->scenario = 'fault_sync_t3_retry';
        $this->setupJourney();
        config(['llm-client.agent_loop.max_iterations' => 4]);
        $conversation = $this->syncConversation();

        // The journey's Generator mock returns an empty paths list, which
        // hides the seeded ledger operations from ApiManager::getOperations()
        // (the agent-definition parser's catalog source). Re-bind it to the
        // seeded doc so a definition permitting ledger.write resolves.
        $this->app->instance(\Dedoc\Scramble\Generator::class, new class extends \Dedoc\Scramble\Generator
        {
            public function __construct()
            {
            }

            public function __invoke(?\Dedoc\Scramble\GeneratorConfig $config = null): array
            {
                return [
                    'paths' => [
                        '/ledger/write' => ['post' => ['operationId' => 'ledger.write', 'summary' => 'Write to the ledger (effectful)']],
                        '/ledger/read' => ['get' => ['operationId' => 'ledger.read', 'summary' => 'Read from the ledger (effect-free)']],
                    ],
                ];
            }
        });

        // An unattended run refuses to start without a resolvable agent
        // definition bound to the conversation, so bind one — permitting
        // the ledger.write operation the run will attempt.
        $agent = app(\ClarionApp\LlmClient\Services\AgentService::class)->create(
            $this->fixture()->user->id,
            "name: root\ninstructions: I am root.\ntools:\n  allow:\n    - ledger.write\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n",
        );
        $conversation->update(['agent_id' => $agent->id, 'agent_version_id' => $agent->current_version_id]);
        $conversation = $conversation->fresh();

        // The transport fails transiently twice, then succeeds: the retry
        // loop is one call, so C3 is written once (before the first attempt)
        // and C4 once (after the final outcome).
        $this->script()
            ->toolRequests([['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']]])
            ->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        // Make the ledger.write host API fail transiently (503) twice, then
        // succeed: the execute_operation's transport is what the retry loop
        // (dispatchExecuteOperationWithRetry) retries, so the whole loop is
        // bracketed as one call.
        $attempts = 0;
        (new OperationCatalogue())->fakeHostApi([
            '/ledger/write' => function () use (&$attempts) {
                $attempts++;
                if ($attempts <= 2) {
                    return \Illuminate\Support\Facades\Http::response(['error' => 'Service unavailable (scripted)'], 503, ['Content-Type' => 'application/json']);
                }
                $this->effectLedger->record('ledger.write');

                return \Illuminate\Support\Facades\Http::response(['ok' => true, 'operation' => 'ledger.write'], 200, ['Content-Type' => 'application/json']);
            },
            '*' => \Illuminate\Support\Facades\Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->killAt('after_tool_recorded', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.', ['unattended' => true, 'retry_limit' => 2]));

        $run = $this->runRowOf($conversation);
        $call = $this->callAt($run, 1, 'call_write');
        $this->assertSame('completed', $call['state'] ?? null, 'the retried call is completed');
        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the effect fired exactly once across the retries (C3 and C4 each written once)');
    }

    // ---------------------------------------------------------------
    // T4 — a continueSyncLoop() continuation after a question answer
    // ---------------------------------------------------------------

    #[Test]
    public function a_killed_continuation_after_a_question_answer_is_not_started(): void
    {
        $this->scenario = 'fault_sync_t4_continue';
        $this->setupJourney();
        config(['llm-client.agent_loop.max_iterations' => 4]);
        $conversation = $this->syncConversation();

        // The run pauses at an ask_user question; the continuation (after the
        // answer) makes the ledger.write call, which is where the kill lands.
        $ask = ['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => ['question' => 'Which notes?', 'choices' => ['New', 'Old']]];
        $this->script()->toolRequests([$ask])->usage(...self::USAGE);
        $this->script()->toolRequests([['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']]])->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        $this->send('sync', $conversation, 'Ask me, then write.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $question = \ClarionApp\LlmClient\Models\AgentQuestion::where('conversation_id', $conversation->id)->firstOrFail();

        $this->killAt('before_tool_started', 1, function () use ($conversation, $question) {
            app(QuestionAnswerIntake::class)->accept($conversation->fresh(), (string) $this->fixture()->user->id, '1');
            $this->app->forgetScopedInstances();

            // Drive the synchronous continuation directly (the loop path the
            // run paused on) rather than through QuestionResumer, whose own
            // catch would close the run as failed and log an error the
            // simulated kill (a SIGKILL runs no catch) must not leave behind.
            // The one-time claim (a fresh dispatch id + the lease) is the
            // resumer's own pre-work, replicated here so the continuation's
            // checkpoint writes run under a held lease.
            if ($question->run_id !== null) {
                $dispatch = app(\ClarionApp\LlmClient\Services\RunLease::class)->mintDispatch($question->run_id, ['paused']);
                if ($dispatch !== null) {
                    app(\ClarionApp\LlmClient\Services\RunLease::class)->claim($question->run_id, $dispatch);
                }
            }
            $paused = \ClarionApp\LlmClient\Models\Message::find($question->paused_message_id);
            $selected = array_values($question->answer_selected ?? []);
            $resolution = new \ClarionApp\LlmClient\ValueObjects\QuestionResolution(
                \ClarionApp\LlmClient\ValueObjects\QuestionState::Answered,
                new \ClarionApp\LlmClient\ValueObjects\ParsedAnswer((string) $question->answer_raw, $selected !== [], $selected),
            );
            $this->app->make(AgentLoopService::class)->resumeQuestionSync($conversation->fresh(), $paused, $resolution);
        });

        $run = $this->runRowOf($conversation);
        // The continuation's step is the one that holds the write call.
        $writeStep = DB::table('agent_run_steps')->where('run_id', $run->id)->orderByDesc('position')->first();
        $checkpoint = json_decode((string) $writeStep->checkpoint, true);
        $call = $checkpoint['calls']['call_write'] ?? null;
        $this->assertSame('not_started', $call['state'] ?? null, 'killed in the continuation before the start marker: the write call is not_started');
        $this->assertSame(0, $this->effectLedger->count('ledger.write'), 'the effect never fired');
    }

    // ---------------------------------------------------------------
    // T6 — a resumeSync() approved call
    // ---------------------------------------------------------------

    #[Test]
    public function a_killed_approved_resume_call_is_not_started(): void
    {
        $this->scenario = 'fault_sync_t6_resume_approved';
        $this->setupJourney();
        config(['llm-client.agent_loop.max_iterations' => 4]);
        $conversation = $this->syncConversation();

        // A confirmation pause: the operation needs the user's approval. The
        // approval resumes the run (resumeSync), which executes the approved
        // call — where the kill lands.
        $document = self::taskBudgetHostApiDocument();
        $document['paths']['/notes/stale'] = [
            'delete' => ['operationId' => 'clearStaleNotes', 'summary' => 'Delete every stale note', 'parameters' => [], 'responses' => ['200' => ['description' => 'Deleted']]],
        ];
        (new OperationCatalogue())->seed($document);

        $clear = ['id' => 'call_clear', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'clearStaleNotes', 'parameters' => []]];
        $this->script()->toolRequests([$clear])->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        $this->send('sync', $conversation, 'Clear the stale notes.')->assertStatus(202);

        $paused = \ClarionApp\LlmClient\Models\Message::where('conversation_id', $conversation->id)->get()->first(
            fn (\ClarionApp\LlmClient\Models\Message $m) => is_array($m->tool_data['pending_confirmation'] ?? null)
        );
        $this->assertNotNull($paused, 'precondition: paused for the user\'s confirmation');

        $this->killAt('before_tool_started', 1, function () use ($conversation, $paused) {
            app(AgentLoopService::class)->resume(Conversation::findOrFail($conversation->id), $paused, true);
        });

        $run = $this->runRowOf($conversation);
        $writeStep = DB::table('agent_run_steps')->where('run_id', $run->id)->orderByDesc('position')->first();
        $checkpoint = json_decode((string) $writeStep->checkpoint, true);
        $call = $checkpoint['calls']['call_clear'] ?? null;
        $this->assertSame('not_started', $call['state'] ?? null, 'killed in the approved resume before the start marker: the call is not_started');
    }

    // ---------------------------------------------------------------
    // Phase 4: resume half
    // ---------------------------------------------------------------
    // The kill half above pins the checkpoint state at each fault point. The
    // resume half drives the run across the kill boundary — expire the lease,
    // run the sweep, run the pushed job — and asserts the exactly-once ledger
    // count and the disposition (reuse / execute / rerun / outcome-unknown)
    // the resume applies.

    /** A single effect-free (ledger.read) call, then the final answer. */
    private function scriptReadThenReply(): void
    {
        $this->script()
            ->toolRequests([['id' => 'call_read', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.read']]])
            ->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);
    }

    /** Two calls (an effectful write and an effect-free read), then the answer. */
    private function scriptTwoCallsThenReply(): void
    {
        $this->script()
            ->toolRequests([
                ['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']],
                ['id' => 'call_read', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.read']],
            ])
            ->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);
    }

    /** Three effectful calls with distinct arguments, then the answer. */
    private function scriptThreeWritesThenReply(): void
    {
        $this->script()
            ->toolRequests([
                ['id' => 'call_w1', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write', 'parameters' => ['note' => 'one']]],
                ['id' => 'call_w2', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write', 'parameters' => ['note' => 'two']]],
                ['id' => 'call_w3', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write', 'parameters' => ['note' => 'three']]],
            ])
            ->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);
    }

    #[Test]
    public function killed_before_tool_started_resumes_and_executes_once(): void
    {
        $this->scenario = 'resume_sync_before_started';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->scriptWriteThenReply();

        $this->killAt('before_tool_started', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.'));

        $run = $this->resumeRun($conversation);

        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the not_started call executes exactly once on resume');
        $this->assertSame('completed', $run->end_state, 'the resumed run completes');
    }

    #[Test]
    public function killed_after_effect_on_effectful_is_outcome_unknown_not_rerun(): void
    {
        $this->scenario = 'resume_sync_after_effect_effectful';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->scriptWriteThenReply();

        $this->killAt('after_tool_effect', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.'));

        $this->resumeRun($conversation);

        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the effectful call is not re-run (the effect fired once, before the kill)');

        $result = $this->toolResult($conversation, 'call_write');
        $this->assertNotNull($result, 'the step message holds the call result');
        $this->assertTrue((bool) ($result['outcome_unknown'] ?? false), 'the result is marked outcome_unknown');
        $this->assertStringContainsStringIgnoringCase('interrupted', (string) ($result['content'] ?? ''), 'the result says the action was interrupted');

        $notice = $this->resumeNotice($conversation, 'outcome_unknown');
        $this->assertNotNull($notice, 'a task_resume_notice (outcome_unknown) is posted');
        $this->assertStringContainsString('Write to the ledger (effectful)', (string) $notice->content, 'the notice names the action label');
    }

    #[Test]
    public function killed_after_effect_on_effect_free_is_rerun(): void
    {
        $this->scenario = 'resume_sync_after_effect_free';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->scriptReadThenReply();

        $this->killAt('after_tool_effect', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Read from the ledger.'));

        $this->resumeRun($conversation);

        $this->assertSame(2, $this->effectLedger->count('ledger.read'), 'the effect-free call is re-run on resume');
    }

    #[Test]
    public function killed_after_recorded_is_reused(): void
    {
        $this->scenario = 'resume_sync_after_recorded';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->scriptWriteThenReply();

        $this->killAt('after_tool_recorded', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.'));

        $this->resumeRun($conversation);

        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the completed call is reused, not re-run');
    }

    #[Test]
    public function killed_after_model_recorded_executes_every_call_once(): void
    {
        $this->scenario = 'resume_sync_after_model_recorded';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->scriptTwoCallsThenReply();

        $this->killAt('after_model_recorded', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write and read the ledger.'));

        $this->resumeRun($conversation);

        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the write call executes once');
        $this->assertSame(1, $this->effectLedger->count('ledger.read'), 'the read call executes once');
    }

    #[Test]
    public function killed_before_step_message_writes_the_message_once(): void
    {
        $this->scenario = 'resume_sync_before_step_message';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->scriptWriteThenReply();

        $this->killAt('before_step_message', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.'));

        $this->resumeRun($conversation);

        // The committed step's message (the one that holds tool_results) is
        // written exactly once: no duplicate. The task_resume_notice and the
        // final answer are separate assistant rows and do not count.
        $stepMessages = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->get()
            ->filter(fn (Message $m) => !empty($m->tool_data['tool_results']));
        $this->assertCount(1, $stepMessages, 'the step message is written once, with no duplicate');
    }

    #[Test]
    public function killed_after_step_message_closes_the_step_and_continues(): void
    {
        $this->scenario = 'resume_sync_after_step_message';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->scriptWriteThenReply();

        $this->killAt('after_step_message', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.'));

        $run = $this->resumeRun($conversation);

        $step = DB::table('agent_run_steps')->where('run_id', $run->id)->orderByDesc('position')->first();
        $this->assertSame('completed', $step->end_state, 'the step is closed');
        $this->assertSame('completed', $run->end_state, 'the run continues to completion');
        $this->assertCount(
            1,
            Message::where('conversation_id', $conversation->id)->where('role', 'assistant')->get()
                ->filter(fn (Message $m) => !empty($m->tool_data['tool_results'])),
            'the step message already written is not written again',
        );
        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the step\'s write is not repeated');
    }

    // T2 — a killed delegate batch: the checkpoint-state half is above; the
    // resume half is here. The batch's members are driven by their parent's
    // resume, which rejoins them.

    #[Test]
    public function a_killed_delegate_batch_is_rejoined_by_the_resume_without_new_delegations(): void
    {
        $this->scenario = 'resume_sync_t2_batch_rejoin';
        $this->setUpHelperJourney();
        $this->bindInjector();
        $this->priceModel();
        $this->fixtureWithModel();
        $root = $this->agent('root');
        $members = [];
        foreach (['a' => 'Part one.', 'b' => 'Part two.', 'c' => 'Part three.'] as $key => $task) {
            $agent = $this->agent($key);
            $this->assignHelper($root, $agent);
            $this->serveHelperTask($task, strtoupper($key).' note', strtoupper($key).' done.');
            $members[$key] = ['agent' => $agent, 'task' => $task, 'call' => "call_to_{$key}"];
        }
        $conversation = $this->rootConversation('sync', $root);
        $this->script()->toolRequests(array_map(
            fn (array $m) => $this->delegateCall($m['call'], $m['agent'], $m['task']),
            array_values($members),
        ))->usage(...self::USAGE);

        // The parent dies with all three delegation rows made and no member
        // started (the rows are created before any job is dispatched).
        $this->killAt('after_delegation_row', 3, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Do all three parts.'));

        $before = $this->delegationsOf($conversation);
        $this->assertCount(3, $before, 'precondition: every row was made');
        foreach ($before as $row) {
            $this->assertSame('queued', $row->status, 'precondition: no member started');
        }
        $this->assertSame([], $this->storedNotes());
        $this->discardUnconsumedScript();
        $this->script()->finalAnswer('All three parts are done.')->usage(...self::USAGE);

        $this->resumeRun($conversation);

        $after = $this->delegationsOf($conversation);
        $this->assertSame(array_map(fn ($d) => $d->id, $before), array_map(fn ($d) => $d->id, $after), 'the resume made no new delegation: it rejoined the three it found');
        foreach ($members as $key => $member) {
            $row = Delegation::where('parent_conversation_id', $conversation->id)->where('helper_agent_id', $member['agent']->id)->firstOrFail();
            $this->assertSame('completed', $row->status, "member {$key} ran");
            $this->assertSame(strtoupper($key).' done.', $row->result_summary);
            $this->assertSame(1, $this->resultCountFor($conversation, $member['call']), "member {$key}'s result reached the parent once");
        }
        $notes = $this->storedNotes();
        sort($notes);
        $this->assertSame(['A note', 'B note', 'C note'], $notes, 'each member ran once');
        $this->assertSame('completed', $this->runRowOf($conversation)->end_state);
        $this->tearDownTaskBudgetJourney();
    }

    #[Test]
    public function killed_approved_call_after_effect_is_outcome_unknown_not_rerun(): void
    {
        $this->scenario = 'resume_sync_t6_approved_after_effect';
        $this->setupJourney();
        config(['llm-client.agent_loop.max_iterations' => 4]);
        $conversation = $this->syncConversation();

        // A confirmation pause: the deletion needs the user's approval.
        $document = self::taskBudgetHostApiDocument();
        $document['paths']['/notes/stale'] = [
            'delete' => ['operationId' => 'clearStaleNotes', 'summary' => 'Delete every stale note', 'parameters' => [], 'responses' => ['200' => ['description' => 'Deleted']]],
        ];
        (new OperationCatalogue())->seed($document);

        // The deletion's effect goes through the host API; record it.
        (new OperationCatalogue())->fakeHostApi([
            '/notes/stale' => function () {
                $this->effectLedger->record('clearStaleNotes');

                return \Illuminate\Support\Facades\Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']);
            },
            '*' => \Illuminate\Support\Facades\Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $clear = ['id' => 'call_clear', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'clearStaleNotes', 'parameters' => []]];
        $this->script()->toolRequests([$clear])->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        $this->send('sync', $conversation, 'Clear the stale notes.')->assertStatus(202);
        $paused = Message::where('conversation_id', $conversation->id)->get()->first(
            fn (Message $m) => is_array($m->tool_data['pending_confirmation'] ?? null)
        );
        $this->assertNotNull($paused, "precondition: paused for the user's confirmation");

        // Approve (the continuation executes the approved call — where the
        // kill lands, after the effect has fired).
        $this->killAt('after_tool_effect', 1, function () use ($conversation, $paused) {
            app(AgentLoopService::class)->resume(Conversation::findOrFail($conversation->id), $paused, true);
        });

        $this->resumeRun($conversation);

        $this->assertSame(1, $this->effectLedger->count('clearStaleNotes'), 'the approved call is not re-run despite the approval (FR-011)');

        $result = $this->toolResult($conversation, 'call_clear');
        $this->assertNotNull($result, 'the step message holds the call result');
        $this->assertTrue((bool) ($result['outcome_unknown'] ?? false), 'the approved call is outcome_unknown, not re-executed');
    }

    #[Test]
    public function a_step_with_two_completed_calls_executes_only_the_third(): void
    {
        $this->scenario = 'resume_sync_us2_ac1';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->scriptThreeWritesThenReply();

        // Kill after the second call is recorded: calls one and two are
        // completed, the third is not_started.
        $this->killAt('after_tool_recorded', 2, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write three notes.'));

        $this->resumeRun($conversation);

        // One and two are reused (their effects fired pre-kill); only the
        // third executes on resume. Three distinct argument hashes, one each.
        $this->assertSame(3, $this->effectLedger->count('ledger.write'), 'only the third call executes on resume (one and two are reused)');
    }

    #[Test]
    public function an_external_mcp_tool_killed_after_effect_is_outcome_unknown_not_rerun(): void
    {
        $this->scenario = 'resume_sync_m3_external_tool';
        $this->setupJourney();
        config(['llm-client.agent_loop.max_iterations' => 4]);
        $conversation = $this->syncConversation();

        // An external mcp: tool whose cached readOnlyHint says true — the
        // classifier does not trust the hint (quickstart M3). The tool's
        // server_id must name the server's own id (its belongsTo lookup),
        // so the server is created first and its id read back.
        $server = \ClarionApp\LlmClient\Models\McpClientServer::create([
            'name' => 'external',
            'transport' => 'http',
            'url' => 'https://mcp.example',
            'user_id' => $this->fixture()->user->id,
        ]);
        $serverId = (string) $server->id;
        McpClientTool::create([
            'server_id' => $serverId,
            'synthetic_operation_id' => "mcp:{$serverId}:read_thing",
            'name' => 'read_thing',
            'description' => 'An external read.',
            'input_schema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true],
            'last_seen_at' => now(),
        ]);

        // The external tool's effect goes through the host API; record it.
        (new OperationCatalogue())->fakeHostApi([
            '/mcp-client/' => function () {
                $this->effectLedger->record('mcp:read_thing');

                return \Illuminate\Support\Facades\Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']);
            },
            '*' => \Illuminate\Support\Facades\Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $mcp = ['id' => 'call_mcp', 'name' => 'execute_operation', 'arguments' => ['operationId' => "mcp:{$serverId}:read_thing"]];
        $this->script()->toolRequests([$mcp])->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        // The external tool's own confirmation would pause the run before
        // its effect fires; allow it so the effect runs and the kill can
        // land after it (the test is about the effect class, not the
        // confirmation).
        $this->app->instance(\ClarionApp\LlmClient\Services\McpClientCallValidator::class, new class
        {
            public function validate(string $operationId, string $method, string $path): array
            {
                return ['status' => \ClarionApp\LlmClient\Services\ApiCallValidator::STATUS_ALLOW, 'reason' => 'test-allowed'];
            }
        });

        $this->killAt('after_tool_effect', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Use the external read tool.'));

        $this->resumeRun($conversation);

        $this->assertSame(1, $this->effectLedger->count('mcp:read_thing'), 'the external tool is not re-run (its readOnlyHint is untrusted, so it is effectful)');

        $result = $this->toolResult($conversation, 'call_mcp');
        $this->assertNotNull($result, 'the step message holds the call result');
        $this->assertTrue((bool) ($result['outcome_unknown'] ?? false), 'the external tool is outcome_unknown, not re-executed');
    }

    #[Test]
    public function a_not_started_confirmation_call_pauses_again_on_resume(): void
    {
        $this->scenario = 'resume_sync_fr012_confirmation';
        $this->setupJourney();
        config(['llm-client.agent_loop.max_iterations' => 4]);
        $conversation = $this->syncConversation();

        $document = self::taskBudgetHostApiDocument();
        $document['paths']['/notes/stale'] = [
            'delete' => ['operationId' => 'clearStaleNotes', 'summary' => 'Delete every stale note', 'parameters' => [], 'responses' => ['200' => ['description' => 'Deleted']]],
        ];
        (new OperationCatalogue())->seed($document);

        $clear = ['id' => 'call_clear', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'clearStaleNotes', 'parameters' => []]];
        $this->script()->toolRequests([$clear])->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        $this->send('sync', $conversation, 'Clear the stale notes.')->assertStatus(202);
        $paused = Message::where('conversation_id', $conversation->id)->get()->first(
            fn (Message $m) => is_array($m->tool_data['pending_confirmation'] ?? null)
        );
        $this->assertNotNull($paused, "precondition: paused for the user's confirmation");

        // Kill before the start marker: the confirmation-requiring call is
        // not_started. On resume it must pause for confirmation again, not
        // execute.
        $this->killAt('before_tool_started', 1, function () use ($conversation, $paused) {
            app(AgentLoopService::class)->resume(Conversation::findOrFail($conversation->id), $paused, true);
        });

        $run = $this->resumeRun($conversation);

        $this->assertSame('paused', $run->resume_state, 'the not_started confirmation call pauses for confirmation again on resume');
    }

    #[Test]
    public function a_not_started_policy_refused_call_is_refused_again(): void
    {
        $this->scenario = 'resume_sync_fr012_policy_refused';
        $this->setupJourney();
        config(['llm-client.agent_loop.max_iterations' => 4]);
        $conversation = $this->syncConversation();

        // The journey's Generator mock returns an empty paths list, which
        // hides the ledger operations from the definition parser. Re-bind it
        // to a doc that resolves both, so the definition (allowing
        // ledger.write) resolves and the agent can call ledger.read.
        $this->app->instance(\Dedoc\Scramble\Generator::class, new class extends \Dedoc\Scramble\Generator
        {
            public function __construct()
            {
            }

            public function __invoke(?\Dedoc\Scramble\GeneratorConfig $config = null): array
            {
                return [
                    'paths' => [
                        '/ledger/write' => ['post' => ['operationId' => 'ledger.write', 'summary' => 'Write to the ledger (effectful)']],
                        '/ledger/read' => ['get' => ['operationId' => 'ledger.read', 'summary' => 'Read from the ledger (effect-free)']],
                    ],
                ];
            }
        });

        // A command-policy refusal: the agent definition denies the operation.
        $agent = app(\ClarionApp\LlmClient\Services\AgentService::class)->create(
            $this->fixture()->user->id,
            "name: root\ninstructions: I am root.\ntools:\n  allow:\n    - ledger.write\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n",
        );
        $conversation->update(['agent_id' => $agent->id, 'agent_version_id' => $agent->current_version_id]);
        $conversation = $conversation->fresh();

        // A call to an operation the definition does not allow: refused by
        // policy. Kill before the start marker (the call is not_started).
        $denied = ['id' => 'call_denied', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.read']];
        $this->script()->toolRequests([$denied])->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        $this->killAt('before_tool_started', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Read the ledger.'));

        $run = $this->resumeRun($conversation);

        // The refused call is refused again: no execution, the run does not
        // complete on the refused call.
        $this->assertSame(0, $this->effectLedger->count('ledger.read'), 'the policy-refused call is not executed on resume');
    }
}
