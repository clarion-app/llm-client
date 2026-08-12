<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostReservation;
use ClarionApp\LlmClient\Models\Message;
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
 * spec.md US2 Acceptance Scenarios 1-2 (FR-005/FR-006/FR-014/SC-003,
 * research.md D7): whatever an admitted request genuinely consumed before
 * stopping is counted exactly -- never zero when real consumption occurred,
 * never inflated beyond it -- and a request that never consumed anything
 * measurable leaves the allowance unchanged.
 *
 * Scenario 1 drives the *primary* reconciliation hook
 * (MetricsRecorder::recordUsage(), inside the same request/job-scoped
 * BudgetGate instance admit() ran on -- research.md D7's own grounding for
 * why this is safe). Scenario 2 drives the *fallback*
 * (RunTraceRecorder::closeRun(), which releases directly through
 * ReservationLedger, bypassing BudgetGate entirely -- see the tasks.md
 * grounding note on why closeRun()'s fallback cannot go through BudgetGate's
 * in-memory state). The two are told apart by which cost_reservations.status
 * results: 'reconciled' vs 'released'.
 *
 * Every monetary assertion is a plain-decimal-string bccomp(), never a
 * (float) cast.
 */
class PartialConsumptionCountedJourneyTest extends TestCase
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

        // A substantial prior exchange, so the estimate this feature
        // computes at admission time (input tokens from history + a
        // configured 1000-token output default) is comfortably nonzero and
        // comfortably different from the small real usage figures recorded
        // below -- exactly the gap this file's central assertion depends on.
        for ($i = 0; $i < 10; $i++) {
            Message::create([
                'conversation_id' => $this->conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'user' => $i % 2 === 0 ? 'Test User' : 'Clarion',
                'content' => str_repeat('a', 200),
                'responseTime' => 0,
            ]);
        }

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

    private function gate(): BudgetGate
    {
        return app(BudgetGate::class);
    }

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
        return (string) $this->gate()->standingFor($this->userA)['user_ceiling']['consumption']['amount'];
    }

    /**
     * Admits the work, returning the resulting held reservation -- the same
     * two calls (BudgetGate::admit() then RunTraceRecorder::openRun()) every
     * real entry path makes, in the same order (tasks.md grounding note
     * item 2: openRun() always runs after admit()).
     */
    private function admitAndOpenRun(): array
    {
        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $this->conversation->id);

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
    // Scenario 1 -- partial consumption before stopping is counted
    // exactly, via MetricsRecorder::recordUsage()'s reconciliation.
    // =================================================================

    #[Test]
    public function partial_consumption_is_reconciled_exactly_and_the_reservation_returns_to_zero(): void
    {
        $this->declareCeiling('1000.00', 'stop');

        [$reservationId, $runId] = $this->admitAndOpenRun();

        $reservation = CostReservation::find($reservationId);
        $estimatedAmount = (string) $reservation->estimated_amount;

        $this->assertSame(
            '0.0000000000',
            $this->userConsumption(),
            'sanity check: nothing has been recorded yet'
        );

        // Real, non-zero usage lands -- deliberately small relative to the
        // estimate's 1000-token output default, so actual_amount and
        // estimated_amount are genuinely different figures (the exact case
        // a decrement-by-the-wrong-figure defect needs to be caught by).
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
        $this->assertNotNull($usageRecord);
        $this->assertFalse((bool) $usageRecord->cost_unpriced, 'sanity check: the recorded usage must be priced');
        $actualCost = (string) $usageRecord->total_cost;

        // The run then stops partway -- a tool-call failure mid-turn is the
        // scenario tasks.md names; the precise reason is not the point, only
        // that the run's own terminal write happens after recordUsage()'s.
        app(RunTraceRecorder::class)->closeRun(
            $runId,
            RunEndState::StoppedEarly,
            'tool call failed mid-turn'
        );

        $reservation->refresh();

        $this->assertNotSame(
            0,
            bccomp($actualCost, $estimatedAmount, 10),
            'fixture defect: actual and estimated cost must genuinely differ for this test to prove anything'
        );

        $this->assertSame(
            CostReservation::STATUS_RECONCILED,
            $reservation->status,
            'a request that consumed something real before stopping must be reconciled, not released'
        );
        $this->assertSame(
            0,
            bccomp((string) $reservation->actual_amount, $actualCost, 10),
            'the reservation\'s actual_amount must equal the real recorded cost'
        );
        $this->assertNotNull($reservation->resolved_at);

        // The ledger must have been decremented by the reservation's OWN
        // estimated_amount, not by actual_amount -- since exactly one
        // reservation was ever placed for this scope, the total returning
        // to precisely zero is what proves the decrement used the right
        // figure; decrementing by actual_amount instead would leave a
        // nonzero residue here because the two figures differ (asserted
        // above).
        $this->assertSame(
            0,
            bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10),
            'the ledger must return to exactly zero -- proof the decrement used estimated_amount, not actual_amount'
        );

        $this->assertSame(
            0,
            bccomp($this->userConsumption(), $actualCost, 10),
            'standing consumption must reflect exactly the genuine partial cost, not the original estimate'
        );
    }

    // =================================================================
    // Scenario 2 -- a request admitted but never measurably consuming
    // anything leaves consumption unchanged, released via closeRun()'s
    // fallback (never reconciled, since recordUsage() never ran).
    // =================================================================

    #[Test]
    public function a_request_that_never_reaches_recordUsage_leaves_consumption_unchanged_and_is_released_via_the_fallback(): void
    {
        $this->declareCeiling('1000.00', 'stop');

        [$reservationId, $runId] = $this->admitAndOpenRun();

        $before = $this->userConsumption();
        $this->assertSame('0.0000000000', $before);

        // The run fails before ever reaching a model -- recordUsage() is
        // never called at all, so only closeRun()'s fallback can resolve
        // this reservation.
        app(RunTraceRecorder::class)->closeRun(
            $runId,
            RunEndState::Failed,
            'failed before reaching a model'
        );

        $reservation = CostReservation::find($reservationId);

        $this->assertSame(
            CostReservation::STATUS_RELEASED,
            $reservation->status,
            'a request that never measurably consumed anything must be released, not reconciled'
        );
        $this->assertNull(
            $reservation->actual_amount,
            'a released reservation carries no actual_amount -- nothing measurable ever happened'
        );
        $this->assertNotNull($reservation->resolved_at);

        $this->assertSame(
            0,
            bccomp($this->reservedTotal('user', $this->userA), '0.0000000000', 10),
            'the ledger must return to exactly zero via the fallback release'
        );

        $after = $this->userConsumption();
        $this->assertSame(
            0,
            bccomp($before, $after, 10),
            'FR-006: a request admitted but never measurably consuming anything must leave the allowance unchanged'
        );
        $this->assertSame('0.0000000000', $after);
    }
}
