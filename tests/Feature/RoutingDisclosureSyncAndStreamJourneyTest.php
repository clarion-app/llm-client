<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\BudgetGate;
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
 * 102-router-pattern, Phase 4 (US2, T028).
 *
 * spec.md US2 Acceptance Scenario 1, FR-003, SC-002,
 * contracts/routing-mechanism.md §6.
 *
 * Written before AgentLoopService::composeRoutingDisclosure() exists and
 * before any of its three call sites (run()'s and resumeSync()'s plain-text
 * completion branches, AgentLoopStreamHandler::finish()'s plain-text branch)
 * invoke it. Cases 1-3 and 5 are expected to FAIL red: composeRoutingDisclosure()
 * is undefined (a fatal Error on AgentLoopService), so no disclosure text is
 * ever prepended and routing_disclosed_at is never set. Case 4 (disclosed
 * exactly once) fails on its first assertion for the same reason. Case 6
 * (routing_reason === null produces no disclosure) is expected to already
 * PASS pre-implementation — nothing wires a disclosure call yet, so a plain
 * reply with no routing text is exactly what today's code already produces;
 * it stays green as a regression guard once T030-T033 land.
 *
 * Mirrors AgentHandoffDisclosureJourneyTest.php's own three-site driving
 * style (direct run()/resumeSync() calls, and an AgentLoopStreamHandler
 * instance with public properties set directly then finish() called) and
 * AutomaticRoutingJourneyTest.php's own scaffolding for the operation
 * catalog / mcp_sessions / episodic_memories / condensation_states tables.
 */
class RoutingDisclosureSyncAndStreamJourneyTest extends TestCase
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
        // row — AgentHandoffDisclosureJourneyTest's own established
        // precedent for this exact table.
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
    // Operation-catalog scaffolding (AgentHandoffDisclosureJourneyTest
    // precedent)
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
    // Fixture helper
    // -----------------------------------------------------------------

    /**
     * A conversation already bound to an agent (agent_id !== null), so
     * attemptInitialRouting()'s own precondition never fires and cannot
     * interfere — this file is exclusively about composeRoutingDisclosure(),
     * not about routing itself (that's Phase 3's own AutomaticRoutingJourneyTest).
     */
    private function makeRoutedConversation(Agent $agent, string $userId, ?string $routingReason): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $userId,
            'server_id' => $this->server->id,
            'title' => 'Already titled',
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
            'routing_reason' => $routingReason,
        ]);
    }

    // -----------------------------------------------------------------
    // Scripted-provider scaffolding (AgentHandoffDisclosureJourneyTest
    // precedent) — the synchronous path.
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
    // 1. Synchronous path — AgentLoopService::run()
    // =================================================================

    #[Test]
    public function run_after_automatic_routing_prepends_a_disclosure_naming_the_specialist_and_the_automatic_reason(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: billing-agent\ninstructions: Handles billing invoices.");
        $conversation = $this->makeRoutedConversation($agent, $this->user->id, 'automatic');

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Here is your answer.'),
        ]);

        $result = $service->run($conversation->fresh(), 'I have a billing question.');

        $this->assertSame('completed', $result['status']);
        $this->assertStringContainsString(
            'This conversation is being handled by "billing-agent", automatically matched to your request.',
            $result['content'],
            'run() must prepend the routing disclosure sentence for an automatic-reason conversation',
        );
        $this->assertStringEndsWith(
            'Here is your answer.',
            $result['content'],
            'the original reply must still follow the disclosure, unmodified',
        );

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertStringContainsString(
            'automatically matched to your request',
            $message->content,
            'the persisted assistant Message.content must carry the disclosure permanently',
        );

        $conversation = $conversation->fresh();
        $this->assertNotNull(
            $conversation->routing_disclosed_at,
            'conversations.routing_disclosed_at must be set once the disclosure has fired',
        );
    }

    // =================================================================
    // 2. Synchronous path — AgentLoopService::resumeSync()
    // =================================================================

    #[Test]
    public function resume_sync_after_automatic_routing_discloses_identically_to_run(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: billing-agent\ninstructions: Handles billing invoices.");
        $conversation = $this->makeRoutedConversation($agent, $this->user->id, 'automatic');
        $conversation->update(['is_processing' => true]);

        // Mirrors AgentHandoffDisclosureJourneyTest's own resumeSync()
        // scaffolding — $approved = false so executeApiCall() is never
        // reached.
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'user' => 'Clarion',
            'tool_data' => [
                'tool_calls' => [[
                    'id' => 'call_confirmed',
                    'function' => ['name' => 'execute_operation', 'arguments' => '{}'],
                ]],
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/1',
                    'arguments' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Confirmed and answered.'),
        ]);

        $result = $service->resumeSync($conversation->fresh(), $message, false);

        $this->assertSame('completed', $result['status']);
        $this->assertStringContainsString(
            'This conversation is being handled by "billing-agent", automatically matched to your request.',
            $result['content'] ?? '',
            'resumeSync() must prepend the identical disclosure sentence shape as run()',
        );

        $savedMessage = Message::find($result['message_id'] ?? null);
        $this->assertNotNull($savedMessage);
        $this->assertStringContainsString('automatically matched to your request', $savedMessage->content);

        $conversation = $conversation->fresh();
        $this->assertNotNull($conversation->routing_disclosed_at);
    }

    // =================================================================
    // 3. Streaming path — AgentLoopStreamHandler::finish()
    // =================================================================

    #[Test]
    public function streamed_finish_after_automatic_routing_discloses_not_lost_or_delayed_relative_to_the_synchronous_path(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: billing-agent\ninstructions: Handles billing invoices.");
        $conversation = $this->makeRoutedConversation($agent, $this->user->id, 'automatic');
        $conversation->update(['is_processing' => true]);

        $runId = $this->admitAndOpenStreamedRun($conversation->fresh());
        $message = $this->runStreamedFinish($conversation->fresh(), $runId, 'Streamed answer.');

        $this->assertStringContainsString(
            'This conversation is being handled by "billing-agent", automatically matched to your request.',
            $message->content,
            'the streamed plain-reply branch must carry the identical disclosure sentence shape as the synchronous path',
        );
        $this->assertStringEndsWith('Streamed answer.', $message->content);

        $conversation = $conversation->fresh();
        $this->assertNotNull(
            $conversation->routing_disclosed_at,
            'conversations.routing_disclosed_at must be set after the streamed finish() call',
        );
    }

    // =================================================================
    // 4. Disclosed exactly once
    // =================================================================

    #[Test]
    public function a_disclosed_routing_decision_is_not_disclosed_again_on_a_later_turn(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: billing-agent\ninstructions: Handles billing invoices.");
        $conversation = $this->makeRoutedConversation($agent, $this->user->id, 'automatic');

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('First reply after routing.'),
            $this->plainReply('Second, unrelated later reply.'),
        ]);

        $first = $service->run($conversation->fresh(), 'First question.');
        $this->assertStringContainsString(
            'This conversation is being handled by "billing-agent"',
            $first['content'],
            'the first turn must carry the routing disclosure',
        );

        $second = $service->run($conversation->fresh(), 'Second, unrelated question.');

        $this->assertSame(
            'Second, unrelated later reply.',
            $second['content'],
            'a second turn after the disclosure has already fired must NOT repeat the disclosure sentence',
        );
        $this->assertStringNotContainsString('This conversation is being handled by', $second['content']);
    }

    // =================================================================
    // 5. Wording differs for automatic vs. explicit
    // =================================================================

    #[Test]
    public function the_disclosure_sentence_wording_differs_for_automatic_versus_explicit_routing_reasons(): void
    {
        $automaticAgent = app(AgentService::class)->create($this->user->id, "name: auto-agent\ninstructions: Handles automatic matches.");
        $explicitAgent = app(AgentService::class)->create($this->user->id, "name: explicit-agent\ninstructions: Handles explicit requests.");

        $automaticConversation = $this->makeRoutedConversation($automaticAgent, $this->user->id, 'automatic');
        $explicitConversation = $this->makeRoutedConversation($explicitAgent, $this->user->id, 'explicit');

        $autoService = $this->serviceWithScriptedProvider([
            $this->plainReply('Reply A.'),
        ]);
        $autoResult = $autoService->run($automaticConversation->fresh(), 'A question.');

        $this->assertStringContainsString(
            'This conversation is being handled by "auto-agent", automatically matched to your request.',
            $autoResult['content'],
        );
        $this->assertStringNotContainsString('as you requested', $autoResult['content']);

        $explicitService = $this->serviceWithScriptedProvider([
            $this->plainReply('Reply B.'),
        ]);
        $explicitResult = $explicitService->run($explicitConversation->fresh(), 'A question.');

        $this->assertStringContainsString(
            'This conversation is being handled by "explicit-agent", as you requested.',
            $explicitResult['content'],
        );
        $this->assertStringNotContainsString('automatically matched', $explicitResult['content']);
    }

    // =================================================================
    // 6. No routing_reason at all — no disclosure at all
    // =================================================================

    #[Test]
    public function a_conversation_with_no_routing_reason_produces_no_routing_disclosure_at_all(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: solo-agent\ninstructions: A single configured specialist.");
        $conversation = $this->makeRoutedConversation($agent, $this->user->id, null);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('An ordinary reply.'),
        ]);

        $result = $service->run($conversation->fresh(), 'A question.');

        $this->assertSame('completed', $result['status']);
        $this->assertSame(
            'An ordinary reply.',
            $result['content'],
            'a conversation with routing_reason === null must never have any routing disclosure text prepended',
        );

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertSame('An ordinary reply.', $message->content);

        $conversation = $conversation->fresh();
        $this->assertNull($conversation->routing_disclosed_at);
    }
}
