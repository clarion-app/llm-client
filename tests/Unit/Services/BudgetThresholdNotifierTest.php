<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\SpendingCeilingReached;
use ClarionApp\LlmClient\Events\SpendingThresholdWarned;
use ClarionApp\LlmClient\Models\BudgetThresholdNotification;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\BudgetLedger;
use ClarionApp\LlmClient\Services\BudgetThresholdNotifier;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The once-per-period latch, and the two things about it that are easy to
 * get subtly wrong.
 *
 * **The latch is a durable row, not a cache flag.** "At most one warning
 * per threshold per scope per period" is a zero-tolerance criterion, and a
 * cache flush, an eviction, or a Redis restart would let a duplicate
 * through. The unique index on
 * (scope_type, scope_id, period_type, period_start, kind) is the atomic
 * cross-worker test-and-set: insertOrIgnore returns 1 for the process that
 * won it and 0 for every other. Because period_start is part of the key, a
 * new period is a new key — nothing has to be cleared and no reset job
 * exists.
 *
 * **The memo has to be discarded first.** BudgetLedger memoizes consumption
 * for the life of a request or job, and on an interactive path the gate has
 * already read that figure earlier in the same request — before this unit
 * of work's cost was added. Comparing against the memo means every
 * threshold test runs one unit of work behind, and the crossing unit's own
 * warning fires late or, for the common case of one unit carrying a scope
 * over its threshold near the end of a period, never. This is the one place
 * in the feature where the memo is deliberately thrown away, because its
 * premise — "the number cannot change during this request" — is false
 * precisely when the increment has just happened.
 *
 * `consumption_at_fire` is audit, not accounting: it records *why* a
 * warning fired and is never read back by enforcement, which is asserted
 * here against the query log rather than left as a comment.
 */
class BudgetThresholdNotifierTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('budget_threshold_notifications')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures — deliberately not named seed(); Orchestra's TestCase
    // declares a public seed() of its own.
    // ---------------------------------------------------------------

    private function notifier(): BudgetThresholdNotifier
    {
        return app(BudgetThresholdNotifier::class);
    }

    private function declareCeiling(
        string $amount = '25.00',
        string $mode = 'stop',
        string $periodType = 'day',
        string $threshold = '0.80',
        BudgetScope $scope = BudgetScope::UserDefault,
    ): SpendingCeiling {
        return app(SpendingCeilingService::class)->upsert(
            $scope,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            [
                'amount' => $amount,
                'period_type' => $periodType,
                'enforcement_mode' => $mode,
                'approach_threshold' => $threshold,
            ],
        );
    }

    private function recordSpend(string $amount, string $date = '2026-08-14'): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => (string) $this->user->id,
            'user_id' => (string) $this->user->id,
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

    private function setSpend(string $amount, string $date = '2026-08-14'): void
    {
        DB::table('cost_summaries')
            ->where('entity_type', CostSummary::ENTITY_USER)
            ->where('entity_id', (string) $this->user->id)
            ->where('period_date', $date)
            ->update(['priced_cost_total' => $amount]);
    }

    /** @return array<int, object> */
    private function rows(?string $kind = null): array
    {
        $query = DB::table('budget_threshold_notifications');

        if ($kind !== null) {
            $query->where('kind', $kind);
        }

        return $query->get()->all();
    }

    // ---------------------------------------------------------------
    // The latch row
    // ---------------------------------------------------------------

    #[Test]
    public function the_latch_row_carries_the_full_key_and_the_figure_that_fired_it(): void
    {
        $ceiling = $this->declareCeiling();
        $this->recordSpend('21.0000000000');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->notifier()->notify((string) $this->user->id);

        $rows = $this->rows('approach');
        $this->assertCount(1, $rows);

        $row = $rows[0];

        $this->assertSame('user', $row->scope_type);
        $this->assertSame((string) $this->user->id, (string) $row->scope_id);
        $this->assertSame('day', $row->period_type);
        $this->assertSame('2026-08-14', substr((string) $row->period_start, 0, 10));
        $this->assertSame(BudgetThresholdNotification::KIND_APPROACH, $row->kind);
        $this->assertSame($ceiling->id, $row->ceiling_id);
        $this->assertSame(0, bccomp((string) $row->consumption_at_fire, '21.00', 10));
        $this->assertNotNull($row->created_at);
    }

    #[Test]
    public function a_second_call_for_the_same_key_writes_no_row_and_dispatches_no_event(): void
    {
        $this->declareCeiling();
        $this->recordSpend('21.0000000000');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->notifier()->notify((string) $this->user->id);
        $this->notifier()->notify((string) $this->user->id);
        $this->notifier()->notify((string) $this->user->id);

        $this->assertCount(1, $this->rows('approach'));
        Event::assertDispatchedTimes(SpendingThresholdWarned::class, 1);
    }

    /**
     * approach and reached are different kinds, so both can fire once each
     * in the same period. A latch keyed without `kind` would let the first
     * of the two suppress the second — and the one it suppressed would be
     * the more important of the two.
     */
    #[Test]
    public function kind_distinguishes_approach_from_reached_so_both_fire_once_each_in_one_period(): void
    {
        $this->declareCeiling('25.00', 'warn');
        $this->recordSpend('30.0000000000');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->notifier()->notify((string) $this->user->id);

        Event::assertDispatchedTimes(SpendingThresholdWarned::class, 1);
        Event::assertDispatchedTimes(SpendingCeilingReached::class, 1);

        $kinds = array_map(fn ($row) => $row->kind, $this->rows());
        sort($kinds);

        $this->assertSame(
            [BudgetThresholdNotification::KIND_APPROACH, BudgetThresholdNotification::KIND_REACHED],
            $kinds
        );

        // ...and neither fires a second time.
        $this->notifier()->notify((string) $this->user->id);

        $this->assertCount(2, $this->rows());
        Event::assertDispatchedTimes(SpendingThresholdWarned::class, 1);
        Event::assertDispatchedTimes(SpendingCeilingReached::class, 1);
    }

    /**
     * ceiling_id is audit, recorded without a foreign key, so history
     * survives the ceiling it describes. A cascade here would erase the
     * record of a warning at the exact moment an operator changed the
     * policy it was about.
     */
    #[Test]
    public function the_history_row_survives_deletion_of_the_ceiling_that_fired_it(): void
    {
        $ceiling = $this->declareCeiling();
        $this->recordSpend('21.0000000000');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->notifier()->notify((string) $this->user->id);

        $this->assertCount(1, $this->rows('approach'));

        SpendingCeiling::withTrashed()->where('id', $ceiling->id)->forceDelete();

        $rows = $this->rows('approach');
        $this->assertCount(1, $rows, 'Removing a ceiling must not cascade over the warning history');
        $this->assertSame($ceiling->id, $rows[0]->ceiling_id, 'The audit reference is kept, dangling and harmless');
    }

    /**
     * consumption_at_fire is a record of *why*, never a second tally. If
     * enforcement ever read it, two figures would exist for one question
     * and they would eventually disagree.
     */
    #[Test]
    public function enforcement_never_queries_the_notification_table_for_its_decision(): void
    {
        $this->declareCeiling();
        $this->recordSpend('21.0000000000');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);
        $this->notifier()->notify((string) $this->user->id);
        $this->assertCount(1, $this->rows('approach'));

        $seen = [];
        DB::listen(function ($query) use (&$seen) {
            if (str_contains($query->sql, 'budget_threshold_notifications')) {
                $seen[] = $query->sql;
            }
        });

        $decision = app(BudgetGate::class)->evaluate((string) $this->user->id);

        $this->assertNotNull($decision->outcome);
        $this->assertSame(
            [],
            $seen,
            "The gate read the warning history to reach a decision:\n".implode("\n", $seen)
        );
    }

    // ---------------------------------------------------------------
    // The memo is discarded before the comparison
    // ---------------------------------------------------------------

    /**
     * The crossing unit's own warning must fire on the crossing, not one
     * unit later. Primed here exactly the way a real request primes it: the
     * gate reads consumption at the top of the request, the unit of work
     * completes and increments it, and the notifier then has to compare
     * against the new figure rather than the one it was handed earlier.
     */
    #[Test]
    public function the_notifier_compares_against_the_post_increment_figure_not_the_memo(): void
    {
        $this->declareCeiling('25.00', 'stop', 'day', '0.80');
        $this->recordSpend('10.0000000000');

        // The gate's read, earlier in this same request: 10.00, well below
        // the 20.00 threshold. Memoized on the scoped BudgetLedger instance.
        $ledger = app(BudgetLedger::class);
        $primed = $ledger->forUser((string) $this->user->id, 'day');
        $this->assertSame(0, bccomp((string) $primed->amount, '10.00', 10));

        // The unit of work completes and the figure changes underneath the
        // memo.
        $this->setSpend('21.0000000000');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->notifier()->notify((string) $this->user->id);

        Event::assertDispatchedTimes(SpendingThresholdWarned::class, 1);

        $rows = $this->rows('approach');
        $this->assertCount(1, $rows);
        $this->assertSame(
            0,
            bccomp((string) $rows[0]->consumption_at_fire, '21.00', 10),
            'The figure recorded must be the post-increment one — a memoized 10.00 would never have crossed at all'
        );
    }

    // ---------------------------------------------------------------
    // Failure isolation
    // ---------------------------------------------------------------

    /**
     * A notifier failure is never the caller's problem. The caller is the
     * metrics path and the gate, and neither may be turned into a failure
     * by a broadcast that could not be delivered.
     */
    #[Test]
    public function a_broadcast_failure_is_swallowed_and_logged_rather_than_propagated(): void
    {
        $this->declareCeiling();
        $this->recordSpend('21.0000000000');

        Log::spy();

        Event::listen(SpendingThresholdWarned::class, function () {
            throw new \RuntimeException('the broadcaster is down');
        });

        $this->notifier()->notify((string) $this->user->id);

        $this->addToAssertionCount(1); // reaching this line is the assertion

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    #[Test]
    public function a_notifier_failure_never_propagates_out_of_notify(): void
    {
        $this->declareCeiling();
        $this->recordSpend('21.0000000000');

        Event::listen(SpendingCeilingReached::class, function () {
            throw new \RuntimeException('boom');
        });
        Event::listen(SpendingThresholdWarned::class, function () {
            throw new \RuntimeException('boom');
        });

        $this->setSpend('30.0000000000');

        $this->notifier()->notify((string) $this->user->id);

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------
    // Nothing to warn about
    // ---------------------------------------------------------------

    #[Test]
    public function no_ceiling_configured_produces_no_row_and_no_event(): void
    {
        $this->recordSpend('1000.0000000000');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->notifier()->notify((string) $this->user->id);

        $this->assertSame([], $this->rows());
        Event::assertNotDispatched(SpendingThresholdWarned::class);
        Event::assertNotDispatched(SpendingCeilingReached::class);
    }

    #[Test]
    public function consumption_below_the_threshold_produces_no_row_and_no_event(): void
    {
        $this->declareCeiling();
        $this->recordSpend('19.9999999999');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->notifier()->notify((string) $this->user->id);

        $this->assertSame([], $this->rows());
        Event::assertNotDispatched(SpendingThresholdWarned::class);
    }

    /**
     * A null user means the installation scope alone — the same shape the
     * gate accepts, for the paths that have no user to attribute work to.
     */
    #[Test]
    public function a_null_user_evaluates_the_installation_scope_alone(): void
    {
        $this->declareCeiling('25.00', 'warn', 'day', '0.80', BudgetScope::Installation);
        $this->recordSpend('21.0000000000');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->notifier()->notify(null);

        $rows = $this->rows('approach');
        $this->assertCount(1, $rows);
        $this->assertSame('installation', $rows[0]->scope_type);
        $this->assertSame(SpendingCeiling::INSTALLATION_SCOPE_ID, (string) $rows[0]->scope_id);
    }
}
