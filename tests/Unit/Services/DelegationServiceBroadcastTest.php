<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\DelegationUpdated;
use ClarionApp\LlmClient\Jobs\RunDelegationBatchMemberJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * 106-multi-agent-run-view, Phase 4 (US2), tasks.md T024 (research.md
 * D4/D4a, data-model.md §3).
 *
 * Every status-write point on an agent_delegations row must fire exactly
 * one DelegationUpdated: row creation ('queued' for a batch member,
 * 'in_progress' for a solo delegation), the queued -> in_progress admission
 * transition (via the new DelegationService::broadcastDelegationAdmitted()),
 * and every terminal transition (completed/exhausted/failed). A broadcast
 * failure must never change delegate()'s/delegateBatch()'s own return
 * value, nor prevent the underlying row write from succeeding.
 *
 * Fixture scaffolding mirrors tests/Unit/Services/DelegationServiceTest.php's
 * own established precedent (operation catalog, mcp_sessions/
 * episodic_memories/condensation_states, scripted/mocked AgentLoopService)
 * for the tests that drive delegate()/delegateBatch() end-to-end; the
 * lighter tests/Unit/Jobs/RunDelegationBatchMemberJobTest.php-style direct
 * Delegation::create() fixture is reused for the tests that exercise
 * DelegationService's already-terminal-write methods
 * (recordBatchMemberTimeoutOrFailure()/forceFinalizeBatchJoinTimeout()) and
 * the new broadcastDelegationAdmitted() directly, without needing the full
 * Agent/Conversation/AgentLoopService stack.
 *
 * Written before ClarionApp\LlmClient\Events\DelegationUpdated exists and
 * before DelegationService fires any event -- every test below is expected
 * to FAIL (either a class-not-found error or an "event was not dispatched"
 * assertion failure) until T028/T029 land. That failure is the correct,
 * expected state for this phase.
 */
class DelegationServiceBroadcastTest extends TestCase
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

        DB::table('agent_delegations')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
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
    // Operation-catalog scaffolding (DelegationServiceTest's own
    // established precedent)
    // -----------------------------------------------------------------

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];

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
    // Heavy fixture helpers (delegate()/delegateBatch() end-to-end)
    // -----------------------------------------------------------------

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
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

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses) {
            return array_shift($responses);
        });
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
            runTraceRecorder: null,
        );
    }

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
    }

    // -----------------------------------------------------------------
    // Light fixture helper (direct DelegationService method calls)
    // -----------------------------------------------------------------

    private function makeLightDelegation(string $status, ?string $batchId = null): Delegation
    {
        return Delegation::create([
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'A broadcast-test fixture delegation.',
            'depth' => 1,
            'status' => $status,
            'batch_id' => $batchId ?? (string) Str::uuid(),
            'started_at' => now(),
        ]);
    }

    // =================================================================
    // delegate() (solo) -- row creation + terminal transitions
    // =================================================================

    #[Test]
    public function successful_solo_delegation_fires_delegation_updated_exactly_twice_creation_then_completed_terminal(): void
    {
        $parent = $this->makeAgent('parent-broadcast-success');
        $helper = $this->makeAgent('helper-broadcast-success');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helper->id);
        $conversation = $this->makeConversation($parent);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply(json_encode([
                'status' => 'success',
                'summary' => 'Helper completed the task.',
                'output' => [],
                'undone' => '',
            ], JSON_FORCE_OBJECT)),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        Event::fake([DelegationUpdated::class]);

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');
        $this->assertSame('success', $result['status'] ?? null, 'fixture sanity: the delegation itself must succeed');

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('completed', $row->status);

        // Row creation (status: in_progress) + the completed terminal write
        // -- exactly two DelegationUpdated dispatches, both naming this row.
        Event::assertDispatchedTimes(DelegationUpdated::class, 2);
        Event::assertDispatched(DelegationUpdated::class, fn (DelegationUpdated $e) => $e->delegationId === $row->id);
    }

    #[Test]
    public function terminal_failure_via_thrown_exception_fires_delegation_updated_exactly_twice_creation_then_failed_terminal(): void
    {
        $parent = $this->makeAgent('parent-broadcast-thrown');
        $helper = $this->makeAgent('helper-broadcast-thrown');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helper->id);
        $conversation = $this->makeConversation($parent);

        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')->once()->andThrow(new \RuntimeException('Provider unreachable.'));
        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        Event::fake([DelegationUpdated::class]);

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Do something that will explode.', null);
        $this->assertSame('failure', $result['status'] ?? null, 'fixture sanity: the delegation must fail');

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);

        Event::assertDispatchedTimes(DelegationUpdated::class, 2);
        Event::assertDispatched(DelegationUpdated::class, fn (DelegationUpdated $e) => $e->delegationId === $row->id);
    }

    #[Test]
    public function terminal_exhaustion_via_max_iterations_ceiling_fires_delegation_updated_exactly_twice_creation_then_exhausted_terminal(): void
    {
        $parent = $this->makeAgent('parent-broadcast-exhausted');
        $helper = $this->makeAgent('helper-broadcast-exhausted');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helper->id);
        $conversation = $this->makeConversation($parent);

        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')->once()->andReturn([
            'status' => 'error',
            'content' => 'Maximum iterations reached',
            'message_id' => null,
            'code' => 'max_iterations',
        ]);
        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        Event::fake([DelegationUpdated::class]);

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Do something exhausting.', null);
        $this->assertSame('partial', $result['status'] ?? null, 'fixture sanity: the delegation must exhaust');

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('exhausted', $row->status);

        Event::assertDispatchedTimes(DelegationUpdated::class, 2);
        Event::assertDispatched(DelegationUpdated::class, fn (DelegationUpdated $e) => $e->delegationId === $row->id);
    }

    #[Test]
    public function terminal_failure_via_confirmation_required_fires_delegation_updated_exactly_twice_creation_then_failed_terminal(): void
    {
        $parent = $this->makeAgent('parent-broadcast-confirm');
        $helper = $this->makeAgent('helper-broadcast-confirm');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helper->id);
        $conversation = $this->makeConversation($parent);

        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')->once()->andReturn([
            'status' => 'confirmation_required',
            'content' => '',
            'message_id' => null,
            'confirmation' => [
                'confirmation_type' => 'api_call',
                'operationId' => 'delete.thing',
                'method' => 'DELETE',
                'path' => '/api/thing',
                'arguments' => [],
                'expires_at' => now()->addMinutes(5)->toIso8601String(),
            ],
        ]);
        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        Event::fake([DelegationUpdated::class]);

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Delete the thing.', null);
        $this->assertSame('failure', $result['status'] ?? null);
        $this->assertSame('confirmation_required', $result['reason'] ?? null);

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);

        Event::assertDispatchedTimes(DelegationUpdated::class, 2);
        Event::assertDispatched(DelegationUpdated::class, fn (DelegationUpdated $e) => $e->delegationId === $row->id);
    }

    #[Test]
    public function a_refused_delegation_never_fires_delegation_updated_since_no_row_is_ever_created(): void
    {
        $parent = $this->makeAgent('parent-broadcast-refused');
        $notAHelper = $this->makeAgent('not-a-helper-broadcast-refused');
        $conversation = $this->makeConversation($parent);

        Event::fake([DelegationUpdated::class]);

        $result = app(DelegationService::class)->delegate($conversation, $notAHelper->id, 'Do something.', null);

        $this->assertSame('not_an_assigned_helper', $result['error'] ?? null);
        $this->assertSame(0, Delegation::count());

        Event::assertNotDispatched(DelegationUpdated::class);
    }

    // =================================================================
    // delegateBatch() -- 'queued' row creation
    // =================================================================

    #[Test]
    public function delegatebatch_fires_delegation_updated_exactly_once_per_row_at_creation_time_before_any_job_ever_runs(): void
    {
        // Every job is faked (never actually runs), so joinWait()'s own
        // bounded wait will eventually force-finalize each row -- keep it
        // short so this test doesn't spend real wall-clock time waiting on
        // the default 120s+5s bound (mirrors DelegationServiceBatchTest's
        // own established precedent for this exact situation).
        config(['llm-client.delegation.max_seconds' => 1]);

        $parent = $this->makeAgent('parent-broadcast-batch');
        $helperA = $this->makeAgent('helper-broadcast-batch-a');
        $helperB = $this->makeAgent('helper-broadcast-batch-b');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helperA->id);
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helperB->id);
        $conversation = $this->makeConversation($parent);

        // Captured, at the moment each job is (fake-)dispatched, how many
        // DelegationUpdated instances have already fired for THAT row --
        // this is BEFORE joinWait() has had any chance to force-finalize
        // anything, since delegateBatch() dispatches every job first and
        // only then calls joinWait().
        $firedCountAtDispatchTime = [];
        Event::listen(DelegationUpdated::class, function (DelegationUpdated $e) use (&$firedCountAtDispatchTime) {
            $firedCountAtDispatchTime[$e->delegationId] = ($firedCountAtDispatchTime[$e->delegationId] ?? 0) + 1;
        });

        $countsObservedAtDispatch = [];
        Bus::fake([function ($job) use (&$countsObservedAtDispatch, &$firedCountAtDispatchTime) {
            if (!$job instanceof RunDelegationBatchMemberJob) {
                return false;
            }
            $countsObservedAtDispatch[$job->delegationId] = $firedCountAtDispatchTime[$job->delegationId] ?? 0;

            return true;
        }]);

        $calls = [
            ['tool_call_id' => 'call_a', 'helper_agent_id' => $helperA->id, 'task' => 'Task A.', 'context' => null],
            ['tool_call_id' => 'call_b', 'helper_agent_id' => $helperB->id, 'task' => 'Task B.', 'context' => null],
        ];

        app(DelegationService::class)->delegateBatch($conversation, $calls);

        $rows = Delegation::where('parent_conversation_id', $conversation->id)->get();
        $this->assertCount(2, $rows, 'fixture sanity: both calls must have created a row');

        foreach ($rows as $row) {
            $this->assertArrayHasKey($row->id, $countsObservedAtDispatch, 'every row must have had a job dispatched for it');
            $this->assertSame(
                1,
                $countsObservedAtDispatch[$row->id],
                'row creation (status: queued) must fire exactly one DelegationUpdated, observed strictly before joinWait() has any chance to force-finalize anything further',
            );
        }
    }

    // =================================================================
    // broadcastDelegationAdmitted() -- the D4a concrete admission wrapper
    // =================================================================

    #[Test]
    public function broadcast_delegation_admitted_fires_delegation_updated_exactly_once_for_the_given_id(): void
    {
        $delegation = $this->makeLightDelegation('in_progress');

        Event::fake([DelegationUpdated::class]);

        app(DelegationService::class)->broadcastDelegationAdmitted($delegation->id);

        Event::assertDispatchedTimes(DelegationUpdated::class, 1);
        Event::assertDispatched(DelegationUpdated::class, fn (DelegationUpdated $e) => $e->delegationId === $delegation->id);
    }

    // =================================================================
    // Other direct-write terminal methods
    // =================================================================

    #[Test]
    public function record_batch_member_timeout_or_failure_fires_delegation_updated_exactly_once(): void
    {
        $delegation = $this->makeLightDelegation('in_progress');

        Event::fake([DelegationUpdated::class]);

        app(DelegationService::class)->recordBatchMemberTimeoutOrFailure($delegation->id, new \RuntimeException('boom'));

        Event::assertDispatchedTimes(DelegationUpdated::class, 1);
        Event::assertDispatched(DelegationUpdated::class, fn (DelegationUpdated $e) => $e->delegationId === $delegation->id);
    }

    #[Test]
    public function record_batch_member_timeout_or_failure_fires_no_event_when_the_row_is_already_terminal(): void
    {
        $delegation = $this->makeLightDelegation('completed');

        Event::fake([DelegationUpdated::class]);

        app(DelegationService::class)->recordBatchMemberTimeoutOrFailure($delegation->id, new \RuntimeException('boom'));

        Event::assertNotDispatched(DelegationUpdated::class);
    }

    #[Test]
    public function force_finalize_batch_join_timeout_fires_delegation_updated_exactly_once(): void
    {
        $delegation = $this->makeLightDelegation('queued');

        Event::fake([DelegationUpdated::class]);

        app(DelegationService::class)->forceFinalizeBatchJoinTimeout($delegation);

        Event::assertDispatchedTimes(DelegationUpdated::class, 1);
        Event::assertDispatched(DelegationUpdated::class, fn (DelegationUpdated $e) => $e->delegationId === $delegation->id);
    }

    // =================================================================
    // Standing rule: a broadcast failure must never change delegate()'s
    // own return value, nor prevent the underlying row write.
    // =================================================================

    #[Test]
    public function a_broadcast_failure_on_the_terminal_write_never_changes_delegates_own_return_value_or_blocks_the_row_write(): void
    {
        $parent = $this->makeAgent('parent-broadcast-failure');
        $helper = $this->makeAgent('helper-broadcast-failure');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helper->id);
        $conversation = $this->makeConversation($parent);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply(json_encode([
                'status' => 'success',
                'summary' => 'Helper completed the task.',
                'output' => [],
                'undone' => '',
            ], JSON_FORCE_OBJECT)),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        // A real listener that throws -- exercises the actual private
        // broadcast() try/catch, never a faked dispatcher (mirrors
        // RunTraceRecorderBroadcastTest's own established never-throw
        // pattern).
        Event::listen(DelegationUpdated::class, function (): void {
            throw new \RuntimeException('Pusher unreachable');
        });

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $this->assertSame(
            'success',
            $result['status'] ?? null,
            'a broadcast failure must never change delegate()\'s own return value',
        );

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row, 'a broadcast failure must never prevent the underlying Delegation row from being written');
        $this->assertSame('completed', $row->status, 'a broadcast failure must never prevent the terminal status write from succeeding');
    }

    #[Test]
    public function a_broadcast_failure_on_broadcast_delegation_admitted_does_not_propagate(): void
    {
        $delegation = $this->makeLightDelegation('in_progress');

        Event::listen(DelegationUpdated::class, function (): void {
            throw new \RuntimeException('Pusher unreachable');
        });

        // Must not throw despite the listener above.
        app(DelegationService::class)->broadcastDelegationAdmitted($delegation->id);

        $this->assertTrue(true, 'broadcastDelegationAdmitted() must swallow a broadcast failure rather than propagate it');
    }
}
