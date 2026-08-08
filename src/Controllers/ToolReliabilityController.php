<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Services\ToolReliabilityQuery;
use ClarionApp\LlmClient\Support\CalendarPeriod;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Auth;

/**
 * Tool reliability rate summaries (contracts/tool-reliability-api.md §1-2).
 *
 * show()/index() need no existence check: a tool with no invocations in the
 * period is simply the no_activity shape, never a 404 -- and never a 403
 * either; only the visible scope narrows for a non-operator, matching
 * LatencyController's never-403-only-narrows shape exactly (no tool or agent
 * is "owned" by anyone here).
 */
class ToolReliabilityController extends Controller
{
    public function __construct(
        private readonly ToolReliabilityQuery $query,
    ) {}

    public function show(Request $request, string $toolName): JsonResponse
    {
        [$period, $date] = $this->period($request);
        $isOperator = OperatorAccess::isOperator(Auth::id());

        $result = $this->query->toolSummary($toolName, $period, $date, Auth::id(), $isOperator);

        return response()->json($result);
    }

    public function index(Request $request): JsonResponse
    {
        [$period, $date] = $this->period($request);
        $isOperator = OperatorAccess::isOperator(Auth::id());

        $rows = $this->query->toolList($period, $date, Auth::id(), $isOperator);

        return response()->json([
            'period' => CalendarPeriod::resolve($period, $date),
            'data' => $rows,
        ]);
    }

    /**
     * period (day/week/month) and date are both required
     * (contracts/tool-reliability-api.md §1 -- "the server resolves it to
     * that bucket's canonical UTC boundaries").
     */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['required', 'in:day,week,month'],
            'date' => ['required', 'date'],
        ]);

        return [$validated['period'], $validated['date']];
    }
}
