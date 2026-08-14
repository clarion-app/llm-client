<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\DelegationQuery;
use ClarionApp\LlmClient\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DelegationController
 *
 * Read-only endpoints over the Delegation rows DelegationService (Phase 3)
 * writes (contracts/delegation-protocol-api.md §1-3): a run's own
 * delegations, a single delegation's detail, and a run's delegated-cost
 * rollup. Mirrors RunController's own pattern -- constructor-injected query
 * service, a single uniform not-found response per resource kind, with
 * every ownership check resolved entirely inside DelegationQuery
 * (research.md D8/D9) rather than any new identifier-comparison code here.
 */
class DelegationController extends Controller
{
    /** Column scale usage_records.total_cost is written/read at. */
    private const COST_SCALE = 10;

    public function __construct(
        private readonly DelegationQuery $delegationQuery,
    ) {}

    /**
     * GET /agent-runs/{runId}/delegations -- every delegation made during a
     * run the caller owns. 200 [] for a zero-delegation owned run -- never
     * 404 (matches RunController's own "empty is not absent" precedent for
     * its own step/action lists).
     */
    public function forRun(Request $request, string $runId): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $delegations = $this->delegationQuery->delegationsForRun($callerUserId, $runId);
        if ($delegations === null) {
            return $this->notFoundResponse('Run not found', 'run_not_found');
        }

        return response()->json($this->delegationRows($delegations));
    }

    /**
     * GET /delegations/{id} -- a single delegation's full detail, the same
     * per-item shape as forRun()'s array elements.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $delegation = $this->delegationQuery->findDelegation($callerUserId, $id);
        if ($delegation === null) {
            return $this->notFoundResponse('Delegation not found', 'delegation_not_found');
        }

        return response()->json($this->delegationRows([$delegation])[0]);
    }

    /**
     * GET /agent-runs/{runId}/cost-with-delegations -- own_cost (the
     * existing, unmodified per-run usage sum) alongside delegated_cost
     * (DelegationQuery::costForRun()'s own transitive rollup, research.md
     * D9) and delegation_count, without merging the two conversation-id
     * scopes.
     */
    public function cost(Request $request, string $runId): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $delegations = $this->delegationQuery->delegationsForRun($callerUserId, $runId);
        if ($delegations === null) {
            return $this->notFoundResponse('Run not found', 'run_not_found');
        }

        $ownUsage = UsageRecord::where('run_id', $runId)
            ->selectRaw('SUM(total_tokens) as tokens, SUM(total_cost) as cost')
            ->first();

        $delegated = $this->delegationQuery->costForRun($callerUserId, $runId);

        return response()->json([
            'run_id' => $runId,
            'own_cost' => [
                'total_cost' => Decimal::round(Decimal::fromNumeric($ownUsage->cost ?? '0'), self::COST_SCALE),
                'total_tokens' => (int) ($ownUsage->tokens ?? 0),
            ],
            'delegated_cost' => [
                'total_cost' => $delegated['total_cost'],
                'total_tokens' => $delegated['total_tokens'],
            ],
            'delegation_count' => $delegated['delegation_count'],
        ]);
    }

    /**
     * Project a batch of Delegation rows to the wire shape
     * (contracts/delegation-protocol-api.md §1), resolving
     * helper_agent_name for the whole batch in one query rather than N+1.
     *
     * @param Delegation[] $delegations
     * @return array<int, array<string, mixed>>
     */
    private function delegationRows(array $delegations): array
    {
        $agentIds = array_values(array_unique(array_map(fn (Delegation $d) => $d->helper_agent_id, $delegations)));
        $names = empty($agentIds) ? [] : Agent::whereIn('id', $agentIds)->pluck('name', 'id')->all();

        return array_map(function (Delegation $d) use ($names) {
            return [
                'id' => $d->id,
                'parent_conversation_id' => $d->parent_conversation_id,
                'helper_agent_id' => $d->helper_agent_id,
                'helper_agent_name' => $names[$d->helper_agent_id] ?? null,
                'helper_conversation_id' => $d->helper_conversation_id,
                'depth' => $d->depth,
                'status' => $d->status,
                'task' => $d->task,
                'context' => $d->context,
                'parent_run_id' => $d->parent_run_id,
                'parent_action_id' => $d->parent_action_id,
                'helper_run_id' => $d->helper_run_id,
                'outcome_summary' => $d->outcome_summary,
                'started_at' => $d->started_at?->toJSON(),
                'completed_at' => $d->completed_at?->toJSON(),
            ];
        }, $delegations);
    }

    /**
     * The uniform "not found" body shape (matches RunController's own
     * precedent) for an absent, or not-owned-by-the-caller, run/delegation
     * id.
     */
    private function notFoundResponse(string $error, string $code): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'code' => $code,
        ], 404);
    }
}
