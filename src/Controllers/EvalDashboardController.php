<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalCaseVersion;
use ClarionApp\LlmClient\Models\EvalJudgment;
use ClarionApp\LlmClient\Services\EvalDashboardQuery;
use ClarionApp\LlmClient\Services\EvalPersistentFailureQuery;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\Request;
use Auth;

/**
 * Operator-only agent quality dashboard read surface
 * (contracts/eval-dashboard-api.md §1-2). Every action checks operator
 * access first and answers 403 otherwise, including plain reads —
 * matching EvalRunController/EvalSuiteController's own stated discipline.
 */
class EvalDashboardController extends Controller
{
    public function __construct(
        private readonly EvalDashboardQuery $dashboardQuery,
        private readonly EvalPersistentFailureQuery $persistentFailureQuery,
    ) {
    }

    /**
     * GET /agent-eval-dashboard/{agentLabel} — never 404s for an
     * unrecognized agent label; an agent with zero suites and zero runs
     * simply produces the all-empty/null shape below, which is itself the
     * empty-state contract, not an error.
     */
    public function index(Request $request, string $agentLabel)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $defaultWindowDays = (int) config('llm-client.eval_dashboard.default_trend_window_days', 30);
        $maxWindowDays = (int) config('llm-client.eval_dashboard.max_trend_window_days', 180);

        $requestedWindowDays = $request->query('trend_window_days');
        $windowDays = $requestedWindowDays !== null
            ? max(1, min((int) $requestedWindowDays, $maxWindowDays))
            : $defaultWindowDays;

        $persistentFailureLimit = (int) config('llm-client.eval_dashboard.persistent_failure_limit', 10);

        return response()->json([
            'agent_label' => $agentLabel,
            'current_pass_rate' => $this->dashboardQuery->currentPassRate($agentLabel),
            'trend' => [
                'window_days' => $windowDays,
                'buckets' => $this->dashboardQuery->trend($agentLabel, $windowDays),
            ],
            'persistent_failures' => $this->persistentFailureQuery->rankedFailures($agentLabel, $persistentFailureLimit),
        ], 200);
    }

    /**
     * GET /eval-runs/{runId}/cases/{caseResultId}/detail — what a case was
     * given, what a correct response should have looked like, what the
     * agent produced, and why it was scored that way, composed at read
     * time from three already-existing, already-tested rows (contracts
     * §2, data-model.md §3). Adds fields to what the existing run-cases
     * listing already returns for the same row — never redefines them.
     */
    public function caseDetail(string $runId, string $caseResultId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $result = EvalCaseResult::where('id', $caseResultId)
            ->where('run_id', $runId)
            ->first();

        if ($result === null) {
            return $this->caseResultNotFound();
        }

        $version = EvalCaseVersion::find($result->eval_case_version_id);

        $expectationResults = collect($result->expectation_results)
            ->map(function (array $expectation) {
                if (!array_key_exists('judgment_id', $expectation) || !$expectation['judgment_id']) {
                    return $expectation;
                }

                $judgment = EvalJudgment::with('overrides')->find($expectation['judgment_id']);

                if ($judgment !== null) {
                    $expectation['judgment'] = $judgment->effective();
                }

                return $expectation;
            })
            ->all();

        return response()->json([
            'id' => $result->id,
            'run_id' => $result->run_id,
            'eval_case_id' => $result->eval_case_id,
            'eval_case_version_id' => $result->eval_case_version_id,
            'given' => $version?->given,
            'expected_behavior' => $version?->expected_behavior,
            'outcome' => $result->outcome->value,
            'outcome_override' => $result->outcome_override,
            'produced_response' => $result->produced_response,
            'attempted_actions' => $result->attempted_actions,
            'expectation_results' => $expectationResults,
            'error_message' => $result->error_message,
            'created_at' => optional($result->created_at)->toJSON(),
        ], 200);
    }

    private function forbidden()
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    /**
     * The one uniform 404 shape (contracts §2) — distinct from
     * EvalRunController's own plain {"message": "Not found."} — for an
     * absent caseResultId, one belonging to a different run, or a
     * nonexistent run: all three are indistinguishable to the caller.
     */
    private function caseResultNotFound()
    {
        return response()->json([
            'error' => 'Case result not found',
            'code' => 'case_result_not_found',
        ], 404);
    }
}
