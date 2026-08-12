<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostReservation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\CostRollupQuery;
use ClarionApp\LlmClient\Services\ReservationLedger;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\EnforcementDecision;
use ClarionApp\LlmClient\ValueObjects\ReservationSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for BudgetGate's held-aware, reservation-attempting behavior
 * (research.md D4/D5/D8/D9, contracts §2/§3, spec.md US1).
 *
 * evaluate()/assess() must measure consumption *plus whatever is currently
 * held in reservation* against a ceiling, not consumption alone; admit()
 * must compute an estimate for the work about to start and atomically
 * attempt to reserve it, refusing indistinguishably from a plain
 * evaluate()-level stop when the atomic attempt itself finds no room.
 *
 * Every monetary assertion below is a plain-decimal-string bccomp(), never
 * a (float) cast — a float formed anywhere in this comparison propagates
 * straight into a currency decision.
 */
class BudgetGateReservationTest extends TestCase
{
    private string $userA;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = (string) Str::uuid();

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'anthropic',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        if (Schema::hasTable('cost_reservations')) {
            DB::table('cost_reservations')->delete();
        }
        if (Schema::hasTable('budget_reservation_ledger')) {
            DB::table('budget_reservation_ledger')->delete();
        }
        DB::table('model_prices')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function gate(): BudgetGate
    {
        return app(BudgetGate::class);
    }

    private function ceilings(): SpendingCeilingService
    {
        return app(SpendingCeilingService::class);
    }

    private function declareCeiling(
        BudgetScope $scope,
        string $amount,
        string $mode = 'stop',
        string $periodType = 'month',
        ?string $scopeId = null,
    ): SpendingCeiling {
        return $this->ceilings()->upsert(
            $scope,
            $scopeId ?? SpendingCeiling::INSTALLATION_SCOPE_ID,
            [
                'amount' => $amount,
                'period_type' => $periodType,
                'enforcement_mode' => $mode,
            ],
        );
    }

    private function recordSpend(string $userId, string $amount, string $date = '2026-08-14'): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $userId,
            'user_id' => $userId,
            'period_date' => $date,
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
     * Seeds budget_reservation_ledger directly — the real table the new
     * ReservationLedger::heldFor() reads from — rather than going through
     * reserve(), so a "currently held" figure can be arranged independently
     * of whether reserve() itself is wired up yet.
     */
    private function placeHeld(string $scopeType, string $scopeId, string $amount): void
    {
        DB::table('budget_reservation_ledger')->insert([
            'id' => (string) Str::uuid(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'reserved_total' => $amount,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function reservedTotal(string $scopeType, string $scopeId): string
    {
        $row = DB::table('budget_reservation_ledger')
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->first();

        return $row === null ? '0.0000000000' : (string) $row->reserved_total;
    }

    private function seedPrice(array $overrides = []): ModelPrice
    {
        return ModelPrice::create(array_merge([
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ], $overrides));
    }

    private function seedConversationFor(string $userId, string $model = 'claude-sonnet-5'): Conversation
    {
        return Conversation::create([
            'server_id' => $this->server->id,
            'title' => 'Already titled',
            'model' => $model,
            'character' => 'Clarion',
            'user_id' => $userId,
            'is_processing' => false,
        ]);
    }

    private function outcomeOf(EnforcementDecision $decision): string
    {
        $outcome = $decision->outcome;

        return $outcome instanceof \BackedEnum ? (string) $outcome->value : (string) $outcome;
    }

    // =================================================================
    // assess()/evaluate() measures consumption + held, via evaluate()
    // (assess() is private) — research.md D4.
    // =================================================================

    #[Test]
    public function consumption_alone_under_the_ceiling_but_consumption_plus_held_at_or_over_it_stops_a_stop_mode_ceiling(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '10.00', 'stop');
        $this->recordSpend($this->userA, '6.0000000000');

        // Sanity: consumption alone must not already be stopping this —
        // otherwise the test would prove nothing about held being consulted.
        $this->assertNotSame(
            'stop',
            $this->outcomeOf($this->gate()->evaluate($this->userA)),
            'sanity check failed: consumption alone must not already stop this scope'
        );

        app()->forgetScopedInstances();
        $this->placeHeld('user', $this->userA, '5.0000000000');

        // 6 (consumption) + 5 (held) = 11 > 10.00 ceiling.
        $decision = $this->gate()->evaluate($this->userA);

        $this->assertSame(
            'stop',
            $this->outcomeOf($decision),
            'consumption and held reservations must be measured together against the ceiling'
        );
    }

    #[Test]
    public function consumption_plus_held_crossing_the_approach_threshold_produces_a_warning_that_consumption_alone_would_not(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '10.00', 'warn');
        $this->recordSpend($this->userA, '3.0000000000');

        $this->assertSame('allow', $this->outcomeOf($this->gate()->evaluate($this->userA)));

        app()->forgetScopedInstances();
        // Default approach threshold is 0.80 * 10.00 = 8.00. 3 + 6 = 9 >= 8.00.
        $this->placeHeld('user', $this->userA, '6.0000000000');

        $decision = $this->gate()->evaluate($this->userA);

        $this->assertSame('allow_with_warning', $this->outcomeOf($decision));
    }

    #[Test]
    public function a_scope_with_zero_currently_held_behaves_exactly_as_076_consumption_alone_did(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop');
        $this->recordSpend($this->userA, '25.0000000000');

        // "Reached" is at-or-above — 076's own baseline behaviour, unaffected
        // by a held figure of zero.
        $this->assertSame('stop', $this->outcomeOf($this->gate()->evaluate($this->userA)));
    }

    // =================================================================
    // admit() computes an estimate and attempts to reserve it —
    // research.md D4/D5.
    // =================================================================

    #[Test]
    public function admit_computes_an_estimate_and_places_a_held_reservation_against_the_ledger(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '100.00', 'stop');
        $this->seedPrice();
        $conversation = $this->seedConversationFor($this->userA);

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $conversation->id);

        $reservation = CostReservation::query()->where('status', CostReservation::STATUS_HELD)->first();

        $this->assertNotNull($reservation, 'admit() must place a held reservation sized to the estimate it computed');
        $this->assertSame(
            0,
            bccomp($this->reservedTotal('user', $this->userA), (string) $reservation->estimated_amount, 10),
            'the ledger total must match the reservation just placed'
        );
    }

    #[Test]
    public function a_second_admit_for_the_same_scope_on_one_instance_does_not_place_a_second_reservation(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '100.00', 'stop');
        $this->seedPrice();
        $conversation = $this->seedConversationFor($this->userA);

        $gate = $this->gate();
        $gate->admit($this->userA, BudgetWorkKind::Interactive, $conversation->id);
        $gate->admit($this->userA, BudgetWorkKind::SystemInitiated, $conversation->id);

        $this->assertSame(
            1,
            CostReservation::query()->count(),
            'the already-admitted short-circuit must prevent a second reservation attempt for a nested unit of work'
        );
    }

    #[Test]
    public function a_declined_request_never_calls_reserve_and_leaves_reserved_total_completely_unchanged(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '5.00', 'stop');
        $this->recordSpend($this->userA, '10.0000000000');
        $this->seedPrice();
        $conversation = $this->seedConversationFor($this->userA);

        try {
            $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $conversation->id);
            $this->fail('Expected a refusal');
        } catch (BudgetExceededException $e) {
            // fall through to assertions below
        }

        $this->assertSame(0, CostReservation::query()->count(), 'a declined request must place no reservation at all (FR-004)');
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10));
    }

    #[Test]
    public function when_the_atomic_reservation_itself_finds_no_room_admit_refuses_exactly_like_a_plain_evaluate_level_stop(): void
    {
        // Consumption alone (5.00) is under the 5.01 ceiling — evaluate()
        // itself, which knows nothing about the new estimate, allows. Only
        // the atomic reservation attempt (which adds the ~0.015 estimate on
        // top) can decline this.
        $this->declareCeiling(BudgetScope::UserDefault, '5.0100000000', 'stop');
        $this->recordSpend($this->userA, '5.0000000000');
        $this->seedPrice(); // output_rate 15.00000000 -> 1000-token default estimate = 0.015
        $conversation = $this->seedConversationFor($this->userA);

        $this->assertNotSame(
            'stop',
            $this->outcomeOf($this->gate()->evaluate($this->userA)),
            'sanity check failed: evaluate() alone must allow so the decline can only come from the atomic reservation attempt'
        );

        try {
            $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $conversation->id);
            $this->fail('Expected the atomic reservation attempt to find no room and admit() to refuse');
        } catch (BudgetExceededException $e) {
            $this->assertSame('stop', $this->outcomeOf($e->decision));
        }

        $this->assertSame(
            0,
            CostReservation::query()->where('status', CostReservation::STATUS_HELD)->count(),
            'a declined atomic reservation must leave nothing held'
        );
    }

    #[Test]
    public function a_reservation_decline_and_a_plain_evaluate_level_stop_render_the_indistinguishable_shape(): void
    {
        $conversation = $this->seedConversationFor($this->userA);

        // Case A: evaluate() itself already says stop.
        $this->declareCeiling(BudgetScope::UserDefault, '5.00', 'stop');
        $this->recordSpend($this->userA, '10.0000000000');

        $bodyA = null;
        try {
            $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $conversation->id);
        } catch (BudgetExceededException $e) {
            $bodyA = $e->decision->toArray(BudgetWorkKind::Interactive);
        }
        $this->assertNotNull($bodyA, 'expected a refusal in case A');

        // Case B: evaluate() allows; only the atomic reservation declines.
        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        app()->forgetScopedInstances();

        $this->declareCeiling(BudgetScope::UserDefault, '5.0100000000', 'stop');
        $this->recordSpend($this->userA, '5.0000000000');
        $this->seedPrice();

        $bodyB = null;
        try {
            $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $conversation->id);
        } catch (BudgetExceededException $e) {
            $bodyB = $e->decision->toArray(BudgetWorkKind::Interactive);
        }
        $this->assertNotNull($bodyB, 'expected a refusal in case B');

        $this->assertSame(
            array_keys($bodyA),
            array_keys($bodyB),
            'a caller must not be able to distinguish which check produced the refusal (contracts §2)'
        );
        $this->assertSame($bodyA['code'], $bodyB['code']);
        $this->assertFalse($bodyB['degraded'], 'an ordinary atomic-bound decline is not a degraded/unreadable outcome');
    }

    // =================================================================
    // Unpriced-model policy branches — research.md D8.
    // =================================================================

    #[Test]
    public function on_unpriced_model_stop_declines_admission_under_a_stop_mode_ceiling_even_with_ample_headroom(): void
    {
        config(['llm-client.budget.on_unpriced_model' => 'stop']);
        $this->declareCeiling(BudgetScope::UserDefault, '1000.00', 'stop');
        $conversation = $this->seedConversationFor($this->userA, 'totally-unpriced-model');

        $this->expectException(BudgetExceededException::class);

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $conversation->id);
    }

    #[Test]
    public function on_unpriced_model_stop_never_blocks_a_warn_mode_ceiling(): void
    {
        config(['llm-client.budget.on_unpriced_model' => 'stop']);
        $this->declareCeiling(BudgetScope::UserDefault, '1000.00', 'warn');
        $conversation = $this->seedConversationFor($this->userA, 'totally-unpriced-model');

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $conversation->id);

        $this->assertSame(0, CostReservation::query()->count());
    }

    #[Test]
    public function on_unpriced_model_admit_untracked_always_admits_with_no_reservation_on_any_axis(): void
    {
        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);
        $this->declareCeiling(BudgetScope::Installation, '1000.00', 'stop');
        $this->declareCeiling(BudgetScope::UserDefault, '1000.00', 'stop');
        $conversation = $this->seedConversationFor($this->userA, 'totally-unpriced-model');

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $conversation->id);

        $this->assertSame(0, CostReservation::query()->count());
        $this->assertSame(0, bccomp($this->reservedTotal('installation', SpendingCeiling::INSTALLATION_SCOPE_ID), '0.0000000000', 10));
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10));
    }

    #[Test]
    public function on_unpriced_model_reserve_flat_estimate_reserves_exactly_the_configured_flat_amount(): void
    {
        config([
            'llm-client.budget.on_unpriced_model' => 'reserve_flat_estimate',
            'llm-client.budget.unpriced_model_flat_estimate' => '2.5000000000',
        ]);
        $this->declareCeiling(BudgetScope::UserDefault, '1000.00', 'stop');
        $conversation = $this->seedConversationFor($this->userA, 'totally-unpriced-model');

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $conversation->id);

        $reservation = CostReservation::first();
        $this->assertNotNull($reservation);
        $this->assertSame(0, bccomp((string) $reservation->estimated_amount, '2.5000000000', 10));
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '2.5000000000', 10));
    }

    // =================================================================
    // Held-unavailable: fail-closed/fail-open — research.md D9, grounding
    // note item 6. Simulated with a ReservationLedger double bound into the
    // container, matching this package's own precedent for an unreadable
    // read (UnreadableConsumptionJourneyTest's ThrowingCostRollupQuery).
    // =================================================================

    #[Test]
    public function a_held_read_failure_under_the_default_policy_stops_a_stop_mode_ceiling_indistinguishably_from_a_consumption_read_failure(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop');
        $this->recordSpend($this->userA, '5.0000000000');

        // Case A: the held read fails.
        $this->app->instance(ReservationLedger::class, new HeldUnavailableReservationLedger(app(SpendingCeilingService::class)));
        $decisionA = $this->gate()->evaluate($this->userA);

        $this->assertSame('stop', $this->outcomeOf($decisionA));
        $this->assertTrue($decisionA->degraded);

        // Case B: 076's own precedent — the consumption read fails instead,
        // with a real (working) ReservationLedger.
        app()->forgetScopedInstances();
        $this->app->instance(CostRollupQuery::class, new ThrowingCostRollupQueryDouble());
        $decisionB = $this->gate()->evaluate($this->userA);

        $this->assertSame('stop', $this->outcomeOf($decisionB));
        $this->assertTrue($decisionB->degraded);

        $this->assertSame(
            $decisionA->toArray(BudgetWorkKind::Interactive)['code'],
            $decisionB->toArray(BudgetWorkKind::Interactive)['code'],
            'a held-read failure and a consumption-read failure must be indistinguishable in shape'
        );
    }

    #[Test]
    public function a_held_read_failure_never_blocks_when_on_unreadable_consumption_is_allow(): void
    {
        config(['llm-client.budget.on_unreadable_consumption' => 'allow']);
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop');
        $this->recordSpend($this->userA, '5.0000000000');

        $this->app->instance(ReservationLedger::class, new HeldUnavailableReservationLedger(app(SpendingCeilingService::class)));

        $this->assertNotSame('stop', $this->outcomeOf($this->gate()->evaluate($this->userA)));
    }

    #[Test]
    public function a_held_read_failure_never_blocks_a_warn_mode_ceiling(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'warn');
        $this->recordSpend($this->userA, '5.0000000000');

        $this->app->instance(ReservationLedger::class, new HeldUnavailableReservationLedger(app(SpendingCeilingService::class)));

        $this->assertNotSame('stop', $this->outcomeOf($this->gate()->evaluate($this->userA)));
    }

    // =================================================================
    // reserve() throwing — distinct from an ordinary null-return decline —
    // research.md D9's write-path mechanism, grounding note item 6.
    // =================================================================

    #[Test]
    public function when_reserve_throws_a_stop_mode_axis_with_the_default_policy_refuses_with_degraded_true(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '1000.00', 'stop');
        $this->seedPrice();
        $conversation = $this->seedConversationFor($this->userA);

        $this->app->instance(ReservationLedger::class, new ThrowingReserveReservationLedger(app(SpendingCeilingService::class)));

        try {
            $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $conversation->id);
            $this->fail('A reserve() failure under a stop-mode axis with the default policy must refuse');
        } catch (BudgetExceededException $e) {
            $this->assertSame('stop', $this->outcomeOf($e->decision));
            $this->assertTrue(
                $e->decision->degraded,
                'a reserve() failure is a degraded outcome, distinct from an ordinary bound-exceeded decline'
            );
        }
    }

    #[Test]
    public function when_reserve_throws_under_a_warn_mode_ceiling_admit_proceeds_without_a_reservation(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '1000.00', 'warn');
        $this->seedPrice();
        $conversation = $this->seedConversationFor($this->userA);

        $this->app->instance(ReservationLedger::class, new ThrowingReserveReservationLedger(app(SpendingCeilingService::class)));

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $conversation->id);

        $this->assertSame(0, CostReservation::query()->count());
    }

    #[Test]
    public function when_reserve_throws_and_on_unreadable_consumption_is_allow_admit_proceeds_without_a_reservation(): void
    {
        config(['llm-client.budget.on_unreadable_consumption' => 'allow']);
        $this->declareCeiling(BudgetScope::UserDefault, '1000.00', 'stop');
        $this->seedPrice();
        $conversation = $this->seedConversationFor($this->userA);

        $this->app->instance(ReservationLedger::class, new ThrowingReserveReservationLedger(app(SpendingCeilingService::class)));

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $conversation->id);

        $this->assertSame(0, CostReservation::query()->count());
    }
}

/**
 * A ReservationLedger whose heldFor() always reports an unreadable figure —
 * mirrors UnreadableConsumptionJourneyTest's ThrowingCostRollupQuery, one
 * layer over for the held side of the read path (research.md D9).
 */
class HeldUnavailableReservationLedger extends ReservationLedger
{
    public function heldFor(string $scopeKey): ReservationSnapshot
    {
        return ReservationSnapshot::unavailable();
    }
}

/**
 * A ReservationLedger whose reserve() always throws a genuine (non-
 * concurrency-abort) failure — the write-path counterpart above.
 */
class ThrowingReserveReservationLedger extends ReservationLedger
{
    public function reserve(
        array $scopeKeys,
        string $estimatedAmount,
        BudgetWorkKind $workKind,
        ?string $userId = null,
        ?string $conversationId = null,
        ?string $runId = null,
    ): ?CostReservation {
        throw new \RuntimeException('reservation ledger write failed');
    }
}

/**
 * A CostRollupQuery whose reads fail — the consumption-side counterpart
 * used only to prove a held-read failure and a consumption-read failure are
 * indistinguishable in the shape they produce.
 */
class ThrowingCostRollupQueryDouble extends CostRollupQuery
{
    public function userTotal(string $userId, string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        throw new \RuntimeException('cost_summaries read failed');
    }

    public function installationTotal(string $from, string $to): array
    {
        throw new \RuntimeException('cost_summaries read failed');
    }
}
