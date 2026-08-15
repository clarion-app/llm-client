<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ConversationHandoff;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\ConversationAgentDefinitionResolver;
use ClarionApp\LlmClient\Services\DegradationGate;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RateLimitGate;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 102-router-pattern, Phase 5 (US3), tasks.md T037.
 *
 * spec.md US3 Acceptance Scenarios 1-3, FR-006/FR-007/FR-008/FR-010,
 * SC-005, contracts/routing-mechanism.md §7.
 *
 * Mirrors AgentHandoffJourneyTest.php's own setUp()/tearDown()/
 * makeConversation()/handoff() scaffolding, and
 * RoutingDisclosureSyncAndStreamJourneyTest.php's/
 * AgentHandoffDisclosureJourneyTest.php's own scripted-provider and
 * streamed-finish() driving style.
 *
 * Written before AgentLoopService::buildKnownSpecialistsSection() exists
 * and before its call site is wired into buildMessagesPayload() (T038/
 * T039, this same phase's Implementation subsection). Cases 1 and 2 are
 * expected to FAIL red for that reason: case 1 because buildMessagesPayload()
 * never emits a "## Known Specialists" section yet, case 2 because the
 * private method it reflects into does not exist at all (a
 * ReflectionException, not a plain assertion failure). Cases 3, 4, and 5
 * drive ONLY the pre-existing, unmodified handoff_to_agent mechanism (093)
 * -- nothing in this feature changes handleHandoffToAgent(),
 * composeHandoffDisclosure(), or AgentLoopStreamHandler::finish() itself --
 * so they may already pass before T038/T039 land; if so, that is a
 * legitimate outcome, not a bug in these tests.
 */
class MidConversationReassignmentJourneyTest extends TestCase
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
        // row -- AgentHandoffJourneyTest's/RoutingDisclosureSyncAndStreamJourneyTest's
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
        // run()/resumeSync()/start() funnel) read these tables regardless
        // of whether auto-memory retrieval or condensation ever actually
        // triggers.
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
    // Operation-catalog scaffolding (AgentHandoffJourneyTest precedent)
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

    private function makeAgent(string $name, string $instructions): Agent
    {
        return app(AgentService::class)->create(
            $this->user->id,
            "name: {$name}\ninstructions: {$instructions}",
        );
    }

    private function makeConversation(?Agent $agent, string $userId): Conversation
    {
        // 'title' is pre-set (AgentHandoffDisclosureJourneyTest's own
        // precedent) so run()'s own title-generation dispatch is never
        // triggered.
        return Conversation::factory()->create([
            'user_id' => $userId,
            'server_id' => $this->server->id,
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    /**
     * The direct, non-HTTP dispatch precedent this package already uses for
     * testing handoff_to_agent (AgentHandoffJourneyTest's own helper) --
     * used here only to simulate the EFFECT of a prior tool-call turn that
     * already ran (case 5, mirroring AgentHandoffDisclosureJourneyTest's own
     * streamed precedent, where the tool-call turn and the final-text turn
     * are two separate stream steps).
     *
     * @return array<string, mixed>
     */
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
    // Reflection invoker for the new private method (case 2) -- mirrors
    // AgentLoopServiceCombinedResultsSectionTest's own invoke() precedent
    // for a system-prompt section builder that is not part of the class's
    // public surface.
    // -----------------------------------------------------------------

    private function invokeBuildKnownSpecialistsSection(AgentLoopService $service, Conversation $conversation): ?string
    {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildKnownSpecialistsSection');
        $method->setAccessible(true);

        return $method->invoke($service, $conversation);
    }

    // -----------------------------------------------------------------
    // Scripted-provider scaffolding (AgentHandoffDisclosureJourneyTest/
    // DelegationJourneyTest precedent) -- the synchronous path.
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

    // -----------------------------------------------------------------
    // Streaming scaffolding (AgentHandoffDisclosureJourneyTest precedent)
    // -----------------------------------------------------------------

    private function admitAndOpenStreamedRun(Conversation $conversation): string
    {
        $rateLimitDecision = app(RateLimitGate::class)->admit(
            (string) $conversation->user_id,
            BudgetWorkKind::Interactive,
            $conversation->id,
        );
        $budgetDecision = app(BudgetGate::class)->admit(
            (string) $conversation->user_id,
            BudgetWorkKind::Interactive,
            $conversation->id,
        );
        app(DegradationGate::class)->evaluate(
            (string) $conversation->user_id,
            $conversation->id,
            $rateLimitDecision,
            $budgetDecision,
        );

        $runId = app(RunTraceRecorder::class)->openRun(
            RunKind::Interactive,
            (string) $conversation->user_id,
            $conversation->id,
            streamed: true,
            model: $conversation->model,
            agentId: $conversation->character ?? $conversation->id,
        );

        $this->assertNotNull($runId, 'run tracing must be enabled for this test to exercise the streamed path');

        return $runId;
    }

    private function runStreamedFinish(Conversation $conversation, string $runId, string $reply): Message
    {
        Event::fake([FinishOpenAIConversationResponseEvent::class]);

        $recorder = app(RunTraceRecorder::class);
        $stepId = $recorder->openStep($runId, 1, (string) \Illuminate\Support\Str::uuid());

        $handler = new AgentLoopStreamHandler(null, null, $recorder);
        $handler->toolCalls = [];
        $handler->reply = $reply;
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
            'run_id' => $runId,
            'step_id' => $stepId,
        ]);

        $handler->finish($data, 2);

        $handler->message->refresh();

        return $handler->message;
    }

    // =================================================================
    // 1. buildMessagesPayload() includes a "## Known Specialists" section
    //    listing every OTHER owned/active agent, excluding the currently-
    //    assigned one (FR-006, contracts §7).
    // =================================================================

    #[Test]
    public function build_messages_payload_includes_a_known_specialists_section_listing_every_other_owned_active_agent_and_excludes_the_current_one(): void
    {
        $agentA = $this->makeAgent('agent-a', 'Handles general questions.');
        $agentB = $this->makeAgent('billing-agent', 'Handles billing invoices and payment disputes.');
        $agentC = $this->makeAgent('shipping-agent', 'Handles shipping and delivery tracking.');

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $messages = app(AgentLoopService::class)->buildMessagesPayload($conversation);

        $systemMsg = collect($messages)->firstWhere('role', 'system');
        $this->assertNotNull($systemMsg, 'buildMessagesPayload() must emit a system message');

        $content = $systemMsg['content'];

        $this->assertStringContainsString(
            '## Known Specialists',
            $content,
            'the system prompt must carry a Known Specialists section when the caller owns other active agents besides the one already assigned',
        );

        $this->assertStringContainsString(
            "**{$agentB->id}** — billing-agent",
            $content,
            'every OTHER owned/active agent must be listed by id and name',
        );
        $this->assertStringContainsString('Handles billing invoices and payment disputes.', $content);

        $this->assertStringContainsString(
            "**{$agentC->id}** — shipping-agent",
            $content,
        );
        $this->assertStringContainsString('Handles shipping and delivery tracking.', $content);

        $this->assertStringNotContainsString(
            "**{$agentA->id}** — agent-a",
            $content,
            'the currently-assigned agent must be excluded from its own Known Specialists section',
        );
    }

    // =================================================================
    // 2. buildKnownSpecialistsSection() returns null when the caller has
    //    no other owned/active agent besides the one already assigned.
    // =================================================================

    #[Test]
    public function build_known_specialists_section_returns_null_when_there_are_no_other_owned_active_agents(): void
    {
        $agentA = $this->makeAgent('agent-a', 'Handles general questions.');
        $conversation = $this->makeConversation($agentA, $this->user->id);

        $section = $this->invokeBuildKnownSpecialistsSection(app(AgentLoopService::class), $conversation);

        $this->assertNull(
            $section,
            'buildKnownSpecialistsSection() must return null when the caller owns no other active agent besides the one already assigned to this conversation',
        );
    }

    // =================================================================
    // 3. A user-correction-shaped follow-up, with the acting agent's own
    //    tool-call response naming a target id, reassigns the conversation
    //    via handoff_to_agent -- history intact, disclosed in the same
    //    turn (US3 AC1/AC2, FR-007, FR-008, SC-005).
    // =================================================================

    #[Test]
    public function a_user_correction_shaped_follow_up_reassigns_the_conversation_to_the_named_specialist_via_handoff_to_agent(): void
    {
        $agentA = $this->makeAgent('agent-a', 'Handles general questions.');
        $agentB = $this->makeAgent('billing-agent', 'Handles billing invoices and payment disputes.');

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $priorMessage = Message::create([
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
        $this->assertNotNull($row, 'a tool-call-driven reassignment must write a ConversationHandoff row exactly like a directly-invoked handoff');
        $this->assertSame($agentB->id, $row->to_agent_id);

        $reread = Message::find($priorMessage->id);
        $this->assertNotNull($reread);
        $this->assertSame(
            'Hello, I need some help today.',
            $reread->content,
            'prior message history must remain fully readable after the reassignment',
        );

        $this->assertStringContainsString(
            'handed off to "billing-agent"',
            $result['content'],
            'the user must be told, within this same follow-up turn, that the conversation moved and to whom',
        );

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertStringContainsString('handed off to "billing-agent"', $message->content);

        $definition = app(ConversationAgentDefinitionResolver::class)->effectiveDefinitionFor($conversation->fresh());
        $this->assertNotNull($definition);
        $this->assertSame(
            'Handles billing invoices and payment disputes.',
            $definition->instructions,
            'the conversation must now be governed by the newly assigned specialist',
        );
    }

    // =================================================================
    // 4. A recognized topic-change-shaped follow-up produces the identical
    //    reassignment behavior via the same mechanism (US3 AC3, FR-010).
    // =================================================================

    #[Test]
    public function a_recognized_topic_change_shaped_follow_up_produces_the_identical_reassignment_via_the_same_mechanism(): void
    {
        $agentA = $this->makeAgent('agent-a', 'Handles general questions.');
        $agentC = $this->makeAgent('shipping-agent', 'Handles shipping and delivery tracking.');

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $priorMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'What are your hours today?',
            'responseTime' => 0,
        ]);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('handoff_to_agent', ['agent_id' => $agentC->id], 'call_1'),
            ]),
            $this->plainReply('I can help you track your shipment.'),
        ]);

        $result = $service->run(
            $conversation->fresh(),
            'Actually, completely different topic now -- where is my package? Can you track my shipment?',
        );

        $this->assertSame('completed', $result['status']);

        $row = ConversationHandoff::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($row, 'a topic-change-shaped follow-up must trigger the identical ConversationHandoff write as a correction-shaped one');
        $this->assertSame($agentC->id, $row->to_agent_id);

        $reread = Message::find($priorMessage->id);
        $this->assertNotNull($reread);
        $this->assertSame('What are your hours today?', $reread->content, 'prior message history must remain fully readable after the reassignment');

        $this->assertStringContainsString(
            'handed off to "shipping-agent"',
            $result['content'],
            'the user must be told, within this same follow-up turn, that the conversation moved and to whom',
        );

        $definition = app(ConversationAgentDefinitionResolver::class)->effectiveDefinitionFor($conversation->fresh());
        $this->assertNotNull($definition);
        $this->assertSame('Handles shipping and delivery tracking.', $definition->instructions);
    }

    // =================================================================
    // 5. The same reassignment, triggered on the streaming path
    //    (AgentLoopStreamHandler::finish()), discloses without being lost
    //    or delayed relative to the synchronous case (FR-006/FR-008/FR-010
    //    in streaming) -- mirrors 093's own US2 AC2 streaming assertion.
    // =================================================================

    #[Test]
    public function the_same_reassignment_on_the_streaming_path_discloses_without_being_lost_or_delayed_relative_to_the_synchronous_case(): void
    {
        $agentA = $this->makeAgent('agent-a', 'Handles general questions.');
        $agentB = $this->makeAgent('billing-agent', 'Handles billing invoices and payment disputes.');

        $conversation = $this->makeConversation($agentA, $this->user->id);
        $conversation->update(['is_processing' => true]);

        // Simulates the effect of a prior streamed turn whose tool-call
        // response named handoff_to_agent with the target id -- the actual
        // tool-call dispatch happens in the stream's own tool-execution
        // turn, a separate step from finish()'s own plain-text completion
        // turn (mirrors AgentHandoffDisclosureJourneyTest's own streamed
        // precedent, which drives the handoff the same way).
        $handoffResult = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($handoffResult['success'] ?? false, 'fixture sanity: the handoff itself must succeed');

        $runId = $this->admitAndOpenStreamedRun($conversation->fresh());
        $message = $this->runStreamedFinish($conversation->fresh(), $runId, 'Streamed billing answer.');

        $this->assertStringContainsString(
            'handed off to "billing-agent"',
            $message->content,
            'the streamed finish() completion must carry the identical disclosure sentence shape as the synchronous path, in this same turn -- not lost, not delayed',
        );
        $this->assertStringEndsWith('Streamed billing answer.', $message->content);

        $row = ConversationHandoff::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull(
            $row->disclosed_at,
            'the ConversationHandoff row must be marked disclosed after the streamed finish() call, matching the synchronous path\'s own timing',
        );
    }
}
