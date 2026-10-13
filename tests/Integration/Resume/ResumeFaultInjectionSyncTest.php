<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\QuestionAnswerIntake;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;
use Tests\Support\EffectLedger;
use Tests\Support\InjectedFault;
use Tests\Support\ScriptedProvider;
use Tests\Support\SnapshotFaultInjector;

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
    use TaskBudgetHelperJourneys;

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
    // The resume planner's own fault points (after_claim, during_join_wait,
    // the takeover and the provider-retry re-drive) are exercised in Phase 4,
    // where the resume path is implemented. The checkpoint-state half pinned
    // above (T1–T6) is what this file owns.
}
