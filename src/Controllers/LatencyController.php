<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Services\LatencyQuery;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Auth;

/**
 * Latency distributions (FR-016-FR-021, contracts/latency-api.md §1).
 *
 * modelShow/modelIndex/agentShow/agentIndex need no existence check: a
 * model/agent with no responses in the period is simply the no_data shape,
 * never a 404 -- and, per contracts/latency-api.md §1, never a 403 either;
 * only the visible scope narrows for a non-operator, matching
 * CostRollupController's userShow/agentShow shape rather than
 * ModelPriceController's unconditional-403 shape.
 */
class LatencyController extends Controller
{
    public function __construct(
        private readonly LatencyQuery $query,
    ) {}

    public function modelShow(Request $request, string $model): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $isOperator = OperatorAccess::isOperator(Auth::id());

        $result = $this->query->modelDistribution($model, $from, $to, Auth::id(), $isOperator);

        return response()->json($result);
    }

    public function modelIndex(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $isOperator = OperatorAccess::isOperator(Auth::id());

        $rows = $this->query->modelList($from, $to, Auth::id(), $isOperator);

        return $this->respondList($from, $to, $rows);
    }

    public function agentShow(Request $request, string $agentId): JsonResponse
    {
        $resolvedAgentId = $agentId === 'unattributed' ? null : $agentId;

        [$from, $to] = $this->period($request);
        $isOperator = OperatorAccess::isOperator(Auth::id());

        $result = $this->query->agentDistribution($resolvedAgentId, $from, $to, Auth::id(), $isOperator);

        return response()->json($result);
    }

    public function agentIndex(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $isOperator = OperatorAccess::isOperator(Auth::id());

        $rows = $this->query->agentList($from, $to, Auth::id(), $isOperator);

        return $this->respondList($from, $to, $rows);
    }

    /**
     * Both from/to (inclusive, UTC calendar dates) are required
     * (contracts/latency-api.md §1 -- "an arbitrary period is satisfied by
     * requiring the caller to name one explicitly").
     */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        return [$validated['from'], $validated['to']];
    }

    private function respondList(string $from, string $to, array $rows): JsonResponse
    {
        $data = array_map(function (array $row) {
            unset($row['from'], $row['to']);

            return $row;
        }, $rows);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'data' => $data,
        ]);
    }
}
