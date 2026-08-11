<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Services\EvalRunConsumptionQuery;
use ClarionApp\LlmClient\Services\EvalRunService;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Http\Request;
use Auth;

/**
 * Operator-only run execution/read surface (contracts/eval-runs-api.md
 * §2-3), mirroring EvalSuiteController's operator-gated shape (077).
 * Every action checks operator access first and answers 403 otherwise,
 * including plain reads (FR-019).
 *
 * EvalRunService is the sole write path for eval_runs/eval_run_cases;
 * this controller only translates HTTP to it.
 */
class EvalRunController extends Controller
{
    public function __construct(
        private readonly EvalRunService $service,
        private readonly EvalSuiteService $suiteService,
        private readonly EvalRunConsumptionQuery $consumptionQuery,
    ) {
    }

    public function store(Request $request, string $suiteId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $suite = $this->suiteService->find($suiteId);

        if ($suite === null) {
            return $this->notFound();
        }

        // FR-020: refused before EvalRunService::start() is ever called —
        // no eval_runs row is created for a suite with zero live cases.
        if ($suite->cases()->count() === 0) {
            return $this->unprocessable('This suite has no cases to evaluate.');
        }

        $run = $this->service->start($suite);

        return response()->json($this->formatRunDetail($run), 201);
    }

    public function index(string $suiteId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $suite = $this->suiteService->find($suiteId);

        if ($suite === null) {
            return $this->notFound();
        }

        $runs = EvalRun::where('suite_id', $suite->id)
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (EvalRun $run) => $this->formatRunSummary($run))
            ->values();

        return response()->json(['data' => $runs], 200);
    }

    public function show(string $runId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $run = EvalRun::find($runId);

        if ($run === null) {
            return $this->notFound();
        }

        return response()->json($this->formatRunDetail($run), 200);
    }

    public function cases(Request $request, string $runId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $run = EvalRun::find($runId);

        if ($run === null) {
            return $this->notFound();
        }

        // Only rows with a completed eval_case_results entry are ever
        // returned — a still-pending/dispatched case simply does not yet
        // appear here (contracts §3). Ordered by the suite's authored,
        // snapshotted case order (eval_run_cases.position).
        $results = EvalCaseResult::where('eval_case_results.run_id', $run->id)
            ->join('eval_run_cases', 'eval_run_cases.id', '=', 'eval_case_results.eval_run_case_id')
            ->orderBy('eval_run_cases.position')
            ->select('eval_case_results.*')
            ->paginate((int) $request->query('per_page', 25));

        $results->getCollection()->transform(fn (EvalCaseResult $result) => $this->formatCaseResult($result));

        return response()->json($results, 200);
    }

    public function resume(string $runId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $run = EvalRun::find($runId);

        if ($run === null) {
            return $this->notFound();
        }

        // Both terminal states — nothing to resume (contracts §2).
        if (in_array($run->status, [EvalRunStatus::Completed, EvalRunStatus::FailedToStart], true)) {
            return $this->unprocessable('This run has already finished.');
        }

        $run = $this->service->resume($run);

        return response()->json($this->formatRunDetail($run), 200);
    }

    /**
     * The §1.3 list-shape — deliberately never carries the detail-only
     * `overall` field (contracts §1.3 vs §1.4).
     *
     * @return array<string, mixed>
     */
    private function formatRunSummary(EvalRun $run): array
    {
        $summary = $this->service->summarize($run);

        return [
            'id' => $run->id,
            'suite_id' => $run->suite_id,
            'agent_label' => $run->agent_label,
            'status' => $run->status->value,
            'case_count' => $run->case_count,
            'completed_count' => $summary['completed_count'],
            'remaining_count' => $summary['remaining_count'],
            'started_at' => optional($run->started_at)->toJSON(),
            'completed_at' => optional($run->completed_at)->toJSON(),
        ];
    }

    /**
     * The §1.4 detail shape, including `consumption` (§1.2 shape) — present
     * and meaningful at any run status, computed fresh at read time
     * (research.md D11, FR-011). Shared by store()/show()/resume(), all
     * three of which return "the run body (§1.4 shape)" per contracts §2-3.
     *
     * @return array<string, mixed>
     */
    private function formatRunDetail(EvalRun $run): array
    {
        $summary = $this->service->summarize($run);
        $consumption = $this->consumptionQuery->summarize($run);
        $judging = $this->consumptionQuery->summarizeJudging($run);

        return array_merge($this->formatRunSummary($run), [
            'failure_reason' => $run->failure_reason,
            'overall' => $summary['overall'],
            'outcome_counts' => [
                'pass' => $summary['pass'],
                'fail' => $summary['fail'],
                'needs_human_review' => $summary['needs_human_review'],
                'errored' => $summary['errored'],
            ],
            'consumption' => [
                'total_cost' => $consumption->totalCost,
                'cost_currency' => config('llm-client.cost.currency'),
                'cost_unpriced' => $consumption->costUnpriced,
                'total_tokens' => $consumption->totalTokens,
                'tool_invocation_count' => $consumption->toolInvocationCount,
                'total_duration_ms' => $consumption->totalDurationMs,
                // What rubric-based judging itself consumed for this run,
                // kept visibly separate from the agent-under-test figures
                // above — always present, an all-zero object when nothing
                // was judged, never an absent key.
                'judging' => [
                    'total_cost' => $judging['cost'],
                    'total_tokens' => $judging['tokens'],
                    'invocation_count' => $judging['invocationCount'],
                    'cost_unpriced' => $judging['costUnpriced'],
                ],
            ],
        ]);
    }

    /**
     * The §1.1 `case_result` shape.
     *
     * @return array<string, mixed>
     */
    private function formatCaseResult(EvalCaseResult $result): array
    {
        return [
            'id' => $result->id,
            'eval_case_id' => $result->eval_case_id,
            'eval_case_version_id' => $result->eval_case_version_id,
            'outcome' => $result->outcome->value,
            'outcome_override' => $result->outcome_override,
            'produced_response' => $result->produced_response,
            'attempted_actions' => $result->attempted_actions,
            'expectation_results' => $result->expectation_results,
            'error_message' => $result->error_message,
            'created_at' => optional($result->created_at)->toJSON(),
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
            'errors' => ['suite' => [$message]],
        ], 422);
    }
}
