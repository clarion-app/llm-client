<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostReservation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\MetricsRecorder;
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
 * spec.md's unpriced-usage edge case ("What happens to usage for a model or
 * operation with no known price? It must be handled according to an
 * explicit, operator-chosen policy — never silently treated as free."),
 * FR-010/SC-007, and quickstart.md step 8's full integrated scenario.
 *
 * Deliberately placed in Phase 7 rather than alongside US1's own
 * BudgetGateReservationTest: the 'reserve_flat_estimate' branch's defining
 * property — the flat hold reconciles to *zero*, not to the flat estimate,
 * once the still-uncosted usage actually lands — needs both US1's
 * admission-time reservation *and* US2's MetricsRecorder::recordUsage()
 * reconciliation to exist for that assertion to mean anything at all. Driven
 * directly through BudgetGate::admit()/MetricsRecorder::recordUsage() (the
 * same production methods PartialConsumptionCountedJourneyTest and
 * BudgetGateReservationTest already drive), rather than the full HTTP entry
 * path, so each of the three policy branches can be exercised with precise
 * control over which model/config combination is in effect.
 *
 * Every monetary assertion is a plain-decimal-string bccomp(), never a
 * (float) cast.
 */
class UnpricedModelPolicyJourneyTest extends TestCase
{
    /** A model deliberately absent from model_prices for this entire file. */
    private const UNPRICED_MODEL = 'totally-unpriced-model';

    private string $userA;
    private Server $server;
    private Conversation $conversation;

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

        $this->conversation = Conversation::create([
            'server_id' => $this->server->id,
            'title' => 'Already titled',
            'model' => self::UNPRICED_MODEL,
            'character' => 'Clarion',
            'user_id' => $this->userA,
            'is_processing' => false,
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

    private function reservedTotal(): string
    {
        $row = DB::table('budget_reservation_ledger')
            ->where('scope_type', 'user')
            ->where('scope_id', $this->userA)
            ->first();

        return $row === null ? '0.0000000000' : (string) $row->reserved_total;
    }

    private function heldAmount(): string
    {
        return (string) $this->gate()->standingFor($this->userA)['user_ceiling']['held']['amount'];
    }

    private function consumptionAmount(): string
    {
        return (string) $this->gate()->standingFor($this->userA)['user_ceiling']['consumption']['amount'];
    }

    // =================================================================
    // 'stop' — the default: a 402, distinguishable from an ordinary
    // ceiling-reached refusal only by its underlying (degraded) cause
    // =================================================================

    #[Test]
    public function stop_declines_unpriced_work_under_a_stop_mode_ceiling_even_with_ample_headroom(): void
    {
        config(['llm-client.budget.on_unpriced_model' => 'stop']);
        $this->declareCeiling('1000.00', 'stop');

        try {
            $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $this->conversation->id);
            $this->fail('an unpriced model under the default policy must be declined under a stop-mode ceiling, even with ample headroom');
        } catch (BudgetExceededException $e) {
            $this->assertTrue($e->decision->isStop());
            $this->assertTrue(
                $e->decision->degraded,
                'the decline is caused by an unreadable price, not a genuine crossing — the shape is a 402 either way, only the cause differs'
            );
        }

        $this->assertSame(0, CostReservation::query()->count(), 'a declined admission places no reservation');
        $this->assertSame(0, bccomp($this->reservedTotal(), '0.0000000000', 10));
    }

    /**
     * Mutation-checklist row 7, written out as a literal assertion of the
     * shipped default rather than left implicit in the test above: flipping
     * this default to 'admit_untracked' is exactly the regression the row
     * exists to catch (the spec's own "never silently treated as free by
     * default").
     */
    #[Test]
    public function the_shipped_default_is_stop_not_admit_untracked(): void
    {
        $this->assertSame(
            'stop',
            (string) config('llm-client.budget.on_unpriced_model'),
            'FR-010/SC-007: unpriced usage must never be treated as free by the shipped default'
        );
    }

    /**
     * Mutation-checklist row 8: the 'stop' policy must never start blocking
     * a warn-only ceiling — a warn ceiling can never refuse admission,
     * regardless of what an unpriced model would otherwise trigger.
     */
    #[Test]
    public function stop_never_blocks_a_warn_mode_ceiling(): void
    {
        config(['llm-client.budget.on_unpriced_model' => 'stop']);
        $this->declareCeiling('1000.00', 'warn');

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $this->conversation->id);

        $this->assertSame(0, CostReservation::query()->count(), 'a warn ceiling never blocks on an unpriced model alone');
    }

    // =================================================================
    // 'admit_untracked' — the identical request now succeeds, held and
    // consumption are unaffected by it, and no reservation is created
    // =================================================================

    #[Test]
    public function admit_untracked_admits_unpriced_work_with_no_reservation_and_leaves_held_and_consumption_unaffected(): void
    {
        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);
        $this->declareCeiling('1000.00', 'stop');

        $heldBefore = $this->heldAmount();
        $consumptionBefore = $this->consumptionAmount();

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $this->conversation->id);

        $this->assertSame(0, CostReservation::query()->count(), "'admit_untracked' places no cost_reservations row at all");
        $this->assertSame(0, bccomp($this->reservedTotal(), '0.0000000000', 10));

        $this->assertSame(0, bccomp($this->heldAmount(), $heldBefore, 10), 'held is unaffected by admit_untracked work');
        $this->assertSame(
            0,
            bccomp($this->consumptionAmount(), $consumptionBefore, 10),
            'consumption is unaffected by admit_untracked work'
        );
    }

    // =================================================================
    // 'reserve_flat_estimate' — reserves exactly the configured flat
    // amount, then reconciles to zero (never to the flat figure) once the
    // still-uncosted usage actually lands
    // =================================================================

    #[Test]
    public function reserve_flat_estimate_holds_the_configured_flat_amount_then_reconciles_to_zero_once_the_uncosted_usage_lands(): void
    {
        config([
            'llm-client.budget.on_unpriced_model' => 'reserve_flat_estimate',
            'llm-client.budget.unpriced_model_flat_estimate' => '5.0000000000',
        ]);
        $this->declareCeiling('1000.00', 'stop');

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $this->conversation->id);

        $reservation = CostReservation::query()->where('status', CostReservation::STATUS_HELD)->first();
        $this->assertNotNull($reservation, "the policy must place a real reservation sized to the configured flat estimate");
        $this->assertSame(0, bccomp((string) $reservation->estimated_amount, '5.0000000000', 10));

        // While the turn is in flight, the flat estimate is genuinely held
        // — visible both in the ledger directly and via the standing report.
        $this->assertSame(0, bccomp($this->reservedTotal(), '5.0000000000', 10));
        $this->assertSame(0, bccomp($this->heldAmount(), '5.0000000000', 10));

        // The still-uncosted usage lands. cost_summaries.priced_cost_total
        // structurally cannot represent unpriced usage (073's own decision,
        // reused unchanged by this feature) — recordUsage() computes a null
        // total_cost for it, so reconciliation settles at zero, never at the
        // flat figure that stood in for it at admission time.
        app(MetricsRecorder::class)->recordUsage(
            $this->conversation->id,
            $this->userA,
            (string) Str::uuid(),
            ['prompt_tokens' => 40, 'completion_tokens' => 10, 'total_tokens' => 50],
            'irrelevant fallback input text',
            'irrelevant fallback output text',
            self::UNPRICED_MODEL,
            'anthropic',
        );

        $reservation->refresh();

        $this->assertSame(
            CostReservation::STATUS_RECONCILED,
            $reservation->status,
            'real (if uncosted) usage landed, so this is a reconciliation, not a fallback release'
        );
        $this->assertSame(
            0,
            bccomp((string) $reservation->actual_amount, '0.0000000000', 10),
            'reconciling to zero — not to the 5.00 flat estimate — since unpriced usage has no representable currency cost'
        );

        $this->assertSame(
            0,
            bccomp($this->reservedTotal(), '0.0000000000', 10),
            'the flat hold must return fully to zero, not settle at a nonzero residue'
        );
        $this->assertSame(0, bccomp($this->heldAmount(), '0.0000000000', 10));
    }

    /**
     * The flat-estimate policy follows the same stop/warn scoping as any
     * other policy value: when the configured flat amount would not fit the
     * remaining allowance under a stop-mode ceiling, admission is declined
     * exactly as it would be for a priced estimate that did not fit.
     */
    #[Test]
    public function reserve_flat_estimate_is_declined_when_the_flat_amount_would_not_fit_the_remaining_ceiling(): void
    {
        config([
            'llm-client.budget.on_unpriced_model' => 'reserve_flat_estimate',
            'llm-client.budget.unpriced_model_flat_estimate' => '5.0000000000',
        ]);
        // Room for well under the flat estimate.
        $this->declareCeiling('1.0000000000', 'stop');

        try {
            $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $this->conversation->id);
            $this->fail('a flat estimate that would not fit the remaining ceiling must be declined');
        } catch (BudgetExceededException $e) {
            $this->assertTrue($e->decision->isStop());
        }

        $this->assertSame(0, CostReservation::query()->count(), 'a decline places no reservation');
        $this->assertSame(0, bccomp($this->reservedTotal(), '0.0000000000', 10));
    }

    #[Test]
    public function reserve_flat_estimate_never_blocks_a_warn_mode_ceiling_even_when_the_flat_amount_would_not_fit(): void
    {
        config([
            'llm-client.budget.on_unpriced_model' => 'reserve_flat_estimate',
            'llm-client.budget.unpriced_model_flat_estimate' => '5.0000000000',
        ]);
        $this->declareCeiling('1.0000000000', 'warn');

        // A warn-mode axis is never passed to ReservationLedger::reserve()
        // at all (research.md D5, corrected) — nothing here can block, and
        // no reservation is placed on an axis that can never be bounded.
        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive, $this->conversation->id);

        $this->assertSame(0, CostReservation::query()->count());
        $this->assertSame(0, bccomp($this->reservedTotal(), '0.0000000000', 10));
    }
}
