<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\DegradationGate;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * spec.md US1 Acceptance Scenarios 1-3, through the real entry path
 * (AgentLoopService::start()/run() -> admitInteractiveWork() ->
 * DegradationGate::evaluate()), covering FR-002/FR-003/SC-001/SC-005.
 *
 * Every scenario here reuses the exact spending-ceiling machinery
 * PredictiveDeclineJourneyTest (084) already exercises — this feature adds
 * a reduction ladder in front of that, never a second measurement of
 * standing (FR-012).
 */
class ReducedNotRefusedJourneyTest extends TestCase
{
    private User $user;
    private Server $server;
    private Server $secondServer;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        // The real container-resolved AgentLoopService's ConversationCondenser
        // queries this table on every trim — needed only when driving the
        // real HTTP entry path (unlike a directly-constructed AgentLoopService
        // with no condenser, PredictiveDeclineJourneyTest's own precedent).
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Primary Server',
            'server_url' => 'http://primary.local:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->secondServer = Server::create([
            'name' => 'Secondary Server',
            'server_url' => 'http://secondary.local:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'big-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);

        // Neither 'big-model' nor 'small-model' carries a ModelPrice row —
        // this feature's own scenarios are about degradation, not cost
        // estimation, so the predictive reservation check (084) is told to
        // admit untracked rather than refuse on an unpriced model.
        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);

        \Illuminate\Support\Facades\Http::fake();
        Queue::fake([SendHttpStreamRequest::class]);
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

    private function declareCeiling(string $amount, string $mode = 'stop'): SpendingCeiling
    {
        return app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => 'month', 'enforcement_mode' => $mode],
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

    private function declareReductionStep(array $overrides = []): ReductionStep
    {
        return ReductionStep::create(array_merge([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
            'enabled' => true,
        ], $overrides));
    }

    /** @var array<int, array{server: ?Server, model: ?string}> */
    private array $dispatchedCalls = [];

    /**
     * A provider double that records which Server it was resolved against
     * and which model appeared in the dispatched options — the "request
     * log double" the task description names, for the synchronous entry
     * path (POST /agent -> AgentLoopService::run() -> callLlmSync()).
     */
    private function fakeProviderRecording(): void
    {
        $this->dispatchedCalls = [];

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options) {
            $this->dispatchedCalls[] = ['model' => $options['model'] ?? null];

            return [
                'choices' => [['message' => ['content' => 'Here is your answer.', 'tool_calls' => []]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ];
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturnUsing(function ($providerType, $server) use ($provider) {
            $this->dispatchedCalls[] = ['server_id' => $server?->id];

            return $provider;
        });
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function requestSynchronousWork(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Please continue.',
            'conversation_id' => $this->conversation->id,
        ]);
    }

    private function dispatchedModel(): ?string
    {
        foreach ($this->dispatchedCalls as $call) {
            if (array_key_exists('model', $call)) {
                return $call['model'];
            }
        }

        return null;
    }

    private function dispatchedServerId(): ?string
    {
        foreach ($this->dispatchedCalls as $call) {
            if (array_key_exists('server_id', $call)) {
                return $call['server_id'];
            }
        }

        return null;
    }

    // =================================================================
    // Scenario 1 — well below any threshold: full capacity
    // =================================================================

    #[Test]
    public function scenario_1_standing_well_below_threshold_completes_at_full_capacity(): void
    {
        $this->fakeProviderRecording();
        $this->declareCeiling('1000.00');
        $this->recordSpend('1.0000000000');
        $this->declareReductionStep(['threshold_ratio' => '0.7500', 'substitute_model' => 'small-model']);

        $response = $this->requestSynchronousWork();

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));
        $this->assertNull($response->json('degraded'));

        $this->assertSame(
            'big-model',
            $this->dispatchedModel(),
            'well below any threshold, the conversation\'s own configured model must be dispatched, unsubstituted'
        );
    }

    // =================================================================
    // Scenario 2 — threshold crossed, ceiling not reached: reduced, usable
    // =================================================================

    #[Test]
    public function scenario_2_threshold_crossed_but_not_the_ceiling_completes_reduced_with_the_substitute_model(): void
    {
        $this->fakeProviderRecording();
        $this->declareCeiling('100.0000000000');
        $this->recordSpend('80.0000000000'); // 80% — crosses 75%, well below the ceiling
        $this->declareReductionStep(['threshold_ratio' => '0.7500', 'substitute_model' => 'small-model']);

        $response = $this->requestSynchronousWork();

        $response->assertStatus(200);
        $this->assertSame(
            'completed',
            $response->json('status'),
            'a request past a reduction threshold but below the ceiling must complete, not be refused'
        );
        $this->assertNotEmpty($response->json('content'), 'a reduced response must still carry real content, not an empty stub');

        $this->assertSame(
            'small-model',
            $this->dispatchedModel(),
            'the dispatched request must use the governing rung\'s substitute model, not the conversation\'s own (FR-002)'
        );
    }

    #[Test]
    public function scenario_2b_a_rung_naming_a_substitute_server_dispatches_against_that_server(): void
    {
        $this->fakeProviderRecording();
        $this->declareCeiling('100.0000000000');
        $this->recordSpend('80.0000000000');
        $this->declareReductionStep([
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
            'substitute_server_id' => $this->secondServer->id,
        ]);

        $response = $this->requestSynchronousWork();

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));

        $this->assertSame(
            $this->secondServer->id,
            $this->dispatchedServerId(),
            'a rung naming substitute_server_id must actually dispatch against that server, not the conversation\'s own (research.md D4a)'
        );
    }

    // =================================================================
    // Scenario 3 — the absolute ceiling itself still refuses
    // =================================================================

    #[Test]
    public function scenario_3_standing_at_the_absolute_ceiling_is_still_refused_and_degradation_gate_is_never_reached(): void
    {
        $this->fakeProviderRecording();
        $this->declareCeiling('100.0000000000');
        $this->recordSpend('100.0000000000'); // at the ceiling itself
        $this->declareReductionStep(['threshold_ratio' => '0.7500', 'substitute_model' => 'small-model']);

        $spy = Mockery::mock(DegradationGate::class);
        $spy->shouldNotReceive('evaluate');
        $this->app->instance(DegradationGate::class, $spy);

        $response = $this->requestSynchronousWork();

        $response->assertStatus(402);
        $this->assertSame('budget_ceiling_reached', $response->json('code'));

        $this->assertEmpty(
            $this->dispatchedCalls,
            'at the absolute ceiling, no outbound provider call may occur — the request must be refused before dispatch'
        );
    }
}
