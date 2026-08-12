<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Services\EvalDashboardQuery;
use ClarionApp\LlmClient\Services\EvalPersistentFailureQuery;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\Request;
use Auth;

/**
 * Operator-only agent quality dashboard read surface
 * (contracts/eval-dashboard-api.md §1). Every action checks operator
 * access first and answers 403 otherwise, including this plain read —
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

    private function forbidden()
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
