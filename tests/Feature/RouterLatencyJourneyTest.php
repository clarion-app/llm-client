<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 102-router-pattern, Phase 3 (US1, T020).
 *
 * SC-003 (routing must add well under 1 second to the first turn),
 * research.md D12, mirroring ForwardingHotPathLatencyTest.php's own
 * comparative "with vs. without" shape (062's own SC-007 precedent):
 * total first-turn latency for a conversation whose agent_id is null at
 * the first run() call (routing must fire) versus an otherwise-identical
 * conversation whose agent_id is already bound (routing is a no-op
 * precondition-check only, per attemptInitialRouting()'s own first guard).
 *
 * Written before RouterService/attemptInitialRouting() and their wiring
 * into run() exist. This file is expected to FAIL — not on the timing
 * assertion itself (added latency would trivially read ~0ms with no
 * routing code running at all, which would make the timing assertion
 * vacuously green), but on a fixture-sanity assertion that the
 * previously-unbound conversation was actually routed to an agent by the
 * first run() call. Without that assertion this file could pass green
 * before any implementation exists, which would defeat its purpose as a
 * red/green gate.
 */
class RouterLatencyJourneyTest extends TestCase
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
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);

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

        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        DB::table('agent_runs')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

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

    private function makeConversation(?\ClarionApp\LlmClient\Models\Agent $agent, string $userId): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $userId,
            'server_id' => $this->server->id,
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
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
    }

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
    }

    #[Test]
    public function routing_adds_well_under_one_second_to_the_first_turn_compared_to_an_already_bound_conversation(): void
    {
        $agentA = app(AgentService::class)->create(
            $this->user->id,
            "name: agent-a\ninstructions: Handles billing invoice and payment matters.",
        );
        app(AgentService::class)->create(
            $this->user->id,
            "name: agent-b\ninstructions: Handles technical software bugs and system errors.",
        );

        $trigger = 'I have a billing invoice and a payment problem that needs sorting out.';

        // Baseline: agent_id already bound at the first run() call —
        // attemptInitialRouting()'s own first precondition
        // ($conversation->agent_id === null) short-circuits immediately,
        // making routing a no-op precondition check only.
        $boundConversation = $this->makeConversation($agentA, $this->user->id);
        $boundService = $this->serviceWithScriptedProvider([
            $this->plainReply('Reply for the already-bound conversation.'),
        ]);

        $startBound = hrtime(true);
        $boundService->run($boundConversation->fresh(), $trigger);
        $boundElapsedMs = (hrtime(true) - $startBound) / 1e6;

        // Comparison: agent_id null at the first run() call — routing must
        // fire.
        $unboundConversation = $this->makeConversation(null, $this->user->id);
        $unboundService = $this->serviceWithScriptedProvider([
            $this->plainReply('Reply for the newly-routed conversation.'),
        ]);

        $startUnbound = hrtime(true);
        $unboundService->run($unboundConversation->fresh(), $trigger);
        $unboundElapsedMs = (hrtime(true) - $startUnbound) / 1e6;

        $unboundConversation = $unboundConversation->fresh();
        $this->assertNotNull(
            $unboundConversation->agent_id,
            'fixture sanity: the previously-unbound conversation must actually have been routed to an agent '
                . 'by the first run() call, or this latency comparison is meaningless',
        );
        $this->assertSame($agentA->id, $unboundConversation->agent_id);

        $addedMs = $unboundElapsedMs - $boundElapsedMs;

        $this->assertLessThan(
            1000.0,
            $addedMs,
            "routing added {$addedMs}ms to the first turn, exceeding SC-003's 1-second ceiling "
                . "(bound-conversation: {$boundElapsedMs}ms, unbound-conversation: {$unboundElapsedMs}ms)",
        );
    }
}
