<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\CapabilityOfferingService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 109-agent-as-capability, Phase 7 (US5), tasks.md T044.
 *
 * Confirms -- per the Ordering rationale, with NO new production code
 * expected -- that DelegationService::invokeAsCapability()'s translation
 * logic (Phase 3/T026) reduces every failure shape runDelegatedTask() can
 * produce down to the identical plain {"error": "..."} shape
 * executeApiCall()'s own rejection already uses: never a hang, a silent
 * partial success, or an unhandled error escaping to the caller's own
 * loop.
 *
 * (a) Quickstart scenario 9 (US5 AC1/AC2, mutation-checklist row 3) -- the
 *     offered agent's own nested run() throws mid-task (mirroring
 *     DelegationFailureIsolationTest.php's own established setup for
 *     delegate_to_helper -- a genuine \RuntimeException from the scripted
 *     LlmProvider, not a literal SchemaValidationError instance, since
 *     that exception's own throw site is deep inside runDelegatedTask()'s
 *     already-existing, reused try/catch and is exercised elsewhere by
 *     DelegationServiceMalformedResultTest.php / SchemaValidationErrorTest.php
 *     -- what this file needs is simply "the nested run throws", any
 *     \Throwable). Also asserts the caller's own turn keeps going
 *     afterward -- the same "try something else" continuation this
 *     package's existing operation-failure tests already exercise for a
 *     plain execute_operation error, run completely unmodified.
 *
 * (b) US5 AC3 -- a bound-exhausted stop (reusing Phase 6/
 *     CapabilityAgentBoundAndAttributionTest.php's own exact
 *     capability_agent.max_seconds exhaustion setup) reaches the caller as
 *     this SAME ordinary {"error": "..."} shape, never a silent partial
 *     success dressed up as a full one.
 *
 * Driven end-to-end through the real, unmocked call chain --
 * AgentLoopService::run() (real) -> handleExecuteOperation() (real) ->
 * DelegationService::invokeAsCapability() (real) -> a nested run() for the
 * offered agent (real) -- with only the LlmProvider collaborator scripted,
 * mirroring CapabilityAgentCallJourneyTest.php's / DelegationFailureIsolationTest.php's
 * own established research.md D1 convention. Belongs in tests/Feature/,
 * never tests/Integration/ (Grounding note 18).
 */
class CapabilityAgentFailureTranslationTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
            });
        }

        if (!Schema::hasTable('episodic_memories')) {
            Schema::create('episodic_memories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('conversation_id');
                $table->text('summary');
                $table->json('topics');
                $table->boolean('protected')->default(false);
                $table->unsignedInteger('word_count');
                $table->unsignedInteger('summary_word_count');
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();
        Carbon::setTestNow();

        DB::table('agent_delegations')->delete();
        DB::table('agent_capability_offerings')->delete();
        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        if (Schema::hasTable('usage_records')) {
            DB::table('usage_records')->delete();
        }
        if (Schema::hasTable('agent_run_actions')) {
            DB::table('agent_run_actions')->delete();
        }
        if (Schema::hasTable('agent_run_steps')) {
            DB::table('agent_run_steps')->delete();
        }
        if (Schema::hasTable('agent_runs')) {
            DB::table('agent_runs')->delete();
        }
        DB::table('agent_helper_assignments')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Operation-catalog scaffolding (DelegationJourneyTest's own
    // established precedent)
    // -----------------------------------------------------------------

    private function seedOperationCatalog(array $operations = []): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
            ];
        }
        $doc = ['paths' => $paths];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function offerCapability(Agent $offered, Agent $caller, string $name, string $description, string $inputDescription): CapabilityOffering
    {
        return app(CapabilityOfferingService::class)->offer(
            $this->user->id,
            $offered->id,
            $caller->id,
            $name,
            $description,
            $inputDescription,
        );
    }

    private function makeConversation(?Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    // -----------------------------------------------------------------
    // Scripted-provider scaffolding (DelegationJourneyTest's own
    // established precedent, research.md D1)
    // -----------------------------------------------------------------

    private function serviceWithScriptedProvider(array|callable $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);

        if (is_array($responses)) {
            $provider->shouldReceive('chat')->andReturnUsing(function () use (&$responses) {
                return array_shift($responses);
            });
        } else {
            $provider->shouldReceive('chat')->andReturnUsing($responses);
        }

        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            presetRegistry: app(\ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry::class),
            metricsRecorder: new \ClarionApp\LlmClient\Services\MetricsRecorder(),
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
    }

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
    }

    private function toolCall(string $name, array $arguments, string $id): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
        ];
    }

    private function toolCallReply(array $calls): array
    {
        return ['choices' => [['message' => ['content' => '', 'tool_calls' => $calls]]]];
    }

    private function executeOperationCall(string $operationId, string $input, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => $operationId,
            'parameters' => ['input' => $input],
        ], $callId);
    }

    private function firstToolResult(Conversation $conversation): ?array
    {
        $toolResultMessage = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data')
            ->orderBy('created_at')
            ->first();

        if ($toolResultMessage === null) {
            return null;
        }

        $toolResults = $toolResultMessage->tool_data['tool_results'] ?? [];
        if (empty($toolResults)) {
            return null;
        }

        return json_decode($toolResults[0]['content'] ?? '', true);
    }

    // =================================================================
    // T044(a) -- the offered agent's own nested run throws mid-task.
    // The caller receives the ordinary {"error": "..."} shape, never the
    // raw six-field envelope and never an unhandled exception, and the
    // caller's own pre-existing "try something else" continuation runs
    // completely unmodified.
    // =================================================================

    #[Test]
    public function a_thrown_exception_inside_the_offered_agents_nested_run_reaches_the_caller_as_the_ordinary_error_shape_and_the_callers_own_turn_continues(): void
    {
        $callerA = $this->makeAgent('t044a-caller-a');
        $offeredB = $this->makeAgent('t044a-offered-b');

        $offering = $this->offerCapability(
            $offeredB,
            $callerA,
            'do_risky_task',
            'Attempts a task engineered to throw partway through.',
            'What to work on.',
        );

        $conversationA = $this->makeConversation($callerA);

        $callCount = 0;
        $service = $this->serviceWithScriptedProvider(function () use (&$callCount, $offering) {
            $callCount++;

            if ($callCount === 1) {
                // A's own turn: invoke B's capability.
                return $this->toolCallReply([$this->executeOperationCall($offering->id, 'Please do the risky task.', 'call_a_invoke_b')]);
            }

            if ($callCount === 2) {
                // B's own nested run() -- a genuine thrown exception,
                // mid-delegation (mirrors DelegationFailureIsolationTest's
                // own established setup for delegate_to_helper).
                throw new \RuntimeException('The provider connection was reset unexpectedly.');
            }

            // A's own NEXT iteration, after receiving B's translated
            // failure -- proves the caller's own "try something else"
            // continuation runs unmodified: the loop does not halt, it
            // simply sees an ordinary tool-result error and keeps going.
            return $this->plainReply('B was unable to complete the risky task, but here is what I can tell you anyway.');
        });
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversationA->fresh(), 'Please have B do the risky task.');
        $this->assertSame(
            'completed',
            $result['status'],
            'the top-level turn must still resolve normally -- a thrown exception inside the nested capability call must never escape to the caller\'s own loop (US5 AC1)',
        );
        $this->assertSame(
            'B was unable to complete the risky task, but here is what I can tell you anyway.',
            $result['content'],
            'fixture sanity: the caller\'s own "try something else" continuation genuinely ran a further turn after seeing the error, proving that logic is unmodified',
        );

        $delegation = Delegation::where('parent_conversation_id', $conversationA->id)->first();
        $this->assertNotNull($delegation, 'fixture sanity: the capability call must have written a Delegation row');
        $this->assertSame('capability_offering', $delegation->origin);
        $this->assertSame('failed', $delegation->status);
        $this->assertSame('failure', $delegation->result_status);

        $aToolResult = $this->firstToolResult($conversationA);
        $this->assertNotNull($aToolResult, 'fixture sanity: A must actually have invoked the capability');
        $this->assertSame(
            ['error'],
            array_keys($aToolResult),
            'A must receive EXACTLY the plain {"error": "..."} shape -- never the raw six-field delegation envelope (status/helper/delegation_id/reason), and never an unhandled exception reaching the caller\'s own loop (FR-016/FR-017, mutation-checklist row 3)',
        );
        $this->assertIsString($aToolResult['error']);
        $this->assertNotSame('', $aToolResult['error']);

        foreach (['status', 'helper', 'delegation_id', 'reason'] as $forbiddenField) {
            $this->assertArrayNotHasKey(
                $forbiddenField,
                $aToolResult,
                "no delegation envelope field (\"{$forbiddenField}\") may leak through on the thrown-exception path",
            );
        }
    }

    // =================================================================
    // T044(b) -- US5 AC3: a bound-exhausted stop (Phase 6's own exact
    // capability_agent.max_seconds exhaustion setup) reaches the caller
    // as this SAME ordinary {"error": "..."} shape, not a silent partial
    // success presented as a full one.
    // =================================================================

    #[Test]
    public function a_bound_exhausted_capability_agent_call_reaches_the_caller_as_the_ordinary_error_shape_never_a_silent_partial_success(): void
    {
        config(['llm-client.capability_agent.max_iterations' => 50]);
        config(['llm-client.capability_agent.max_seconds' => 1]);
        config(['llm-client.agent_loop.max_iterations' => 20]);

        $callerA = $this->makeAgent('t044b-caller-a');
        $offeredB = $this->makeAgent('t044b-offered-b');

        $offering = $this->offerCapability(
            $offeredB,
            $callerA,
            'run_long_task',
            'Runs a task engineered to never finish on its own.',
            'What to work on.',
        );

        $conversationA = $this->makeConversation($callerA);

        Carbon::setTestNow(Carbon::now());

        $callCount = 0;
        $service = $this->serviceWithScriptedProvider(function () use (&$callCount, $offering) {
            $callCount++;

            if ($callCount === 1) {
                return $this->toolCallReply([$this->executeOperationCall($offering->id, 'Keep working without ever finishing.', 'call_a_invoke_b')]);
            }

            if ($callCount <= 6) {
                // Every one of these calls advances the faked clock well
                // past the 1-second capability_agent deadline -- a correct
                // implementation's nested offered-agent run must stop
                // after just one or two of these.
                Carbon::setTestNow(Carbon::now()->addSeconds(2));

                return $this->toolCallReply([$this->toolCall('list_applications', [], 'call_b_'.$callCount)]);
            }

            return $this->plainReply('A received B\'s partial outcome and is reporting it.');
        });
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversationA->fresh(), 'Please have B run a long task.');
        $this->assertSame('completed', $result['status'], 'the top-level turn itself must still resolve normally even though the capability call was cut off');

        $delegation = Delegation::where('parent_conversation_id', $conversationA->id)->first();
        $this->assertNotNull($delegation, 'fixture sanity: the capability call must have written a Delegation row');
        $this->assertSame('capability_offering', $delegation->origin);
        $this->assertSame('exhausted', $delegation->status);
        $this->assertSame('partial', $delegation->result_status, 'a bound-exhausted stop is a SYSTEM-detected non-completion -- output is always null, distinct from a HELPER-reported partial success (data-model.md §6)');
        $this->assertSame('bound_exceeded', $delegation->result_reason);
        $this->assertNull($delegation->result_output, 'a bound-exhausted stop must never carry a genuine-looking output -- it is not a success dressed up as one');

        $aToolResult = $this->firstToolResult($conversationA);
        $this->assertNotNull($aToolResult, 'fixture sanity: A must actually have invoked the capability');
        $this->assertSame(
            ['error'],
            array_keys($aToolResult),
            'A must receive EXACTLY the plain {"error": "..."} shape on the bound-exhausted path too -- the same ordinary shape as any other capability failure, never a silent partial success presented as a full one (US5 AC3)',
        );

        foreach (['status', 'helper', 'delegation_id', 'reason'] as $forbiddenField) {
            $this->assertArrayNotHasKey(
                $forbiddenField,
                $aToolResult,
                "no delegation envelope field (\"{$forbiddenField}\") may leak through on the bound-exhausted path",
            );
        }
    }
}
