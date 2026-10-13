<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\TaskBudgetJourneys;
use Tests\Support\EffectLedger;
use Tests\Support\InjectedFault;
use Tests\Support\ScriptedProvider;
use Tests\Support\SnapshotFaultInjector;

/**
 * The streamed path, killed at a fault point, leaves the checkpoint in a
 * resumable (or outcome-unknown) state (research D21) — the same
 * exactly-once contract as the synchronous half, exercised on the stream
 * sites. The SnapshotFaultInjector stands in for a SIGKILLed worker.
 *
 * The contract, at each stream site, is the same three-point state across
 * the kill boundary:
 *   - killed at `before_tool_started` — the call is `not_started`, the
 *     effect has not fired;
 *   - killed at `after_tool_effect` — the call is `started` with no result
 *     and the effect fired once (the `killed_after_effect_before_record`
 *     state, which quickstart M1 turns red);
 *   - killed at `after_tool_recorded` — the call is `completed` with its
 *     result and the effect fired once.
 *
 * The sites: T7 a registry tool (an execute_operation) via
 * McpToolExecutor::executeTool in AgentLoopStreamHandler::handleToolCalls(),
 * T8 a meta tool via executeMetaTool, and T5 the approved call in
 * AgentLoopService::resume() (a confirmation pause), plus its
 * declarative-memory approval branch.
 */
class ResumeFaultInjectionStreamTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    private const USAGE = [10, 10];

    private EffectLedger $effectLedger;

    private ScriptedProvider $provider;

    private SnapshotFaultInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
        config(['llm-client.agent_loop.max_iterations' => 4]);
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** Bind the executor with a fixed token, seed the ledger operations, bind the provider's stack, and bind the snapshot injector. */
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
        $this->app->bind('llm-client.http_handler', fn () => $this->provider->handlerStack());

        $this->injector = new SnapshotFaultInjector();
        $this->app->instance(\ClarionApp\LlmClient\Contracts\FaultInjector::class, $this->injector);
    }

    /** @return object the run row for the conversation's single run */
    private function runRowOf(Conversation $conversation): object
    {
        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderByDesc('started_at')->first();
        $this->assertNotNull($row, 'the run exists');

        return $row;
    }

    /** The call's entry in the latest step's checkpoint. */
    private function latestCallAt(object $run, string $callId): ?array
    {
        $step = DB::table('agent_run_steps')->where('run_id', $run->id)->orderByDesc('position')->first();
        $this->assertNotNull($step, 'a step exists');
        $checkpoint = json_decode((string) $step->checkpoint, true);

        return $checkpoint['calls'][$callId] ?? null;
    }

    /**
     * Drive the stream, catching the injected fault. Fails the test when the
     * run errored before the fault point, or when the fault never fired (the
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
    // T7 — a registry tool (an execute_operation) via the stream handler
    // ---------------------------------------------------------------

    #[Test]
    public function a_killed_streamed_tool_call_is_not_started_and_the_effect_never_fired(): void
    {
        $this->scenario = 'fault_stream_t7_before_started';
        $this->setupJourney();
        $conversation = $this->conversationFor('stream');

        $this->send('stream', $conversation, 'Write to the ledger.')->assertStatus(201);

        $this->killAt('before_tool_started', 1, fn () => $this->playTurn(0, '', [
            ['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']],
        ], self::USAGE));

        $run = $this->runRowOf($conversation);
        $call = $this->latestCallAt($run, 'call_write');
        $this->assertSame('not_started', $call['state'] ?? null, 'killed before the start marker: the call is not_started');
        $this->assertArrayNotHasKey('result', $call, 'no result is recorded');
        $this->assertSame(0, $this->effectLedger->count('ledger.write'), 'the effect never fired');
    }

    #[Test]
    public function a_killed_streamed_tool_call_after_the_effect_is_started_without_result(): void
    {
        $this->scenario = 'fault_stream_t7_after_effect';
        $this->setupJourney();
        $conversation = $this->conversationFor('stream');

        $this->send('stream', $conversation, 'Write to the ledger.')->assertStatus(201);

        $this->killAt('after_tool_effect', 1, fn () => $this->playTurn(0, '', [
            ['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']],
        ], self::USAGE));

        $run = $this->runRowOf($conversation);
        $call = $this->latestCallAt($run, 'call_write');
        $this->assertSame('started', $call['state'] ?? null, 'killed after the effect: the call is started');
        $this->assertArrayNotHasKey('result', $call, 'the result is not yet recorded (C4 not written)');
        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the effect fired exactly once');
    }

    #[Test]
    public function a_killed_streamed_tool_call_after_the_record_is_completed(): void
    {
        $this->scenario = 'fault_stream_t7_after_recorded';
        $this->setupJourney();
        $conversation = $this->conversationFor('stream');

        $this->send('stream', $conversation, 'Write to the ledger.')->assertStatus(201);

        $this->killAt('after_tool_recorded', 1, fn () => $this->playTurn(0, '', [
            ['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']],
        ], self::USAGE));

        $run = $this->runRowOf($conversation);
        $call = $this->latestCallAt($run, 'call_write');
        $this->assertSame('completed', $call['state'] ?? null, 'killed after the record: the call is completed');
        $this->assertArrayHasKey('result', $call, 'the result is recorded (C4 written)');
        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the effect fired exactly once');
    }

    // ---------------------------------------------------------------
    // T8 — a meta tool via executeMetaTool
    // ---------------------------------------------------------------

    #[Test]
    public function a_killed_streamed_meta_tool_call_is_not_started(): void
    {
        $this->scenario = 'fault_stream_t8_meta';
        $this->setupJourney();
        $conversation = $this->conversationFor('stream');

        $this->send('stream', $conversation, 'Search the operations.')->assertStatus(201);

        $this->killAt('before_tool_started', 1, fn () => $this->playTurn(0, '', [
            ['id' => 'call_search', 'name' => 'search_operations', 'arguments' => ['query' => 'notes']],
        ], self::USAGE));

        $run = $this->runRowOf($conversation);
        $call = $this->latestCallAt($run, 'call_search');
        $this->assertSame('not_started', $call['state'] ?? null, 'killed before the start marker: the meta tool call is not_started');
    }

    // ---------------------------------------------------------------
    // T5 — the approved call in AgentLoopService::resume() (a
    // confirmation pause), plus its declarative-memory approval branch
    // ---------------------------------------------------------------

    #[Test]
    public function a_killed_approved_streamed_resume_call_is_not_started(): void
    {
        $this->scenario = 'fault_stream_t5_resume_approved';
        $this->setupJourney();

        // The host API offers a deletion, which needs the user's confirmation.
        $document = self::taskBudgetHostApiDocument();
        $document['paths']['/notes/stale'] = [
            'delete' => ['operationId' => 'clearStaleNotes', 'summary' => 'Delete every stale note', 'parameters' => [], 'responses' => ['200' => ['description' => 'Deleted']]],
        ];
        (new OperationCatalogue())->seed($document);

        $conversation = $this->conversationFor('stream');
        $clear = ['id' => 'call_clear', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'clearStaleNotes', 'parameters' => []]];

        $this->send('stream', $conversation, 'Clear the stale notes.')->assertStatus(201);
        $this->playTurn(0, '', [$clear], self::USAGE);

        $paused = Message::where('conversation_id', $conversation->id)->get()->first(
            fn (Message $m) => is_array($m->tool_data['pending_confirmation'] ?? null)
        );
        $this->assertNotNull($paused, 'precondition: paused for the user\'s confirmation');

        // Approve: the continuation (resume) executes the approved call — where
        // the kill lands.
        $this->killAt('before_tool_started', 1, function () use ($conversation, $paused) {
            app(AgentLoopService::class)->resume(Conversation::findOrFail($conversation->id), $paused, true);
        });

        $run = $this->runRowOf($conversation);
        $call = $this->latestCallAt($run, 'call_clear');
        $this->assertSame('not_started', $call['state'] ?? null, 'killed in the approved resume before the start marker: the call is not_started');
    }

    #[Test]
    public function a_killed_approved_declarative_memory_resume_call_is_not_started(): void
    {
        $this->scenario = 'fault_stream_t5_declarative_memory';
        $this->setupJourney();

        // A propose_declarative_memory call pauses for the user's
        // confirmation (confirmation_type = declarative_memory). Approving
        // it routes resume() to the declarative-memory approval branch —
        // where the kill lands.
        $conversation = $this->conversationFor('stream');
        $propose = ['id' => 'call_memory', 'name' => 'propose_declarative_memory', 'arguments' => ['type' => 'fact', 'content' => 'The user prefers concise answers.']];

        $this->send('stream', $conversation, 'Remember that I prefer concise answers.')->assertStatus(201);
        $this->playTurn(0, '', [$propose], self::USAGE);

        $paused = Message::where('conversation_id', $conversation->id)->get()->first(
            fn (Message $m) => is_array($m->tool_data['pending_confirmation'] ?? null)
                && ($m->tool_data['pending_confirmation']['confirmation_type'] ?? null) === 'declarative_memory'
        );
        $this->assertNotNull($paused, "precondition: paused for the user's declarative-memory confirmation");

        // Approve: the continuation (resume) enters the declarative-memory
        // approval branch — where the kill lands.
        $this->killAt('before_tool_started', 1, function () use ($conversation, $paused) {
            app(AgentLoopService::class)->resume(Conversation::findOrFail($conversation->id), $paused, true);
        });

        $run = $this->runRowOf($conversation);
        $call = $this->latestCallAt($run, 'call_memory');
        $this->assertSame('not_started', $call['state'] ?? null, 'killed in the approved declarative-memory resume before the start marker: the call is not_started');
    }
}
