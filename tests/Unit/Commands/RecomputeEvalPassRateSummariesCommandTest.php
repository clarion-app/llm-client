<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalPassRateSummary;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * llm-client:recompute-eval-pass-rate-summaries rebuilds
 * eval_pass_rate_summaries from eval_case_results (joined to eval_runs for
 * agent_label), for a given --agent-label or, when omitted, for every
 * agent. It sums COALESCE(outcome_override, outcome), never the raw
 * outcome column alone (quickstart mutation row 10), and is idempotent --
 * it truncates (the given agent's rows, or all rows) before rebuilding, so
 * running it twice in a row reproduces the same counts rather than
 * doubling them.
 */
class RecomputeEvalPassRateSummariesCommandTest extends TestCase
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

    private function makeRun(string $agentLabel): EvalRun
    {
        $run = new EvalRun();
        $run->suite_id = (string) Str::uuid();
        $run->agent_label = $agentLabel;
        $run->status = EvalRunStatus::Completed;
        $run->case_count = 1;
        $run->started_at = now();
        $run->completed_at = now();
        $run->save();

        return $run;
    }

    private function makeResult(EvalRun $run, string $outcome, Carbon $createdAt, ?string $outcomeOverride = null): EvalCaseResult
    {
        return EvalCaseResult::create([
            'run_id' => $run->id,
            'eval_run_case_id' => (string) Str::uuid(),
            'eval_case_id' => (string) Str::uuid(),
            'eval_case_version_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'outcome' => $outcome,
            'outcome_override' => $outcomeOverride,
            'produced_response' => 'a response',
            'attempted_actions' => [],
            'expectation_results' => [],
            'created_at' => $createdAt,
        ]);
    }

    private function bucket(string $agentLabel, string $periodDate): ?EvalPassRateSummary
    {
        return EvalPassRateSummary::where('agent_label', $agentLabel)
            ->where('period_date', $periodDate)
            ->first();
    }

    // ---------------------------------------------------------------
    // Rebuild for a given --agent-label, honoring the effective outcome
    // ---------------------------------------------------------------

    #[Test]
    public function it_rebuilds_bucket_counts_for_the_given_agent_label_using_the_effective_outcome_not_the_raw_one(): void
    {
        $agentLabel = 'quality-agent';
        $run = $this->makeRun($agentLabel);

        $this->makeResult($run, 'pass', Carbon::parse('2026-08-01 09:00:00'));
        $this->makeResult($run, 'fail', Carbon::parse('2026-08-01 10:00:00'));
        // Overridden from pass to fail -- the rebuilt bucket must reflect
        // the override, never the raw stored outcome (quickstart mutation
        // row 10).
        $this->makeResult($run, 'pass', Carbon::parse('2026-08-02 09:00:00'), outcomeOverride: 'fail');
        $this->makeResult($run, 'needs_human_review', Carbon::parse('2026-08-02 10:00:00'));

        // Stale/wrong pre-existing rollup data for this agent -- proves the
        // command truncates before rebuilding rather than adding on top.
        EvalPassRateSummary::create([
            'agent_label' => $agentLabel,
            'period_date' => '2026-08-01',
            'pass_count' => 999,
            'fail_count' => 999,
            'needs_human_review_count' => 0,
            'errored_count' => 0,
            'unjudged_count' => 0,
            'total_count' => 1998,
        ]);

        $exitCode = Artisan::call('llm-client:recompute-eval-pass-rate-summaries', ['--agent-label' => $agentLabel]);

        $this->assertSame(0, $exitCode);

        $dayOne = $this->bucket($agentLabel, '2026-08-01');
        $this->assertNotNull($dayOne);
        $this->assertSame(1, $dayOne->pass_count);
        $this->assertSame(1, $dayOne->fail_count);
        $this->assertSame(2, $dayOne->total_count, 'the stale pre-existing row must be replaced, not added to');

        $dayTwo = $this->bucket($agentLabel, '2026-08-02');
        $this->assertNotNull($dayTwo);
        $this->assertSame(
            0,
            $dayTwo->pass_count,
            'the overridden result must be summed under its effective (fail) outcome, not its raw (pass) outcome'
        );
        $this->assertSame(1, $dayTwo->fail_count);
        $this->assertSame(1, $dayTwo->needs_human_review_count);
        $this->assertSame(2, $dayTwo->total_count);
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    #[Test]
    public function running_the_command_twice_in_a_row_reproduces_the_same_counts_not_doubled(): void
    {
        $agentLabel = 'quality-agent';
        $run = $this->makeRun($agentLabel);
        $this->makeResult($run, 'pass', Carbon::parse('2026-08-01 09:00:00'));
        $this->makeResult($run, 'fail', Carbon::parse('2026-08-01 10:00:00'));

        Artisan::call('llm-client:recompute-eval-pass-rate-summaries', ['--agent-label' => $agentLabel]);
        $firstRun = $this->bucket($agentLabel, '2026-08-01');
        $this->assertNotNull($firstRun);
        $this->assertSame(1, $firstRun->pass_count);
        $this->assertSame(1, $firstRun->fail_count);
        $this->assertSame(2, $firstRun->total_count);

        Artisan::call('llm-client:recompute-eval-pass-rate-summaries', ['--agent-label' => $agentLabel]);
        $secondRun = $this->bucket($agentLabel, '2026-08-01');

        $this->assertSame(1, $secondRun->pass_count, 'a second run must not double the pass count');
        $this->assertSame(1, $secondRun->fail_count, 'a second run must not double the fail count');
        $this->assertSame(2, $secondRun->total_count, 'a second run must not double total_count');
        $this->assertSame(
            1,
            EvalPassRateSummary::where('agent_label', $agentLabel)->where('period_date', '2026-08-01')->count(),
            'a second run must not create a duplicate row for the same bucket'
        );
    }

    // ---------------------------------------------------------------
    // Omitted --agent-label rebuilds every agent
    // ---------------------------------------------------------------

    #[Test]
    public function omitting_agent_label_rebuilds_buckets_for_every_agent(): void
    {
        $runA = $this->makeRun('agent-a');
        $this->makeResult($runA, 'pass', Carbon::parse('2026-08-01 09:00:00'));

        $runB = $this->makeRun('agent-b');
        $this->makeResult($runB, 'fail', Carbon::parse('2026-08-01 09:00:00'));

        Artisan::call('llm-client:recompute-eval-pass-rate-summaries');

        $bucketA = $this->bucket('agent-a', '2026-08-01');
        $bucketB = $this->bucket('agent-b', '2026-08-01');

        $this->assertNotNull($bucketA);
        $this->assertSame(1, $bucketA->pass_count);
        $this->assertNotNull($bucketB);
        $this->assertSame(1, $bucketB->fail_count);
    }

    // ---------------------------------------------------------------
    // A scoped --agent-label rebuild leaves other agents' rows untouched
    // ---------------------------------------------------------------

    #[Test]
    public function a_scoped_agent_label_rebuild_leaves_a_different_agents_existing_bucket_untouched(): void
    {
        $runA = $this->makeRun('agent-a');
        $this->makeResult($runA, 'pass', Carbon::parse('2026-08-01 09:00:00'));

        EvalPassRateSummary::create([
            'agent_label' => 'agent-b',
            'period_date' => '2026-08-01',
            'pass_count' => 3,
            'fail_count' => 1,
            'needs_human_review_count' => 0,
            'errored_count' => 0,
            'unjudged_count' => 0,
            'total_count' => 4,
        ]);

        Artisan::call('llm-client:recompute-eval-pass-rate-summaries', ['--agent-label' => 'agent-a']);

        $bucketB = $this->bucket('agent-b', '2026-08-01');
        $this->assertNotNull($bucketB, 'agent-b\'s existing bucket must survive a rebuild scoped to agent-a');
        $this->assertSame(3, $bucketB->pass_count);
        $this->assertSame(1, $bucketB->fail_count);
    }
}
