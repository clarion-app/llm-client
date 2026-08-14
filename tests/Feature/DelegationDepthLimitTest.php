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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 098-delegation-protocol, Phase 5 (US4), tasks.md T039.
 *
 * Quickstart scenario 7: a chain of nested, LIVE delegations -- A
 * delegates to its assigned helper B, and (inside B's own delegated,
 * nested run) B itself delegates to ITS assigned helper C, both of which
 * must succeed at `max_chain_depth = 2` (depth 1, then depth 2, landing
 * exactly at the configured limit). C then attempts to delegate further,
 * to ITS assigned helper D -- computed depth 3, one past the limit -- and
 * that fourth hop must be refused, writing no `Delegation` row at all.
 *
 * Every hop is driven through the real, unmodified `AgentLoopService::run()`
 * against a SINGLE scripted `LlmProvider` double shared across the whole
 * chain (research.md D1) -- since every nested `run()` call in this chain
 * resolves the exact same container-bound `AgentLoopService` instance,
 * one ordered response queue is sufficient to script every level's own
 * turn, in the exact order the chain's own recursive `chat()` calls occur
 * (DelegationJourneyTest.php's own established scripted-provider
 * convention, extended here to more than one nesting level).
 *
 * Written before `DelegationService::delegate()` enforces
 * `max_chain_depth` at all (T042 — today, `depth` is computed and stored,
 * per T018, but never refused on) -- the fourth hop is expected to
 * proceed exactly like any other successful delegation today, so this
 * test is expected to FAIL: the refusal shape never appears, and a
 * `Delegation` row exists for the fourth hop too (3 rows total, not the
 * 2 the finished feature requires).
 */
class DelegationDepthLimitTest extends TestCase
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

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function () use (&$responses) {
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
    // Quickstart scenario 7 -- A -> B -> C -> D, max_chain_depth = 2
    // =================================================================

    #[Test]
    public function a_fourth_delegation_hop_exceeding_the_configured_max_chain_depth_is_refused_writing_no_delegation_row(): void
    {
        config(['llm-client.delegation.max_chain_depth' => 2]);

        $agentA = $this->makeAgent('chain-agent-a');
        $agentB = $this->makeAgent('chain-agent-b');
        $agentC = $this->makeAgent('chain-agent-c');
        $agentD = $this->makeAgent('chain-agent-d');

        // Each is an assigned helper of the one before it (097's own
        // unmodified POST /agents/{id}/helpers).
        $this->assignHelper($agentA->id, $agentB->id);
        $this->assignHelper($agentB->id, $agentC->id);
        $this->assignHelper($agentC->id, $agentD->id);

        $conversationA = $this->makeConversation($agentA);

        // One shared provider double, scripted in the EXACT order the
        // chain's own recursive chat() calls occur -- research.md D1.
        // At most 7 calls are ever needed: 1 (A delegates to B) + 1 (B
        // delegates to C) + 1 (C attempts to delegate to D) + up to 4
        // more finishing replies, however many of A/B/C/D's own loops
        // are still open by the time the chain unwinds. Today (depth
        // unenforced), D's own nested run genuinely executes, consuming
        // one of those slots; once max_chain_depth is enforced, D never
        // runs at all and the corresponding slot is simply never drawn.
        $service = $this->serviceWithScriptedProvider([
            // A's own turn: delegate to B (depth 1).
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $agentB->id,
                    'task' => 'Handle the first hop of this chain.',
                    'context' => null,
                ], 'call_a_to_b'),
            ]),
            // B's own nested turn: delegate to C (depth 2, exactly at
            // the configured limit -- must still succeed).
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $agentC->id,
                    'task' => 'Handle the second hop of this chain.',
                    'context' => null,
                ], 'call_b_to_c'),
            ]),
            // C's own nested turn: attempt to delegate to D (depth 3,
            // one past the limit -- must be refused).
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $agentD->id,
                    'task' => 'Attempt a fourth hop past the depth limit.',
                    'context' => null,
                ], 'call_c_to_d'),
            ]),
            // Consumed by D's own nested turn ONLY if depth is not yet
            // enforced (today); once enforced, this slot is simply
            // never drawn and instead becomes C's own finishing reply.
            $this->plainReply('D completed its work (depth enforcement not yet active).'),
            // C's own finishing reply (once enforced, this is what the
            // slot above actually becomes).
            $this->plainReply("C's final answer, relying on whatever came back from the fourth-hop attempt."),
            // B's own finishing reply.
            $this->plainReply("B's final answer, relying on C's own outcome."),
            // A's own finishing reply.
            $this->plainReply("A's final answer, relying on B's own outcome."),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversationA->fresh(), 'Please handle this chained task.');
        $this->assertSame('completed', $result['status'], 'the top-level turn itself must still resolve normally, however the fourth hop was handled');

        $delegationAB = Delegation::where('parent_conversation_id', $conversationA->id)->first();
        $this->assertNotNull($delegationAB, 'fixture sanity: the first hop (A to B) must have succeeded');
        $this->assertSame(1, $delegationAB->depth);
        $this->assertSame('completed', $delegationAB->status);

        $delegationBC = Delegation::where('parent_conversation_id', $delegationAB->helper_conversation_id)->first();
        $this->assertNotNull($delegationBC, 'fixture sanity: the second hop (B to C), landing EXACTLY at the configured limit of 2, must still have succeeded');
        $this->assertSame(2, $delegationBC->depth);
        $this->assertSame('completed', $delegationBC->status);

        $conversationC = Conversation::find($delegationBC->helper_conversation_id);
        $this->assertNotNull($conversationC);

        $decoded = $this->firstToolResult($conversationC);
        $this->assertNotNull($decoded, 'fixture sanity: C\'s own first iteration must have produced a tool result for its delegate_to_helper(D) attempt');

        $this->assertSame(
            'delegation_depth_exceeded',
            $decoded['error'] ?? null,
            'the fourth hop, computing depth 3 against a configured max_chain_depth of 2, must be refused',
        );

        $this->assertSame(
            2,
            Delegation::count(),
            'no Delegation row may ever be written for the refused fourth hop -- only the two successful hops (A-to-B, B-to-C) may exist',
        );
    }
}
