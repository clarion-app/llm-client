<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
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
 * 098-delegation-protocol, Phase 5 (US4), tasks.md T038.
 *
 * The full HTTP-adjacent journey for a delegation that never produces a
 * final answer on its own and must instead be cut off by one of the two
 * configured bounds (research.md D3) -- iteration ceiling
 * (`delegation.max_iterations`, quickstart scenario 5) or wall-clock
 * deadline (`delegation.max_seconds`, quickstart scenario 6). Mirrors
 * DelegationJourneyTest.php's own established scripted-`LlmProvider`
 * driving pattern (never Http::fake()).
 *
 * Written before `AgentLoopService::run()` reads either `$options` key
 * (T040) or `DelegationService::delegate()` maps a ceiling-reached return
 * to a terminal `Delegation.status: 'exhausted'` (T041) -- every test
 * below is expected to FAIL: `delegate()`'s current, unmodified return
 * statement is unconditional (`'status' => 'completed'`, always), so the
 * `delegate_to_helper` tool result never reports `status: "exhausted"`
 * for any reason, no matter how the nested run actually concluded.
 */
class DelegationBoundExhaustionTest extends TestCase
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
        DB::table('messages')->delete();
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

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function assignHelper(string $parentAgentId, string $helperAgentId): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson($this->helpersUrl($parentAgentId), ['helper_agent_id' => $helperAgentId])
            ->assertStatus(201);
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
    // Quickstart scenario 5 -- the per-delegation ITERATION ceiling
    // =================================================================

    #[Test]
    public function a_delegation_exhausting_its_iteration_ceiling_reports_status_exhausted_with_incomplete_because_iteration_limit(): void
    {
        // Both configured to the same low value: `delegation.max_iterations`
        // is what T041 will actually thread through as the nested run's
        // own override, while `agent_loop.max_iterations` is the plain,
        // pre-existing ceiling that governs both the parent's own outer
        // loop AND (today, since the override does not exist yet) the
        // nested helper loop too -- keeping this test's own provider
        // script deterministic in both the current and the implemented
        // state.
        config(['llm-client.delegation.max_iterations' => 2]);
        config(['llm-client.agent_loop.max_iterations' => 2]);

        $parent = $this->makeAgent('parent-agent-exhaustion-iterations');
        $helper = $this->makeAgent('helper-agent-exhaustion-iterations');
        $this->assignHelper($parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        $service = $this->serviceWithScriptedProvider([
            // Parent's own iteration 1: delegate immediately.
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $helper->id,
                    'task' => 'Keep working without ever finishing.',
                    'context' => null,
                ], 'call_delegate_exhaustion'),
            ]),
            // Helper's own nested run: two rounds of tool calls, NEVER a
            // final answer -- runs the 2-iteration ceiling all the way out.
            $this->toolCallReply([$this->toolCall('list_applications', [], 'call_helper_1')]),
            $this->toolCallReply([$this->toolCall('list_applications', [], 'call_helper_2')]),
            // Parent's own iteration 2 (its last, under the same ceiling):
            // a normal final reply once the tool result comes back.
            $this->plainReply('The helper could not finish in time, but here is what I have.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Please delegate this endless task.');
        $this->assertSame('completed', $result['status'], 'the parent turn itself must still resolve normally even though the delegated work was exhausted');

        $decoded = $this->firstToolResult($conversation);
        $this->assertNotNull($decoded, 'fixture sanity: the delegating iteration must have produced a tool result');

        $this->assertSame('exhausted', $decoded['status'] ?? null, 'the delegate_to_helper tool result must report status: exhausted once the iteration ceiling is reached');
        $this->assertSame($helper->name, $decoded['helper'] ?? null);
        $this->assertArrayHasKey('partial_result', $decoded);
        $this->assertSame('iteration_limit', $decoded['incomplete_because'] ?? null);

        $delegationRow = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegationRow);
        $this->assertSame('exhausted', $delegationRow->status, 'the Delegation row itself must record the exhausted status');
    }

    // =================================================================
    // Quickstart scenario 6 -- the per-delegation wall-clock DEADLINE
    // =================================================================

    #[Test]
    public function a_delegation_exhausting_its_wall_clock_deadline_reports_status_exhausted_with_incomplete_because_time_limit(): void
    {
        // A generous iteration allowance on both axes -- ONLY the tight
        // 1-second delegation deadline may plausibly stop this nested
        // run once T040/T041 are implemented; today (deadline unread),
        // nothing but this generous iteration ceiling can stop it at all.
        config(['llm-client.delegation.max_iterations' => 50]);
        config(['llm-client.delegation.max_seconds' => 1]);
        config(['llm-client.agent_loop.max_iterations' => 20]);

        $parent = $this->makeAgent('parent-agent-exhaustion-time');
        $helper = $this->makeAgent('helper-agent-exhaustion-time');
        $this->assignHelper($parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        Carbon::setTestNow(Carbon::now());

        $callCount = 0;
        $service = $this->serviceWithScriptedProvider(function () use (&$callCount, $helper) {
            $callCount++;

            if ($callCount === 1) {
                // Parent's own first iteration: delegate immediately.
                return $this->toolCallReply([
                    $this->toolCall('delegate_to_helper', [
                        'helper_agent_id' => $helper->id,
                        'task' => 'Keep working past the wall-clock deadline.',
                        'context' => null,
                    ], 'call_delegate_time_exhaustion'),
                ]);
            }

            if ($callCount <= 6) {
                // Every one of these calls advances the faked clock
                // well past the 1-second deadline -- a correct
                // implementation's nested helper run must stop after
                // just one or two of these, long before this generous
                // cap of 5 further calls is ever reached. Whichever
                // loop (helper, still delegating, or -- once the tool
                // result is back -- the parent's own outer loop) is
                // actually asking gets an inert tool call it can
                // execute for free (list_applications), never a final
                // answer, so nothing can complete "by accident" within
                // this window.
                Carbon::setTestNow(Carbon::now()->addSeconds(2));

                return $this->toolCallReply([$this->toolCall('list_applications', [], 'call_'.$callCount)]);
            }

            return $this->plainReply('Continuing after the delegated work was cut short.');
        });
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Please delegate this task that will run past its deadline.');
        $this->assertSame('completed', $result['status'], 'the parent turn itself must still resolve normally even though the delegated work was exhausted');

        $decoded = $this->firstToolResult($conversation);
        $this->assertNotNull($decoded, 'fixture sanity: the delegating iteration must have produced a tool result');

        $this->assertSame('exhausted', $decoded['status'] ?? null, 'the delegate_to_helper tool result must report status: exhausted once the wall-clock deadline is reached');
        $this->assertSame($helper->name, $decoded['helper'] ?? null);
        $this->assertArrayHasKey('partial_result', $decoded);
        $this->assertSame('time_limit', $decoded['incomplete_because'] ?? null, 'a wall-clock deadline stop must report incomplete_because: time_limit, distinct from an iteration-ceiling stop');

        $delegationRow = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegationRow);
        $this->assertSame('exhausted', $delegationRow->status, 'the Delegation row itself must record the exhausted status');
    }
}
