<?php

namespace Tests\RealDatabase;

use ClarionApp\LlmClient\Services\EvalDashboardQuery;
use ClarionApp\LlmClient\Services\EvalPersistentFailureQuery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * FR-013 at real scale: a trend/persistent-failures read must cost what its
 * requested window/case-count implies, never what an agent's entire
 * lifetime of results implies. SQLite `:memory:` cannot decide this —
 * every unit test in this feature proves the *shape* of the two queries
 * (one table, one capped subquery per case), not their cost as history
 * grows without bound, which only an engine's own query planner and clock
 * can settle.
 *
 * Two properties, each requiring a real optimizer or a real clock:
 *  - trend() must never scan eval_case_results at all, and must use an
 *    index into eval_pass_rate_summaries rather than a full table scan —
 *    decidable only by reading the engine's own EXPLAIN output and query
 *    log, never by asserting on PHP source.
 *  - trend() and rankedFailures() must cost the same, wall-clock, at ten
 *    times the seeded history — decidable only by actually timing two real
 *    runs against two real data volumes.
 */
#[Group('real-db')]
class EvalDashboardQueryCostTest extends RealDatabaseTestCase
{
    protected array $seedTables = [
        'eval_case_results',
        'eval_runs',
        'eval_cases',
        'eval_suites',
        'eval_pass_rate_summaries',
    ];

    /** @var string[] */
    private const AGENT_LABELS = [
        'scale-agent-alpha',
        'scale-agent-beta',
        'scale-agent-gamma',
        'scale-agent-delta',
        'scale-agent-epsilon',
    ];

    private const CASES_PER_AGENT = 8;

    private const OUTCOME_CYCLE = ['pass', 'fail', 'pass', 'pass', 'errored', 'needs_human_review', 'unjudged', 'fail'];

    private const INSERT_CHUNK = 500;

    // -----------------------------------------------------------------
    // Property 1 — trend() never touches eval_case_results, and reads
    // eval_pass_rate_summaries through an index, not a full table scan
    // (mutation-checklist row 3).
    // -----------------------------------------------------------------

    #[Test]
    public function trend_reads_only_the_rollup_table_through_an_index_never_the_raw_case_results(): void
    {
        $this->assertReady();

        $caseIdsByAgent = $this->seedSuitesAndCases();
        $this->seedCaseResultHistory($caseIdsByAgent, resultsPerAgent: 2000, spanDays: 400);
        $this->rebuildRollup();

        $agentLabel = self::AGENT_LABELS[0];

        DB::enableQueryLog();
        DB::flushQueryLog();

        $rows = app(EvalDashboardQuery::class)->trend($agentLabel, 30);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotEmpty($rows, 'the seeded window should contain at least one bucket');

        $this->assertCount(
            1,
            $log,
            'trend() must issue exactly one query — no per-row or per-day follow-up query'
        );

        $sql = strtolower($log[0]['query']);
        $this->assertStringContainsString('eval_pass_rate_summaries', $sql);
        $this->assertStringNotContainsString(
            'eval_case_results',
            $sql,
            'trend() must never read eval_case_results directly — that is exactly the O(history) '
            .'scan the rollup table (D2) exists to avoid'
        );

        // EXPLAIN the identical query trend() itself runs, against the
        // engine's own optimizer — the only authority on whether it used
        // the (agent_label, period_date) index or fell back to a full
        // table scan.
        $builder = DB::table('eval_pass_rate_summaries')
            ->where('agent_label', $agentLabel)
            ->whereBetween('period_date', [now()->subDays(30)->toDateString(), now()->toDateString()])
            ->orderBy('period_date');

        $plan = DB::select('EXPLAIN '.$builder->toSql(), $builder->getBindings());

        $this->assertNotEmpty($plan, 'EXPLAIN produced no rows');
        $this->assertNotNull(
            $plan[0]->key ?? null,
            'trend()\'s query must resolve through an index (the (agent_label, period_date) composite), '
            .'not a full table scan — EXPLAIN reported key=NULL. Plan: '.json_encode($plan[0])
        );
    }

    // -----------------------------------------------------------------
    // Property 2 — cost tracks the requested window / an agent's own case
    // count, not total accumulated history: wall-clock time at 10x the
    // seeded history must not differ from the baseline by an order of
    // magnitude.
    // -----------------------------------------------------------------

    #[Test]
    public function trend_and_ranked_failures_cost_does_not_grow_an_order_of_magnitude_at_ten_times_the_history(): void
    {
        $this->assertReady();

        $caseIdsByAgent = $this->seedSuitesAndCases();
        $agentLabel = self::AGENT_LABELS[0];
        $agentCaseIds = $caseIdsByAgent[$agentLabel];

        // Baseline: a few thousand rows for the measured agent (plus the
        // other four agents, so the rollup/case tables are not
        // trivially single-agent), spread across ~400 days.
        $this->seedCaseResultHistory($caseIdsByAgent, resultsPerAgent: 2000, spanDays: 400);
        $this->rebuildRollup();

        $dashboardQuery = app(EvalDashboardQuery::class);
        $failureQuery = app(EvalPersistentFailureQuery::class);

        $baselineTrendSeconds = $this->timeIt(fn () => $dashboardQuery->trend($agentLabel, 30));
        $baselineFailuresSeconds = $this->timeIt(fn () => $failureQuery->rankedFailures($agentLabel, 10));

        // 10x: extend the same agents' history another ~4000 days further
        // into the past (never overlapping the baseline window), so total
        // row/bucket volume is roughly 10x the baseline while the
        // measured 30-day window and each case's own lookback-capped
        // recent history are unchanged.
        $this->seedCaseResultHistory(
            $caseIdsByAgent,
            resultsPerAgent: 18000,
            spanDays: 4400,
            startingDaysAgo: 400,
        );
        $this->rebuildRollup();

        $scaledTrendSeconds = $this->timeIt(fn () => $dashboardQuery->trend($agentLabel, 30));
        $scaledFailuresSeconds = $this->timeIt(fn () => $failureQuery->rankedFailures($agentLabel, 10));

        $totalBuckets = DB::table('eval_pass_rate_summaries')->count();
        $this->assertGreaterThan(
            1000,
            $totalBuckets,
            'the 10x seeding step should have produced a genuinely large rollup table, not a trivial one'
        );

        // A generous, environment-tolerant bound (this tier's own existing
        // style, per quickstart.md — not a tight microbenchmark): 10x the
        // history must not cost an order of magnitude more wall-clock
        // time. A small floor keeps the ratio meaningful when the
        // baseline itself measures near zero.
        $floor = 0.005;
        $trendRatio = $scaledTrendSeconds / max($baselineTrendSeconds, $floor);
        $failuresRatio = $scaledFailuresSeconds / max($baselineFailuresSeconds, $floor);

        $this->assertLessThan(
            10.0,
            $trendRatio,
            sprintf(
                'trend() cost grew %.2fx from baseline (%.4fs) to 10x history (%.4fs) — cost must track '
                .'window size, not total history size',
                $trendRatio,
                $baselineTrendSeconds,
                $scaledTrendSeconds
            )
        );

        $this->assertLessThan(
            10.0,
            $failuresRatio,
            sprintf(
                'rankedFailures() cost grew %.2fx from baseline (%.4fs) to 10x history (%.4fs) — cost must '
                .'track case count / lookback cap, not total history size',
                $failuresRatio,
                $baselineFailuresSeconds,
                $scaledFailuresSeconds
            )
        );
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * One eval_suites row and CASES_PER_AGENT eval_cases rows per agent —
     * the bounded set EvalPersistentFailureQuery::rankedFailures() resolves
     * its per-case subqueries from.
     *
     * @return array<string, array<int, string>> agent_label => case ids
     */
    private function seedSuitesAndCases(): array
    {
        $caseIdsByAgent = [];

        foreach (self::AGENT_LABELS as $agentLabel) {
            $suiteId = (string) Str::uuid();

            DB::table('eval_suites')->insert([
                'id' => $suiteId,
                'name' => 'Scale fixture suite for '.$agentLabel,
                'agent_identifier' => $agentLabel,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ]);

            $caseIds = [];

            for ($i = 0; $i < self::CASES_PER_AGENT; $i++) {
                $caseId = (string) Str::uuid();
                $caseIds[] = $caseId;

                DB::table('eval_cases')->insert([
                    'id' => $caseId,
                    'suite_id' => $suiteId,
                    'current_version_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'deleted_at' => null,
                ]);
            }

            $caseIdsByAgent[$agentLabel] = $caseIds;
        }

        return $caseIdsByAgent;
    }

    /**
     * For every agent, insert $resultsPerAgent eval_case_results rows
     * (each paired with its own one-case eval_runs row, so the
     * (run_id, eval_case_id) uniqueness constraint is trivially satisfied),
     * spread evenly across $spanDays days ending $startingDaysAgo days
     * before now — so a later call can extend history strictly further
     * into the past than an earlier one, never overlapping it.
     *
     * @param  array<string, array<int, string>>  $caseIdsByAgent
     */
    private function seedCaseResultHistory(
        array $caseIdsByAgent,
        int $resultsPerAgent,
        int $spanDays,
        int $startingDaysAgo = 0,
    ): void {
        foreach ($caseIdsByAgent as $agentLabel => $caseIds) {
            $runRows = [];
            $resultRows = [];

            for ($i = 0; $i < $resultsPerAgent; $i++) {
                $dayOffset = $startingDaysAgo + intdiv($i * $spanDays, max($resultsPerAgent, 1));
                $createdAt = now()->subDays($dayOffset)->setTime(12, 0, 0);
                $caseId = $caseIds[$i % count($caseIds)];
                $outcome = self::OUTCOME_CYCLE[$i % count(self::OUTCOME_CYCLE)];
                $runId = (string) Str::uuid();

                $runRows[] = [
                    'id' => $runId,
                    'suite_id' => (string) Str::uuid(),
                    'agent_label' => $agentLabel,
                    'server_id' => null,
                    'model' => null,
                    'status' => 'completed',
                    'case_count' => 1,
                    'failure_reason' => null,
                    'started_at' => $createdAt,
                    'completed_at' => $createdAt,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                $resultRows[] = [
                    'id' => (string) Str::uuid(),
                    'run_id' => $runId,
                    'eval_run_case_id' => (string) Str::uuid(),
                    'eval_case_id' => $caseId,
                    'eval_case_version_id' => (string) Str::uuid(),
                    'conversation_id' => (string) Str::uuid(),
                    'outcome' => $outcome,
                    'outcome_override' => null,
                    'produced_response' => 'scale fixture response',
                    'attempted_actions' => '[]',
                    'expectation_results' => '[]',
                    'error_message' => null,
                    'created_at' => $createdAt,
                ];

                if (count($runRows) >= self::INSERT_CHUNK) {
                    DB::table('eval_runs')->insert($runRows);
                    DB::table('eval_case_results')->insert($resultRows);
                    $runRows = [];
                    $resultRows = [];
                }
            }

            if ($runRows !== []) {
                DB::table('eval_runs')->insert($runRows);
                DB::table('eval_case_results')->insert($resultRows);
            }
        }
    }

    /**
     * Rebuild eval_pass_rate_summaries from whatever eval_case_results
     * exist right now — the same command an operator would run to
     * backfill/repair, exercised here at real scale rather than mocked.
     */
    private function rebuildRollup(): void
    {
        $exitCode = Artisan::call('llm-client:recompute-eval-pass-rate-summaries');

        $this->assertSame(0, $exitCode, 'recompute command failed: '.Artisan::output());
    }

    private function timeIt(callable $callback): float
    {
        $start = microtime(true);
        $callback();

        return microtime(true) - $start;
    }
}
