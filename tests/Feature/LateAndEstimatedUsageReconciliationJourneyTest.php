<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostReservation;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md's edge case (FR-009/SC-006, research.md D7): usage a model
 * service reports only after a request has already finished, or reports
 * only as an estimate, must reconcile against the allowance without
 * creating a gap that lets the ceiling be quietly overshot.
 *
 * Two distinct scenarios, both proven against the real
 * MetricsRecorder::recordUsage() reconciliation path (never a test double):
 *
 *  1. A late-arriving usage report -- recordUsage() called *after*
 *     RunTraceRecorder::closeRun() has already released the reservation via
 *     its fallback. cost_summaries/consumption still increments normally
 *     (recordUsage()'s own increment is timing-agnostic, unrelated to this
 *     feature), while the reservation reconciliation itself finds nothing
 *     left to touch -- the idempotent `WHERE status = 'held'` guard makes
 *     it a silent no-op: no double-release, no exception, no negative
 *     reserved_total.
 *  2. recordUsage()'s existing full-estimation-fallback branch (an empty
 *     $providerUsage array) for an admitted, reserved request -- the
 *     reservation still reconciles normally, to whatever cost that
 *     fallback itself computed, with no reservation-specific
 *     estimated-usage handling anywhere in the reconciliation path.
 *
 * Every monetary assertion is a plain-decimal-string bccomp(), never a
 * (float) cast.
 */
class LateAndEstimatedUsageReconciliationJourneyTest extends TestCase
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
        DB::table('usage_records')->delete();
        DB::table('messages')->delete();
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

    private function userConsumption(): string
    {
        return (string) app(BudgetGate::class)->standingFor($this->userA)['user_ceiling']['consumption']['amount'];
    }

    private function admitAndOpenRun(): array
    {
        app(BudgetGate::class)->admit($this->userA, BudgetWorkKind::Interactive, $this->conversation->id);

        $reservation = CostReservation::query()->where('status', CostReservation::STATUS_HELD)->first();
        $this->assertNotNull($reservation, 'admit() must place a held reservation to exercise this file at all');

        $runId = app(RunTraceRecorder::class)->openRun(
            RunKind::Interactive,
            $this->userA,
            $this->conversation->id,
        );
        $this->assertNotNull($runId);

        return [$reservation->id, $runId];
    }

    // =================================================================
    // Scenario 1 -- a usage report arriving after the run has already
    // closed (and the reservation already released via the fallback).
    // =================================================================

    #[Test]
    public function a_late_arriving_usage_report_still_increments_consumption_with_no_double_release_and_no_negative_ledger(): void
    {
        $this->declareCeiling('1000.00', 'stop');

        [$reservationId, $runId] = $this->admitAndOpenRun();

        // The run ends before any usage was ever reported -- closeRun()'s
        // fallback releases the reservation.
        app(RunTraceRecorder::class)->closeRun($runId, RunEndState::Failed, 'model call failed');

        $reservation = CostReservation::find($reservationId);
        $this->assertSame(
            CostReservation::STATUS_RELEASED,
            $reservation->status,
            'sanity check: the fallback must have already released this reservation before the late report arrives'
        );
        $this->assertSame(0, bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10));

        $before = $this->userConsumption();
        $this->assertSame('0.0000000000', $before);

        // The provider's usage callback arrives late -- after the run has
        // already closed and the reservation already released.
        app(MetricsRecorder::class)->recordUsage(
            $this->conversation->id,
            $this->userA,
            (string) Str::uuid(),
            ['prompt_tokens' => 40, 'completion_tokens' => 10, 'total_tokens' => 50],
            'irrelevant fallback input text',
            'irrelevant fallback output text',
            'claude-sonnet-5',
            'anthropic',
        );

        $usageRecord = UsageRecord::where('conversation_id', $this->conversation->id)->latest('created_at')->first();
        $this->assertNotNull($usageRecord, 'the late report must still be recorded as ordinary usage');
        $actualCost = (string) $usageRecord->total_cost;

        // cost_summaries/consumption increments normally -- unrelated to
        // reservation timing (recordUsage()'s own increment is
        // timing-agnostic and unchanged by this feature).
        $this->assertSame(
            0,
            bccomp($this->userConsumption(), $actualCost, 10),
            'a late usage report must still be counted against consumption exactly as an on-time one would be'
        );

        // The reservation itself: no double-release, still exactly
        // 'released' (never flipped to 'reconciled' by the late attempt),
        // no exception escaped, and the ledger never goes negative.
        $reservation->refresh();
        $this->assertSame(
            CostReservation::STATUS_RELEASED,
            $reservation->status,
            'a late reconciliation attempt against an already-released reservation must be a silent no-op'
        );
        $this->assertNull($reservation->actual_amount);
        $this->assertSame(
            0,
            bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10),
            'the ledger must not go negative or be touched again by the late, no-op reconciliation attempt'
        );
    }

    // =================================================================
    // Scenario 2 -- recordUsage()'s existing full-estimation-fallback
    // branch (empty providerUsage) still reconciles the reservation
    // normally, to whatever cost that fallback itself computed.
    // =================================================================

    #[Test]
    public function recordUsage_full_estimation_fallback_still_reconciles_the_reservation_to_its_own_computed_cost(): void
    {
        $this->declareCeiling('1000.00', 'stop');

        [$reservationId, $runId] = $this->admitAndOpenRun();

        // Empty $providerUsage forces recordUsage()'s pre-existing full
        // estimation fallback (UsageEstimator::estimate() over the raw
        // input/output text) rather than trusting provider-reported tokens.
        app(MetricsRecorder::class)->recordUsage(
            $this->conversation->id,
            $this->userA,
            (string) Str::uuid(),
            [],
            'a modest amount of input text to estimate from',
            'a modest amount of output text to estimate from',
            'claude-sonnet-5',
            'anthropic',
        );

        $usageRecord = UsageRecord::where('conversation_id', $this->conversation->id)->latest('created_at')->first();
        $this->assertNotNull($usageRecord);
        $this->assertTrue((bool) $usageRecord->input_estimated, 'sanity check: this call must have taken the full-estimation fallback');
        $this->assertTrue((bool) $usageRecord->output_estimated, 'sanity check: this call must have taken the full-estimation fallback');
        $estimatedFallbackCost = (string) $usageRecord->total_cost;

        $reservation = CostReservation::find($reservationId);
        $this->assertSame(
            CostReservation::STATUS_RECONCILED,
            $reservation->status,
            'a reservation must reconcile normally even when recordUsage() itself only had an estimate to work with (FR-009)'
        );
        $this->assertSame(
            0,
            bccomp((string) $reservation->actual_amount, $estimatedFallbackCost, 10),
            'the reservation must reconcile to exactly the cost recordUsage() itself computed via its own fallback'
        );
        $this->assertNotNull($reservation->resolved_at);

        $this->assertSame(
            0,
            bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10),
            'the ledger must return to zero via the same estimated_amount-based decrement as any other reconciliation'
        );

        // closeRun()'s fallback, run afterward, must find nothing left to
        // release -- it is already reconciled.
        app(RunTraceRecorder::class)->closeRun($runId, RunEndState::Completed);

        $reservation->refresh();
        $this->assertSame(
            CostReservation::STATUS_RECONCILED,
            $reservation->status,
            'closeRun() running after recordUsage() already reconciled the reservation must not overwrite it'
        );
    }
}
