<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalPassRateSummary;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Services\EvalDashboardQuery;
use ClarionApp\LlmClient\ValueObjects\EvalCaseOutcome;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * currentPassRate() delegates to the existing, already-tested
 * EvalRunService::summarize() for an agent's most recently Completed run
 * (research.md D8) -- never a rollup read -- and is null when no such run
 * exists, regardless of how many in_progress/incomplete runs exist for the
 * same agent. trend() reads eval_pass_rate_summaries only, scoped to
 * (agent_label, period_date BETWEEN ...), with a requested window clamped
 * to config('llm-client.eval_dashboard.max_trend_window_days') and a day
 * with zero activity simply absent from the result, never a zero-row.
 */
class EvalDashboardQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('eval_pass_rate_summaries')->delete();
        DB::table('eval_case_results')->delete();
        DB::table('eval_runs')->delete();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    private function query(): EvalDashboardQuery
    {
        return app(EvalDashboardQuery::class);
    }

    private function makeRun(string $agentLabel, EvalRunStatus $status, int $caseCount, ?Carbon $completedAt = null): EvalRun
    {
        $run = new EvalRun();
        $run->suite_id = (string) Str::uuid();
        $run->agent_label = $agentLabel;
        $run->status = $status;
        $run->case_count = $caseCount;
        $run->started_at = now();
        $run->completed_at = $completedAt;
        $run->save();

        return $run;
    }

    private function makeResult(EvalRun $run, EvalCaseOutcome $outcome): EvalCaseResult
    {
        return EvalCaseResult::create([
            'run_id' => $run->id,
            'eval_run_case_id' => (string) Str::uuid(),
            'eval_case_id' => (string) Str::uuid(),
            'eval_case_version_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'outcome' => $outcome,
            'produced_response' => 'a response',
            'attempted_actions' => [],
            'expectation_results' => [],
        ]);
    }

    private function seedBucket(string $agentLabel, string $periodDate, int $pass = 0, int $fail = 0): EvalPassRateSummary
    {
        return EvalPassRateSummary::create([
            'agent_label' => $agentLabel,
            'period_date' => $periodDate,
            'pass_count' => $pass,
            'fail_count' => $fail,
            'needs_human_review_count' => 0,
            'errored_count' => 0,
            'unjudged_count' => 0,
            'total_count' => $pass + $fail,
        ]);
    }

    // ---------------------------------------------------------------
    // currentPassRate()
    // ---------------------------------------------------------------

    #[Test]
    public function current_pass_rate_is_null_for_an_agent_with_zero_completed_runs_even_with_in_progress_and_incomplete_runs_present(): void
    {
        $agentLabel = 'quality-agent';
        $inProgress = $this->makeRun($agentLabel, EvalRunStatus::InProgress, 2);
        $this->makeResult($inProgress, EvalCaseOutcome::Pass);

        $incomplete = $this->makeRun($agentLabel, EvalRunStatus::Incomplete, 2);
        $this->makeResult($incomplete, EvalCaseOutcome::Fail);

        $this->assertNull($this->query()->currentPassRate($agentLabel));
    }

    #[Test]
    public function current_pass_rate_includes_errored_in_the_denominator_and_excludes_needs_human_review_and_unjudged(): void
    {
        $agentLabel = 'quality-agent';
        $run = $this->makeRun($agentLabel, EvalRunStatus::Completed, 12, completedAt: now());

        // 8 pass / 1 fail / 1 errored -- correct pass_rate is 8/10, never
        // 8/9 (which would result from excluding errored from the
        // denominator, quickstart mutation row 5).
        for ($i = 0; $i < 8; $i++) {
            $this->makeResult($run, EvalCaseOutcome::Pass);
        }
        $this->makeResult($run, EvalCaseOutcome::Fail);
        $this->makeResult($run, EvalCaseOutcome::Errored);
        // Pending/indeterminate states -- must contribute to neither the
        // numerator nor the denominator.
        $this->makeResult($run, EvalCaseOutcome::NeedsHumanReview);
        $this->makeResult($run, EvalCaseOutcome::Unjudged);

        $current = $this->query()->currentPassRate($agentLabel);

        $this->assertNotNull($current);
        $this->assertSame($run->id, $current['run_id']);
        $this->assertSame(8, $current['pass_count']);
        $this->assertSame(1, $current['fail_count']);
        $this->assertSame(1, $current['errored_count']);
        $this->assertSame(1, $current['needs_human_review_count']);
        $this->assertSame(1, $current['unjudged_count']);
        $this->assertEqualsWithDelta(
            0.8,
            $current['pass_rate'],
            0.0001,
            'pass_rate must be pass / (pass + fail + errored) = 8/10, with needs_human_review/unjudged excluded from both numerator and denominator'
        );
        $this->assertArrayHasKey('completed_at', $current);
    }

    #[Test]
    public function current_pass_rate_only_consults_the_agents_most_recently_completed_run_never_an_in_progress_or_incomplete_one(): void
    {
        $agentLabel = 'quality-agent';

        $olderCompleted = $this->makeRun($agentLabel, EvalRunStatus::Completed, 2, completedAt: now()->subDays(5));
        $this->makeResult($olderCompleted, EvalCaseOutcome::Fail);
        $this->makeResult($olderCompleted, EvalCaseOutcome::Fail);

        $newerCompleted = $this->makeRun($agentLabel, EvalRunStatus::Completed, 2, completedAt: now()->subDay());
        $this->makeResult($newerCompleted, EvalCaseOutcome::Pass);
        $this->makeResult($newerCompleted, EvalCaseOutcome::Pass);

        // Started after both completed runs, but never itself completed --
        // must never be consulted for "current" (research.md D8).
        $inProgress = $this->makeRun($agentLabel, EvalRunStatus::InProgress, 2);
        $this->makeResult($inProgress, EvalCaseOutcome::Fail);

        $current = $this->query()->currentPassRate($agentLabel);

        $this->assertNotNull($current);
        $this->assertSame($newerCompleted->id, $current['run_id']);
        $this->assertEqualsWithDelta(1.0, $current['pass_rate'], 0.0001);
    }

    // ---------------------------------------------------------------
    // trend()
    // ---------------------------------------------------------------

    #[Test]
    public function trend_reads_only_eval_pass_rate_summaries_scoped_to_the_agent_with_gaps_for_inactive_days(): void
    {
        $agentLabel = 'quality-agent';
        $this->seedBucket($agentLabel, '2026-08-01', pass: 5, fail: 1);
        $this->seedBucket($agentLabel, '2026-08-03', pass: 2, fail: 0);
        // A bucket belonging to a different agent must never leak into this
        // agent's trend.
        $this->seedBucket('other-agent', '2026-08-02', pass: 9, fail: 9);

        Carbon::setTestNow(Carbon::parse('2026-08-05 00:00:00'));

        try {
            $trend = $this->query()->trend($agentLabel, 30);
        } finally {
            Carbon::setTestNow();
        }

        $dates = collect($trend)->pluck('period_date')->all();
        $this->assertSame(
            ['2026-08-01', '2026-08-03'],
            $dates,
            'a day with zero activity must be simply absent from buckets, never a zero-row, and no other agent\'s bucket may appear'
        );
    }

    #[Test]
    public function trend_window_days_beyond_the_configured_maximum_is_clamped_never_honored_as_requested(): void
    {
        config(['llm-client.eval_dashboard.max_trend_window_days' => 30]);
        $agentLabel = 'quality-agent';

        Carbon::setTestNow(Carbon::parse('2026-08-31 00:00:00'));

        try {
            // 40 days back from "today" -- outside the 30-day configured max.
            $this->seedBucket($agentLabel, '2026-07-22', pass: 1, fail: 0);
            // 10 days back -- well inside any reasonable window.
            $this->seedBucket($agentLabel, '2026-08-21', pass: 1, fail: 0);

            $trend = $this->query()->trend($agentLabel, 400);
        } finally {
            Carbon::setTestNow();
        }

        $dates = collect($trend)->pluck('period_date')->all();
        $this->assertNotContains(
            '2026-07-22',
            $dates,
            'a requested trend_window_days far beyond the configured max must be clamped, never honored as requested'
        );
        $this->assertContains('2026-08-21', $dates);
    }
}
