<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\LlmClient\Commands\ReleaseAbandonedReservationsCommand;
use ClarionApp\LlmClient\Models\CostReservation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for ReleaseAbandonedReservationsCommand -- the direct
 * analogue of ResolveAbandonedRunsCommandTest, one table over
 * (research.md D6): same cutoff-driven bulk-eligibility shape, but with
 * cost_reservations/budget_reservation_ledger in place of
 * agent_runs/agent_run_steps, and deliberately no join or reference to
 * either of those tables anywhere.
 *
 * Reservations and ledger rows are inserted directly via DB::table(...),
 * bypassing ReservationLedger::reserve()/BudgetGate entirely -- exactly
 * ResolveAbandonedRunsCommandTest's own insertRun()/insertStep() bypass
 * style -- so this file exercises the command in isolation from the rest
 * of the admission/reconciliation machinery.
 */
class ReleaseAbandonedReservationsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.budget.reservation.abandonment_minutes', 30);
    }

    protected function tearDown(): void
    {
        foreach (['cost_reservations', 'budget_reservation_ledger'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function insertReservation(
        string $id,
        string $userId,
        string $status,
        string $heldAt,
        string $estimatedAmount = '5.0000000000',
        ?string $runId = null,
        ?string $resolvedAt = null,
        ?string $actualAmount = null,
        ?array $scopeKeys = null,
    ): void {
        DB::table('cost_reservations')->insert([
            'id' => $id,
            'scope_keys' => json_encode($scopeKeys ?? ['user:'.$userId]),
            'user_id' => $userId,
            'conversation_id' => null,
            'run_id' => $runId,
            'work_kind' => 'interactive',
            'estimated_amount' => $estimatedAmount,
            'actual_amount' => $actualAmount,
            'status' => $status,
            'held_at' => $heldAt,
            'resolved_at' => $resolvedAt,
        ]);
    }

    private function insertLedgerRow(string $scopeType, string $scopeId, string $reservedTotal): void
    {
        DB::table('budget_reservation_ledger')->insert([
            'id' => (string) Str::uuid(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'reserved_total' => $reservedTotal,
            'updated_at' => now(),
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

    // =================================================================
    // No coupling to agent_runs/agent_run_steps -- research.md D6.
    // =================================================================

    #[Test]
    public function the_commands_own_source_never_references_agent_runs_or_agent_run_steps(): void
    {
        $path = (new \ReflectionClass(ReleaseAbandonedReservationsCommand::class))->getFileName();
        $source = file_get_contents($path);

        $this->assertStringNotContainsString(
            'agent_runs',
            $source,
            'the reservation sweeps eligibility query must never join or reference agent_runs (research.md D6)'
        );
        $this->assertStringNotContainsString(
            'agent_run_steps',
            $source,
            'the reservation sweeps eligibility query must never join or reference agent_run_steps (research.md D6)'
        );
    }

    #[Test]
    public function a_reservation_whose_run_id_points_at_a_nonexistent_run_row_is_swept_normally(): void
    {
        $userId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        $this->insertLedgerRow('user', $userId, '5.0000000000');
        $this->insertReservation(
            (string) Str::uuid(),
            $userId,
            CostReservation::STATUS_HELD,
            $staleTime->format('Y-m-d H:i:s.u'),
            '5.0000000000',
            runId: (string) Str::uuid(), // no agent_runs row exists for this id at all
        );

        $exitCode = Artisan::call('llm-client:release-abandoned-reservations');
        $this->assertSame(0, $exitCode);

        $reservation = CostReservation::query()->where('user_id', $userId)->first();
        $this->assertSame(
            CostReservation::STATUS_ABANDONED,
            $reservation->status,
            'a run_id naming no real run must not stop the sweep from resolving the reservation'
        );
    }

    // =================================================================
    // Basic mechanism, mirroring ResolveAbandonedRunsCommandTest's own
    // core-logic coverage.
    // =================================================================

    #[Test]
    public function a_reservation_past_the_cutoff_is_abandoned_and_the_ledger_is_decremented(): void
    {
        $userId = (string) Str::uuid();
        $reservationId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        $this->insertLedgerRow('user', $userId, '5.0000000000');
        $this->insertReservation($reservationId, $userId, CostReservation::STATUS_HELD, $staleTime->format('Y-m-d H:i:s.u'), '5.0000000000');

        $exitCode = Artisan::call('llm-client:release-abandoned-reservations');
        $this->assertSame(0, $exitCode);

        $reservation = CostReservation::find($reservationId);
        $this->assertSame(CostReservation::STATUS_ABANDONED, $reservation->status);
        $this->assertNotNull($reservation->resolved_at);
        $this->assertNull($reservation->actual_amount);

        $this->assertSame(0, bccomp($this->reservedTotal('user', $userId), '0.0000000000', 10));
    }

    #[Test]
    public function a_reservation_inside_the_cutoff_is_untouched(): void
    {
        $userId = (string) Str::uuid();
        $reservationId = (string) Str::uuid();
        $recentTime = CarbonImmutable::now()->subMinutes(5);

        $this->insertLedgerRow('user', $userId, '5.0000000000');
        $this->insertReservation($reservationId, $userId, CostReservation::STATUS_HELD, $recentTime->format('Y-m-d H:i:s.u'), '5.0000000000');

        $exitCode = Artisan::call('llm-client:release-abandoned-reservations');
        $this->assertSame(0, $exitCode);

        $reservation = CostReservation::find($reservationId);
        $this->assertSame(CostReservation::STATUS_HELD, $reservation->status);
        $this->assertSame(0, bccomp($this->reservedTotal('user', $userId), '5.0000000000', 10));
    }

    // =================================================================
    // --minutes overrides the config default.
    // =================================================================

    #[Test]
    public function minutes_option_overrides_config_default(): void
    {
        $userId = (string) Str::uuid();
        $reservationId = (string) Str::uuid();
        // 90 minutes ago -- outside a 60-minute override, inside the
        // 30-minute config default (i.e. eligible either way, so this
        // alone would not distinguish the option from the config -- the
        // *inside*-cutoff case below is what proves the override applies).
        $staleTime = CarbonImmutable::now()->subMinutes(90);

        $this->insertLedgerRow('user', $userId, '5.0000000000');
        $this->insertReservation($reservationId, $userId, CostReservation::STATUS_HELD, $staleTime->format('Y-m-d H:i:s.u'), '5.0000000000');

        $exitCode = Artisan::call('llm-client:release-abandoned-reservations', ['--minutes' => 60]);
        $this->assertSame(0, $exitCode);

        $reservation = CostReservation::find($reservationId);
        $this->assertSame(CostReservation::STATUS_ABANDONED, $reservation->status);
    }

    #[Test]
    public function minutes_option_can_also_keep_a_reservation_untouched_that_the_config_default_would_have_swept(): void
    {
        $userId = (string) Str::uuid();
        $reservationId = (string) Str::uuid();
        // 40 minutes ago -- past the 30-minute config default, but inside
        // a 60-minute --minutes override.
        $staleTime = CarbonImmutable::now()->subMinutes(40);

        $this->insertLedgerRow('user', $userId, '5.0000000000');
        $this->insertReservation($reservationId, $userId, CostReservation::STATUS_HELD, $staleTime->format('Y-m-d H:i:s.u'), '5.0000000000');

        $exitCode = Artisan::call('llm-client:release-abandoned-reservations', ['--minutes' => 60]);
        $this->assertSame(0, $exitCode);

        $reservation = CostReservation::find($reservationId);
        $this->assertSame(
            CostReservation::STATUS_HELD,
            $reservation->status,
            '--minutes must genuinely override the config default, not merely widen it'
        );
    }

    // =================================================================
    // --dry-run
    // =================================================================

    #[Test]
    public function dry_run_changes_nothing(): void
    {
        $userId = (string) Str::uuid();
        $reservationId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        $this->insertLedgerRow('user', $userId, '5.0000000000');
        $this->insertReservation($reservationId, $userId, CostReservation::STATUS_HELD, $staleTime->format('Y-m-d H:i:s.u'), '5.0000000000');

        $exitCode = Artisan::call('llm-client:release-abandoned-reservations', ['--dry-run' => true]);
        $this->assertSame(0, $exitCode);

        $reservation = CostReservation::find($reservationId);
        $this->assertSame(CostReservation::STATUS_HELD, $reservation->status);
        $this->assertSame(0, bccomp($this->reservedTotal('user', $userId), '5.0000000000', 10));
    }

    // =================================================================
    // The bulk UPDATE's own WHERE status = 'held' guard: a reservation
    // already transitioned by a concurrent recordUsage()/closeRun() call
    // is left untouched, not double-resolved.
    // =================================================================

    #[Test]
    public function a_reservation_already_resolved_by_a_concurrent_caller_before_the_sweep_runs_is_left_untouched(): void
    {
        $userId = (string) Str::uuid();
        $reservationId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        // The ledger has already been decremented by whichever concurrent
        // caller (recordUsage() or closeRun()'s fallback) won the race and
        // reconciled this reservation first -- exactly the state a real
        // race would leave behind by the time the sweep's own query runs.
        $this->insertLedgerRow('user', $userId, '0.0000000000');
        $this->insertReservation(
            $reservationId,
            $userId,
            CostReservation::STATUS_RECONCILED,
            $staleTime->format('Y-m-d H:i:s.u'),
            '5.0000000000',
            resolvedAt: now()->format('Y-m-d H:i:s.u'),
            actualAmount: '3.1400000000',
        );

        $exitCode = Artisan::call('llm-client:release-abandoned-reservations');
        $this->assertSame(0, $exitCode);

        $reservation = CostReservation::find($reservationId);
        $this->assertSame(
            CostReservation::STATUS_RECONCILED,
            $reservation->status,
            'a reservation already resolved by a concurrent caller must not be overwritten by the sweep'
        );
        $this->assertSame(
            0,
            bccomp((string) $reservation->actual_amount, '3.1400000000', 10),
            'the sweep must not clobber a concurrently-set actual_amount'
        );
        $this->assertSame(
            0,
            bccomp($this->reservedTotal('user', $userId), '0.0000000000', 10),
            'the sweep must not decrement the ledger a second time for a reservation it did not itself transition'
        );
    }
}
