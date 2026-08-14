<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Exceptions\HelperAssignmentCycleException;
use ClarionApp\LlmClient\Exceptions\HelperDepthLimitExceededException;
use ClarionApp\LlmClient\Exceptions\HelperExceedsParentPermissionsException;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Services\AgentHelperQuery;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for helper assignments (contracts/subagent-model-api.md
 * §1/§2, 097-subagent-model, Phase 3/US1+US2).
 *
 * Both actions are owner-only. assign()/helpers() each resolve the target
 * (parent) agent via AgentQuery::findAgent() themselves, before ever
 * calling AgentHelperService/AgentHelperQuery, so a caller who does not
 * own it gets the uniform 404 without either service class needing a
 * bespoke exception to distinguish "not owned" from a validation failure
 * — the same pattern AgentShareController already established. Neither
 * action ever reaches for AgentQuery::findAccessibleAgent() — mere access
 * to an agent (a `use`/`use_and_edit` grant) is deliberately never enough
 * to manage its helpers.
 *
 * Unlike AgentShareController::validationFailedResponse()'s single
 * catch-all 422 shape, assign() here renders four distinct 422 bodies per
 * rejection reason: a plain \RuntimeException (self-assignment),
 * HelperExceedsParentPermissionsException (exceeds-parent-permissions),
 * HelperAssignmentCycleException (cycle-detected, 097-subagent-model Phase
 * 4/US3), and HelperDepthLimitExceededException (depth-limit-exceeded,
 * same phase).
 */
class AgentHelperController extends Controller
{
    public function __construct(
        private readonly AgentHelperService $service,
        private readonly AgentHelperQuery $query,
        private readonly AgentQuery $agentQuery,
    ) {}

    /**
     * POST /agents/{id}/helpers (contracts §1). `201` for a first-ever
     * assignment of this (parent, helper) pair, `200` for a re-assignment
     * after a prior removal — both are the same upsert, distinguished only
     * by whether the row existed before this call.
     */
    public function assign(Request $request, string $id): JsonResponse
    {
        if ($this->agentQuery->findAgent(Auth::id(), $id) === null) {
            return $this->notFoundResponse();
        }

        $helperAgentId = (string) $request->input('helper_agent_id');

        if ($this->agentQuery->findAgent(Auth::id(), $helperAgentId) === null) {
            // Deliberately the identical 404 shape as the parent-side
            // check above (contracts §1) — a caller can never probe for
            // another user's agent ids via this endpoint by distinguishing
            // "parent not found" from "helper not found".
            return $this->notFoundResponse();
        }

        $existed = AgentHelperAssignment::withTrashed()
            ->where('parent_agent_id', $id)
            ->where('helper_agent_id', $helperAgentId)
            ->exists();

        try {
            $assignment = $this->service->assign(Auth::id(), $id, $helperAgentId);
        } catch (HelperExceedsParentPermissionsException $e) {
            return response()->json([
                'error' => 'exceeds_parent_permissions',
                'message' => $e->getMessage(),
                'excess_operation_ids' => $e->excessOperationIds,
            ], 422);
        } catch (HelperAssignmentCycleException $e) {
            return response()->json([
                'error' => 'cycle_detected',
                'message' => $e->getMessage(),
                'cycle_path' => $e->cyclePath,
            ], 422);
        } catch (HelperDepthLimitExceededException $e) {
            return response()->json([
                'error' => 'depth_limit_exceeded',
                'message' => $e->getMessage(),
                'computed_depth' => $e->computedDepth,
                'max_depth' => $e->maxDepth,
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => 'self_assignment',
                'message' => $e->getMessage(),
            ], 422);
        }

        $row = $this->query->annotate($assignment);

        return response()->json($this->rowResource($row), $existed ? 200 : 201);
    }

    /**
     * GET /agents/{id}/helpers (contracts §2). Owner-only, `404`
     * identically to assign() for a non-owner caller. Only currently-active
     * assignments are ever returned, each annotated live with
     * `within_bounds`/`effective_operation_count`.
     */
    public function helpers(Request $request, string $id): JsonResponse
    {
        $rows = $this->query->helpersFor(Auth::id(), $id);

        if ($rows === null) {
            return $this->notFoundResponse();
        }

        return response()->json([
            'data' => $rows->map(fn (object $row) => $this->rowResource($row))->all(),
        ]);
    }

    /**
     * GET /agents/{id}/helpers/hierarchy (contracts §3, FR-007). Owner-only,
     * `404` identically to assign()/helpers() for a non-owner caller. The
     * full descendant graph beneath the given agent, flattened depth-first
     * — not only its immediate helpers.
     */
    public function hierarchy(Request $request, string $id): JsonResponse
    {
        $result = $this->query->hierarchyFor(Auth::id(), $id);

        if ($result === null) {
            return $this->notFoundResponse();
        }

        return response()->json($result);
    }

    private function rowResource(object $row): array
    {
        return [
            'id' => $row->id,
            'parent_agent_id' => $row->parent_agent_id,
            'helper_agent_id' => $row->helper_agent_id,
            'helper_name' => $row->helper_name,
            'helper_purpose' => $row->helper_purpose,
            'helper_status' => $row->helper_status,
            'within_bounds' => $row->within_bounds,
            'effective_operation_count' => $row->effective_operation_count,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The uniform "not found" body for an absent or not-owned-by-the-
     * caller agent id — mirrors AgentShareController::notFoundResponse()'s
     * exact shape.
     */
    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'Agent not found',
            'code' => 'agent_not_found',
        ], 404);
    }
}
