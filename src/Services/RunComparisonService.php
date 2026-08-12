<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Models\EvalRunCase;
use ClarionApp\LlmClient\ValueObjects\CaseComparisonCategory;
use ClarionApp\LlmClient\ValueObjects\CaseComparisonResult;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use ClarionApp\LlmClient\ValueObjects\ExpectationKind;
use ClarionApp\LlmClient\ValueObjects\VarianceConfidence;

/**
 * The core comparison: matches a compared run's eval_run_cases against
 * whichever reference was active at that run's own completion time, and
 * classifies every case per data-model.md §3's eight-category rule.
 * Never persists its result (research.md D1) — computed fresh on every
 * call.
 *
 * A matched case landing on Regressed or MateriallyDrifted then has its
 * confidence (and, for MateriallyDrifted, driftedExpectationIndex)
 * populated from this agent's own accumulated run history via a single
 * batched EvalCaseHistoryQuery call plus CaseVarianceAnalyzer's pure
 * decision rules — never a fabricated statistical model, never one
 * history query per case. Every other category keeps confidence and
 * driftedExpectationIndex strictly null.
 */
class RunComparisonService
{
    public function __construct(
        private readonly EvalReferenceService $referenceService,
        private readonly EvalCaseHistoryQuery $historyQuery,
        private readonly CaseVarianceAnalyzer $varianceAnalyzer,
    ) {
    }

    /**
     * @return array{
     *   reference_run_id: ?string,
     *   reference_incomplete: bool,
     *   compared_incomplete: bool,
     *   cases: array<int, CaseComparisonResult>,
     * }
     *
     * @throws \InvalidArgumentException when the compared run has not
     *   genuinely finished (status in_progress or failed_to_start,
     *   FR-014) — a controller-level 422, never a silent partial
     *   comparison.
     */
    public function compare(EvalRun $run): array
    {
        if (in_array($run->status, [EvalRunStatus::InProgress, EvalRunStatus::FailedToStart], true)) {
            throw new \InvalidArgumentException('This run has not finished yet.');
        }

        $designation = $this->referenceService->activeAt(
            $run->agent_label,
            $run->completed_at ?? $run->updated_at,
        );

        if ($designation === null) {
            return [
                'reference_run_id' => null,
                'reference_incomplete' => false,
                'compared_incomplete' => false,
                'cases' => [],
            ];
        }

        $referenceRun = EvalRun::find($designation->run_id);

        $referenceCases = $this->loadRunCases($referenceRun?->id);
        $comparedCases = $this->loadRunCases($run->id);

        $evalCaseIds = array_unique(array_merge(array_keys($referenceCases), array_keys($comparedCases)));

        $results = [];
        foreach ($evalCaseIds as $evalCaseId) {
            $results[$evalCaseId] = $this->classify(
                $evalCaseId,
                $referenceCases[$evalCaseId] ?? null,
                $comparedCases[$evalCaseId] ?? null,
            );
        }

        $results = $this->attachVarianceConfidence($results, $run->agent_label, $referenceRun?->id, $run->id, $referenceCases, $comparedCases);

        return [
            'reference_run_id' => $designation->run_id,
            'reference_incomplete' => $referenceRun?->status === EvalRunStatus::Incomplete,
            'compared_incomplete' => $run->status === EvalRunStatus::Incomplete,
            'cases' => $this->orderResults($results, $comparedCases, $referenceCases),
        ];
    }

    /**
     * One run's eval_run_cases snapshot, joined against its (at most one)
     * eval_case_results row apiece, keyed by eval_case_id.
     *
     * @return array<string, array{eval_run_case_id: string, eval_case_version_id: string, position: int, result: ?array{outcome: string, expectation_results: array}}>
     */
    private function loadRunCases(?string $runId): array
    {
        if ($runId === null) {
            return [];
        }

        $runCases = EvalRunCase::where('run_id', $runId)->orderBy('position')->get();

        $resultsByRunCaseId = EvalCaseResult::where('run_id', $runId)
            ->get()
            ->keyBy('eval_run_case_id');

        $byCaseId = [];

        foreach ($runCases as $runCase) {
            $result = $resultsByRunCaseId->get($runCase->id);

            $byCaseId[$runCase->eval_case_id] = [
                'eval_run_case_id' => $runCase->id,
                'eval_case_version_id' => $runCase->eval_case_version_id,
                'position' => $runCase->position,
                'result' => $result === null ? null : [
                    // COALESCE(outcome_override, outcome) — the effective
                    // outcome, matching EvalRunService::summarize()'s own
                    // pattern rather than reinventing it.
                    'outcome' => $result->outcome_override ?? $result->outcome->value,
                    'expectation_results' => $result->expectation_results ?? [],
                ],
            ];
        }

        return $byCaseId;
    }

    /**
     * data-model.md §3's classification rule, most specific first: added,
     * removed, edited, inconclusive (no result / not a clean pass-fail),
     * then the boolean/numeric rule for a genuinely matched, clean pair.
     */
    private function classify(string $evalCaseId, ?array $reference, ?array $compared): CaseComparisonResult
    {
        // 1. Added: present only in the compared run's snapshot.
        if ($reference === null && $compared !== null) {
            return new CaseComparisonResult(
                evalCaseId: $evalCaseId,
                category: CaseComparisonCategory::Added,
                confidence: null,
                referenceEvalRunCaseId: null,
                comparedEvalRunCaseId: $compared['eval_run_case_id'],
                referenceOutcome: null,
                comparedOutcome: $compared['result']['outcome'] ?? null,
                inconclusiveReason: null,
                driftedExpectationIndex: null,
            );
        }

        // 2. Removed: present only in the reference run's snapshot.
        if ($reference !== null && $compared === null) {
            return new CaseComparisonResult(
                evalCaseId: $evalCaseId,
                category: CaseComparisonCategory::Removed,
                confidence: null,
                referenceEvalRunCaseId: $reference['eval_run_case_id'],
                comparedEvalRunCaseId: null,
                referenceOutcome: $reference['result']['outcome'] ?? null,
                comparedOutcome: null,
                inconclusiveReason: null,
                driftedExpectationIndex: null,
            );
        }

        // Present in both snapshots from here on.

        // 3. Edited: same case id, different pinned version — "did it
        // pass" is not a comparable question when the suite's own
        // definition of the case changed underneath it.
        if ($reference['eval_case_version_id'] !== $compared['eval_case_version_id']) {
            return new CaseComparisonResult(
                evalCaseId: $evalCaseId,
                category: CaseComparisonCategory::Edited,
                confidence: null,
                referenceEvalRunCaseId: $reference['eval_run_case_id'],
                comparedEvalRunCaseId: $compared['eval_run_case_id'],
                referenceOutcome: $reference['result']['outcome'] ?? null,
                comparedOutcome: $compared['result']['outcome'] ?? null,
                inconclusiveReason: null,
                driftedExpectationIndex: null,
            );
        }

        // 4. Matched, but either side never produced a result at all
        // (an incomplete run's gap).
        if ($reference['result'] === null || $compared['result'] === null) {
            return new CaseComparisonResult(
                evalCaseId: $evalCaseId,
                category: CaseComparisonCategory::Inconclusive,
                confidence: null,
                referenceEvalRunCaseId: $reference['eval_run_case_id'],
                comparedEvalRunCaseId: $compared['eval_run_case_id'],
                referenceOutcome: $reference['result']['outcome'] ?? null,
                comparedOutcome: $compared['result']['outcome'] ?? null,
                inconclusiveReason: $reference['result'] === null ? 'reference_no_result' : 'compared_no_result',
                driftedExpectationIndex: null,
            );
        }

        $referenceOutcome = $reference['result']['outcome'];
        $comparedOutcome = $compared['result']['outcome'];

        // 5. Matched, both sides have a result, but either side's
        // effective outcome is not a clean pass/fail.
        $inconclusiveReason = $this->inconclusiveReasonFor('reference', $referenceOutcome)
            ?? $this->inconclusiveReasonFor('compared', $comparedOutcome);

        if ($inconclusiveReason !== null) {
            return new CaseComparisonResult(
                evalCaseId: $evalCaseId,
                category: CaseComparisonCategory::Inconclusive,
                confidence: null,
                referenceEvalRunCaseId: $reference['eval_run_case_id'],
                comparedEvalRunCaseId: $compared['eval_run_case_id'],
                referenceOutcome: $referenceOutcome,
                comparedOutcome: $comparedOutcome,
                inconclusiveReason: $inconclusiveReason,
                driftedExpectationIndex: null,
            );
        }

        // 6. Both sides have a clean pass/fail effective outcome.
        $category = match (true) {
            $referenceOutcome === 'pass' && $comparedOutcome === 'fail' => CaseComparisonCategory::Regressed,
            $referenceOutcome === 'fail' && $comparedOutcome === 'pass' => CaseComparisonCategory::Improved,
            $referenceOutcome === 'pass' && $comparedOutcome === 'pass'
                && $this->hasMaterialScoreDrop($reference, $compared) => CaseComparisonCategory::MateriallyDrifted,
            default => CaseComparisonCategory::Unchanged,
        };

        return new CaseComparisonResult(
            evalCaseId: $evalCaseId,
            category: $category,
            // Phase 4/US2 populates confidence for Regressed/MateriallyDrifted.
            confidence: null,
            referenceEvalRunCaseId: $reference['eval_run_case_id'],
            comparedEvalRunCaseId: $compared['eval_run_case_id'],
            referenceOutcome: $referenceOutcome,
            comparedOutcome: $comparedOutcome,
            inconclusiveReason: null,
            // Phase 4/US2 populates the drifted expectation index.
            driftedExpectationIndex: null,
        );
    }

    /**
     * Populates confidence (and, for MateriallyDrifted, the winning
     * driftedExpectationIndex) for every Regressed/MateriallyDrifted
     * entry, using a single batched historical query for the whole
     * comparison rather than one per case. Every other category is
     * returned unchanged.
     *
     * @param  array<string, CaseComparisonResult>  $results
     * @return array<string, CaseComparisonResult>
     */
    private function attachVarianceConfidence(
        array $results,
        string $agentLabel,
        ?string $referenceRunId,
        string $comparedRunId,
        array $referenceCases,
        array $comparedCases,
    ): array {
        $caseVersionPairs = [];

        foreach ($results as $evalCaseId => $result) {
            if (!in_array($result->category, [CaseComparisonCategory::Regressed, CaseComparisonCategory::MateriallyDrifted], true)) {
                continue;
            }

            $caseVersionPairs[] = [
                'eval_case_id' => $evalCaseId,
                'eval_case_version_id' => $comparedCases[$evalCaseId]['eval_case_version_id'],
            ];
        }

        if (empty($caseVersionPairs)) {
            return $results;
        }

        $excludeRunIds = array_values(array_filter([$referenceRunId, $comparedRunId], fn ($id) => $id !== null));
        $limitPerCase = (int) config('llm-client.eval_regression.history_lookback_limit', 20);

        $histories = $this->historyQuery->historiesFor(
            $agentLabel,
            $caseVersionPairs,
            $excludeRunIds,
            $limitPerCase,
        );

        foreach ($caseVersionPairs as $pair) {
            $evalCaseId = $pair['eval_case_id'];
            $result = $results[$evalCaseId];
            $history = $histories[$evalCaseId] ?? ['outcomes' => [], 'scores_by_expectation_index' => []];

            if ($result->category === CaseComparisonCategory::Regressed) {
                $confidence = $this->varianceAnalyzer->classifyBooleanTransition($history['outcomes']);
                $results[$evalCaseId] = $this->withVariance($result, $confidence, null);

                continue;
            }

            // MateriallyDrifted: run the numeric-drift rule once per
            // expectation index that dropped materially, keeping the
            // single most severe verdict (likely_regression > ordinary_
            // variation > insufficient_history, ties broken by lowest
            // index — research.md D8).
            $droppedIndices = $this->materiallyDroppedIndices($referenceCases[$evalCaseId], $comparedCases[$evalCaseId]);

            $bestConfidence = null;
            $bestIndex = null;

            foreach ($droppedIndices as $index) {
                $comparedScore = $comparedCases[$evalCaseId]['result']['expectation_results'][$index]['score'] ?? null;

                if ($comparedScore === null) {
                    continue;
                }

                $priorScores = $history['scores_by_expectation_index'][$index] ?? [];
                $confidence = $this->varianceAnalyzer->classifyNumericDrift($priorScores, $comparedScore);

                if ($bestConfidence === null || $this->severity($confidence) > $this->severity($bestConfidence)) {
                    $bestConfidence = $confidence;
                    $bestIndex = $index;
                }
            }

            $results[$evalCaseId] = $this->withVariance($result, $bestConfidence, $bestIndex);
        }

        return $results;
    }

    /**
     * Every rubric_judgment expectation index, judged on both sides, that
     * dropped by at least material_score_drop — the same predicate
     * hasMaterialScoreDrop() checks, but collecting every qualifying
     * index rather than stopping at the first.
     *
     * @return array<int, int>
     */
    private function materiallyDroppedIndices(array $reference, array $compared): array
    {
        $referenceExpectations = $reference['result']['expectation_results'] ?? [];
        $comparedExpectations = $compared['result']['expectation_results'] ?? [];
        $threshold = (int) config('llm-client.eval_regression.material_score_drop', 2);

        $indices = [];

        foreach ($referenceExpectations as $index => $referenceExpectation) {
            if (($referenceExpectation['kind'] ?? null) !== ExpectationKind::RubricJudgment->value) {
                continue;
            }

            if (($referenceExpectation['status'] ?? null) !== 'judged') {
                continue;
            }

            $comparedExpectation = $comparedExpectations[$index] ?? null;

            if ($comparedExpectation === null
                || ($comparedExpectation['kind'] ?? null) !== ExpectationKind::RubricJudgment->value
                || ($comparedExpectation['status'] ?? null) !== 'judged') {
                continue;
            }

            $referenceScore = $referenceExpectation['score'] ?? null;
            $comparedScore = $comparedExpectation['score'] ?? null;

            if ($referenceScore === null || $comparedScore === null) {
                continue;
            }

            if (($referenceScore - $comparedScore) >= $threshold) {
                $indices[] = $index;
            }
        }

        return $indices;
    }

    /** likely_regression > ordinary_variation > insufficient_history. */
    private function severity(VarianceConfidence $confidence): int
    {
        return match ($confidence) {
            VarianceConfidence::LikelyRegression => 2,
            VarianceConfidence::OrdinaryVariation => 1,
            VarianceConfidence::InsufficientHistory => 0,
        };
    }

    private function withVariance(CaseComparisonResult $result, ?VarianceConfidence $confidence, ?int $driftedExpectationIndex): CaseComparisonResult
    {
        return new CaseComparisonResult(
            evalCaseId: $result->evalCaseId,
            category: $result->category,
            confidence: $confidence,
            referenceEvalRunCaseId: $result->referenceEvalRunCaseId,
            comparedEvalRunCaseId: $result->comparedEvalRunCaseId,
            referenceOutcome: $result->referenceOutcome,
            comparedOutcome: $result->comparedOutcome,
            inconclusiveReason: $result->inconclusiveReason,
            driftedExpectationIndex: $driftedExpectationIndex,
        );
    }

    private function inconclusiveReasonFor(string $side, string $outcome): ?string
    {
        return match ($outcome) {
            'pass', 'fail' => null,
            'unjudged' => "{$side}_unjudged",
            'needs_human_review' => "{$side}_needs_human_review",
            'errored' => "{$side}_errored",
            default => null,
        };
    }

    /**
     * True iff any rubric_judgment expectation, judged on both sides at
     * the same index, dropped by at least material_score_drop. Applies
     * only where both sides have a real score at that index (research.md
     * D10) — a case with no rubric_judgment expectations, or one that is
     * unjudged on either side, simply never enters this axis.
     */
    private function hasMaterialScoreDrop(array $reference, array $compared): bool
    {
        return !empty($this->materiallyDroppedIndices($reference, $compared));
    }

    /**
     * cases is ordered by position in the compared run's eval_run_cases
     * snapshot (matched/edited/added cases all have one); a removed case
     * has no compared-run position and is appended after every case that
     * does, ordered by its own reference-run position (data-model.md
     * §3).
     *
     * @param  array<string, CaseComparisonResult>  $results
     * @return array<int, CaseComparisonResult>
     */
    private function orderResults(array $results, array $comparedCases, array $referenceCases): array
    {
        $withPosition = [];
        $removed = [];

        foreach ($results as $evalCaseId => $result) {
            if (isset($comparedCases[$evalCaseId])) {
                $withPosition[] = ['position' => $comparedCases[$evalCaseId]['position'], 'result' => $result];
            } else {
                $removed[] = ['position' => $referenceCases[$evalCaseId]['position'] ?? 0, 'result' => $result];
            }
        }

        usort($withPosition, fn (array $a, array $b) => $a['position'] <=> $b['position']);
        usort($removed, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return array_map(fn (array $entry) => $entry['result'], array_merge($withPosition, $removed));
    }
}
