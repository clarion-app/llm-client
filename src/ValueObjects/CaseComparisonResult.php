<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * One case's classified comparison entry, returned by
 * RunComparisonService::compare() and consumed by
 * EvalRunComparisonController. One entry exists per case appearing in
 * either run's eval_run_cases snapshot.
 */
final class CaseComparisonResult
{
    public function __construct(
        public readonly string $evalCaseId,
        public readonly CaseComparisonCategory $category,
        // Only ever set when $category is Regressed or MateriallyDrifted.
        public readonly ?VarianceConfidence $confidence,
        // Null when $category is Added.
        public readonly ?string $referenceEvalRunCaseId,
        // Null when $category is Removed.
        public readonly ?string $comparedEvalRunCaseId,
        // Null when no reference-side result exists at all (an
        // incomplete reference's gap).
        public readonly ?string $referenceOutcome,
        // Null symmetrically for an incomplete compared run.
        public readonly ?string $comparedOutcome,
        // Set only when $category is Inconclusive — one of:
        // 'reference_no_result' | 'compared_no_result' |
        // 'reference_unjudged' | 'compared_unjudged' |
        // 'reference_needs_human_review' | 'compared_needs_human_review' |
        // 'reference_errored' | 'compared_errored'.
        public readonly ?string $inconclusiveReason,
        // Set only when $category is MateriallyDrifted — the pinned
        // version's expectations[] index whose score drop produced
        // $confidence. When more than one rubric_judgment expectation
        // drops materially in the same case, this names the index whose
        // verdict was most severe, never left ambiguous between them.
        public readonly ?int $driftedExpectationIndex,
    ) {}
}
