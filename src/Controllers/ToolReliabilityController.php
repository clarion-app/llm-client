<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\ToolReliabilitySummary;
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
        $agentId = $this->resolveAgentIdParam($request);

        $result = $this->query->toolSummary(
            toolName: $toolName,
            periodType: $period,
            date: $date,
            agentId: $agentId,
            callerId: Auth::id(),
            isOperator: $isOperator,
        );

        return response()->json($this->deSentinelize($result));
    }

    public function index(Request $request): JsonResponse
    {
        [$period, $date] = $this->period($request);
        $isOperator = OperatorAccess::isOperator(Auth::id());

        $rows = $this->query->toolList($period, $date, Auth::id(), $isOperator);

        return response()->json([
            'period' => CalendarPeriod::resolve($period, $date),
            'data' => array_map(fn (array $row) => $this->deSentinelize($row), $rows),
        ]);
    }

    public function agentBreakdown(Request $request, string $toolName): JsonResponse
    {
        [$period, $date] = $this->period($request);
        $isOperator = OperatorAccess::isOperator(Auth::id());

        $rows = $this->query->toolAgentBreakdown($toolName, $period, $date, Auth::id(), $isOperator);

        return response()->json([
            'tool_name' => $toolName,
            'period' => CalendarPeriod::resolve($period, $date),
            'data' => array_map(fn (array $row) => $this->deSentinelize($row), $rows),
        ]);
    }

    /**
     * The literal string `unattributed` resolves to the internal storage
     * sentinel before reaching ToolReliabilityQuery; omitted stays null
     * (the "all agents" aggregate, US1 behavior); any other value passes
     * through unchanged as a real agent id (contracts/tool-reliability-
     * api.md §1).
     */
    private function resolveAgentIdParam(Request $request): ?string
    {
        $agentId = $request->query('agent_id');

        if ($agentId === null) {
            return null;
        }

        return $agentId === 'unattributed'
            ? ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET
            : $agentId;
    }

    /**
     * The internal Unattributed sentinel UUID is a storage detail and must
     * never leak into an API response -- it is always rewritten to the
     * literal string "unattributed" before serialization
     * (contracts/tool-reliability-api.md §1/§3).
     */
    private function deSentinelize(array $result): array
    {
        if (($result['agent_id'] ?? null) === ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET) {
            $result['agent_id'] = 'unattributed';
        }

        return $result;
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
