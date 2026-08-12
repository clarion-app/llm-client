<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Models\EvalRunCase;
use ClarionApp\LlmClient\Services\EvalCaseHistoryQuery;
use ClarionApp\LlmClient\Services\RunComparisonService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use ClarionApp\LlmClient\ValueObjects\CaseComparisonCategory;
use ClarionApp\LlmClient\ValueObjects\CaseComparisonResult;

/**
 * Run and case-level comparison (contracts §3/§4). Operator gated
 * throughout. index() is the list view only — deliberately no
 * produced_response/expectation_results per contracts §1.2's own note.
 * caseDetail() is the full side-by-side view for one case: both sides'
 * actual responses plus, when a confidence verdict was attached, the
 * historical sample that verdict was drawn from.
 */
class EvalRunComparisonController extends Controller
{
    public function __construct(
        private readonly RunComparisonService $service,
        private readonly EvalCaseHistoryQuery $historyQuery,
    ) {
    }

    public function index(string $runId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $run = EvalRun::find($runId);

        if ($run === null) {
            return $this->notFound();
        }

        try {
            $comparison = $this->service->compare($run);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        return response()->json([
            'reference_run_id' => $comparison['reference_run_id'],
            'reference_incomplete' => $comparison['reference_incomplete'],
            'compared_incomplete' => $comparison['compared_incomplete'],
            'cases' => array_map(
                fn (CaseComparisonResult $case) => $this->formatCaseComparison($case),
                $comparison['cases'],
            ),
        ], 200);
    }

    /**
     * The §1.2 `case_comparison` shape.
     *
     * @return array<string, mixed>
     */
    private function formatCaseComparison(CaseComparisonResult $case): array
    {
        return [
            'eval_case_id' => $case->evalCaseId,
            'category' => $case->category->value,
            'confidence' => $case->confidence?->value,
            'reference_eval_run_case_id' => $case->referenceEvalRunCaseId,
            'compared_eval_run_case_id' => $case->comparedEvalRunCaseId,
            'reference_outcome' => $case->referenceOutcome,
            'compared_outcome' => $case->comparedOutcome,
            'inconclusive_reason' => $case->inconclusiveReason,
            'drifted_expectation_index' => $case->driftedExpectationIndex,
        ];
    }

    public function caseDetail(string $runId, string $evalCaseId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $run = EvalRun::find($runId);

        if ($run === null) {
            return $this->notFound();
        }

        try {
            $comparison = $this->service->compare($run);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        $case = null;
        foreach ($comparison['cases'] as $candidate) {
            if ($candidate->evalCaseId === $evalCaseId) {
                $case = $candidate;

                break;
            }
        }

        if ($case === null) {
            return $this->notFound();
        }

        return response()->json(
            $this->formatCaseComparisonDetail($case, $run, $comparison['reference_run_id']),
            200,
        );
    }

    /**
     * The §1.4 `case_comparison_detail` shape: both sides' full
     * produced_response/expectation_results, plus (only when confidence
     * is non-null) the historical sample that verdict was drawn from.
     *
     * @return array<string, mixed>
     */
    private function formatCaseComparisonDetail(CaseComparisonResult $case, EvalRun $comparedRun, ?string $referenceRunId): array
    {
        $detail = [
            'eval_case_id' => $case->evalCaseId,
            'category' => $case->category->value,
            'confidence' => $case->confidence?->value,
            'drifted_expectation_index' => $case->driftedExpectationIndex,
            'reference' => $this->loadResultSide($case->referenceEvalRunCaseId),
            'compared' => $this->loadResultSide($case->comparedEvalRunCaseId),
        ];

        if ($case->confidence !== null) {
            $detail['history_used'] = $this->historyUsedFor($case, $comparedRun, $referenceRunId);
        }

        return $detail;
    }

    /**
     * The full result row for one side of a case, or null when that side
     * never produced a result at all (an added/removed case, or a
     * matched case caught mid-incomplete-run) — a literal null, never an
     * object with null fields.
     *
     * @return array<string, mixed>|null
     */
    private function loadResultSide(?string $evalRunCaseId): ?array
    {
        if ($evalRunCaseId === null) {
            return null;
        }

        $result = EvalCaseResult::where('eval_run_case_id', $evalRunCaseId)->first();

        if ($result === null) {
            return null;
        }

        return [
            'eval_run_case_id' => $result->eval_run_case_id,
            'run_id' => $result->run_id,
            // COALESCE(outcome_override, outcome) — the effective
            // outcome, matching the §1.2 case_comparison shape's own
            // reference_outcome/compared_outcome.
            'outcome' => $result->outcome_override ?? $result->outcome->value,
            'produced_response' => $result->produced_response,
            'expectation_results' => $result->expectation_results ?? [],
        ];
    }

    /**
     * The evidence EvalCaseHistoryQuery already gathers for this case
     * when RunComparisonService attaches confidence — recomputed here
     * with a single-case query rather than threaded back out of
     * compare()'s return shape (a single-case call, not one per case in
     * a batch, so no N+1 is introduced). Exactly one of
     * prior_fail_count/prior_score_range is populated, depending on
     * whether the case's confidence came from the boolean (regressed) or
     * numeric (materially_drifted) axis.
     *
     * @return array<string, mixed>
     */
    private function historyUsedFor(CaseComparisonResult $case, EvalRun $comparedRun, ?string $referenceRunId): array
    {
        $evalCaseVersionId = EvalRunCase::find($case->comparedEvalRunCaseId)?->eval_case_version_id;

        $excludeRunIds = array_values(array_filter(
            [$referenceRunId, $comparedRun->id],
            fn ($id) => $id !== null,
        ));

        $limitPerCase = (int) config('llm-client.eval_regression.history_lookback_limit', 20);

        $histories = $this->historyQuery->historiesFor(
            $comparedRun->agent_label,
            [['eval_case_id' => $case->evalCaseId, 'eval_case_version_id' => $evalCaseVersionId]],
            $excludeRunIds,
            $limitPerCase,
        );

        $history = $histories[$case->evalCaseId] ?? ['outcomes' => [], 'scores_by_expectation_index' => []];

        if ($case->category === CaseComparisonCategory::Regressed) {
            return [
                'sample_size' => count($history['outcomes']),
                'prior_fail_count' => count(array_filter($history['outcomes'], fn ($outcome) => $outcome === 'fail')),
                'prior_score_range' => null,
            ];
        }

        // MateriallyDrifted: evidence specific to the drifted
        // expectation index, never an unattributed blend across
        // expectations.
        $scores = $history['scores_by_expectation_index'][$case->driftedExpectationIndex] ?? [];

        return [
            'sample_size' => count($scores),
            'prior_fail_count' => null,
            'prior_score_range' => empty($scores) ? null : [min($scores), max($scores)],
        ];
    }

    private function forbidden()
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    private function notFound()
    {
        return response()->json(['message' => 'Not found.'], 404);
    }

    private function unprocessable(string $message)
    {
        return response()->json([
            'message' => $message,
            'errors' => ['run' => [$message]],
        ], 422);
    }
}
