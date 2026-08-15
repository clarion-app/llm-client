<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Exceptions\SchemaValidationError;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 098-delegation-protocol, Phase 4 (US3), tasks.md T025.
 *
 * The read surface over the `Delegation` rows Phase 3's `DelegationService`
 * writes (contracts/delegation-protocol-api.md §1-3): `GET
 * /agent-runs/{runId}/delegations`, `GET /delegations/{id}`, and `GET
 * /agent-runs/{runId}/cost-with-delegations`. Every GET call in this file
 * goes over real HTTP, since these three endpoints are the actual subject
 * under test — driving the delegations themselves that back the "two
 * delegations in one turn" and "cost rollup" scenarios reuses
 * DelegationJourneyTest.php's own established scripted-`LlmProvider`
 * pattern (research.md D1), never Http::fake(), for the identical reason
 * that test gives: the whole point is to exercise the real agent loop and
 * the real nested `delegate_to_helper` -> `AgentLoopService::run()` call
 * without a live provider. The simpler shape/ownership scenarios build
 * their `AgentRun`/`Delegation` fixtures directly (RunTraceRecorder +
 * `Delegation::create()`), mirroring RunControllerTest's own precedent for
 * a pure read-path controller.
 *
 * Written before `DelegationController`, `DelegationQuery`, and all three
 * routes exist (T028/T029 are Phase 4's implementation tasks, not yet
 * done) -- every request below hits Laravel's own "route not found" 404,
 * which is a DIFFERENT body/shape from what the eventual controller
 * returns for a 200, an empty array, or its own uniform not-found
 * response, so every assertion in this file is expected to FAIL red.
 * Not-found assertions deliberately check for `error`/`code` JSON keys
 * (mirroring RunController's own uniform-404 idiom) rather than the
 * literal message string, since the exact wording is DelegationController's
 * call to make in Phase 4's own implementation tasks -- but Laravel's
 * default route-not-found body carries neither key, so this is still a
 * genuine, non-vacuous failure right now, not one that happens to already
 * pass by accident of the route being absent.
 */
class DelegationQueryControllerTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private Server $server;

    private RunTraceRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->recorder = $this->app->make(RunTraceRecorder::class);
        $this->seedOperationCatalog();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

        // The full-journey scenarios (two-delegations-in-one-turn, cost
        // rollup) drive the real AgentLoopService::run() -- the same three
        // hand-declared tables DelegationJourneyTest's own setUp() needs
        // for that path (MCP session lookup, memory/condensation reads
        // inside buildMessagesPayload()/applyContextWindowTrim()).
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
        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        if (Schema::hasTable('cost_summaries')) {
            DB::table('cost_summaries')->delete();
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
    // Operation-catalog scaffolding (AgentHandoffJourneyTest/
    // DelegationJourneyTest's own established precedent)
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

    private function agentsUrl(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function agentUrl(string $id): string
    {
        return $this->agentsUrl().'/'.$id;
    }

    private function helpersUrl(string $agentId): string
    {
        return $this->agentUrl($agentId).'/helpers';
    }

    private function makeAgent(User $owner, string $name): Agent
    {
        return app(AgentService::class)->create($owner->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    /**
     * The real, unmodified 097 HTTP endpoint -- the exact same precedent
     * DelegationJourneyTest's own assignHelper() uses.
     */
    private function assignHelper(User $owner, string $parentAgentId, string $helperAgentId): void
    {
        $this->actingAs($owner, 'api')
            ->postJson($this->helpersUrl($parentAgentId), ['helper_agent_id' => $helperAgentId])
            ->assertStatus(201);
    }

    private function makeConversation(User $owner, ?Agent $agent): Conversation
    {
        // 'title' pre-set (DelegationJourneyTest's own precedent) so run()'s
        // own title-generation dispatch is never triggered.
        return Conversation::factory()->create([
            'user_id' => $owner->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    /**
     * A run opened and immediately closed via the real RunTraceRecorder --
     * RunControllerTest's own precedent for building a pure-read-path
     * fixture without driving the whole agent loop.
     */
    private function makeRun(User $owner): string
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $owner->id);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        return $runId;
    }

    /**
     * A `Delegation` row created directly (Grounding note item 12's
     * `RunTraceQuery`/`AgentQuery` "owner-scoped read" precedent this
     * feature's own `DelegationQuery` mirrors) -- for the shape/ownership
     * scenarios, which only need a real row to read back, not a real
     * delegated agent-loop run.
     */
    private function makeDelegationRow(User $owner, ?string $parentRunId, array $overrides = []): Delegation
    {
        $parentAgent = $this->makeAgent($owner, 'parent-agent-'.Str::random(8));
        $helperAgent = $this->makeAgent($owner, 'helper-agent-'.Str::random(8));
        $parentConversation = $this->makeConversation($owner, $parentAgent);
        $helperConversation = $this->makeConversation($owner, $helperAgent);

        return Delegation::create(array_merge([
            'parent_conversation_id' => $parentConversation->id,
            'parent_agent_id' => $parentAgent->id,
            'helper_agent_id' => $helperAgent->id,
            'helper_conversation_id' => $helperConversation->id,
            'helper_agent_version_id' => $helperAgent->current_version_id,
            'owner_user_id' => $owner->id,
            'task' => 'Extract line items from the attached invoice text.',
            'context' => 'Invoice text: ...',
            'depth' => 1,
            'status' => 'completed',
            'parent_run_id' => $parentRunId,
            'parent_action_id' => null,
            'helper_run_id' => null,
            'outcome_summary' => 'Completed normally.',
            'started_at' => now(),
            'completed_at' => now(),
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // Scripted-provider scaffolding (DelegationJourneyTest's own
    // established precedent, research.md D1)
    // -----------------------------------------------------------------

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

    // =================================================================
    // §1 -- GET /agent-runs/{runId}/delegations
    // =================================================================

    #[Test]
    public function run_with_one_delegation_returns_200_with_the_contract_shape(): void
    {
        $runId = $this->makeRun($this->user);
        $delegation = $this->makeDelegationRow($this->user, $runId);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/delegations");

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonStructure([
                '*' => [
                    'id', 'parent_conversation_id', 'helper_agent_id', 'helper_agent_name',
                    'helper_conversation_id', 'depth', 'status', 'task', 'context',
                    'parent_run_id', 'parent_action_id', 'helper_run_id', 'outcome_summary',
                    'started_at', 'completed_at',
                ],
            ]);

        $row = $response->json()[0];
        $this->assertSame($delegation->id, $row['id']);
        $this->assertSame($delegation->helper_agent_id, $row['helper_agent_id']);
        $this->assertSame($runId, $row['parent_run_id']);
    }

    #[Test]
    public function run_with_zero_delegations_returns_200_empty_array_not_404(): void
    {
        $runId = $this->makeRun($this->user);
        // No Delegation row created at all.

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/delegations");

        // RunController's own "empty is not absent" precedent (data-model.md
        // §7/contracts §1) -- an owned run with zero delegations is a 200
        // with an empty array, never a 404.
        $response->assertStatus(200)->assertExactJson([]);
    }

    #[Test]
    public function nonexistent_run_id_returns_404_for_the_delegations_endpoint(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/clarion-app/llm-client/agent-runs/'.(string) Str::uuid().'/delegations');

        $response->assertStatus(404)->assertJsonStructure(['error', 'code']);
    }

    #[Test]
    public function foreign_owned_run_id_returns_404_for_the_delegations_endpoint(): void
    {
        $runId = $this->makeRun($this->otherUser);
        $this->makeDelegationRow($this->otherUser, $runId);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/delegations");

        $response->assertStatus(404)->assertJsonStructure(['error', 'code']);
    }

    #[Test]
    public function two_delegations_to_two_different_helpers_in_one_turn_are_both_listed_and_each_is_named_in_the_disclosure(): void
    {
        $parent = $this->makeAgent($this->user, 'parent-agent-two-delegations');
        $helperOne = $this->makeAgent($this->user, 'helper-agent-one');
        $helperTwo = $this->makeAgent($this->user, 'helper-agent-two');
        $this->assignHelper($this->user, $parent->id, $helperOne->id);
        $this->assignHelper($this->user, $parent->id, $helperTwo->id);

        $conversation = $this->makeConversation($this->user, $parent);

        $service = $this->serviceWithScriptedProvider([
            // Both delegate_to_helper calls land in the SAME iteration, so
            // both delegations share one parent_run_id.
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $helperOne->id,
                    'task' => 'Summarize section A.',
                    'context' => 'Section A covers onboarding.',
                ], 'call_delegate_one'),
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $helperTwo->id,
                    'task' => 'Summarize section B.',
                    'context' => 'Section B covers billing.',
                ], 'call_delegate_two'),
            ]),
            $this->plainReply('Section A summary.'), // consumed by helper one's own nested run()
            $this->plainReply('Section B summary.'), // consumed by helper two's own nested run()
            $this->plainReply('Here is the combined summary, incorporating both helpers\' work.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Please summarize sections A and B.');
        $this->assertSame('completed', $result['status'], 'fixture sanity: the turn must complete');
        $this->assertStringContainsString($helperOne->name, $result['content'], 'the disclosure sentence must name the first helper');
        $this->assertStringContainsString($helperTwo->name, $result['content'], 'the disclosure sentence must name the second helper');

        $parentRun = DB::table('agent_runs')->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($parentRun, 'fixture sanity: the parent turn must have opened its own run trace');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$parentRun->id}/delegations");

        $response->assertStatus(200)->assertJsonCount(2);

        $data = $response->json();
        $helperAgentIds = array_column($data, 'helper_agent_id');
        $helperRunIds = array_column($data, 'helper_run_id');

        $this->assertEqualsCanonicalizing([$helperOne->id, $helperTwo->id], $helperAgentIds, 'both distinct helper_agent_id values must be listed');
        $this->assertCount(2, array_unique(array_filter($helperRunIds)), 'both delegations must carry distinct, non-null helper_run_id values');
    }

    // =================================================================
    // §2 -- GET /delegations/{id}
    // =================================================================

    #[Test]
    public function a_single_delegation_returns_200_with_the_same_per_item_shape(): void
    {
        $runId = $this->makeRun($this->user);
        $delegation = $this->makeDelegationRow($this->user, $runId);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/delegations/{$delegation->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id', 'parent_conversation_id', 'helper_agent_id', 'helper_agent_name',
                'helper_conversation_id', 'depth', 'status', 'task', 'context',
                'parent_run_id', 'parent_action_id', 'helper_run_id', 'outcome_summary',
                'started_at', 'completed_at',
            ])
            ->assertJson([
                'id' => $delegation->id,
                'helper_agent_id' => $delegation->helper_agent_id,
            ]);
    }

    #[Test]
    public function nonexistent_delegation_id_returns_404(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/clarion-app/llm-client/delegations/'.(string) Str::uuid());

        $response->assertStatus(404)->assertJsonStructure(['error', 'code']);
    }

    #[Test]
    public function foreign_owned_delegation_id_returns_404(): void
    {
        $delegation = $this->makeDelegationRow($this->otherUser, null);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/delegations/{$delegation->id}");

        $response->assertStatus(404)->assertJsonStructure(['error', 'code']);
    }

    // =================================================================
    // §3 -- GET /agent-runs/{runId}/cost-with-delegations
    // =================================================================

    #[Test]
    public function cost_with_delegations_rolls_up_delegated_cost_matching_usage_records_without_double_counting(): void
    {
        $parent = $this->makeAgent($this->user, 'parent-agent-cost');
        $helper = $this->makeAgent($this->user, 'helper-agent-cost');
        $this->assignHelper($this->user, $parent->id, $helper->id);

        $conversation = $this->makeConversation($this->user, $parent);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $helper->id,
                    'task' => 'Extract line items from the attached invoice text.',
                    'context' => 'Invoice text: line 1, line 2, line 3.',
                ], 'call_delegate_cost'),
            ]),
            $this->plainReply('Extracted three line items, each with a description and an amount.'),
            $this->plainReply('Here are the extracted line items, courtesy of the helper.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Please extract the invoice line items.');
        $this->assertSame('completed', $result['status'], 'fixture sanity: the turn must complete');

        $parentRun = DB::table('agent_runs')->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($parentRun, 'fixture sanity: the parent turn must have opened its own run trace');

        $delegation = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegation, 'fixture sanity: the delegation must have written its own row');
        $this->assertNotNull($delegation->helper_conversation_id);

        $helperUsageTokens = (int) DB::table('usage_records')
            ->where('conversation_id', $delegation->helper_conversation_id)
            ->sum('total_tokens');
        $this->assertGreaterThan(0, $helperUsageTokens, 'fixture sanity: the helper\'s own nested run must have recorded real usage');

        $ownUsageTokens = (int) DB::table('usage_records')
            ->where('run_id', $parentRun->id)
            ->sum('total_tokens');
        $this->assertGreaterThan(0, $ownUsageTokens, 'fixture sanity: the parent\'s own turn must have recorded real usage too');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$parentRun->id}/cost-with-delegations");

        $response->assertStatus(200)
            ->assertJsonStructure(['run_id', 'own_cost' => ['total_cost', 'total_tokens'], 'delegated_cost' => ['total_cost', 'total_tokens'], 'delegation_count']);

        $body = $response->json();
        $this->assertSame($parentRun->id, $body['run_id']);
        $this->assertSame(1, $body['delegation_count']);
        $this->assertGreaterThan(
            0,
            $body['delegated_cost']['total_tokens'],
            'delegated_cost.total_tokens must be nonzero, matching usage_records summed directly against the helper\'s own helper_conversation_id',
        );
        $this->assertSame(
            $helperUsageTokens,
            $body['delegated_cost']['total_tokens'],
            'delegated_cost.total_tokens must exactly match usage_records summed directly against the helper\'s own helper_conversation_id -- no merging, no double-counting',
        );
        $this->assertSame(
            $ownUsageTokens,
            $body['own_cost']['total_tokens'],
            'own_cost must remain the existing, unmodified per-run usage sum -- never inflated by the delegated work',
        );

        // The helper's own conversation still shows its own accurate cost
        // when queried directly via the existing, unmodified cost-rollup
        // endpoint (070/074) -- proving no merging of the two conversation
        // ids happened anywhere along the way.
        $today = now()->toDateString();
        $helperCostResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/cost-rollups/conversations/{$delegation->helper_conversation_id}?from={$today}&to={$today}");

        $helperCostResponse->assertStatus(200);
        $this->assertGreaterThan(
            0,
            $helperCostResponse->json('request_count'),
            'the helper conversation\'s own cost-rollup view must independently show its own activity, unaffected by the new delegated-cost rollup',
        );
    }

    #[Test]
    public function cost_with_delegations_returns_404_on_the_same_ownership_rule(): void
    {
        $runId = $this->makeRun($this->otherUser);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/cost-with-delegations");

        $response->assertStatus(404)->assertJsonStructure(['error', 'code']);
    }

    // =================================================================
    // 099-result-aggregation, Phase 3 (US1 + US2), tasks.md T015 --
    // additive result_* fields on the two existing per-delegation read
    // endpoints (contracts/result-aggregation-api.md §1, data-model.md
    // §6). Written before DelegationController::delegationRows() emits
    // any of the six new keys -- every assertion below is expected to
    // FAIL red.
    // =================================================================

    /**
     * The exact key order delegationRows() is expected to produce once
     * Phase 3's T020 lands: the six new result_* keys inserted after
     * outcome_summary, before started_at (Grounding note item 7),
     * every existing key otherwise unchanged. 101-parallel-subagent-execution
     * (T019, contracts §2) additively inserts one further key, `batch_id`,
     * immediately after `status` -- the single place delegationRows()
     * assembles the wire shape for both forRun()/show(), so both endpoints
     * gain it identically.
     */
    private function expectedDelegationRowKeyOrder(): array
    {
        return [
            'id', 'parent_conversation_id', 'helper_agent_id', 'helper_agent_name',
            'helper_conversation_id', 'depth', 'status', 'batch_id', 'task', 'context',
            'parent_run_id', 'parent_action_id', 'helper_run_id', 'outcome_summary',
            'result_status', 'result_reason', 'result_summary', 'result_output',
            'result_undone', 'result_truncated',
            'started_at', 'completed_at',
        ];
    }

    #[Test]
    public function delegations_for_run_includes_the_six_new_result_fields_after_a_successful_delegation(): void
    {
        $runId = $this->makeRun($this->user);
        $output = ['line_items' => ['Widget A', 'Widget B'], 'total' => '1042.50'];

        $this->makeDelegationRow($this->user, $runId, [
            'result_status' => 'success',
            'result_reason' => null,
            'result_summary' => 'Extracted all line items from the invoice.',
            'result_output' => json_encode($output),
            'result_undone' => '',
            'result_truncated' => false,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/delegations");

        $response->assertStatus(200)->assertJsonCount(1);

        $row = $response->json()[0];
        $this->assertSame(
            $this->expectedDelegationRowKeyOrder(),
            array_keys($row),
            'the six new result_* keys must appear immediately after outcome_summary, before started_at, with every existing key unchanged',
        );

        $this->assertSame('success', $row['result_status']);
        $this->assertNull($row['result_reason']);
        $this->assertSame('Extracted all line items from the invoice.', $row['result_summary']);
        $this->assertSame($output, $row['result_output'], 'result_output must be JSON-decoded to a native array, not left as a raw string');
        $this->assertSame('', $row['result_undone']);
        $this->assertFalse($row['result_truncated']);
    }

    #[Test]
    public function a_single_delegation_includes_the_six_new_result_fields_after_a_successful_delegation(): void
    {
        $runId = $this->makeRun($this->user);
        $output = ['currency' => 'USD'];

        $delegation = $this->makeDelegationRow($this->user, $runId, [
            'result_status' => 'success',
            'result_reason' => null,
            'result_summary' => 'Normalized the currency field.',
            'result_output' => json_encode($output),
            'result_undone' => '',
            'result_truncated' => false,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/delegations/{$delegation->id}");

        $response->assertStatus(200);

        $row = $response->json();
        $this->assertSame(
            $this->expectedDelegationRowKeyOrder(),
            array_keys($row),
            'the six new result_* keys must appear immediately after outcome_summary, before started_at, with every existing key unchanged',
        );

        $this->assertSame('success', $row['result_status']);
        $this->assertNull($row['result_reason']);
        $this->assertSame('Normalized the currency field.', $row['result_summary']);
        $this->assertSame($output, $row['result_output'], 'result_output must be JSON-decoded to a native array, not left as a raw string');
        $this->assertSame('', $row['result_undone']);
        $this->assertFalse($row['result_truncated']);
    }

    // =================================================================
    // 099-result-aggregation, Phase 4 (US5), tasks.md T025: quickstart
    // scenario 5 AC3 -- after a malformed-result delegation, GET
    // /delegations/{id} reflects the same result_status/result_reason the
    // incident produced. Driven through the real DelegationService::
    // delegate() (not a hand-built Delegation row, unlike this file's own
    // "includes the six new result fields" tests above): Phase 3's read
    // path (DelegationController::delegationRows()) is already fully
    // generic and would pass on a hand-built row regardless of whether
    // Phase 4's catch-block mapping exists, so only driving the actual
    // write path exercises the gap T026/T027 close.
    // =================================================================

    #[Test]
    public function get_delegation_reflects_malformed_output_failure_after_the_incident(): void
    {
        $parent = $this->makeAgent($this->user, 'parent-malformed');
        $helper = $this->makeAgent($this->user, 'helper-malformed');
        $this->assignHelper($this->user, $parent->id, $helper->id);
        $conversation = $this->makeConversation($this->user, $parent);

        $mock = Mockery::mock(AgentLoopService::class);
        $mock->shouldReceive('run')->once()->andThrow(new SchemaValidationError(
            'The response did not match the required schema.',
            [],
            'not valid json at all',
        ));
        $this->app->instance(AgentLoopService::class, $mock);

        app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $delegation = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegation, 'fixture sanity: the incident must still write a Delegation row');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/delegations/{$delegation->id}");

        $response->assertStatus(200);

        $row = $response->json();
        $this->assertSame('failure', $row['result_status']);
        $this->assertSame('malformed_output', $row['result_reason']);
        $this->assertNull($row['result_output']);
    }

    #[Test]
    public function get_delegation_reflects_no_output_failure_after_the_incident(): void
    {
        $parent = $this->makeAgent($this->user, 'parent-no-output');
        $helper = $this->makeAgent($this->user, 'helper-no-output');
        $this->assignHelper($this->user, $parent->id, $helper->id);
        $conversation = $this->makeConversation($this->user, $parent);

        $mock = Mockery::mock(AgentLoopService::class);
        $mock->shouldReceive('run')->once()->andThrow(new SchemaValidationError(
            'The response was empty.',
            [],
            '   ',
        ));
        $this->app->instance(AgentLoopService::class, $mock);

        app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $delegation = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegation, 'fixture sanity: the incident must still write a Delegation row');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/delegations/{$delegation->id}");

        $response->assertStatus(200);

        $row = $response->json();
        $this->assertSame('failure', $row['result_status']);
        $this->assertSame('no_output', $row['result_reason']);
        $this->assertNull($row['result_output']);
    }

    // =================================================================
    // 099-result-aggregation, Phase 5 (US3), tasks.md T032 --
    // GET /agent-runs/{runId}/combined-results (contracts/
    // result-aggregation-api.md §2, data-model.md §4/§6). Sequenced after
    // T015/T025 (this file's own two prior 099 extensions) -- not [P].
    // Built directly against hand-built Delegation rows, mirroring this
    // file's own established "shape/ownership scenarios build their
    // fixtures directly" precedent (no agent-loop scaffolding needed,
    // research.md D5).
    //
    // Written before DelegationController::combinedResults() and its
    // route exist -- every request below hits Laravel's own "route not
    // found" 404 (a different body/shape from what the eventual
    // controller returns for a 200, a 200 null, or its own uniform
    // not-found response), matching this file's own class docblock
    // precedent for its Phase 4-era endpoints -- so every assertion below
    // is expected to FAIL red, non-vacuously.
    //
    // Per ResultAggregationServiceTest's own T030 docblock: each
    // `contributors` entry may carry an additional `output` field beyond
    // the five checked here (helper_agent_id/helper_agent_name are
    // checked via assertArrayHasKey rather than an exact key-set match on
    // each entry) -- needed so AgentLoopService::buildCombinedHelperResultsSection()
    // can attribute a combined_output entry back to its specific
    // contributor(s) without a second, redundant Delegation query. The
    // *top-level* response shape (run_id/contributors/combined_output/
    // conflicts/truncated) is still asserted exactly, per contracts §2.
    // =================================================================

    private function combinedResultsUrl(string $runId): string
    {
        return "/api/clarion-app/llm-client/agent-runs/{$runId}/combined-results";
    }

    #[Test]
    public function combined_results_returns_404_for_a_nonexistent_run(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->combinedResultsUrl((string) Str::uuid()));

        $response->assertStatus(404)->assertJsonStructure(['error', 'code']);
    }

    #[Test]
    public function combined_results_returns_404_for_a_foreign_owned_run(): void
    {
        $runId = $this->makeRun($this->otherUser);

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->combinedResultsUrl($runId));

        $response->assertStatus(404)->assertJsonStructure(['error', 'code']);
    }

    #[Test]
    public function combined_results_returns_200_null_for_an_owned_run_with_zero_delegations(): void
    {
        $runId = $this->makeRun($this->user);

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->combinedResultsUrl($runId));

        $response->assertStatus(200);
        // assertContent('null'), not assertNull($response->json()):
        // TestResponse::json() calls decodeResponseJson(), which treats a
        // fully-decoded-to-null body as an invalid response and fails with
        // "Invalid JSON was returned from the route." before ever
        // returning -- true for every response whose entire JSON payload
        // is the literal top-level `null`, regardless of what the
        // controller returns. Same fix as this repo's own
        // EvalReferenceController::current()/RegressionReportJourneyTest.php
        // precedent for the identical "a real, valid 200 null body" case.
        $response->assertContent('null');
    }

    #[Test]
    public function combined_results_returns_200_null_for_an_owned_run_with_exactly_one_qualifying_delegation(): void
    {
        $runId = $this->makeRun($this->user);
        $this->makeDelegationRow($this->user, $runId, [
            'result_status' => 'success',
            'result_summary' => 'Done.',
            'result_output' => json_encode(['a' => 1]),
            'result_undone' => '',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->combinedResultsUrl($runId));

        $response->assertStatus(200);
        $response->assertContent('null');
    }

    #[Test]
    public function combined_results_returns_the_full_shape_for_two_delegations_to_different_helpers(): void
    {
        $runId = $this->makeRun($this->user);

        $extractor = $this->makeDelegationRow($this->user, $runId, [
            'result_status' => 'success',
            'result_summary' => 'Extracted the line items.',
            'result_output' => json_encode(['line_items' => ['Widget A', 'Widget B']]),
            'result_undone' => '',
        ]);
        $normalizer = $this->makeDelegationRow($this->user, $runId, [
            'result_status' => 'success',
            'result_summary' => 'Normalized the currency.',
            'result_output' => json_encode(['currency' => 'USD']),
            'result_undone' => '',
        ]);

        $extractorName = Agent::find($extractor->helper_agent_id)->name;
        $normalizerName = Agent::find($normalizer->helper_agent_id)->name;

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->combinedResultsUrl($runId));

        $response->assertStatus(200)->assertJsonStructure([
            'run_id', 'contributors', 'combined_output', 'conflicts', 'truncated',
        ]);

        $body = $response->json();
        $this->assertSame(
            ['run_id', 'contributors', 'combined_output', 'conflicts', 'truncated'],
            array_keys($body),
            'the top-level response shape must be exactly contracts/result-aggregation-api.md §2\'s five keys',
        );
        $this->assertSame($runId, $body['run_id']);
        $this->assertCount(2, $body['contributors']);
        $this->assertSame([], $body['conflicts']);
        $this->assertFalse($body['truncated']);
        $this->assertSame(
            ['line_items' => ['Widget A', 'Widget B'], 'currency' => 'USD'],
            $body['combined_output'],
        );

        $byDelegationId = collect($body['contributors'])->keyBy('delegation_id');
        $this->assertArrayHasKey($extractor->id, $byDelegationId);
        $this->assertArrayHasKey($normalizer->id, $byDelegationId);

        $extractorRow = $byDelegationId[$extractor->id];
        $this->assertArrayHasKey('helper_agent_id', $extractorRow);
        $this->assertArrayHasKey('helper_agent_name', $extractorRow);
        $this->assertSame($extractor->helper_agent_id, $extractorRow['helper_agent_id']);
        $this->assertSame($extractorName, $extractorRow['helper_agent_name']);

        $normalizerRow = $byDelegationId[$normalizer->id];
        $this->assertSame($normalizer->helper_agent_id, $normalizerRow['helper_agent_id']);
        $this->assertSame($normalizerName, $normalizerRow['helper_agent_name']);
    }

    #[Test]
    public function combined_results_provenance_is_unchanged_when_refetched_after_the_run_has_fully_ended(): void
    {
        // makeRun() both opens AND closes the run (RunEndState::Completed)
        // before this test ever calls the endpoint -- there is no live
        // conversation in progress at any point below, matching the
        // "later reviewer of the interaction record" framing (research.md
        // D5/D8) directly, not merely simulating it after the fact.
        $runId = $this->makeRun($this->user);

        $extractor = $this->makeDelegationRow($this->user, $runId, [
            'result_status' => 'success',
            'result_summary' => 'Extracted the line items.',
            'result_output' => json_encode(['line_items' => ['Widget A']]),
            'result_undone' => '',
        ]);
        $normalizer = $this->makeDelegationRow($this->user, $runId, [
            'result_status' => 'success',
            'result_summary' => 'Normalized the currency.',
            'result_output' => json_encode(['currency' => 'USD']),
            'result_undone' => '',
        ]);

        $first = $this->actingAs($this->user, 'api')->getJson($this->combinedResultsUrl($runId));
        $first->assertStatus(200);

        $second = $this->actingAs($this->user, 'api')->getJson($this->combinedResultsUrl($runId));
        $second->assertStatus(200);

        $this->assertSame(
            $first->json(),
            $second->json(),
            'a later reviewer re-fetching the same run\'s combined results after it has fully ended must see byte-identical provenance -- no live conversation required',
        );

        $contributorIds = array_column($first->json()['contributors'], 'delegation_id');
        $this->assertEqualsCanonicalizing([$extractor->id, $normalizer->id], $contributorIds);
    }

    // =================================================================
    // 099-result-aggregation, Phase 6 (US4), tasks.md T040 -- quickstart
    // scenario 4: a genuine conflict between two helpers is surfaced, not
    // silently resolved (FR-010/FR-011). Sequenced after T032's own tests
    // above, not [P]. `ResultAggregationService::combineForRun()` still
    // unions every key unconditionally today (conflicts hardcoded to
    // `[]`), so this is expected to FAIL red until Phase 6's
    // implementation (T041) exists.
    // =================================================================

    #[Test]
    public function combined_results_excludes_a_conflicting_key_from_combined_output_and_records_it_in_conflicts_with_both_values_and_provenance(): void
    {
        $runId = $this->makeRun($this->user);

        $extractor = $this->makeDelegationRow($this->user, $runId, [
            'result_status' => 'success',
            'result_summary' => 'Computed the total.',
            'result_output' => json_encode(['total' => '1042.50']),
            'result_undone' => '',
        ]);
        $normalizer = $this->makeDelegationRow($this->user, $runId, [
            'result_status' => 'success',
            'result_summary' => 'Recomputed the total.',
            'result_output' => json_encode(['total' => '1024.50']),
            'result_undone' => '',
        ]);

        $extractorName = Agent::find($extractor->helper_agent_id)->name;
        $normalizerName = Agent::find($normalizer->helper_agent_id)->name;

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->combinedResultsUrl($runId));

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertArrayNotHasKey(
            'total',
            $body['combined_output'],
            'a key with two differing values must never appear in combined_output',
        );
        $this->assertCount(1, $body['conflicts']);

        $conflict = $body['conflicts'][0];
        $this->assertSame('total', $conflict['key']);
        $this->assertCount(2, $conflict['values']);

        $byDelegationId = collect($conflict['values'])->keyBy('delegation_id');

        $extractorValue = $byDelegationId[$extractor->id];
        $this->assertSame('1042.50', $extractorValue['value']);
        $this->assertSame($extractor->helper_agent_id, $extractorValue['helper_agent_id']);
        $this->assertSame($extractorName, $extractorValue['helper_agent_name']);

        $normalizerValue = $byDelegationId[$normalizer->id];
        $this->assertSame('1024.50', $normalizerValue['value']);
        $this->assertSame($normalizer->helper_agent_id, $normalizerValue['helper_agent_id']);
        $this->assertSame($normalizerName, $normalizerValue['helper_agent_name']);
    }

    // =================================================================
    // 099-result-aggregation, Phase 7 (US6), tasks.md T047 -- a dedicated,
    // cap-forcing proof (at the read-API layer) of both size bounds
    // (research.md D4, quickstart scenarios 6/7): under a low
    // combined_output_cap_bytes, GET /agent-runs/{runId}/combined-results
    // reflects truncated: true; separately, GET /delegations/{id} surfaces
    // the raw truncated result_output string (not null) for a delegation
    // whose result_output was actually truncated by a low
    // result_output_cap_bytes, confirming Grounding note item 7's
    // defensive-decode fix (T020) holds under real truncation.
    // =================================================================

    #[Test]
    public function combined_results_reflects_truncated_true_under_a_low_combined_output_cap(): void
    {
        config(['llm-client.delegation.combined_output_cap_bytes' => 200]);

        $runId = $this->makeRun($this->user);

        $this->makeDelegationRow($this->user, $runId, [
            'result_status' => 'success',
            'result_summary' => 'Extracted the line items.',
            'result_output' => json_encode(['line_items' => ['Widget A with a fairly long descriptive name', 'Widget B with a fairly long descriptive name']]),
            'result_undone' => '',
        ]);
        $this->makeDelegationRow($this->user, $runId, [
            'result_status' => 'success',
            'result_summary' => 'Normalized the currency.',
            'result_output' => json_encode(['currency' => 'USD', 'exchange_rate' => '1.08432', 'source' => 'central-bank-feed']),
            'result_undone' => '',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->combinedResultsUrl($runId));

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertTrue(
            $body['truncated'],
            'GET /agent-runs/{runId}/combined-results must reflect truncated: true when the assembled combined view exceeds the configured combined_output_cap_bytes',
        );
    }

    #[Test]
    public function get_delegation_surfaces_the_raw_truncated_result_output_string_rather_than_null(): void
    {
        config(['llm-client.delegation.result_output_cap_bytes' => 50]);

        $parent = $this->makeAgent($this->user, 'parent-truncated-output');
        $helper = $this->makeAgent($this->user, 'helper-truncated-output');
        $this->assignHelper($this->user, $parent->id, $helper->id);
        $conversation = $this->makeConversation($this->user, $parent);

        $largeOutput = ['line_items' => array_fill(0, 10, 'Widget with a fairly long descriptive name')];
        $this->assertGreaterThan(
            50,
            strlen(json_encode($largeOutput)),
            'fixture sanity: the encoded output must actually exceed the configured 50-byte cap',
        );

        $mock = Mockery::mock(AgentLoopService::class);
        $mock->shouldReceive('run')->once()->andReturn([
            'status' => 'completed',
            'content' => json_encode($largeOutput),
            'validated' => [
                'status' => 'success',
                'summary' => 'Extracted all line items.',
                'output' => $largeOutput,
                'undone' => '',
            ],
            'message_id' => null,
        ]);
        $this->app->instance(AgentLoopService::class, $mock);

        app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $delegation = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegation, 'fixture sanity: the delegation must still write a Delegation row');
        $this->assertTrue($delegation->result_truncated, 'fixture sanity: the stored result_output must actually have been truncated');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/delegations/{$delegation->id}");

        $response->assertStatus(200);

        $row = $response->json();
        $this->assertTrue($row['result_truncated']);
        $this->assertNotNull(
            $row['result_output'],
            'a truncated (non-JSON) result_output must surface as the raw truncated string, not be silently swallowed to null by json_decode()',
        );
        $this->assertIsString(
            $row['result_output'],
            'a truncated result_output fails json_decode() (cut mid-object plus a non-JSON trailing marker), so the defensive fallback must return the raw string, not a decoded array',
        );
        $this->assertStringEndsWith(
            "\n\n[TRUNCATED: original content exceeded cap]",
            $row['result_output'],
        );
    }
}
