<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Services\RunComparisonService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use ClarionApp\LlmClient\ValueObjects\CaseComparisonResult;

/**
 * Run-level comparison (contracts §3). Operator gated. index() is the
 * list view only — deliberately no produced_response/expectation_results
 * per contracts §1.2's own note; the full side-by-side detail is a later
 * addition on top of this same controller.
 */
class EvalRunComparisonController extends Controller
{
    public function __construct(
        private readonly RunComparisonService $service,
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
