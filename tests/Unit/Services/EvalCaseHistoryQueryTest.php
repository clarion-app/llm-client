<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Models\EvalRunCase;
use ClarionApp\LlmClient\Services\EvalCaseHistoryQuery;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Real SQLite — a DB-batching correctness test, not a pure-logic one.
 * Covers the exact evidence shape CaseVarianceAnalyzer is judged
 * against: a case's own result from either run being compared must never
 * count as its own prior history, history is capped per case rather than
 * growing unboundedly with an agent's lifetime run count, different
 * cases' histories never bleed into each other, a corrected outcome
 * counts rather than a stale original one, and — the property most
 * directly at risk of a silent regression — a prior result that isn't a
 * clean pass or fail (unjudged, needing human review, or errored) is
 * excluded entirely rather than padding a case's apparent sample size
 * with noise that says nothing about whether the case has actually
 * failed before.
 */
class EvalCaseHistoryQueryTest extends TestCase
{
    private string $agentLabel = 'history-query-fixture-agent';

    protected function tearDown(): void
    {
        DB::table('eval_case_results')->delete();
        DB::table('eval_run_cases')->delete();
        DB::table('eval_runs')->delete();

        parent::tearDown();
    }

    private function query(): EvalCaseHistoryQuery
    {
        return app(EvalCaseHistoryQuery::class);
    }

    private function makeRun(): EvalRun
    {
        return EvalRun::create([
            'suite_id' => (string) Str::uuid(),
            'agent_label' => $this->agentLabel,
            'status' => EvalRunStatus::Completed,
            'case_count' => 1,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $expectationResults
     */
    private function makeResult(
        EvalRun $run,
        string $evalCaseId,
        string $evalCaseVersionId,
        string $outcome,
        ?string $outcomeOverride = null,
        array $expectationResults = [],
        ?\DateTimeInterface $createdAt = null,
    ): EvalCaseResult {
        $runCase = EvalRunCase::create([
            'run_id' => $run->id,
            'eval_case_id' => $evalCaseId,
            'eval_case_version_id' => $evalCaseVersionId,
            'position' => 0,
            'status' => 'completed',
        ]);

        return EvalCaseResult::create([
            'run_id' => $run->id,
            'eval_run_case_id' => $runCase->id,
            'eval_case_id' => $evalCaseId,
            'eval_case_version_id' => $evalCaseVersionId,
            'conversation_id' => (string) Str::uuid(),
            'outcome' => $outcome,
            'outcome_override' => $outcomeOverride,
            'produced_response' => 'a response',
            'attempted_actions' => [],
            'expectation_results' => $expectationResults,
            'error_message' => null,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    // ---------------------------------------------------------------
    // A case's own result from either run being compared never counts
    // as its own prior history.
    // ---------------------------------------------------------------

    #[Test]
    public function results_from_the_reference_and_compared_runs_themselves_are_excluded_from_history(): void
    {
        $evalCaseId = (string) Str::uuid();
        $evalCaseVersionId = (string) Str::uuid();

        $referenceRun = $this->makeRun();
        $comparedRun = $this->makeRun();
        $priorRun = $this->makeRun();

        $this->makeResult($referenceRun, $evalCaseId, $evalCaseVersionId, 'pass');
        $this->makeResult($comparedRun, $evalCaseId, $evalCaseVersionId, 'fail');
        $this->makeResult($priorRun, $evalCaseId, $evalCaseVersionId, 'pass');

        $histories = $this->query()->historiesFor(
            $this->agentLabel,
            [['eval_case_id' => $evalCaseId, 'eval_case_version_id' => $evalCaseVersionId]],
            [$referenceRun->id, $comparedRun->id],
            20,
        );

        $this->assertSame(['pass'], $histories[$evalCaseId]['outcomes']);
    }

    // ---------------------------------------------------------------
    // history_lookback_limit truncation, newest first.
    // ---------------------------------------------------------------

    #[Test]
    public function history_is_truncated_to_the_configured_lookback_limit_newest_first(): void
    {
        $evalCaseId = (string) Str::uuid();
        $evalCaseVersionId = (string) Str::uuid();

        $referenceRun = $this->makeRun();
        $comparedRun = $this->makeRun();

        // Five prior results, oldest to newest, alternating so a
        // truncation-order mistake is unambiguous.
        $outcomesOldestFirst = ['fail', 'pass', 'fail', 'pass', 'fail'];
        foreach ($outcomesOldestFirst as $i => $outcome) {
            $run = $this->makeRun();
            $this->makeResult($run, $evalCaseId, $evalCaseVersionId, $outcome, null, [], now()->addMinutes($i));
        }

        $histories = $this->query()->historiesFor(
            $this->agentLabel,
            [['eval_case_id' => $evalCaseId, 'eval_case_version_id' => $evalCaseVersionId]],
            [$referenceRun->id, $comparedRun->id],
            3,
        );

        // Newest three, newest first: minute 4 (fail), minute 3 (pass),
        // minute 2 (fail).
        $this->assertSame(['fail', 'pass', 'fail'], $histories[$evalCaseId]['outcomes']);
    }

    // ---------------------------------------------------------------
    // Strict grouping by eval_case_id — no bleed between cases.
    // ---------------------------------------------------------------

    #[Test]
    public function history_groups_strictly_by_eval_case_id_with_no_bleed_between_different_cases(): void
    {
        $caseA = (string) Str::uuid();
        $versionA = (string) Str::uuid();
        $caseB = (string) Str::uuid();
        $versionB = (string) Str::uuid();

        $referenceRun = $this->makeRun();
        $comparedRun = $this->makeRun();

        $runA = $this->makeRun();
        $this->makeResult($runA, $caseA, $versionA, 'pass');

        $runB = $this->makeRun();
        $this->makeResult($runB, $caseB, $versionB, 'fail');

        $histories = $this->query()->historiesFor(
            $this->agentLabel,
            [
                ['eval_case_id' => $caseA, 'eval_case_version_id' => $versionA],
                ['eval_case_id' => $caseB, 'eval_case_version_id' => $versionB],
            ],
            [$referenceRun->id, $comparedRun->id],
            20,
        );

        $this->assertSame(['pass'], $histories[$caseA]['outcomes']);
        $this->assertSame(['fail'], $histories[$caseB]['outcomes']);
    }

    // ---------------------------------------------------------------
    // Effective outcome: a corrected override counts, not the stale
    // original.
    // ---------------------------------------------------------------

    #[Test]
    public function outcomes_reads_the_effective_outcome_a_corrected_override_counts_not_the_stale_original(): void
    {
        $evalCaseId = (string) Str::uuid();
        $evalCaseVersionId = (string) Str::uuid();

        $referenceRun = $this->makeRun();
        $comparedRun = $this->makeRun();

        $priorRun = $this->makeRun();
        // Original outcome was fail, corrected via override to pass.
        $this->makeResult($priorRun, $evalCaseId, $evalCaseVersionId, 'fail', 'pass');

        $histories = $this->query()->historiesFor(
            $this->agentLabel,
            [['eval_case_id' => $evalCaseId, 'eval_case_version_id' => $evalCaseVersionId]],
            [$referenceRun->id, $comparedRun->id],
            20,
        );

        $this->assertSame(['pass'], $histories[$evalCaseId]['outcomes']);
    }

    // ---------------------------------------------------------------
    // The central filtering assertion: a prior result whose effective
    // outcome isn't a clean pass/fail is excluded from `outcomes`
    // entirely — never mistaken for either a pass or a fail, and never
    // allowed to pad a case's apparent sample size with non-evidence.
    // ---------------------------------------------------------------

    #[Test]
    public function outcomes_excludes_unjudged_needs_human_review_and_errored_results_entirely(): void
    {
        $evalCaseId = (string) Str::uuid();
        $evalCaseVersionId = (string) Str::uuid();

        $referenceRun = $this->makeRun();
        $comparedRun = $this->makeRun();

        foreach (['pass', 'errored', 'pass', 'unjudged', 'needs_human_review'] as $outcome) {
            $run = $this->makeRun();
            $this->makeResult($run, $evalCaseId, $evalCaseVersionId, $outcome);
        }

        $histories = $this->query()->historiesFor(
            $this->agentLabel,
            [['eval_case_id' => $evalCaseId, 'eval_case_version_id' => $evalCaseVersionId]],
            [$referenceRun->id, $comparedRun->id],
            20,
        );

        // Five raw rows behind this case; only the two pass/fail rows
        // may survive into `outcomes`.
        $this->assertCount(2, $histories[$evalCaseId]['outcomes']);
        $this->assertSame(['pass', 'pass'], $histories[$evalCaseId]['outcomes']);
        $this->assertNotContains('errored', $histories[$evalCaseId]['outcomes']);
        $this->assertNotContains('unjudged', $histories[$evalCaseId]['outcomes']);
        $this->assertNotContains('needs_human_review', $histories[$evalCaseId]['outcomes']);
    }

    #[Test]
    public function noise_excluded_from_outcomes_can_never_carry_a_case_past_the_min_history_for_variance_floor(): void
    {
        config(['llm-client.eval_regression.min_history_for_variance' => 5]);

        $evalCaseId = (string) Str::uuid();
        $evalCaseVersionId = (string) Str::uuid();

        $referenceRun = $this->makeRun();
        $comparedRun = $this->makeRun();

        // Five raw rows — enough to look like a full-sized sample at a
        // glance — but only two are genuinely pass/fail comparable.
        foreach (['pass', 'errored', 'errored', 'unjudged', 'pass'] as $outcome) {
            $run = $this->makeRun();
            $this->makeResult($run, $evalCaseId, $evalCaseVersionId, $outcome);
        }

        $histories = $this->query()->historiesFor(
            $this->agentLabel,
            [['eval_case_id' => $evalCaseId, 'eval_case_version_id' => $evalCaseVersionId]],
            [$referenceRun->id, $comparedRun->id],
            20,
        );

        $this->assertCount(
            2,
            $histories[$evalCaseId]['outcomes'],
            'a history padded with non-pass/fail noise must never cross the configured floor on the strength of that noise'
        );
    }

    // ---------------------------------------------------------------
    // scores_by_expectation_index: only judged rubric scores, keyed by
    // the version's own expectation index; an unjudged attempt at that
    // index contributes no entry.
    // ---------------------------------------------------------------

    #[Test]
    public function scores_by_expectation_index_holds_only_judged_scores_and_an_unjudged_attempt_contributes_no_entry(): void
    {
        $evalCaseId = (string) Str::uuid();
        $evalCaseVersionId = (string) Str::uuid();

        $referenceRun = $this->makeRun();
        $comparedRun = $this->makeRun();

        $runOne = $this->makeRun();
        $this->makeResult($runOne, $evalCaseId, $evalCaseVersionId, 'pass', null, [
            ['kind' => 'rubric_judgment', 'status' => 'judged', 'score' => 8, 'met' => true],
        ]);

        $runTwo = $this->makeRun();
        $this->makeResult($runTwo, $evalCaseId, $evalCaseVersionId, 'unjudged', null, [
            ['kind' => 'rubric_judgment', 'status' => 'unjudged', 'score' => null, 'met' => null],
        ]);

        $runThree = $this->makeRun();
        $this->makeResult($runThree, $evalCaseId, $evalCaseVersionId, 'pass', null, [
            ['kind' => 'rubric_judgment', 'status' => 'judged', 'score' => 6, 'met' => true],
        ]);

        $histories = $this->query()->historiesFor(
            $this->agentLabel,
            [['eval_case_id' => $evalCaseId, 'eval_case_version_id' => $evalCaseVersionId]],
            [$referenceRun->id, $comparedRun->id],
            20,
        );

        $scoresAtIndexZero = $histories[$evalCaseId]['scores_by_expectation_index'][0];

        $this->assertCount(
            2,
            $scoresAtIndexZero,
            'the unjudged attempt must contribute no entry at all — an absence of evidence, not a low score'
        );
        $this->assertContains(8, $scoresAtIndexZero);
        $this->assertContains(6, $scoresAtIndexZero);
    }

    // ---------------------------------------------------------------
    // One batched query for the whole call, never one per case
    // (EvalRunConsumptionQuery::summarizeJudging()'s own already-
    // established shape).
    // ---------------------------------------------------------------

    #[Test]
    public function historiesFor_issues_exactly_one_query_regardless_of_how_many_cases_are_requested(): void
    {
        $referenceRun = $this->makeRun();
        $comparedRun = $this->makeRun();

        $caseVersionPairs = [];
        for ($i = 0; $i < 4; $i++) {
            $evalCaseId = (string) Str::uuid();
            $evalCaseVersionId = (string) Str::uuid();
            $caseVersionPairs[] = ['eval_case_id' => $evalCaseId, 'eval_case_version_id' => $evalCaseVersionId];

            $run = $this->makeRun();
            $this->makeResult($run, $evalCaseId, $evalCaseVersionId, 'pass');
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->query()->historiesFor($this->agentLabel, $caseVersionPairs, [$referenceRun->id, $comparedRun->id], 20);

        $this->assertSame(
            1,
            $queryCount,
            'one batched query for the whole call, never one per case'
        );
    }
}
