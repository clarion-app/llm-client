<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ConversationHandoff;
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
 * 102-router-pattern, Phase 7 (Polish, T056).
 *
 * FR-014/SC-008, research.md D10 — the "zero new code" attribution claim.
 * Every prior phase's own journey tests incidentally asserted attribution
 * as a side effect of proving routing/disclosure behavior; this file is
 * the dedicated, direct proof that Message's existing `creating` listener
 * (093, Message.php L61-66 — reads ConversationHandoff::currentAgentIdentityFor()
 * only when agent_id is not already explicitly set) and
 * ConversationHandoff::currentAgentIdentityFor() correctly cover every
 * agent-identity-changing path this feature adds, with zero new
 * attribution code written anywhere in this feature (RouterService,
 * AgentLoopService's new routing methods, and AgentService's default-
 * handler methods touch conversations.agent_id/agent_version_id and
 * conversation_handoffs rows only — never Message directly).
 *
 * Mirrors AutomaticRoutingJourneyTest.php's, RouterDefaultFallbackJourneyTest.php's,
 * MidConversationReassignmentJourneyTest.php's, and
 * SpecialistUnavailableMidConversationJourneyTest.php's own fixture/
 * scripted-provider scaffolding verbatim — this file drives the same four
 * production code paths those files already prove behaviorally correct,
 * but asserts ONLY on the produced Message row's agent_id/agent_version_id,
 * never on conversations.routing_reason or disclosure wording.
 *
 * Written after every implementation phase (3-6) is complete — every case
 * in this file is expected to be GREEN already, since it exercises no new
 * production code path. A red result here would mean D10's claim is wrong,
 * not that a task is still outstanding.
 */
class RouterAttributionJourneyTest extends TestCase
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

        DB::table('conversation_handoffs')->delete();
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
    // Fixtures
    // -----------------------------------------------------------------

    private function makeAgent(string $name, string $instructions): Agent
    {
        return app(AgentService::class)->create(
            $this->user->id,
            "name: {$name}\ninstructions: {$instructions}",
        );
    }

    private function makeUnboundConversation(string $userId): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $userId,
            'server_id' => $this->server->id,
            'title' => 'Already titled',
            'agent_id' => null,
            'agent_version_id' => null,
        ]);
    }

    private function makeConversation(Agent $agent, string $userId): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $userId,
            'server_id' => $this->server->id,
            'title' => 'Already titled',
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);
    }

    private function markAsDefaultHandler(Agent $agent): void
    {
        DB::table('agents')->where('id', $agent->id)->update(['is_default_handler' => true]);
    }

    /** @return array<string, mixed> */
    private function handoff(Conversation $conversation, string $targetAgentId): array
    {
        $result = app(AgentLoopService::class)->executeMetaTool(
            'handoff_to_agent',
            ['agent_id' => $targetAgentId],
            $conversation,
        );

        return json_decode($result, true);
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
    // 1. Automatic routing decision — the produced Message carries the
    //    actual handling specialist's agent_id/agent_version_id, matching
    //    conversations.agent_id/agent_version_id after routing fires.
    // =================================================================

    #[Test]
    public function a_message_produced_after_an_automatic_routing_decision_carries_the_actual_handling_specialists_identity(): void
    {
        $billingAgent = $this->makeAgent('billing-agent', 'Handles billing invoices, payment questions, and account charges.');
        $technicalAgent = $this->makeAgent('technical-agent', 'Handles technical software bugs, crashes, and system errors.');

        $conversation = $this->makeUnboundConversation($this->user->id);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Sure, I can help with your billing invoice.'),
        ]);

        $result = $service->run(
            $conversation->fresh(),
            'I have a question about my billing invoice and a payment that was charged twice.',
        );

        $this->assertSame('completed', $result['status']);

        $conversation = $conversation->fresh();
        $this->assertSame('automatic', $conversation->routing_reason, 'fixture sanity: routing must actually have fired');
        $this->assertSame($billingAgent->id, $conversation->agent_id, 'fixture sanity: automatic routing must have bound the billing specialist');

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertSame(
            $conversation->agent_id,
            $message->agent_id,
            'the produced Message must carry the SAME agent_id the routing decision bound onto the conversation',
        );
        $this->assertSame(
            $conversation->agent_version_id,
            $message->agent_version_id,
            'the produced Message must carry the SAME agent_version_id the routing decision bound onto the conversation',
        );
        $this->assertSame($billingAgent->id, $message->agent_id);
        $this->assertNotSame($technicalAgent->id, $message->agent_id, 'attribution must never fall to the non-handling specialist');
    }

    // =================================================================
    // 2. Default-handler fallback — the produced Message carries the
    //    default's own identity.
    // =================================================================

    #[Test]
    public function a_message_produced_after_a_default_handler_fallback_carries_the_defaults_own_identity(): void
    {
        $billingAgent = $this->makeAgent('billing-agent', 'Handles billing invoices, payment questions, and account charges.');
        $generalAgent = $this->makeAgent('general-agent', 'A general-purpose fallback assistant with no specific topic focus.');
        $this->markAsDefaultHandler($generalAgent);

        $conversation = $this->makeUnboundConversation($this->user->id);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Happy to help with whatever you need.'),
        ]);

        $result = $service->run(
            $conversation->fresh(),
            'What time does the movie start tonight?',
        );

        $this->assertSame('completed', $result['status']);

        $conversation = $conversation->fresh();
        $this->assertSame('default', $conversation->routing_reason, 'fixture sanity: the default-handler fallback must actually have fired');
        $this->assertSame($generalAgent->id, $conversation->agent_id, 'fixture sanity: the default handler must be bound');

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertSame(
            $generalAgent->id,
            $message->agent_id,
            'the produced Message must carry the default handler\'s own identity',
        );
        $this->assertSame($generalAgent->current_version_id, $message->agent_version_id);
        $this->assertNotSame($billingAgent->id, $message->agent_id, 'attribution must never fall to an unrelated, never-matched specialist');
    }

    // =================================================================
    // 3. Mid-conversation reassignment via a user-correction-driven
    //    handoff_to_agent tool call — the produced Message carries the
    //    NEW agent's identity, never the original.
    // =================================================================

    #[Test]
    public function a_message_produced_after_a_user_correction_driven_handoff_carries_the_new_agents_identity_never_the_original(): void
    {
        $agentA = $this->makeAgent('agent-a', 'Handles general questions.');
        $agentB = $this->makeAgent('billing-agent', 'Handles billing invoices and payment disputes.');

        $conversation = $this->makeConversation($agentA, $this->user->id);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Hello, I need some help today.',
            'responseTime' => 0,
        ]);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('handoff_to_agent', ['agent_id' => $agentB->id], 'call_1'),
            ]),
            $this->plainReply('Sure, I can help with your billing question.'),
        ]);

        $result = $service->run(
            $conversation->fresh(),
            "Actually, you're not the right agent for this -- I need the billing specialist instead.",
        );

        $this->assertSame('completed', $result['status']);

        $row = ConversationHandoff::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($row, 'fixture sanity: the correction must have written a handoff row');
        $this->assertSame($agentB->id, $row->to_agent_id);
        $this->assertNull($row->reason, 'fixture sanity: an ordinary, user/agent-initiated handoff must carry no reason tag');

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertSame(
            $agentB->id,
            $message->agent_id,
            'the produced Message must carry the NEW specialist\'s identity after a user-correction-driven handoff',
        );
        $this->assertSame($agentB->current_version_id, $message->agent_version_id);
        $this->assertNotSame($agentA->id, $message->agent_id, 'the produced Message must never carry the ORIGINAL specialist\'s identity once reassigned');
    }

    // =================================================================
    // 4. Mid-conversation reassignment via the D7 automatic-unavailability
    //    fallback — the produced Message carries the NEW agent's identity,
    //    never the deactivated original.
    // =================================================================

    #[Test]
    public function a_message_produced_after_an_automatic_unavailability_fallback_carries_the_new_agents_identity_never_the_deactivated_original(): void
    {
        $agentA = $this->makeAgent('agent-a', 'I am agent A, the original specialist.');
        $agentB = $this->makeAgent('agent-b', 'I am agent B, the only other active specialist.');

        $conversation = $this->makeConversation($agentA, $this->user->id);

        // Turn 1: agent A answers normally.
        $firstService = $this->serviceWithScriptedProvider([
            $this->plainReply('First reply, from agent A.'),
        ]);
        $firstResult = $firstService->run($conversation->fresh(), 'My first question.');
        $this->assertSame('completed', $firstResult['status']);
        $firstMessage = Message::find($firstResult['message_id']);
        $this->assertSame($agentA->id, $firstMessage->agent_id, 'fixture sanity: the first reply must be attributed to agent A');

        // Agent A becomes unavailable mid-conversation.
        app(AgentService::class)->deactivate($agentA->fresh(), true);
        $this->assertFalse($agentA->fresh()->is_active, 'fixture sanity: agent A must actually be deactivated');

        // Turn 2: the automatic fallback takes over.
        $secondService = $this->serviceWithScriptedProvider([
            $this->plainReply('Second reply, from whichever fallback took over.'),
        ]);
        $secondResult = $secondService->run($conversation->fresh(), 'My second question, after my specialist went away.');
        $this->assertSame('completed', $secondResult['status']);

        $row = ConversationHandoff::where('conversation_id', $conversation->id)->orderByDesc('position')->first();
        $this->assertNotNull($row, 'fixture sanity: the automatic fallback must have written a handoff row');
        $this->assertSame('unavailable', $row->reason, 'fixture sanity: the fallback row must be the automatic-unavailability kind');
        $this->assertSame($agentB->id, $row->to_agent_id);

        $secondMessage = Message::find($secondResult['message_id']);
        $this->assertNotNull($secondMessage);
        $this->assertSame(
            $agentB->id,
            $secondMessage->agent_id,
            'the produced Message must carry the NEW fallback specialist\'s identity after an automatic unavailability reassignment',
        );
        $this->assertSame($agentB->current_version_id, $secondMessage->agent_version_id);
        $this->assertNotSame(
            $agentA->id,
            $secondMessage->agent_id,
            'the produced Message must never carry the deactivated ORIGINAL specialist\'s identity once reassigned',
        );
    }
}
