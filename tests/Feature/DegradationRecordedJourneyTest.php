<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\DegradationEvent;
use ClarionApp\LlmClient\Models\DegradationSummary;
use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\MetricsRecorder;
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
 * FR-010 and research.md D11, mutation-testing row 14: every degraded
 * response is recorded exactly once, and a full-capacity response records
 * nothing.
 */
class DegradationRecordedJourneyTest extends TestCase
{
    private User $user;
    private Server $server;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

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
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'big-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);

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

    private function fakeProviderAnswers(): void
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => 'Here is your answer.', 'tool_calls' => []]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function requestSynchronousWork(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Please continue.',
            'conversation_id' => $this->conversation->id,
        ]);
    }

    #[Test]
    public function a_degraded_response_writes_a_degradation_event_and_increments_both_summaries(): void
    {
        $this->fakeProviderAnswers();
        $this->declareCeiling('100.0000000000');
        $this->recordSpend('80.0000000000');
        $step = ReductionStep::create([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
            'enabled' => true,
        ]);

        $response = $this->requestSynchronousWork();
        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));

        $runId = DB::table('agent_runs')->where('conversation_id', $this->conversation->id)->value('id');
        $this->assertNotNull($runId, 'a run must have been minted for this response');

        $event = DegradationEvent::where('run_id', $runId)->first();
        $this->assertNotNull($event, 'a degraded response must write a degradation_events row (FR-010)');
        $this->assertSame((string) $this->user->id, $event->user_id);
        $this->assertSame($this->conversation->id, $event->conversation_id);
        $this->assertSame($step->id, $event->reduction_step_id);
        $this->assertSame('budget_user', $event->axis);
        $this->assertNotNull($event->ratio);

        $userSummary = DegradationSummary::where('entity_type', DegradationSummary::ENTITY_USER)
            ->where('entity_id', $this->user->id)
            ->first();
        $this->assertNotNull($userSummary);
        $this->assertSame(1, $userSummary->degraded_response_count);
        $this->assertNotNull($userSummary->last_degraded_at);

        $installationSummary = DegradationSummary::where('entity_type', DegradationSummary::ENTITY_INSTALLATION)
            ->where('entity_id', SpendingCeiling::INSTALLATION_SCOPE_ID)
            ->first();
        $this->assertNotNull($installationSummary);
        $this->assertSame(1, $installationSummary->degraded_response_count);
    }

    #[Test]
    public function a_full_capacity_response_records_nothing(): void
    {
        $this->fakeProviderAnswers();
        $this->declareCeiling('1000.00');
        $this->recordSpend('1.0000000000');
        ReductionStep::create([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
            'enabled' => true,
        ]);

        $response = $this->requestSynchronousWork();
        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));

        $this->assertSame(0, DegradationEvent::count(), 'a full-capacity response must write no degradation_events row');
        $this->assertSame(0, DegradationSummary::count(), 'a full-capacity response must increment no summary');
    }

    #[Test]
    public function calling_record_degradation_twice_in_quick_succession_increments_the_summary_by_exactly_two(): void
    {
        $step = ReductionStep::create([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
            'enabled' => true,
        ]);

        $recorder = app(MetricsRecorder::class);

        $recorder->recordDegradation($this->conversation->id, (string) $this->user->id, null, $step, 'budget_user', '0.8000');
        $recorder->recordDegradation($this->conversation->id, (string) $this->user->id, null, $step, 'budget_user', '0.8100');

        $userSummary = DegradationSummary::where('entity_type', DegradationSummary::ENTITY_USER)
            ->where('entity_id', $this->user->id)
            ->first();

        $this->assertNotNull($userSummary);
        $this->assertSame(
            2,
            $userSummary->degraded_response_count,
            'two independent recordDegradation() calls must add up to exactly 2 — an insertOrIgnore + column=column+n upsert, never a lost update from a read-modify-write'
        );
    }
}
