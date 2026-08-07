<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Services\CostRollupQuery;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Auth;

/**
 * Cost rollups (FR-009–FR-016, FR-021, FR-022, contracts/cost-api.md §3/§4).
 *
 * conversationShow follows ConversationController::show()'s idiom —
 * Conversation::findOrFail() (404 when absent) then an explicit 403 when
 * found but foreign-owned — not RunController's uniform-404
 * notFoundResponse(), which does not apply here (a cost_summaries row is
 * written lazily and its absence must never be confused with the
 * conversation itself not existing, contracts/cost-api.md §3).
 *
 * userShow/userIndex/agentShow/agentIndex need no existence check: a
 * userId/agentId with no usage in the period is simply the zero-value
 * shape, never a 404.
 */
class CostRollupController extends Controller
{
    public function __construct(
        private readonly CostRollupQuery $query,
    ) {}

    public function conversationShow(Request $request, string $conversationId): JsonResponse
    {
        $conversation = Conversation::findOrFail($conversationId);

        $isOperator = OperatorAccess::isOperator(Auth::id());

        if (!$isOperator && $conversation->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        [$from, $to] = $this->period($request);

        $result = $this->query->conversationTotal($conversationId, $from, $to, Auth::id(), $isOperator);

        return $this->respond($from, $to, $result);
    }

    public function conversationIndex(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $isOperator = OperatorAccess::isOperator(Auth::id());

        $rows = $this->query->conversationList($from, $to, Auth::id(), $isOperator);

        return $this->respondList($rows);
    }

    public function userShow(Request $request, string $userId): JsonResponse
    {
        $isOperator = OperatorAccess::isOperator(Auth::id());

        if (!$isOperator && $userId !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        [$from, $to] = $this->period($request);

        $result = $this->query->userTotal($userId, $from, $to, Auth::id(), $isOperator);

        return $this->respond($from, $to, $result);
    }

    public function userIndex(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $isOperator = OperatorAccess::isOperator(Auth::id());

        $rows = $this->query->userList($from, $to, Auth::id(), $isOperator);

        return $this->respondList($rows);
    }

    public function agentShow(Request $request, string $agentId): JsonResponse
    {
        $resolvedAgentId = $agentId === 'unattributed'
            ? CostSummary::UNATTRIBUTED_AGENT_BUCKET
            : $agentId;

        $isOperator = OperatorAccess::isOperator(Auth::id());

        [$from, $to] = $this->period($request);

        $result = $this->query->agentTotal($resolvedAgentId, $from, $to, Auth::id(), $isOperator);

        return $this->respond($from, $to, $result);
    }

    public function agentIndex(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $isOperator = OperatorAccess::isOperator(Auth::id());

        $rows = $this->query->agentList($from, $to, Auth::id(), $isOperator);

        return $this->respondList($rows);
    }

    /**
     * Both from/to (inclusive, UTC calendar dates) are required
     * (contracts/cost-api.md §3 — "an arbitrary period is satisfied by
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

    private function respond(string $from, string $to, array $result): JsonResponse
    {
        return response()->json(array_merge([
            'currency' => config('llm-client.cost.currency'),
            'period' => ['from' => $from, 'to' => $to],
        ], $result));
    }

    private function respondList(array $rows): JsonResponse
    {
        return response()->json([
            'currency' => config('llm-client.cost.currency'),
            'data' => $rows,
        ]);
    }
}
