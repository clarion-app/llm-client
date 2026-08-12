<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostReservation;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\ReservationLedger;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md's crash-path edge case, FR-012/SC-008, research.md D6: a
 * reservation that outlives the process holding it -- a crashed worker,
 * simulated here by placing a reservation directly via
 * ReservationLedger::reserve() rather than through the normal admission
 * flow, since a genuine `kill -9` cannot be driven from PHPUnit -- must
 * eventually be released by an independent sweep
 * (`llm-client:release-abandoned-reservations`) rather than permanently
 * reducing the allowance for work that never happened.
 *
 * The central, product-shaped assertion (not just a status column):
 * a request wrongly blocked by a leaked hold succeeds once the sweep has
 * released it -- the concrete "leaked reservations progressively lock users
 * out" failure this feature exists to prevent.
 *
 * research.md D6: the sweep is deliberately NOT coupled to agent_runs in
 * any way -- a reservation with a populated run_id is found by the same
 * cutoff-driven query as one with none at all.
 */
class ReservationAbandonmentJourneyTest extends TestCase
{
    private string $userA;
    private Server $server;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = (string) Str::uuid();

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));
        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.budget.reservation.abandonment_minutes' => 30]);

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'anthropic',
        ]);

        $this->conversation = Conversation::create([
            'server_id' => $this->server->id,
            'title' => 'Already titled',
            'model' => 'claude-sonnet-5',
            'character' => 'Clarion',
            'user_id' => $this->userA,
            'is_processing' => false,
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
        DB::table('conversations')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function declareCeiling(string $amount, string $mode = 'stop'): SpendingCeiling
    {
        return app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => 'month', 'enforcement_mode' => $mode],
        );
    }

    private function reservedTotal(string $scopeType, string $scopeId): string
    {
        $row = DB::table('budget_reservation_ledger')
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->first();

        return $row === null ? '0.0000000000' : (string) $row->reserved_total;
    }

    /** Places a held reservation directly -- the "crashed worker" simulation. */
    private function placeLeakedReservation(string $amount, ?string $runId = null): CostReservation
    {
        $reservation = app(ReservationLedger::class)->reserve(
            ['user:'.$this->userA],
            $amount,
            BudgetWorkKind::Interactive,
            userId: $this->userA,
            conversationId: $this->conversation->id,
            runId: $runId,
        );

        $this->assertNotNull($reservation, 'fixture defect: the leaked reservation itself must be placeable');

        return $reservation;
    }

    // =================================================================
    // Held, then untouched inside the cutoff.
    // =================================================================

    #[Test]
    public function a_held_reservation_is_reflected_in_the_scopes_held_amount(): void
    {
        $this->declareCeiling('1000.00', 'stop');

        $reservation = $this->placeLeakedReservation('5.0000000000');

        $this->assertSame(CostReservation::STATUS_HELD, $reservation->status);
        $this->assertSame(
            0,
            bccomp($this->reservedTotal('user', $this->userA), '5.0000000000', 10),
            'a held reservation must be reflected in the scopes held total'
        );
    }

    #[Test]
    public function a_reservation_inside_the_abandonment_cutoff_is_untouched_by_the_sweep(): void
    {
        $this->declareCeiling('1000.00', 'stop');

        $reservation = $this->placeLeakedReservation('5.0000000000');

        // 10 minutes later -- well inside the 30-minute default cutoff.
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:10:00', 'UTC'));

        $exitCode = Artisan::call('llm-client:release-abandoned-reservations');
        $this->assertSame(0, $exitCode);

        $reservation->refresh();
        $this->assertSame(
            CostReservation::STATUS_HELD,
            $reservation->status,
            'a reservation held for less than the configured cutoff must not be swept'
        );
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '5.0000000000', 10));
    }

    // =================================================================
    // Past the cutoff: abandoned, released, and the leaked hold no
    // longer blocks a wrongly-refused request.
    // =================================================================

    #[Test]
    public function a_reservation_past_the_cutoff_is_abandoned_and_the_ledger_is_decremented(): void
    {
        $this->declareCeiling('1000.00', 'stop');

        $reservation = $this->placeLeakedReservation('5.0000000000');

        // 31 minutes later -- past the 30-minute default cutoff.
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:31:00', 'UTC'));

        $exitCode = Artisan::call('llm-client:release-abandoned-reservations');
        $this->assertSame(0, $exitCode);

        $reservation->refresh();
        $this->assertSame(CostReservation::STATUS_ABANDONED, $reservation->status);
        $this->assertNotNull($reservation->resolved_at);
        $this->assertNull($reservation->actual_amount, 'an abandoned reservation carries no actual_amount');

        $this->assertSame(
            0,
            bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10),
            'the ledger must be decremented by the reservations own estimated_amount'
        );
    }

    #[Test]
    public function a_request_wrongly_blocked_by_a_leaked_hold_succeeds_once_the_sweep_has_released_it(): void
    {
        // Tight enough that the leaked 5.00 hold alone reaches the ceiling
        // (0 consumption + 5.00 held >= 5.00), but with ample room for an
        // ordinary admission once the hold is gone.
        $this->declareCeiling('5.0000000000', 'stop');

        $this->placeLeakedReservation('5.0000000000');

        $blocked = false;
        try {
            app(BudgetGate::class)->admit($this->userA, BudgetWorkKind::Interactive, $this->conversation->id);
        } catch (BudgetExceededException $e) {
            $blocked = true;
        }
        $this->assertTrue($blocked, 'fixture defect: the leaked hold must actually block a request for this test to prove anything');

        $this->app->forgetScopedInstances();

        // Past the cutoff: the sweep releases the leaked hold.
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:31:00', 'UTC'));
        Artisan::call('llm-client:release-abandoned-reservations');

        $this->app->forgetScopedInstances();

        // The identical request now succeeds -- nothing left blocking it.
        app(BudgetGate::class)->admit($this->userA, BudgetWorkKind::Interactive, $this->conversation->id);

        $this->assertSame(
            CostReservation::STATUS_HELD,
            CostReservation::query()->latest('held_at')->first()->status,
            'the fresh admission must have placed its own new held reservation'
        );
    }

    // =================================================================
    // --dry-run
    // =================================================================

    #[Test]
    public function dry_run_changes_nothing(): void
    {
        $this->declareCeiling('1000.00', 'stop');

        $reservation = $this->placeLeakedReservation('5.0000000000');

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:31:00', 'UTC'));

        $exitCode = Artisan::call('llm-client:release-abandoned-reservations', ['--dry-run' => true]);
        $this->assertSame(0, $exitCode);

        $reservation->refresh();
        $this->assertSame(
            CostReservation::STATUS_HELD,
            $reservation->status,
            '--dry-run must report eligibility without changing any row'
        );
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '5.0000000000', 10));
    }

    // =================================================================
    // No coupling to agent_runs -- research.md D6, mutation-checklist
    // row 9. A reservation with a populated (still in-progress) run_id
    // is found exactly the same way as one with none at all.
    // =================================================================

    #[Test]
    public function a_reservation_belonging_to_a_still_open_run_is_swept_identically_to_one_with_no_run_at_all(): void
    {
        $this->declareCeiling('1000.00', 'stop');

        // A crashed worker: it opened a run (still in_progress -- it never
        // reached closeRun()) and holds a reservation tied to that run.
        $runId = app(RunTraceRecorder::class)->openRun(
            RunKind::Interactive,
            $this->userA,
            $this->conversation->id,
        );
        $this->assertNotNull($runId);
        $withRun = $this->placeLeakedReservation('2.0000000000', runId: $runId);

        // A direct-admit()-style reservation with no run at all (e.g. a
        // null-user embedding call's own admission never opens a run).
        $withoutRun = $this->placeLeakedReservation('3.0000000000', runId: null);

        $this->assertNotNull($withRun->run_id);
        $this->assertNull($withoutRun->run_id);

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:31:00', 'UTC'));

        Artisan::call('llm-client:release-abandoned-reservations');

        $withRun->refresh();
        $withoutRun->refresh();

        $this->assertSame(
            CostReservation::STATUS_ABANDONED,
            $withRun->status,
            'a reservation tied to a still-open run must be swept -- the sweep never joins against agent_runs'
        );
        $this->assertSame(
            CostReservation::STATUS_ABANDONED,
            $withoutRun->status,
            'a reservation with no run at all must be swept identically'
        );

        // The run itself is untouched by this sweep -- it is a different
        // sweep's (ResolveAbandonedRunsCommand's) job entirely.
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame('in_progress', $run->end_state);
    }

    // =================================================================
    // Idempotency -- calling the sweep again on an already-abandoned
    // reservation is a no-op.
    // =================================================================

    #[Test]
    public function running_the_sweep_twice_on_an_already_abandoned_reservation_does_not_double_decrement(): void
    {
        $this->declareCeiling('1000.00', 'stop');

        $reservation = $this->placeLeakedReservation('5.0000000000');

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:31:00', 'UTC'));

        Artisan::call('llm-client:release-abandoned-reservations');
        $reservation->refresh();
        $this->assertSame(CostReservation::STATUS_ABANDONED, $reservation->status);
        $resolvedAtFirst = (string) $reservation->resolved_at;

        // Run it again, well past the cutoff for a second time.
        Carbon::setTestNow(Carbon::parse('2026-08-14 11:31:00', 'UTC'));
        Artisan::call('llm-client:release-abandoned-reservations');

        $reservation->refresh();
        $this->assertSame(CostReservation::STATUS_ABANDONED, $reservation->status);
        $this->assertSame(
            $resolvedAtFirst,
            (string) $reservation->resolved_at,
            'a second sweep must not re-resolve an already-terminal reservation'
        );
        $this->assertSame(
            0,
            bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10),
            'the ledger must not be decremented a second time for the same reservation'
        );
    }

    #[Test]
    public function reconcile_and_release_are_also_no_ops_on_an_already_abandoned_reservation(): void
    {
        $this->declareCeiling('1000.00', 'stop');

        $reservation = $this->placeLeakedReservation('5.0000000000');

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:31:00', 'UTC'));
        Artisan::call('llm-client:release-abandoned-reservations');
        $reservation->refresh();
        $this->assertSame(CostReservation::STATUS_ABANDONED, $reservation->status);

        $ledger = app(ReservationLedger::class);
        $ledger->reconcile($reservation, '9.9999999999');
        $ledger->release($reservation);

        $reservation->refresh();
        $this->assertSame(
            CostReservation::STATUS_ABANDONED,
            $reservation->status,
            'reconcile()/release() must be no-ops once a reservation has already resolved via the sweep'
        );
        $this->assertNull($reservation->actual_amount);
        $this->assertSame(
            0,
            bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10),
            'neither call may touch the ledger for an already-resolved reservation'
        );
    }
}
