<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\DegradationGate;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\ConsumptionSnapshot;
use ClarionApp\LlmClient\ValueObjects\EnforcementDecision;
use ClarionApp\LlmClient\ValueObjects\RateLimitDecision;
use ClarionApp\LlmClient\ValueObjects\RateLimitReading;
use ClarionApp\LlmClient\ValueObjects\ReservationSnapshot;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * research.md D6, edge case/FR-008/FR-009, SC-006/SC-007, quickstart steps
 * 12-13, mutation-testing rows 6-7 — exercised independently over all
 * three tool-call-processing entry paths (AgentLoopService::run(),
 * AgentLoopService::resumeSync(), AgentLoopStreamHandler::handleToolCalls()),
 * since a mutation removing the interception from only one path must be
 * caught even if the other two still have it.
 */
class WithheldToolRefusalJourneyTest extends TestCase
{
    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('agent_runs')->delete();
        DB::table('reduction_steps')->delete();
        DB::table('degradation_events')->delete();
        DB::table('degradation_summaries')->delete();
        if (DB::getSchemaBuilder()->hasTable('cost_reservations')) {
            DB::table('cost_reservations')->delete();
        }
        if (DB::getSchemaBuilder()->hasTable('budget_reservation_ledger')) {
            DB::table('budget_reservation_ledger')->delete();
        }

        Mockery::close();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function newConversation(): Conversation
    {
        return Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);
    }

    private function declareCeiling(string $amount): void
    {
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );
    }

    private function recordSpend(string $amount): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $this->user->id,
            'user_id' => $this->user->id,
            'period_date' => '2026-08-12',
            'request_count' => 1,
            'priced_cost_total' => $amount,
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function withholdingRung(): ReductionStep
    {
        return ReductionStep::create([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'withheld_tools' => ['propose_declarative_memory'],
            'enabled' => true,
        ]);
    }

    /** Crosses the withholding rung's threshold without reaching the ceiling. */
    private function crossThreshold(): void
    {
        $this->declareCeiling('100.0000000000');
        $this->recordSpend('80.0000000000');
    }

    /** @var array|null the $tools argument the provider double last saw */
    private ?array $capturedTools = null;

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $this->capturedTools = null;

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools) use (&$responses) {
            $this->capturedTools = $tools;

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

    private function toolNames(?array $tools): array
    {
        return array_map(fn ($t) => $t['function']['name'] ?? null, $tools ?? []);
    }

    /**
     * Directly evaluate + link a 'reduced' decision for a run, the same
     * handoff RunTraceRecorder::openRun()/admitInteractiveWork() will
     * perform automatically once Phase 3 wires it — used here to exercise
     * the streamed handler's own withheld-tool interception in isolation.
     */
    private function linkReducedDecisionForRun(string $conversationId, string $runId): void
    {
        $ceiling = SpendingCeiling::create([
            'scope_type' => 'user_default',
            'scope_id' => SpendingCeiling::INSTALLATION_SCOPE_ID,
            'amount' => '100.0000000000',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]);
        $snapshot = new ConsumptionSnapshot(
            amount: '80.0000000000',
            requestCount: 1,
            unpricedRequestCount: 0,
            unpricedTotalTokens: 0,
            hasEstimatedCost: false,
            periodType: 'month',
            periodFrom: '2026-08-01',
            periodTo: '2026-08-31',
            resetsAt: Carbon::parse('2026-09-01 00:00:00', 'UTC')->toImmutable(),
        );
        $budgetDecision = new EnforcementDecision(
            outcome: EnforcementDecision::ALLOW,
            governingCeiling: $ceiling,
            snapshot: $snapshot,
            held: new ReservationSnapshot(amount: '0', available: true),
        );
        $rateLimitDecision = new RateLimitDecision(RateLimitDecision::ALLOW);

        $gate = app(DegradationGate::class);
        $gate->evaluate((string) $this->user->id, $conversationId, $rateLimitDecision, $budgetDecision);
        $gate->linkRun((string) $this->user->id, $conversationId, $runId);
    }

    // =================================================================
    // Path 1 — AgentLoopService::run()
    // =================================================================

    #[Test]
    public function run_dispatches_normally_and_omits_the_withheld_tool_when_it_is_never_called(): void
    {
        $this->withholdingRung();
        $this->crossThreshold();
        $conversation = $this->newConversation();

        $service = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => 'All done, no memory proposal needed.', 'tool_calls' => []]]]],
        ]);

        $result = $service->run($conversation, 'Just answer plainly.');

        $this->assertSame('completed', $result['status']);
        $this->assertNotContains('propose_declarative_memory', $this->toolNames($this->capturedTools));
        $this->assertTrue($result['degraded'] ?? false, 'a response completing under a crossed threshold must be disclosed as reduced');
    }

    #[Test]
    public function run_refuses_when_the_model_attempts_the_withheld_tool_mid_response(): void
    {
        $this->withholdingRung();
        $this->crossThreshold();
        $conversation = $this->newConversation();

        $service = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'propose_declarative_memory', 'arguments' => '{"type":"fact","content":"x"}'],
            ]]]]]],
        ]);

        $result = $service->run($conversation, 'Please remember that I like dark mode.');

        $this->assertSame('stopped', $result['status']);
        $this->assertSame('degradation_capability_required', $result['code'] ?? null);

        $message = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($message);
        $toolResults = $message->tool_data['tool_results'] ?? [];
        $this->assertCount(1, $toolResults, 'the withheld call must still receive a synthesized tool_result — no assistant message left unanswered');
        $this->assertSame('call_1', $toolResults[0]['tool_call_id']);

        $conversation->refresh();
        $this->assertFalse($conversation->is_processing);
    }

    // =================================================================
    // Path 2 — AgentLoopService::resumeSync()
    // =================================================================

    #[Test]
    public function resume_sync_refuses_when_its_continuation_loop_attempts_the_withheld_tool(): void
    {
        $this->withholdingRung();
        $this->crossThreshold();
        $conversation = $this->newConversation();
        $conversation->update(['is_processing' => true]);

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
            ['choices' => [['message' => ['content' => '', 'tool_calls' => [[
                'id' => 'call_2',
                'type' => 'function',
                'function' => ['name' => 'propose_declarative_memory', 'arguments' => '{"type":"fact","content":"x"}'],
            ]]]]]],
        ]);

        $result = $service->resumeSync($conversation, $message, false);

        $this->assertSame('stopped', $result['status']);
        $this->assertSame('degradation_capability_required', $result['code'] ?? null);

        $this->assertNotNull($result['message_id']);
        $lastMessage = Message::find($result['message_id']);
        $this->assertNotNull($lastMessage);
        $toolResults = $lastMessage->tool_data['tool_results'] ?? [];
        $this->assertCount(1, $toolResults);
        $this->assertSame('call_2', $toolResults[0]['tool_call_id']);

        $conversation->refresh();
        $this->assertFalse($conversation->is_processing);
    }

    // =================================================================
    // Path 3 — AgentLoopStreamHandler::handleToolCalls()
    // =================================================================

    #[Test]
    public function the_streaming_handler_dispatches_normally_and_omits_the_withheld_tool_when_never_called(): void
    {
        Event::fake([FinishOpenAIConversationResponseEvent::class]);

        $this->withholdingRung();
        config(['llm-client.run_trace.enabled' => true]);

        $conversation = $this->newConversation();
        $conversation->update(['is_processing' => true]);

        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, (string) $conversation->user_id, $conversation->id);
        $this->linkReducedDecisionForRun($conversation->id, $runId);
        $stepId = $recorder->openStep($runId, 1, (string) \Illuminate\Support\Str::uuid());

        $handler = new AgentLoopStreamHandler(null, null, $recorder);
        $handler->toolCalls = [];
        $handler->reply = 'All done.';
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
        $this->assertStringContainsString('All done.', $handler->message->content);
        $this->assertArrayHasKey('degradation', $handler->message->tool_data ?? [], 'a reduced streamed reply must carry the structured degradation object');
    }

    #[Test]
    public function the_streaming_handler_refuses_when_it_attempts_to_call_the_withheld_tool(): void
    {
        Event::fake([FinishOpenAIConversationResponseEvent::class]);

        $this->withholdingRung();
        config(['llm-client.run_trace.enabled' => true]);

        $conversation = $this->newConversation();
        $conversation->update(['is_processing' => true]);

        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, (string) $conversation->user_id, $conversation->id);
        $this->linkReducedDecisionForRun($conversation->id, $runId);
        $stepId = $recorder->openStep($runId, 1, (string) \Illuminate\Support\Str::uuid());

        $handler = new AgentLoopStreamHandler(null, null, $recorder);
        $handler->toolCalls = [[
            'id' => 'call_3',
            'type' => 'function',
            'function' => ['name' => 'propose_declarative_memory', 'arguments' => '{"type":"fact","content":"x"}'],
        ]];
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
        $toolResults = $handler->message->tool_data['tool_results'] ?? null;
        $this->assertNotNull($toolResults, 'the withheld call must still receive a synthesized tool_result');
        $this->assertCount(1, $toolResults);
        $this->assertSame('call_3', $toolResults[0]['tool_call_id']);

        Event::assertDispatched(FinishOpenAIConversationResponseEvent::class, function ($event) {
            return str_contains($event->reply, 'unavailable') || str_contains($event->reply, 'refused');
        });

        $conversation->refresh();
        $this->assertFalse($conversation->is_processing);
    }
}
