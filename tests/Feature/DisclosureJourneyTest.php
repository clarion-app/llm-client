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
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\DegradationGate;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RateLimitGate;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * spec.md US2 Acceptance Scenarios 1-2, FR-004/FR-005, SC-002,
 * research.md D10, quickstart steps 5/7/8, mutation-testing rows 12-13.
 *
 * A reduced response — on every completion path that can produce one
 * (AgentLoopService::run(), AgentLoopService::resumeSync(), and the
 * streamed AgentLoopStreamHandler::finish() plain-reply branch) — must
 * plainly disclose that reduction occurred: the persisted content is
 * prefixed with a plain-language sentence, a structured `degradation`
 * block is attached (contracts §4), and the identical structured block
 * lands in the saved Message.tool_data. A full-capacity response on any
 * of those same paths carries none of the three. The disclosure sentence
 * itself must be composed by one shared place (DegradationDecision::
 * composeDisclosure()) rather than built independently per path, so an
 * equivalent decision produces byte-identical prose regardless of which
 * path produced it.
 */
class DisclosureJourneyTest extends TestCase
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

    private function newConversation(string $model = 'big-model'): Conversation
    {
        return Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => $model,
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

    private function substituteModelRung(): ReductionStep
    {
        return ReductionStep::create([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
            'enabled' => true,
        ]);
    }

    /** Crosses the rung's threshold without reaching the ceiling. */
    private function crossThreshold(): void
    {
        $this->declareCeiling('100.0000000000');
        $this->recordSpend('80.0000000000');
    }

    /** @var array|null the $tools argument the provider double last saw */
    private ?string $capturedModel = null;

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $this->capturedModel = null;

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses) {
            $this->capturedModel = $options['model'] ?? null;

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

    /**
     * Replicates AgentLoopService::admitInteractiveWork()'s own admission +
     * DegradationGate::evaluate() + RunTraceRecorder::openRun() sequence
     * (that private method cannot be called directly from a test) so the
     * streamed path can be driven the same way production's start() drives
     * it, using the identical, container-resolved RateLimitGate/BudgetGate/
     * DegradationGate instances a preceding run() call on the same user
     * already populated — guaranteeing a byte-for-byte identical
     * DegradationDecision rather than a hand-built stand-in.
     */
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

        $this->assertNotNull($runId, 'run tracing must be enabled for this test to exercise the streamed handoff');

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
    // Synchronous path — AgentLoopService::run()
    // =================================================================

    #[Test]
    public function run_reduced_response_discloses_prefix_flag_and_structured_block(): void
    {
        $this->crossThreshold();
        $this->substituteModelRung();
        $conversation = $this->newConversation();

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Here is your answer.'),
        ]);

        $result = $service->run($conversation, 'Please help.');

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['degraded'] ?? false, 'a reduced response must carry degraded => true');
        $this->assertStringContainsString(
            'reduced mode',
            $result['content'],
            'the disclosure sentence must be prepended to the returned content'
        );
        $this->assertStringEndsWith(
            'Here is your answer.',
            $result['content'],
            'the original reply must still follow the disclosure, unmodified'
        );

        $degradation = $result['degradation'] ?? null;
        $this->assertIsArray($degradation, 'the return array must carry a degradation block (contracts §4)');
        $this->assertSame(
            ['axis', 'substitute_model', 'withheld_tools', 'history_budget_ratio', 'resets_at'],
            array_keys($degradation),
            'the degradation block must match contracts §4\'s shape exactly'
        );
        $this->assertSame('budget_user', $degradation['axis']);
        $this->assertSame('small-model', $degradation['substitute_model']);

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertSame(
            $degradation,
            $message->tool_data['degradation'] ?? null,
            'the saved Message.tool_data must carry the identical structured degradation object'
        );
    }

    #[Test]
    public function run_full_capacity_response_carries_no_disclosure(): void
    {
        // Well below the 75% threshold — no rung crossed.
        $this->declareCeiling('1000.00');
        $this->recordSpend('1.0000000000');
        $this->substituteModelRung();
        $conversation = $this->newConversation();

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Here is your answer.'),
        ]);

        $result = $service->run($conversation, 'Please help.');

        $this->assertSame('completed', $result['status']);
        $this->assertArrayNotHasKey('degraded', $result);
        $this->assertArrayNotHasKey('degradation', $result);
        $this->assertSame('Here is your answer.', $result['content'], 'a full-capacity response must carry no disclosure prefix');

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertArrayNotHasKey('degradation', $message->tool_data ?? [], 'a full-capacity response writes no tool_data.degradation key');
    }

    // =================================================================
    // Synchronous path — AgentLoopService::resumeSync()
    // =================================================================

    #[Test]
    public function resume_sync_reduced_response_discloses_prefix_flag_and_structured_block(): void
    {
        $this->crossThreshold();
        $this->substituteModelRung();
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
            $this->plainReply('Confirmed and answered.'),
        ]);

        $result = $service->resumeSync($conversation, $message, false);

        $this->assertSame('completed', $result['status']);
        $this->assertTrue(
            $result['degraded'] ?? false,
            'resumeSync()\'s own completion branch must disclose a reduced decision exactly as run()\'s does — '
            .'currently unwired per Phase 3\'s own Progress Log (T049 is still outstanding)'
        );
        $this->assertStringContainsString(
            'reduced mode',
            $result['content'] ?? '',
            'resumeSync() must prepend the disclosure sentence to the returned content'
        );

        $degradation = $result['degradation'] ?? null;
        $this->assertIsArray($degradation, 'resumeSync()\'s return array must carry a degradation block (contracts §4)');
        $this->assertSame(
            ['axis', 'substitute_model', 'withheld_tools', 'history_budget_ratio', 'resets_at'],
            array_keys($degradation ?? []),
            'the degradation block must match contracts §4\'s shape exactly'
        );

        $savedMessage = Message::find($result['message_id'] ?? null);
        $this->assertNotNull($savedMessage);
        $this->assertSame(
            $degradation,
            $savedMessage->tool_data['degradation'] ?? null,
            'resumeSync() must write the identical structured degradation object into Message.tool_data'
        );
    }

    #[Test]
    public function resume_sync_full_capacity_response_carries_no_disclosure(): void
    {
        $this->declareCeiling('1000.00');
        $this->recordSpend('1.0000000000');
        $this->substituteModelRung();
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
            $this->plainReply('Confirmed and answered.'),
        ]);

        $result = $service->resumeSync($conversation, $message, false);

        $this->assertSame('completed', $result['status']);
        $this->assertArrayNotHasKey('degraded', $result);
        $this->assertArrayNotHasKey('degradation', $result);
        $this->assertSame('Confirmed and answered.', $result['content']);

        $savedMessage = Message::find($result['message_id']);
        $this->assertNotNull($savedMessage);
        $this->assertArrayNotHasKey('degradation', $savedMessage->tool_data ?? []);
    }

    // =================================================================
    // Streamed path — AgentLoopStreamHandler::finish()'s plain-reply branch
    // =================================================================

    #[Test]
    public function streamed_reduced_plain_reply_discloses_prefix_and_structured_block(): void
    {
        $this->crossThreshold();
        $this->substituteModelRung();
        $conversation = $this->newConversation();
        $conversation->update(['is_processing' => true]);

        $runId = $this->admitAndOpenStreamedRun($conversation);
        $message = $this->runStreamedFinish($conversation, $runId, 'Streamed answer.');

        $this->assertStringContainsString('reduced mode', $message->content);
        $this->assertStringEndsWith('Streamed answer.', $message->content);

        $degradation = $message->tool_data['degradation'] ?? null;
        $this->assertIsArray($degradation, 'the streamed plain-reply branch must carry a structured degradation object in tool_data');
        $this->assertSame(
            ['axis', 'substitute_model', 'withheld_tools', 'history_budget_ratio', 'resets_at'],
            array_keys($degradation),
            'the degradation block must match contracts §4\'s shape exactly'
        );
        $this->assertSame('budget_user', $degradation['axis']);
        $this->assertSame('small-model', $degradation['substitute_model']);
    }

    #[Test]
    public function streamed_full_capacity_plain_reply_carries_no_disclosure(): void
    {
        $this->declareCeiling('1000.00');
        $this->recordSpend('1.0000000000');
        $this->substituteModelRung();
        $conversation = $this->newConversation();
        $conversation->update(['is_processing' => true]);

        $runId = $this->admitAndOpenStreamedRun($conversation);
        $message = $this->runStreamedFinish($conversation, $runId, 'Streamed answer.');

        $this->assertSame('Streamed answer.', $message->content, 'a full-capacity streamed reply must carry no disclosure prefix');
        $this->assertArrayNotHasKey('degradation', $message->tool_data ?? []);
    }

    // =================================================================
    // Cross-path equality — mutation-testing row 13
    // =================================================================

    #[Test]
    public function disclosure_prose_is_byte_identical_across_the_synchronous_and_streamed_paths(): void
    {
        $this->crossThreshold();
        $this->substituteModelRung();

        // Same user (so the budget_user axis's standing/rung/reset are
        // identical), two different conversations so each path opens its
        // own run and neither overwrites the other's linked decision.
        $syncConversation = $this->newConversation();
        $streamedConversation = $this->newConversation();
        $streamedConversation->update(['is_processing' => true]);

        $sharedReply = 'Identical reply text used by both paths.';

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply($sharedReply),
        ]);
        $syncResult = $service->run($syncConversation, 'Please help.');
        $this->assertTrue($syncResult['degraded'] ?? false, 'the synchronous scenario must itself be reduced for this comparison to be meaningful');

        $syncDisclosure = rtrim(substr($syncResult['content'], 0, strlen($syncResult['content']) - strlen($sharedReply)));

        $runId = $this->admitAndOpenStreamedRun($streamedConversation);
        $streamedMessage = $this->runStreamedFinish($streamedConversation, $runId, $sharedReply);

        $this->assertArrayHasKey(
            'degradation',
            $streamedMessage->tool_data ?? [],
            'the streamed scenario must itself be reduced for this comparison to be meaningful'
        );

        $streamedDisclosure = rtrim(substr($streamedMessage->content, 0, strlen($streamedMessage->content) - strlen($sharedReply)));

        $this->assertNotSame('', $syncDisclosure, 'the synchronous path must have produced a non-empty disclosure sentence');
        $this->assertSame(
            $syncDisclosure,
            $streamedDisclosure,
            'the synchronous and streamed paths must compose byte-identical disclosure prose for an equivalent '
            .'decision, proving both call DegradationDecision::composeDisclosure() rather than each building its own string'
        );
    }
}
