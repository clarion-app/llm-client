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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 102-router-pattern, Phase 3 (US1, T019).
 *
 * spec.md US1 Acceptance Scenarios 1-3, FR-001/FR-009/FR-012/FR-013/FR-014/
 * FR-016, research.md D1/D2/D10, contracts/routing-mechanism.md §1-2.
 *
 * Mirrors AgentHandoffJourneyTest.php's own real Conversation/Agent/
 * AgentVersion fixture style (no agent_id set at conversation creation) and
 * AgentHandoffDisclosureJourneyTest.php's own scripted-provider /
 * admitAndOpenStreamedRun() / runStreamedFinish() scaffolding for the
 * streaming path.
 *
 * Written before RouterService, attemptInitialRouting(), and their wiring
 * into run()/start() exist — every test in this file is expected to FAIL:
 * a previously-unbound conversation's agent_id/routing_reason stay null
 * after the first turn (no code path sets them yet), and any produced
 * Message row is attributed to no agent at all (D10's "zero new code"
 * attribution claim depends entirely on D2 having already bound
 * conversation.agent_id — which nothing does yet).
 */
class AutomaticRoutingJourneyTest extends TestCase
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
        // row — AgentHandoffJourneyTest's/AgentHandoffDisclosureJourneyTest's
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
    // Fixtures
    // -----------------------------------------------------------------

    private function makeUnboundConversation(string $userId): Conversation
    {
        // 'title' pre-set (AgentHandoffDisclosureJourneyTest's own
        // precedent) so run()'s own title-generation dispatch is never
        // triggered. agent_id/agent_version_id deliberately left null —
        // D2's own precondition for attemptInitialRouting() to fire at all.
        return Conversation::factory()->create([
            'user_id' => $userId,
            'server_id' => $this->server->id,
            'title' => 'Already titled',
            'agent_id' => null,
            'agent_version_id' => null,
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
    // 1. Synchronous path — AgentLoopService::run() — billing-shaped
    //    message (US1 AC1).
    // =================================================================

    #[Test]
    public function a_billing_shaped_message_binds_the_conversation_to_the_billing_specialist_never_the_technical_one(): void
    {
        $billingAgent = app(AgentService::class)->create(
            $this->user->id,
            "name: billing-agent\ninstructions: Handles billing invoices, payment questions, and account charges.",
        );
        $technicalAgent = app(AgentService::class)->create(
            $this->user->id,
            "name: technical-agent\ninstructions: Handles technical software bugs, crashes, and system errors.",
        );

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
        $this->assertSame(
            $billingAgent->id,
            $conversation->agent_id,
            'the conversation must be bound to the billing specialist, matched from the billing-shaped trigger message',
        );
        $this->assertNotSame(
            $technicalAgent->id,
            $conversation->agent_id,
            'the conversation must never be bound to the technical specialist for a billing-shaped request',
        );
        $this->assertSame(
            'automatic',
            $conversation->routing_reason,
            'conversations.routing_reason must read automatic once routing has fired',
        );

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertNotNull(
            $message->agent_id,
            'the produced Message row must be attributed to a real agent, never left generic/null (D10)',
        );
        $this->assertSame($billingAgent->id, $message->agent_id);
        $this->assertSame($billingAgent->current_version_id, $message->agent_version_id);
    }

    // =================================================================
    // 2. start()/AgentLoopStreamHandler::finish() — technical-shaped
    //    message (US1 AC2), plus attribution of the streamed reply (AC3).
    // =================================================================

    #[Test]
    public function a_technical_shaped_message_binds_the_conversation_to_the_technical_specialist_never_the_billing_one(): void
    {
        $billingAgent = app(AgentService::class)->create(
            $this->user->id,
            "name: billing-agent\ninstructions: Handles billing invoices, payment questions, and account charges.",
        );
        $technicalAgent = app(AgentService::class)->create(
            $this->user->id,
            "name: technical-agent\ninstructions: Handles technical software bugs, crashes, and system errors.",
        );

        $conversation = $this->makeUnboundConversation($this->user->id);

        $triggerMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'My application keeps crashing with a software bug I cannot fix — a technical error in the system.',
            'responseTime' => 0,
        ]);

        Queue::fake();
        app(AgentLoopService::class)->start($conversation->fresh(), 1, null, $triggerMessage->id);

        $conversation = $conversation->fresh();
        $this->assertSame(
            $technicalAgent->id,
            $conversation->agent_id,
            'the conversation must be bound to the technical specialist, matched from the technical-shaped trigger message',
        );
        $this->assertNotSame(
            $billingAgent->id,
            $conversation->agent_id,
            'the conversation must never be bound to the billing specialist for a technical-shaped request',
        );
        $this->assertSame(
            'automatic',
            $conversation->routing_reason,
            'conversations.routing_reason must read automatic once routing has fired, identically on the start()/streaming entry point',
        );

        // Drive the streamed completion path (AgentLoopStreamHandler::finish())
        // on the now-routed conversation, and confirm the produced assistant
        // Message is attributed to the specialist that actually handled it.
        $runId = $this->admitAndOpenStreamedRun($conversation->fresh());
        $message = $this->runStreamedFinish($conversation->fresh(), $runId, "Let's debug that crash together.");

        $this->assertNotNull(
            $message->agent_id,
            'the produced Message row must be attributed to a real agent, never left generic/null (D10)',
        );
        $this->assertSame($technicalAgent->id, $message->agent_id);
        $this->assertSame($technicalAgent->current_version_id, $message->agent_version_id);
        $this->assertNotSame($billingAgent->id, $message->agent_id);
    }
}
