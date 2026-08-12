<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalPassRateSummary;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Services\EvalPassRateRollupService;
use ClarionApp\LlmClient\ValueObjects\EvalCaseOutcome;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * recordResult() upserts a day-bucketed eval_pass_rate_summaries row using
 * the same insertOrIgnore + atomic column = column + n idiom
 * MetricsRecorder::upsertCostSummary()/upsertToolReliabilitySummary()
 * already use for cost_summaries/tool_reliability_summaries, keyed on
 * (agent_label, period_date) derived from the result's own created_at
 * date. adjustForOverride() moves one case's contribution from its old
 * effective-outcome column to its new one, at the original result's own
 * date -- never the write instant of the override itself -- leaving
 * total_count unchanged, since the case is still exactly one result.
 */
class EvalPassRateRollupServiceTest extends TestCase
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

    private function service(): EvalPassRateRollupService
    {
        return app(EvalPassRateRollupService::class);
    }

    private function makeRun(string $agentLabel = 'quality-agent'): EvalRun
    {
        $run = new EvalRun();
        $run->suite_id = (string) Str::uuid();
        $run->agent_label = $agentLabel;
        $run->status = EvalRunStatus::InProgress;
        $run->case_count = 1;
        $run->started_at = now();
        $run->save();

        return $run;
    }

    private function makeCaseResult(EvalRun $run, EvalCaseOutcome $outcome, ?Carbon $createdAt = null): EvalCaseResult
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
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function bucket(string $agentLabel, string $periodDate): ?EvalPassRateSummary
    {
        return EvalPassRateSummary::where('agent_label', $agentLabel)
            ->where('period_date', $periodDate)
            ->first();
    }

    // ---------------------------------------------------------------
    // recordResult()
    // ---------------------------------------------------------------

    #[Test]
    public function record_result_creates_a_bucket_row_and_moves_both_the_outcome_column_and_total_count(): void
    {
        $run = $this->makeRun();
        $result = $this->makeCaseResult($run, EvalCaseOutcome::Pass, Carbon::parse('2026-08-01 09:00:00'));

        $this->service()->recordResult($run, $result);

        $bucket = $this->bucket($run->agent_label, '2026-08-01');
        $this->assertNotNull($bucket, 'a bucket row must exist for (agent_label, period_date) after a single recorded result');
        $this->assertSame(1, $bucket->pass_count);
        $this->assertSame(0, $bucket->fail_count);
        $this->assertSame(0, $bucket->needs_human_review_count);
        $this->assertSame(0, $bucket->errored_count);
        $this->assertSame(0, $bucket->unjudged_count);
        $this->assertSame(
            1,
            $bucket->total_count,
            'total_count must move together with the matching outcome column on every single recorded result -- never just one of the two'
        );
    }

    #[Test]
    public function a_second_recordresult_call_for_a_different_date_creates_a_second_independent_bucket_row(): void
    {
        $run = $this->makeRun();
        $resultDayOne = $this->makeCaseResult($run, EvalCaseOutcome::Pass, Carbon::parse('2026-08-01 09:00:00'));
        $resultDayTwo = $this->makeCaseResult($run, EvalCaseOutcome::Fail, Carbon::parse('2026-08-02 09:00:00'));

        $this->service()->recordResult($run, $resultDayOne);
        $this->service()->recordResult($run, $resultDayTwo);

        $this->assertSame(
            2,
            EvalPassRateSummary::count(),
            'two distinct period_date buckets must exist -- the second call must never overwrite the first'
        );

        $bucketOne = $this->bucket($run->agent_label, '2026-08-01');
        $bucketTwo = $this->bucket($run->agent_label, '2026-08-02');

        $this->assertSame(1, $bucketOne->pass_count);
        $this->assertSame(0, $bucketOne->fail_count);
        $this->assertSame(1, $bucketOne->total_count);
        $this->assertSame(0, $bucketTwo->pass_count);
        $this->assertSame(1, $bucketTwo->fail_count);
        $this->assertSame(1, $bucketTwo->total_count);
    }

    // ---------------------------------------------------------------
    // adjustForOverride()
    // ---------------------------------------------------------------

    #[Test]
    public function adjust_for_override_decrements_the_old_column_and_increments_the_new_one_leaving_total_count_unchanged(): void
    {
        $run = $this->makeRun();
        $result = $this->makeCaseResult($run, EvalCaseOutcome::Pass, Carbon::parse('2026-08-01 09:00:00'));
        $this->service()->recordResult($run, $result);

        $this->service()->adjustForOverride($result, EvalCaseOutcome::Pass->value, EvalCaseOutcome::Fail->value);

        $bucket = $this->bucket($run->agent_label, '2026-08-01');
        $this->assertSame(0, $bucket->pass_count);
        $this->assertSame(1, $bucket->fail_count);
        $this->assertSame(
            1,
            $bucket->total_count,
            'total_count must never move on an override adjustment -- the case is still exactly one result'
        );
    }

    #[Test]
    public function an_override_recorded_on_a_later_simulated_day_still_adjusts_the_original_results_own_day_bucket(): void
    {
        $run = $this->makeRun();
        $result = $this->makeCaseResult($run, EvalCaseOutcome::Pass, Carbon::parse('2026-08-01 09:00:00'));
        $this->service()->recordResult($run, $result);

        // Simulate the override being recorded several days after the
        // original result -- adjustForOverride() must key off the result's
        // own created_at date (via its run() relation), never "now" at the
        // moment the override itself is written.
        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00'));

        try {
            $this->service()->adjustForOverride($result, EvalCaseOutcome::Pass->value, EvalCaseOutcome::Fail->value);
        } finally {
            Carbon::setTestNow();
        }

        $originalDayBucket = $this->bucket($run->agent_label, '2026-08-01');
        $overrideDayBucket = $this->bucket($run->agent_label, '2026-08-05');

        $this->assertNotNull($originalDayBucket, 'the adjustment must land in the original result day\'s bucket');
        $this->assertSame(0, $originalDayBucket->pass_count);
        $this->assertSame(1, $originalDayBucket->fail_count);
        $this->assertSame(1, $originalDayBucket->total_count);
        $this->assertNull($overrideDayBucket, 'no bucket for the override\'s own write day should ever be created by adjustForOverride()');
    }

    #[Test]
    public function an_override_between_two_outcomes_that_are_already_equal_is_a_no_op(): void
    {
        $run = $this->makeRun();
        $result = $this->makeCaseResult($run, EvalCaseOutcome::Pass, Carbon::parse('2026-08-01 09:00:00'));
        $this->service()->recordResult($run, $result);

        $this->service()->adjustForOverride($result, EvalCaseOutcome::Pass->value, EvalCaseOutcome::Pass->value);

        $bucket = $this->bucket($run->agent_label, '2026-08-01');
        $this->assertSame(1, $bucket->pass_count);
        $this->assertSame(0, $bucket->fail_count);
        $this->assertSame(1, $bucket->total_count);
    }
}
