<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\CostReservation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\ReservationLedger;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for ReservationLedger — the sole reader/writer of
 * budget_reservation_ledger/cost_reservations (contracts/reservation-api.md
 * §3, research.md D5/D7).
 *
 * This file proves logic and idempotency against the SQLite :memory: schema;
 * the genuine multi-connection concurrency proof is
 * tests/RealDatabase/ReservationConcurrencyTest.php (Phase 5).
 *
 * Every monetary comparison uses bccomp()/plain-decimal strings, never a
 * (float) cast.
 */
class ReservationLedgerTest extends TestCase
{
    private string $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = (string) Str::uuid();

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (Schema::hasTable('cost_reservations')) {
            DB::table('cost_reservations')->delete();
        }
        if (Schema::hasTable('budget_reservation_ledger')) {
            DB::table('budget_reservation_ledger')->delete();
        }
        if (Schema::hasTable('spending_ceilings')) {
            DB::table('spending_ceilings')->delete();
        }
        if (Schema::hasTable('cost_summaries')) {
            DB::table('cost_summaries')->delete();
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function ledger(): ReservationLedger
    {
        return new ReservationLedger(app(SpendingCeilingService::class));
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

    private function recordSpend(string $entityType, string $entityId, string $userId, string $amount): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $userId,
            'period_date' => Carbon::now()->toDateString(),
            'request_count' => 1,
            'priced_cost_total' => $amount,
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
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

    // ---------------------------------------------------------------
    // reserve() — anchor row creation, single axis
    // ---------------------------------------------------------------

    #[Test]
    public function reserve_against_a_scope_with_no_prior_row_creates_one_and_returns_a_held_reservation(): void
    {
        $this->declareCeiling(BudgetScope::User, '100.00', scopeId: $this->userA);

        $reservation = $this->ledger()->reserve(
            ['user:'.$this->userA],
            '5.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );

        $this->assertNotNull($reservation);
        $this->assertInstanceOf(CostReservation::class, $reservation);
        $this->assertSame(CostReservation::STATUS_HELD, $reservation->status);
        $this->assertSame(0, bccomp($reservation->estimated_amount, '5.0000000000', 10));

        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '5.0000000000', 10));
    }

    #[Test]
    public function a_second_reserve_call_that_would_exceed_the_ceiling_returns_null_and_leaves_reserved_total_unchanged(): void
    {
        $this->declareCeiling(BudgetScope::User, '10.00', scopeId: $this->userA);

        $first = $this->ledger()->reserve(
            ['user:'.$this->userA],
            '7.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );
        $this->assertNotNull($first);
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '7.0000000000', 10));

        // 7 (already held) + 5 (this attempt) = 12 > 10.00 ceiling.
        $second = $this->ledger()->reserve(
            ['user:'.$this->userA],
            '5.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );

        $this->assertNull($second);
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '7.0000000000', 10));
        $this->assertSame(1, CostReservation::query()->count(), 'the declined attempt must not create a reservation row');
    }

    #[Test]
    public function reserve_accounts_for_recorded_consumption_as_well_as_prior_holds(): void
    {
        $this->declareCeiling(BudgetScope::User, '10.00', scopeId: $this->userA);
        $this->recordSpend(CostSummary::ENTITY_USER, $this->userA, $this->userA, '8.0000000000');

        // 8 (consumption) + 5 (this attempt) = 13 > 10.00 ceiling.
        $result = $this->ledger()->reserve(
            ['user:'.$this->userA],
            '5.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );

        $this->assertNull($result);
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10));

        // 8 (consumption) + 2 (this attempt) = 10 <= 10.00 ceiling: fits exactly.
        $result = $this->ledger()->reserve(
            ['user:'.$this->userA],
            '2.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );

        $this->assertNotNull($result);
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '2.0000000000', 10));
    }

    // ---------------------------------------------------------------
    // reserve() — multiple scope keys, all-or-nothing
    // ---------------------------------------------------------------

    #[Test]
    public function reserve_against_two_scope_keys_commits_both_when_both_fit(): void
    {
        $this->declareCeiling(BudgetScope::Installation, '100.00');
        $this->declareCeiling(BudgetScope::User, '100.00', scopeId: $this->userA);

        $reservation = $this->ledger()->reserve(
            ['installation', 'user:'.$this->userA],
            '5.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );

        $this->assertNotNull($reservation);
        $this->assertSame(0, bccomp($this->reservedTotal('installation', SpendingCeiling::INSTALLATION_SCOPE_ID), '5.0000000000', 10));
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '5.0000000000', 10));
    }

    #[Test]
    public function reserve_against_two_scope_keys_commits_neither_when_one_axis_would_exceed_its_ceiling(): void
    {
        // Installation ceiling has ample room; the user's own ceiling does not.
        $this->declareCeiling(BudgetScope::Installation, '100.00');
        $this->declareCeiling(BudgetScope::User, '3.00', scopeId: $this->userA);

        $reservation = $this->ledger()->reserve(
            ['installation', 'user:'.$this->userA],
            '5.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );

        $this->assertNull($reservation);

        // Neither axis committed — including the installation axis, which
        // on its own would have fit.
        $this->assertSame(0, bccomp($this->reservedTotal('installation', SpendingCeiling::INSTALLATION_SCOPE_ID), '0.0000000000', 10));
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10));
        $this->assertSame(0, CostReservation::query()->count());
    }

    // ---------------------------------------------------------------
    // reconcile()
    // ---------------------------------------------------------------

    #[Test]
    public function reconcile_marks_reconciled_and_decrements_the_ledger_by_the_estimate_not_the_actual(): void
    {
        $this->declareCeiling(BudgetScope::User, '100.00', scopeId: $this->userA);

        $reservation = $this->ledger()->reserve(
            ['user:'.$this->userA],
            '5.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );
        $this->assertNotNull($reservation);

        // Actual cost differs from the estimate.
        $this->ledger()->reconcile($reservation, '3.2500000000');

        $fresh = CostReservation::find($reservation->id);
        $this->assertSame(CostReservation::STATUS_RECONCILED, $fresh->status);
        $this->assertSame(0, bccomp($fresh->actual_amount, '3.2500000000', 10));
        $this->assertNotNull($fresh->resolved_at);

        // Decremented by the estimate (5.00), not the actual (3.25).
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10));
    }

    #[Test]
    public function reconcile_is_a_no_op_when_called_a_second_time(): void
    {
        $this->declareCeiling(BudgetScope::User, '100.00', scopeId: $this->userA);

        $reservation = $this->ledger()->reserve(
            ['user:'.$this->userA],
            '5.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );

        $this->ledger()->reconcile($reservation, '3.0000000000');
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10));

        // Second call, same (now stale) object.
        $this->ledger()->reconcile($reservation, '999.0000000000');

        $fresh = CostReservation::find($reservation->id);
        $this->assertSame(0, bccomp($fresh->actual_amount, '3.0000000000', 10), 'the first reconciliation must stand');
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10), 'no second decrement');
    }

    // ---------------------------------------------------------------
    // release()
    // ---------------------------------------------------------------

    #[Test]
    public function release_marks_released_leaves_actual_amount_null_and_decrements_the_ledger(): void
    {
        $this->declareCeiling(BudgetScope::User, '100.00', scopeId: $this->userA);

        $reservation = $this->ledger()->reserve(
            ['user:'.$this->userA],
            '5.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );

        $this->ledger()->release($reservation);

        $fresh = CostReservation::find($reservation->id);
        $this->assertSame(CostReservation::STATUS_RELEASED, $fresh->status);
        $this->assertNull($fresh->actual_amount);
        $this->assertNotNull($fresh->resolved_at);
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10));
    }

    #[Test]
    public function release_is_a_no_op_when_called_a_second_time(): void
    {
        $this->declareCeiling(BudgetScope::User, '100.00', scopeId: $this->userA);

        $reservation = $this->ledger()->reserve(
            ['user:'.$this->userA],
            '5.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );

        $this->ledger()->release($reservation);
        $this->ledger()->release($reservation);

        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10), 'no double-decrement');
    }

    /**
     * The cross-process double-decrement race (grounding note item 8 /
     * mutation-checklist row 14). Simulates MetricsRecorder::recordUsage()'s
     * reconciliation racing RunTraceRecorder::closeRun()'s fallback release
     * for the same reservation: closeRun()'s fallback fetches the
     * CostReservation row once (status = 'held'), recordUsage() wins the
     * race and reconciles it first, and then the fallback's release() call
     * runs against its own now-stale in-memory object (whose ->status
     * property still reads 'held').
     */
    #[Test]
    public function release_using_a_stale_in_memory_object_is_a_complete_no_op_when_something_else_already_reconciled_it(): void
    {
        $this->declareCeiling(BudgetScope::User, '100.00', scopeId: $this->userA);

        $reservation = $this->ledger()->reserve(
            ['user:'.$this->userA],
            '5.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );

        // closeRun()'s fallback fetches the row once, before anything races.
        $staleFetch = CostReservation::find($reservation->id);
        $this->assertSame(CostReservation::STATUS_HELD, $staleFetch->status);

        // recordUsage() wins the race and reconciles the underlying row.
        $this->ledger()->reconcile($reservation, '4.0000000000');
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10));

        // closeRun()'s fallback now calls release() using its first,
        // now-stale fetch — ->status still reads 'held' in PHP memory.
        $this->ledger()->release($staleFetch);

        $fresh = CostReservation::find($reservation->id);
        $this->assertSame(CostReservation::STATUS_RECONCILED, $fresh->status, 'must not be clobbered back to released');
        $this->assertSame(0, bccomp($fresh->actual_amount, '4.0000000000', 10), 'must not be cleared by the stale release');

        // Decremented exactly once — by reconcile(), never a second time by
        // the stale release() call.
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10));
    }

    // ---------------------------------------------------------------
    // heldFor()
    // ---------------------------------------------------------------

    #[Test]
    public function heldfor_reads_zero_and_available_for_a_scope_with_no_row_at_all(): void
    {
        $snapshot = $this->ledger()->heldFor('user:'.$this->userA);

        $this->assertTrue($snapshot->available);
        $this->assertSame(0, bccomp($snapshot->amount, '0.0000000000', 10));
    }

    #[Test]
    public function heldfor_reads_the_current_reserved_total_for_an_existing_scope(): void
    {
        $this->declareCeiling(BudgetScope::User, '100.00', scopeId: $this->userA);
        $this->ledger()->reserve(
            ['user:'.$this->userA],
            '5.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );

        $snapshot = $this->ledger()->heldFor('user:'.$this->userA);

        $this->assertTrue($snapshot->available);
        $this->assertSame(0, bccomp($snapshot->amount, '5.0000000000', 10));
    }

    #[Test]
    public function heldfor_never_throws_reporting_a_read_failure_as_unavailable_instead(): void
    {
        Schema::drop('budget_reservation_ledger');

        $snapshot = $this->ledger()->heldFor('user:'.$this->userA);

        $this->assertFalse($snapshot->available);
        $this->assertNull($snapshot->amount);
    }

    // ---------------------------------------------------------------
    // Failure propagation for the write paths (contracts §3's failure
    // contract): a throwable that is not a recognized concurrency abort
    // propagates to the caller from reserve()/reconcile()/release().
    // ---------------------------------------------------------------

    #[Test]
    public function reserve_propagates_a_genuine_failure_rather_than_swallowing_it(): void
    {
        $this->declareCeiling(BudgetScope::User, '100.00', scopeId: $this->userA);

        Schema::drop('cost_summaries');

        $this->expectException(\Throwable::class);

        $this->ledger()->reserve(
            ['user:'.$this->userA],
            '5.0000000000',
            BudgetWorkKind::Interactive,
            userId: $this->userA,
        );
    }
}
