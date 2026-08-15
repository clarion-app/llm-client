<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
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
 * 102-router-pattern, Phase 4 (US2, T029).
 *
 * spec.md US2 Acceptance Scenarios 2-3, FR-002/FR-015, SC-004,
 * contracts/routing-mechanism.md (ConversationController::store()'s
 * pre-existing explicit-agent branch, L93-110, plus the new
 * routing_reason = 'explicit' write T034 adds immediately after it).
 *
 * No equivalent file named ExplicitAgentOverrideJourneyTest.php exists yet
 * (checked: only ConversationRecordsBoundAgentVersionJourneyTest.php, from
 * 090, covers POST /conversation's explicit agent_id path, and it does not
 * assert on routing_reason at all — this is a new file, not an extension).
 *
 * naming_a_specialist_explicitly_bypasses_automatic_routing... and
 * an_explicitly_named_agent_records_routing_reason_explicit are expected to
 * FAIL red: the former only because routing_reason stays null instead of
 * 'explicit' isn't asserted there directly, but attemptInitialRouting()
 * itself is already a correct no-op today whenever agent_id is set at
 * creation (Phase 3), so that first case is expected to already PASS — it
 * proves bypass, not the new write. an_explicitly_named_agent_records_routing_reason_explicit
 * is the one genuinely red case: ConversationController::store() does not
 * yet set routing_reason on the explicit-agent branch (T034's own job).
 * naming_a_nonexistent_agent_still_returns_agent_not_found and
 * naming_a_deactivated_agent_still_returns_agent_deactivated are expected to
 * already PASS — FR-015's rejection behavior needs no code change (tasks.md
 * §8), and this proves it, not merely asserts it.
 */
class ExplicitAgentOverrideJourneyTest extends TestCase
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

        // executeApiCall()'s own getOrCreateSession() needs an MCP session
        // row — AutomaticRoutingJourneyTest's/AgentHandoffDisclosureJourneyTest's
        // own established precedent for this exact table.
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

        // buildMessagesPayload()/applyContextWindowTrim() (both in the
        // run()/start() funnel) read these tables regardless of whether
        // auto-memory retrieval or condensation ever actually triggers.
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

    // -----------------------------------------------------------------
    // Operation-catalog scaffolding
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
    // Scripted-provider scaffolding (AutomaticRoutingJourneyTest precedent)
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
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
    }

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
    }

    // =================================================================
    // 1. Naming a specialist explicitly bypasses automatic routing, even
    //    for a message shaped to clearly favor a different specialist.
    // =================================================================

    #[Test]
    public function naming_a_specialist_explicitly_bypasses_automatic_routing_even_when_it_would_not_win(): void
    {
        $technicalAgent = app(AgentService::class)->create(
            $this->user->id,
            "name: technical-agent\ninstructions: Handles technical software bugs, crashes, and system errors.",
        );
        $billingAgent = app(AgentService::class)->create(
            $this->user->id,
            "name: billing-agent\ninstructions: Handles billing invoices, payment questions, and account charges.",
        );

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $technicalAgent->id,
            'server_id' => $this->server->id,
            'model' => 'gpt-4o',
            // Pre-set so run()'s own title-generation dispatch is never
            // triggered — AgentHandoffDisclosureJourneyTest's/
            // AutomaticRoutingJourneyTest's own established precedent; the
            // SyncQueue driver would otherwise execute
            // OpenAIGenerateConversationTitleRequest inline and attempt a
            // real HTTP call, unrelated to what this test is about.
            'title' => 'Already titled',
        ]);

        $response->assertStatus(201);

        $conversationId = $response->json('id');
        $conversation = Conversation::find($conversationId);
        $this->assertNotNull($conversation);
        $this->assertSame(
            $technicalAgent->id,
            $conversation->agent_id,
            'fixture sanity: the conversation must be bound to the explicitly-named technical agent at creation',
        );

        // The first message sent is billing-shaped — the message that, left
        // to automatic routing (Phase 3's RouterService), would clearly
        // match the billing specialist instead. attemptInitialRouting()'s
        // own precondition (agent_id === null) already makes this a no-op
        // once agent_id is bound at creation, so the technical agent must
        // still be the one that handles it.
        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Sure, I can look into that billing invoice.'),
        ]);

        $result = $service->run(
            $conversation->fresh(),
            'I have a question about my billing invoice and a payment that was charged twice.',
        );

        $this->assertSame('completed', $result['status']);

        $conversation = $conversation->fresh();
        $this->assertSame(
            $technicalAgent->id,
            $conversation->agent_id,
            'the explicitly-named agent must still handle the conversation, even for a message that would automatically route to a different specialist',
        );
        $this->assertNotSame($billingAgent->id, $conversation->agent_id);

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertSame($technicalAgent->id, $message->agent_id);
        $this->assertSame($technicalAgent->current_version_id, $message->agent_version_id);
    }

    // =================================================================
    // 2. The created conversation's routing_reason === 'explicit'
    //    (T034/Phase 4 Implementation's own write — expected red here).
    // =================================================================

    #[Test]
    public function an_explicitly_named_agent_records_routing_reason_explicit(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: some-agent\ninstructions: Does things.");

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $this->server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(201);

        $conversationId = $response->json('id');
        $conversation = Conversation::find($conversationId);
        $this->assertNotNull($conversation);
        $this->assertSame(
            'explicit',
            $conversation->routing_reason,
            'conversations.routing_reason must read "explicit" whenever the conversation was created with an explicit agent_id',
        );
    }

    // =================================================================
    // 3. Naming a nonexistent/inaccessible agent → unchanged rejection.
    // =================================================================

    #[Test]
    public function naming_a_nonexistent_agent_still_returns_agent_not_found_and_writes_no_conversation(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => '11111111-1111-1111-1111-111111111111',
            'server_id' => $this->server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(404);
        $response->assertExactJson([
            'error' => 'Agent not found',
            'code' => 'agent_not_found',
        ]);
        $this->assertSame(0, Conversation::count(), 'no Conversation row must be written, even partially, on a 404 refusal');
    }

    #[Test]
    public function naming_a_deactivated_agent_still_returns_agent_deactivated_and_writes_no_conversation(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: retiring-agent\ninstructions: About to be deactivated.");
        app(AgentService::class)->deactivate($agent, true);

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $this->server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'agent_deactivated');
        $this->assertSame(0, Conversation::count(), 'no Conversation row must be written, even partially, on a 409 refusal');
    }
}
