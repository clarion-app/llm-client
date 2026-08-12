<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Models\CostReservation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\ReservationLedger;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\ReservationSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A user's standing report has to account for what is currently held in
 * reservation, not only what has already been recorded as spent — a
 * long-running turn that has reserved part of the allowance is exactly the
 * situation US4 exists to make legible, and a predictive decline (US1) is
 * only fair if the same figure that explains it was visible beforehand.
 *
 * spec.md US4 Acceptance Scenarios 1-2, FR-008/SC-005, contracts §1
 * (reservation-api.md):
 *
 *  - `held` is a sibling of `consumption` in every applicable block, present
 *    — never omitted — even when nothing is currently held.
 *  - `remaining` nets out consumption *and* held from the ceiling.
 *  - `held.available` can be false independently of `consumption.available`,
 *    and either one being false degrades the whole report.
 *  - Once a held turn resolves (reconciled or released), the hold
 *    disappears from standing on its own, with no operator action.
 */
class StandingIncludesHeldJourneyTest extends TestCase
{
    private const NOW = '2026-08-14 10:00:00';

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));

        $this->userA = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('cost_reservations')->delete();
        DB::table('budget_reservation_ledger')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures / helpers
    // ---------------------------------------------------------------

    private function selfEndpoint(): string
    {
        return '/api/clarion-app/llm-client/budget/standing';
    }

    /**
     * Mirrors the request-boundary discipline BudgetStandingJourneyTest
     * establishes: Laravel's test harness keeps one container across every
     * simulated request in a test method, while a deployment builds one per
     * request, so the scoped BudgetGate/BudgetLedger memo is discarded
     * explicitly before each call.
     */
    private function standing(): \Illuminate\Testing\TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->actingAs($this->userA, 'api')->getJson($this->selfEndpoint());
    }

    private function declareUserCeiling(string $amount, string $mode = 'stop'): SpendingCeiling
    {
        return app(SpendingCeilingService::class)->upsert(
            BudgetScope::User,
            $this->userA->id,
            ['amount' => $amount, 'period_type' => 'month', 'enforcement_mode' => $mode],
        );
    }

    private function recordSpend(string $amount, string $date = '2026-08-14'): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $this->userA->id,
            'user_id' => $this->userA->id,
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
     * Places a genuine reservation through the real ReservationLedger — the
     * same atomic path admit() uses — standing in for "a long-running turn
     * currently in flight".
     */
    private function placeReservation(string $amount): CostReservation
    {
        $reservation = app(ReservationLedger::class)->reserve(
            ['user:'.$this->userA->id],
            $amount,
            BudgetWorkKind::Interactive,
            $this->userA->id,
        );

        $this->assertNotNull($reservation, 'test setup expects the reservation itself to succeed');

        return $reservation;
    }

    private function assertDecimalString(mixed $actual, string $expected, string $message): void
    {
        $this->assertIsString($actual, $message.' — must be a decimal string, never a JSON number');
        $this->assertSame(0, bccomp($actual, $expected, 10), $message." — expected {$expected}, got {$actual}");
    }

    // ---------------------------------------------------------------
    // held is present, never omitted, and narrows remaining
    // ---------------------------------------------------------------

    #[Test]
    public function a_currently_held_reservation_is_visible_on_standing_and_narrows_remaining(): void
    {
        $this->declareUserCeiling('25.00');
        $this->recordSpend('5.0000000000');

        // Nothing held yet: the key is still present, at a real zero.
        $before = $this->standing()->assertStatus(200)->json('user_ceiling');

        $this->assertArrayHasKey('held', $before, 'held must be present even when nothing is currently held');
        $this->assertSame('0.0000000000', $before['held']['amount'], 'a real zero, not an omitted field');
        $this->assertTrue($before['held']['available']);
        $this->assertDecimalString($before['remaining'], '20.0000000000', 'remaining with nothing held');

        // A long-running turn now holds part of the allowance.
        $this->placeReservation('3.2000000000');

        $during = $this->standing()->assertStatus(200)->json('user_ceiling');

        $this->assertDecimalString($during['held']['amount'], '3.2000000000', 'the held figure while the turn is in flight');
        $this->assertTrue($during['held']['available']);
        $this->assertDecimalString(
            $during['remaining'],
            '16.8000000000',
            'remaining nets out consumption + held, not consumption alone'
        );
        $this->assertTrue(
            bccomp($during['remaining'], bcsub('25.00', '5.0000000000', 10), 10) < 0,
            'remaining while a reservation is outstanding must be smaller than ceiling - consumption alone would suggest'
        );
    }

    // ---------------------------------------------------------------
    // Completion (either resolution) releases the hold automatically
    // ---------------------------------------------------------------

    #[Test]
    public function once_a_held_turn_is_released_the_hold_returns_to_zero_and_remaining_widens_back_out(): void
    {
        $this->declareUserCeiling('25.00');
        $this->recordSpend('5.0000000000');

        $reservation = $this->placeReservation('3.2000000000');

        $narrowed = $this->standing()->assertStatus(200)->json('user_ceiling.remaining');
        $this->assertDecimalString($narrowed, '16.8000000000', 'remaining while the turn is in flight');

        app(ReservationLedger::class)->release($reservation);

        $after = $this->standing()->assertStatus(200)->json('user_ceiling');

        $this->assertSame('0.0000000000', $after['held']['amount'], 'the hold is gone once the turn is released');
        $this->assertDecimalString(
            $after['remaining'],
            '20.0000000000',
            'remaining widens back out automatically, with no operator action'
        );
    }

    #[Test]
    public function once_a_held_turn_is_reconciled_the_hold_also_returns_to_zero(): void
    {
        $this->declareUserCeiling('25.00');
        $this->recordSpend('5.0000000000');

        $reservation = $this->placeReservation('3.2000000000');

        app(ReservationLedger::class)->reconcile($reservation, '2.9000000000');

        $after = $this->standing()->assertStatus(200)->json('user_ceiling');

        $this->assertArrayHasKey('held', $after, 'held must be present on the report');
        $this->assertSame('0.0000000000', $after['held']['amount'], 'a reconciled turn no longer holds anything');
    }

    // ---------------------------------------------------------------
    // held.available can fail independently of consumption.available
    // ---------------------------------------------------------------

    #[Test]
    public function a_held_read_failure_degrades_the_report_while_consumption_still_renders_normally(): void
    {
        $this->declareUserCeiling('25.00');
        $this->recordSpend('5.0000000000');

        $this->app->instance(
            ReservationLedger::class,
            new StandingHeldUnavailableReservationLedger(app(SpendingCeilingService::class)),
        );

        $response = $this->standing();
        $response->assertStatus(200, 'a standing report that cannot read a held figure still answers');

        $this->assertTrue(
            $response->json('degraded'),
            'a held-read failure must degrade the top-level report, independent of consumption'
        );

        $block = $response->json('user_ceiling');

        $this->assertTrue($block['consumption']['available'], "the consumption figure itself is unaffected and still renders");
        $this->assertDecimalString($block['consumption']['amount'], '5.0000000000', 'consumption renders normally');

        $this->assertArrayHasKey('held', $block);
        $this->assertFalse($block['held']['available']);
        $this->assertNull($block['held']['amount']);
    }

    // ---------------------------------------------------------------
    // A predictive decline is explained by the exact figure checked
    // beforehand (spec.md US4 Acceptance Scenario 2)
    // ---------------------------------------------------------------

    #[Test]
    public function a_predictive_decline_is_explained_by_the_same_remaining_figure_standing_already_showed(): void
    {
        $this->declareUserCeiling('25.00');
        $this->recordSpend('5.0000000000');

        // A turn already in flight holds exactly what is left of the
        // allowance — the next request has nothing to be admitted against.
        $this->placeReservation('20.0000000000');

        $before = $this->standing()->assertStatus(200)->json('user_ceiling');

        $this->assertSame(
            '0.0000000000',
            $before['remaining'],
            'nothing left once consumption + held reach the ceiling — this is the figure the user could have checked'
        );
        $this->assertTrue($before['reached']);

        $this->app->forgetScopedInstances();

        try {
            app(BudgetGate::class)->admit($this->userA->id, BudgetWorkKind::Interactive);
            $this->fail('a request with no plausible remaining allowance to admit against must be declined');
        } catch (BudgetExceededException $e) {
            $this->assertTrue($e->decision->isStop());
        }

        $after = $this->standing()->assertStatus(200)->json('user_ceiling');

        $this->assertSame(
            $before['remaining'],
            $after['remaining'],
            'the decline is explained by, and leaves unchanged, the exact remaining figure standing already showed'
        );
        $this->assertSame(
            $before['held']['amount'],
            $after['held']['amount'],
            'a decline places no reservation of its own'
        );
    }
}

/**
 * A ReservationLedger whose heldFor() always reports an unreadable figure —
 * the held-side counterpart of BudgetStandingJourneyTest's own
 * StandingUnreadableCostRollupQuery, proving the two failure surfaces are
 * independent (contract point 2).
 */
class StandingHeldUnavailableReservationLedger extends ReservationLedger
{
    public function heldFor(string $scopeKey): ReservationSnapshot
    {
        return ReservationSnapshot::unavailable();
    }
}
