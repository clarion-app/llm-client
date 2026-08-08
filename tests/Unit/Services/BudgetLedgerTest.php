<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Services\BudgetLedger;
use ClarionApp\LlmClient\Services\CostRollupQuery;
use ClarionApp\LlmClient\Support\CalendarPeriod;
use ClarionApp\LlmClient\ValueObjects\ConsumptionSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for BudgetLedger — the sole reader of current-period
 * consumption.
 *
 *   forUser(string $userId, string $periodType): ConsumptionSnapshot
 *   forInstallation(string $periodType): ConsumptionSnapshot
 *   forget(?string $scopeKey = null): void
 *
 * Three properties here are load-bearing rather than incidental:
 *
 *  - The installation figure is the exact arithmetic sum of the per-user
 *    figures, because it is computed from the same entity_type='user' rows.
 *    The conversation and agent rows are separate dimensions over the same
 *    money; summing any of them together would double-count.
 *  - A read that throws yields available = false, never a zero and never a
 *    partial figure. A zero here would read as "nothing spent" and let work
 *    straight through a ceiling.
 *  - The memo lives on the instance and nowhere else. A queue worker keeps
 *    one container across many jobs and flushes only scoped instances
 *    between them, so anything longer-lived than a scoped binding would
 *    carry one job's consumption figure into every later job for the life
 *    of the worker.
 */
class BudgetLedgerTest extends TestCase
{
    private string $userA;
    private string $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = (string) Str::uuid();
        $this->userB = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('cost_summaries')) {
            DB::table('cost_summaries')->delete();
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function ledger(): BudgetLedger
    {
        return new BudgetLedger(new CostRollupQuery());
    }

    private function seedSummary(string $entityType, string $entityId, string $userId, string $date, array $overrides = []): void
    {
        DB::table('cost_summaries')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $userId,
            'period_date' => $date,
            'request_count' => 1,
            'priced_cost_total' => '0',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ], $overrides));
    }

    /**
     * Count only the queries that actually touch cost_summaries, so an
     * unrelated framework query can never make a memoization assertion pass
     * or fail by accident.
     */
    private function countConsumptionQueries(callable $fn): int
    {
        $count = 0;

        DB::listen(function ($query) use (&$count) {
            if (str_contains($query->sql, 'cost_summaries')) {
                $count++;
            }
        });

        $fn();

        // Testbench has no public "stop listening" hook; the closure simply
        // stops being consulted once this method returns and $count is read.
        return $count;
    }

    // ---------------------------------------------------------------
    // forUser()
    // ---------------------------------------------------------------

    #[Test]
    public function for_user_sums_exactly_the_rows_inside_the_current_period_and_no_others(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));

        try {
            // Inside the current month.
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-08-01', ['priced_cost_total' => '1.2500000000']);
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-08-14', ['priced_cost_total' => '2.7500000000']);
            // Outside it, on both sides.
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-07-31', ['priced_cost_total' => '100.0000000000']);
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-09-01', ['priced_cost_total' => '200.0000000000']);
            // Another user's usage is never mixed in.
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userB, $this->userB, '2026-08-14', ['priced_cost_total' => '9.0000000000']);

            $snapshot = $this->ledger()->forUser($this->userA, 'month');

            $this->assertInstanceOf(ConsumptionSnapshot::class, $snapshot);
            $this->assertTrue($snapshot->available);
            $this->assertSame('4.0000000000', $snapshot->amount);
            $this->assertSame(2, $snapshot->requestCount);
            $this->assertSame('month', $snapshot->periodType);
            $this->assertSame('2026-08-01', $snapshot->periodFrom);
            $this->assertSame('2026-08-31', $snapshot->periodTo);
            $this->assertSame('2026-09-01T00:00:00+00:00', $snapshot->resetsAt->toIso8601String());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function for_user_honours_each_period_type_boundary(): void
    {
        // Friday 2026-08-07: its day is the 7th, its ISO week is Mon 3rd to
        // Sun 9th, its month is August.
        Carbon::setTestNow(Carbon::parse('2026-08-07 10:00:00', 'UTC'));

        try {
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-08-07', ['priced_cost_total' => '1.0000000000']);
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-08-03', ['priced_cost_total' => '2.0000000000']);
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-08-25', ['priced_cost_total' => '4.0000000000']);

            $this->assertSame('1.0000000000', $this->ledger()->forUser($this->userA, 'day')->amount);
            $this->assertSame('3.0000000000', $this->ledger()->forUser($this->userA, 'week')->amount);
            $this->assertSame('7.0000000000', $this->ledger()->forUser($this->userA, 'month')->amount);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function a_scope_with_no_usage_in_the_period_reads_as_zero_and_available(): void
    {
        $snapshot = $this->ledger()->forUser($this->userA, 'month');

        $this->assertTrue($snapshot->available, 'No usage yet is not the same thing as an unreadable figure');
        $this->assertSame('0.0000000000', $snapshot->amount);
        $this->assertSame(0, $snapshot->requestCount);
        $this->assertSame(0, $snapshot->unpricedRequestCount);
        $this->assertSame(0, $snapshot->unpricedTotalTokens);
        $this->assertFalse($snapshot->hasEstimatedCost);
    }

    #[Test]
    public function the_unpriced_and_estimated_columns_are_carried_through(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));

        try {
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-08-10', [
                'priced_cost_total' => '3.0000000000',
                'request_count' => 5,
                'unpriced_request_count' => 2,
                'unpriced_total_tokens' => 1200,
                'estimated_request_count' => 0,
            ]);
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-08-11', [
                'priced_cost_total' => '1.0000000000',
                'request_count' => 3,
                'unpriced_request_count' => 1,
                'unpriced_total_tokens' => 800,
                'estimated_request_count' => 1,
            ]);

            $snapshot = $this->ledger()->forUser($this->userA, 'month');

            $this->assertSame('4.0000000000', $snapshot->amount);
            $this->assertSame(8, $snapshot->requestCount);
            $this->assertSame(3, $snapshot->unpricedRequestCount);
            $this->assertSame(2000, $snapshot->unpricedTotalTokens);
            $this->assertTrue($snapshot->hasEstimatedCost);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---------------------------------------------------------------
    // forInstallation()
    // ---------------------------------------------------------------

    #[Test]
    public function for_installation_is_the_exact_sum_of_every_users_own_figure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));

        try {
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-08-02', ['priced_cost_total' => '1.1111111111']);
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-08-09', ['priced_cost_total' => '2.2222222222']);
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userB, $this->userB, '2026-08-09', ['priced_cost_total' => '3.3333333333']);

            $ledger = $this->ledger();

            $a = $ledger->forUser($this->userA, 'month')->amount;
            $b = $ledger->forUser($this->userB, 'month')->amount;
            $installation = $ledger->forInstallation('month')->amount;

            $this->assertSame('3.3333333333', $a);
            $this->assertSame('3.3333333333', $b);
            $this->assertSame(bcadd($a, $b, 10), $installation, 'The installation figure must be the arithmetic sum of the per-user figures');
            $this->assertSame('6.6666666666', $installation);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function for_installation_reads_the_user_dimension_only_and_never_the_conversation_or_agent_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));

        try {
            // The same money recorded across three dimensions, with
            // deliberately different totals per dimension so confusing them
            // is arithmetically visible rather than coincidentally equal.
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-08-05', ['priced_cost_total' => '5.0000000000']);
            $this->seedSummary(CostSummary::ENTITY_CONVERSATION, (string) Str::uuid(), $this->userA, '2026-08-05', ['priced_cost_total' => '70.0000000000']);
            $this->seedSummary(CostSummary::ENTITY_AGENT, (string) Str::uuid(), $this->userA, '2026-08-05', ['priced_cost_total' => '900.0000000000']);

            $this->assertSame('5.0000000000', $this->ledger()->forInstallation('month')->amount);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function an_installation_with_no_usage_reads_as_zero_and_available(): void
    {
        $snapshot = $this->ledger()->forInstallation('day');

        $this->assertTrue($snapshot->available);
        $this->assertSame('0.0000000000', $snapshot->amount);
        $this->assertSame(0, $snapshot->requestCount);
    }

    #[Test]
    public function the_installation_period_matches_calendar_period_containing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 10:00:00', 'UTC'));

        try {
            [$from, $to] = CalendarPeriod::containing('week');

            $snapshot = $this->ledger()->forInstallation('week');

            $this->assertSame($from, $snapshot->periodFrom);
            $this->assertSame($to, $snapshot->periodTo);
            $this->assertSame(
                CalendarPeriod::resetsAt('week', $to)->toIso8601String(),
                $snapshot->resetsAt->toIso8601String()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---------------------------------------------------------------
    // Unreadable consumption
    // ---------------------------------------------------------------

    #[Test]
    public function a_throwing_read_yields_an_unavailable_snapshot_rather_than_a_zero(): void
    {
        $ledger = new BudgetLedger(new ThrowingCostRollupQuery());

        $user = $ledger->forUser($this->userA, 'month');
        $installation = $ledger->forInstallation('month');

        foreach (['user' => $user, 'installation' => $installation] as $label => $snapshot) {
            $this->assertFalse($snapshot->available, "{$label} scope must report the figure as unavailable");
            $this->assertNull($snapshot->amount, "{$label} scope must not report a zero amount");
            $this->assertNull($snapshot->requestCount);
            $this->assertNull($snapshot->unpricedRequestCount);
            $this->assertNull($snapshot->unpricedTotalTokens);
            $this->assertNull($snapshot->hasEstimatedCost);

            // The period is still known — only the consumption figure is not.
            $this->assertSame('month', $snapshot->periodType);
            $this->assertNotSame('', $snapshot->periodFrom);
            $this->assertNotSame('', $snapshot->periodTo);
        }

        // An unavailable snapshot omits the figures from its wire shape
        // entirely; an omitted figure cannot be misread as "nothing spent".
        $body = $user->toArray();
        $this->assertFalse($body['available']);
        $this->assertArrayNotHasKey('amount', $body);
        $this->assertArrayNotHasKey('request_count', $body);
        $this->assertArrayNotHasKey('unpriced_request_count', $body);
        $this->assertArrayNotHasKey('has_estimated_cost', $body);
    }

    #[Test]
    public function a_missing_cost_summaries_table_yields_an_unavailable_snapshot(): void
    {
        Schema::drop('cost_summaries');

        $snapshot = $this->ledger()->forUser($this->userA, 'day');

        $this->assertFalse($snapshot->available);
        $this->assertNull($snapshot->amount);
    }

    // ---------------------------------------------------------------
    // Memoization and lifetime
    // ---------------------------------------------------------------

    #[Test]
    public function two_reads_of_the_same_scope_and_period_issue_one_query(): void
    {
        $ledger = $this->ledger();

        $queries = $this->countConsumptionQueries(function () use ($ledger) {
            $ledger->forUser($this->userA, 'month');
            $ledger->forUser($this->userA, 'month');
            $ledger->forUser($this->userA, 'month');
        });

        $this->assertSame(1, $queries, 'The memo must serve the second and third read');
    }

    #[Test]
    public function the_memo_is_keyed_by_scope_and_period_so_a_different_scope_or_period_still_reads(): void
    {
        $ledger = $this->ledger();

        $queries = $this->countConsumptionQueries(function () use ($ledger) {
            $ledger->forUser($this->userA, 'month');   // 1
            $ledger->forUser($this->userA, 'day');     // 2 — different period
            $ledger->forUser($this->userB, 'month');   // 3 — different user
            $ledger->forInstallation('month');         // 4 — different scope kind
            $ledger->forUser($this->userA, 'month');   // memo hit
            $ledger->forInstallation('month');         // memo hit
        });

        $this->assertSame(4, $queries);
    }

    #[Test]
    public function a_fresh_instance_re_reads_rather_than_inheriting_another_instances_memo(): void
    {
        $first = $this->ledger();
        $first->forUser($this->userA, 'month');

        $second = $this->ledger();

        $queries = $this->countConsumptionQueries(function () use ($second) {
            $second->forUser($this->userA, 'month');
        });

        $this->assertSame(1, $queries, 'Nothing about the memo may be static or otherwise survive the instance');
    }

    #[Test]
    public function the_container_binding_is_scoped_so_a_worker_flushing_scoped_instances_re_reads(): void
    {
        $first = app(BudgetLedger::class);
        $this->assertSame($first, app(BudgetLedger::class), 'Within one request or job the same instance must be shared');

        $first->forUser($this->userA, 'month');

        // What a queue worker does between jobs.
        app()->forgetScopedInstances();

        $second = app(BudgetLedger::class);
        $this->assertNotSame($first, $second, 'A scoped binding must not survive forgetScopedInstances(); a singleton would');

        $queries = $this->countConsumptionQueries(function () use ($second) {
            $second->forUser($this->userA, 'month');
        });

        $this->assertSame(1, $queries, 'A stale figure carried from the previous job would let work through after a crossing');
    }

    #[Test]
    public function forget_discards_the_memo_so_the_next_read_sees_a_just_committed_increment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));

        try {
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-08-14', ['priced_cost_total' => '1.0000000000']);

            $ledger = $this->ledger();
            $this->assertSame('1.0000000000', $ledger->forUser($this->userA, 'month')->amount);

            // An increment lands after the first read — precisely the moment
            // at which the memo's premise ("the figure cannot change during
            // this request") stops being true.
            DB::table('cost_summaries')
                ->where('entity_type', CostSummary::ENTITY_USER)
                ->where('entity_id', $this->userA)
                ->update(['priced_cost_total' => '4.0000000000']);

            $this->assertSame(
                '1.0000000000',
                $ledger->forUser($this->userA, 'month')->amount,
                'Precondition: without forget() the memo is still serving the pre-increment figure'
            );

            $ledger->forget();

            $this->assertSame('4.0000000000', $ledger->forUser($this->userA, 'month')->amount);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function forget_can_discard_a_single_scope_without_discarding_the_others(): void
    {
        $ledger = $this->ledger();
        $ledger->forUser($this->userA, 'month');
        $ledger->forUser($this->userB, 'month');
        $ledger->forInstallation('month');

        $ledger->forget('user:'.$this->userA);

        $queries = $this->countConsumptionQueries(function () use ($ledger) {
            $ledger->forUser($this->userA, 'month');   // re-reads
            $ledger->forUser($this->userB, 'month');   // still memoized
            $ledger->forInstallation('month');         // still memoized
        });

        $this->assertSame(1, $queries);
    }

    // ---------------------------------------------------------------
    // No floats anywhere
    // ---------------------------------------------------------------

    #[Test]
    public function every_returned_figure_is_a_plain_decimal_string(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));

        try {
            // A magnitude small enough that a float round-trip would render
            // it in scientific notation, which bcmath rejects outright.
            $this->seedSummary(CostSummary::ENTITY_USER, $this->userA, $this->userA, '2026-08-14', ['priced_cost_total' => '0.0000342000']);

            $amount = $this->ledger()->forUser($this->userA, 'month')->amount;

            $this->assertIsString($amount);
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{10}$/', $amount);
            $this->assertSame('0.0000342000', $amount);
            $this->assertSame(0, bccomp($amount, '0.0000342', 10));
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function the_class_contains_no_float_cast(): void
    {
        $source = file_get_contents((new \ReflectionClass(BudgetLedger::class))->getFileName());

        $this->assertStringNotContainsString('(float)', $source);
        $this->assertStringNotContainsString('(double)', $source);
        $this->assertStringNotContainsString('floatval', $source);
    }
}

/**
 * A CostRollupQuery whose reads fail in a way scoped to this one query —
 * a lock timeout, a malformed decimal, a connection error on that
 * statement — rather than a simulated total outage, which would fail the
 * request for unrelated reasons long before the ledger mattered.
 */
class ThrowingCostRollupQuery extends CostRollupQuery
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
