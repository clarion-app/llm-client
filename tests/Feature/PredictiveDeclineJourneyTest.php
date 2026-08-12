<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md User Story 1, through the real entry path — AgentController's
 * synchronous POST /agent (AgentLoopService::start() -> ... -> run()) and
 * its streamed sibling POST /message (AgentLoopService::start() ->
 * admitInteractiveWork() -> BudgetGate::admit()) — covering Acceptance
 * Scenarios 1-3 (FR-001/FR-002/FR-003/FR-004, SC-001/SC-002).
 *
 * Unlike CeilingStopsWorkJourneyTest (076), which drives a ceiling already
 * *reached* by recorded consumption, every scenario here leaves recorded
 * consumption comfortably under the ceiling and instead narrows the
 * remaining headroom to less than what admitting the request would
 * plausibly cost — the predictive check this feature adds, not the
 * retrospective one 076 already had. A conversation with a substantial
 * message history is seeded throughout so the estimate genuinely reflects
 * "a long conversation," matching the acceptance scenario's own framing,
 * though the gap between the ceiling and recorded consumption is also kept
 * smaller than the estimate's output-token floor alone, so the decline does
 * not depend on tuning history length against any one price precisely.
 */
class PredictiveDeclineJourneyTest extends TestCase
{
    private User $operator;
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

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));

        $this->operator = User::factory()->create();
        $this->user = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'anthropic',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'claude-sonnet-5',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);

        ModelPrice::create([
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);

        \Illuminate\Support\Facades\Http::fake();
        Queue::fake([SendHttpStreamRequest::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('agent_runs')->delete();
        if (Schema::hasTable('cost_reservations')) {
            DB::table('cost_reservations')->delete();
        }
        if (Schema::hasTable('budget_reservation_ledger')) {
            DB::table('budget_reservation_ledger')->delete();
        }
        DB::table('model_prices')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function declareCeiling(string $amount, string $mode = 'stop', string $periodType = 'month'): SpendingCeiling
    {
        return app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => $periodType, 'enforcement_mode' => $mode],
        );
    }

    private function recordSpend(string $amount): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $this->user->id,
            'user_id' => $this->user->id,
            'period_date' => '2026-08-14',
            'request_count' => 1,
            'priced_cost_total' => $amount,
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * A substantial prior exchange, so the estimate this feature computes
     * genuinely reflects "a long conversation" rather than an empty one —
     * spec.md US1 Acceptance Scenario 2's own framing.
     */
    private function seedLongHistory(int $messages = 40, int $charsEach = 200): void
    {
        for ($i = 0; $i < $messages; $i++) {
            Message::create([
                'conversation_id' => $this->conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'user' => $i % 2 === 0 ? 'Test User' : 'Clarion',
                'content' => str_repeat('a', $charsEach),
                'responseTime' => 0,
            ]);
        }
    }

    /**
     * A provider double that answers instantly when reached — used only for
     * the scenarios that must be admitted, so a request that is *not*
     * refused completes for a reason of its own.
     */
    private function fakeProviderAnswers(): void
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => 'Here is your answer.']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    /** How many times the decline scenarios' provider double's chat() was called. */
    private int $chatCallCount = 0;

    /**
     * A provider double that *would* answer if `chat()` — the one method
     * that actually dispatches a model-consuming call — were reached, but
     * counts every call so "zero outbound provider calls" (SC-001) can be
     * asserted as its own, unambiguous fact rather than folded into the
     * primary status-code assertion: a still-permissive double keeps a
     * not-yet-declined request's failure reading as "expected 402, got 200"
     * rather than as an opaque Mockery violation converted to a 500 by
     * AgentController's blanket catch. `countTokens()` is answered
     * unconditionally — it is a local, no-network tokenizer estimate this
     * package's own context-window trimming already calls independently of
     * budget enforcement (076 baseline, unrelated to this feature).
     */
    private function fakeProviderMustNotBeReached(): void
    {
        $this->chatCallCount = 0;

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function () {
            $this->chatCallCount++;

            return [
                'choices' => [['message' => ['content' => 'Here is your answer.']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ];
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    /**
     * A ceiling with just enough more headroom than recorded consumption
     * to admit an ordinary request (scenario 1), but not enough to admit
     * one whose plausible cost — this feature's whole point — would exceed
     * it (scenario 2). The gap (0.01) is kept below the estimate's
     * output-token floor alone (1000 tokens at a 15.00000000 output rate =
     * 0.015), so the decline does not depend on the seeded history's exact
     * length.
     */
    private function declareTightCeiling(): void
    {
        $this->recordSpend('5.0000000000');
        $this->declareCeiling('5.0100000000', 'stop');
    }

    private function requestSynchronousWork(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Please continue.',
            'conversation_id' => $this->conversation->id,
        ]);
    }

    private function requestStreamedWork(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'content' => 'Please continue.',
            'conversation_id' => $this->conversation->id,
        ]);
    }

    private function standingConsumption(): string
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/clarion-app/llm-client/budget/standing');
        $response->assertStatus(200);

        return (string) $response->json('user_ceiling.consumption.amount');
    }

    // =================================================================
    // Scenario 1 — ample headroom admits normally
    // =================================================================

    #[Test]
    public function scenario_1_a_ceiling_with_ample_headroom_admits_an_ordinary_synchronous_request(): void
    {
        $this->fakeProviderAnswers();
        $this->declareCeiling('1000.00', 'stop');
        $this->seedLongHistory();

        $response = $this->requestSynchronousWork();

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));
    }

    #[Test]
    public function scenario_1_a_ceiling_with_ample_headroom_admits_an_ordinary_streamed_request(): void
    {
        $this->fakeProviderAnswers();
        $this->declareCeiling('1000.00', 'stop');
        $this->seedLongHistory();

        $this->requestStreamedWork()->assertStatus(201);

        Queue::assertPushed(SendHttpStreamRequest::class);
    }

    // =================================================================
    // Scenario 2 — plausible cost exceeding remaining headroom declines
    // before any model work begins
    // =================================================================

    #[Test]
    public function scenario_2_a_ceiling_too_tight_to_plausibly_cover_the_request_declines_it_with_402_before_any_provider_call(): void
    {
        $this->fakeProviderMustNotBeReached();
        $this->declareTightCeiling();
        $this->seedLongHistory();

        $response = $this->requestSynchronousWork();

        $response->assertStatus(402);

        $body = $response->json();
        $this->assertSame('budget_ceiling_reached', $body['code']);
        $this->assertNotEmpty($body['message']);
        $this->assertMatchesRegularExpression(
            '/spending ceiling/i',
            $body['message'],
            'The decline must state plainly that a spending ceiling would be exceeded (SC-002)'
        );

        $this->assertSame(0, $this->chatCallCount, 'no outbound provider call may occur before a predictive decline (SC-001)');
    }

    #[Test]
    public function scenario_2_a_ceiling_too_tight_to_plausibly_cover_the_request_declines_the_streamed_path_before_any_provider_call(): void
    {
        $this->fakeProviderMustNotBeReached();
        $this->declareTightCeiling();
        $this->seedLongHistory();

        $response = $this->requestStreamedWork();

        $response->assertStatus(402);
        $this->assertSame('budget_ceiling_reached', $response->json('code'));

        Queue::assertNotPushed(SendHttpStreamRequest::class);
    }

    // =================================================================
    // Scenario 3 — a declined request leaves the remaining allowance
    // unchanged
    // =================================================================

    #[Test]
    public function scenario_3_the_remaining_allowance_is_unchanged_immediately_after_a_predictive_decline(): void
    {
        $this->fakeProviderMustNotBeReached();
        $this->declareTightCeiling();
        $this->seedLongHistory();

        $before = $this->standingConsumption();

        $this->requestSynchronousWork()->assertStatus(402);

        $after = $this->standingConsumption();

        $this->assertSame(
            0,
            bccomp($before, $after, 10),
            'A declined request must leave the reported consumption exactly as it was (FR-004)'
        );
        $this->assertSame(0, bccomp($after, '5.0000000000', 10));
        $this->assertSame(0, $this->chatCallCount, 'no outbound provider call may occur before a predictive decline');
    }

    #[Test]
    public function scenario_3_the_remaining_allowance_is_unchanged_immediately_after_a_predictive_decline_on_the_streamed_path(): void
    {
        $this->fakeProviderMustNotBeReached();
        $this->declareTightCeiling();
        $this->seedLongHistory();

        $before = $this->standingConsumption();

        $this->requestStreamedWork()->assertStatus(402);

        $after = $this->standingConsumption();

        $this->assertSame(0, bccomp($before, $after, 10));
    }
}
