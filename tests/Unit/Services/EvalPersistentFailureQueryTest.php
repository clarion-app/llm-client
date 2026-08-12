<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Models\EvalSuite;
use ClarionApp\LlmClient\Services\EvalCaseService;
use ClarionApp\LlmClient\Services\EvalPersistentFailureQuery;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * rankedFailures() ranks an agent's own live cases by fail_count
 * descending, then total_count descending -- never by total_count alone
 * (quickstart mutation row 4) -- over each case's own recent history,
 * capped to config('llm-client.eval_dashboard.persistent_failure_lookback')
 * results (data-model.md §2/research.md D3) applied *before* the fail/
 * total counts are computed, so an old, since-fixed failure streak outside
 * the cap can never outrank a case that is currently, recently failing.
 * The ranked set is scoped to the requesting agent's own case ids -- a
 * different agent's cases, however many failures they carry, never appear.
 */
class EvalPersistentFailureQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('eval_case_results')->delete();
        DB::table('eval_runs')->delete();
        DB::table('eval_cases')->delete();
        DB::table('eval_case_versions')->delete();
        DB::table('eval_suites')->delete();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    private function query(): EvalPersistentFailureQuery
    {
        return app(EvalPersistentFailureQuery::class);
    }

    private function suite(string $agentLabel, string $name): EvalSuite
    {
        return app(EvalSuiteService::class)->create($name, $agentLabel);
    }

    private function addCase(EvalSuite $suite, string $given): string
    {
        return app(EvalCaseService::class)->addCase(
            $suite,
            $given,
            'expected behavior',
            [['kind' => 'text_match', 'expected_text' => 'ok']],
        )->id;
    }

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

    /**
     * eval_case_results has a unique(run_id, eval_case_id) constraint
     * (mirroring production: a given run records at most one result per
     * case) -- so each simulated historical result for a case gets its own
     * fresh run row, exactly like a real installation running the same
     * suite repeatedly over time.
     */
    private function makeResult(string $agentLabel, string $evalCaseId, string $outcome, Carbon $createdAt): EvalCaseResult
    {
        $run = $this->makeRun($agentLabel);

        return EvalCaseResult::create([
            'run_id' => $run->id,
            'eval_run_case_id' => (string) Str::uuid(),
            'eval_case_id' => $evalCaseId,
            'eval_case_version_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'outcome' => $outcome,
            'produced_response' => 'a response',
            'attempted_actions' => [],
            'expectation_results' => [],
            'created_at' => $createdAt,
        ]);
    }

    // ---------------------------------------------------------------
    // Ranking: fail_count desc, then total_count desc -- not total_count
    // alone
    // ---------------------------------------------------------------

    #[Test]
    public function ranking_orders_by_fail_count_descending_then_total_count_descending_never_total_count_alone(): void
    {
        $agentLabel = 'quality-agent';
        $suite = $this->suite($agentLabel, 'Persistent failure ranking fixture suite');
        $caseHighVolumeLowFail = $this->addCase($suite, 'high volume, low fail rate');
        $caseLowVolumeHighFail = $this->addCase($suite, 'low volume, high fail rate');

        // caseHighVolumeLowFail: 2 fail, 8 pass (total 10) -- higher
        // total_count than the other case.
        for ($i = 0; $i < 2; $i++) {
            $this->makeResult($agentLabel, $caseHighVolumeLowFail, 'fail', now()->subMinutes($i));
        }
        for ($i = 2; $i < 10; $i++) {
            $this->makeResult($agentLabel, $caseHighVolumeLowFail, 'pass', now()->subMinutes($i));
        }

        // caseLowVolumeHighFail: 5 fail, 1 pass (total 6) -- lower
        // total_count, but a materially higher fail_count.
        for ($i = 0; $i < 5; $i++) {
            $this->makeResult($agentLabel, $caseLowVolumeHighFail, 'fail', now()->subMinutes($i));
        }
        $this->makeResult($agentLabel, $caseLowVolumeHighFail, 'pass', now()->subMinutes(5));

        $ranked = $this->query()->rankedFailures($agentLabel, 10);
        $order = collect($ranked)->pluck('eval_case_id')->all();

        $this->assertSame(
            [$caseLowVolumeHighFail, $caseHighVolumeLowFail],
            $order,
            'ranking must be fail_count descending (5 before 2) -- ranking by total_count alone would wrongly put the 10-result case first'
        );
    }

    // ---------------------------------------------------------------
    // Per-case lookback cap applied before fail/total are computed
    // ---------------------------------------------------------------

    #[Test]
    public function the_per_case_lookback_cap_is_applied_before_ranking_so_an_old_since_fixed_streak_never_outranks_a_currently_failing_case(): void
    {
        config(['llm-client.eval_dashboard.persistent_failure_lookback' => 20]);
        $agentLabel = 'quality-agent';
        $suite = $this->suite($agentLabel, 'Lookback cap fixture suite');
        $caseSinceFixed = $this->addCase($suite, 'used to fail a lot, now fixed');
        $caseCurrentlyFailing = $this->addCase($suite, 'currently failing, no old history');

        // caseSinceFixed: 20 very old fails (well outside the 20-result
        // lookback once the 20 recent passes below are also present), then
        // 20 recent passes -- the most recent 20 results (the capped
        // window) are therefore all passes.
        for ($i = 0; $i < 20; $i++) {
            $this->makeResult($agentLabel, $caseSinceFixed, 'fail', now()->subDays(100 + $i));
        }
        for ($i = 0; $i < 20; $i++) {
            $this->makeResult($agentLabel, $caseSinceFixed, 'pass', now()->subDays($i));
        }

        // caseCurrentlyFailing: exactly 20 recent results (no older
        // history at all), 10 of which are fail.
        for ($i = 0; $i < 10; $i++) {
            $this->makeResult($agentLabel, $caseCurrentlyFailing, 'fail', now()->subDays($i));
        }
        for ($i = 10; $i < 20; $i++) {
            $this->makeResult($agentLabel, $caseCurrentlyFailing, 'pass', now()->subDays($i));
        }

        $ranked = $this->query()->rankedFailures($agentLabel, 10);
        $byCaseId = collect($ranked)->keyBy('eval_case_id');

        $this->assertSame(
            0,
            $byCaseId[$caseSinceFixed]['fail_count'],
            'the capped window for the since-fixed case must contain only its 20 most recent (all-passing) results -- its 20 old fails must never be counted'
        );
        $this->assertSame(20, $byCaseId[$caseSinceFixed]['total_count'], 'the capped total must be 20, not the case\'s full 40-result history');
        $this->assertSame(10, $byCaseId[$caseCurrentlyFailing]['fail_count']);

        $order = collect($ranked)->pluck('eval_case_id')->all();
        $this->assertSame(
            [$caseCurrentlyFailing, $caseSinceFixed],
            $order,
            'the currently-failing case must rank first -- an implementation that summed each case\'s entire history instead of its capped recent window would wrongly rank the since-fixed case (20 all-time fails) above it'
        );
    }

    // ---------------------------------------------------------------
    // Cross-agent scoping
    // ---------------------------------------------------------------

    #[Test]
    public function ranking_is_scoped_to_the_requesting_agents_own_cases_a_different_agents_case_never_appears(): void
    {
        $agentA = 'agent-a';
        $agentB = 'agent-b';

        $suiteA = $this->suite($agentA, 'Agent A fixture suite');
        $caseA = $this->addCase($suiteA, 'agent a case');
        $this->makeResult($agentA, $caseA, 'fail', now());
        $this->makeResult($agentA, $caseA, 'pass', now()->subMinute());

        $suiteB = $this->suite($agentB, 'Agent B fixture suite');
        $caseB = $this->addCase($suiteB, 'agent b case, many failures');
        for ($i = 0; $i < 10; $i++) {
            $this->makeResult($agentB, $caseB, 'fail', now()->subMinutes($i));
        }

        $ranked = $this->query()->rankedFailures($agentA, 10);

        $this->assertCount(1, $ranked, 'only agent A\'s own case may appear in agent A\'s ranking');
        $this->assertSame($caseA, $ranked[0]['eval_case_id']);
    }
}
