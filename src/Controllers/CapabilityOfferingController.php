<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Exceptions\CapabilityOfferingCycleException;
use ClarionApp\LlmClient\Services\AgentQuery;
use ClarionApp\LlmClient\Services\CapabilityOfferingQuery;
use ClarionApp\LlmClient\Services\CapabilityOfferingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for capability offerings (contracts/capability-offering-api.md,
 * 109-agent-as-capability, Phase 2/Foundational), mirroring
 * AgentHelperController's exact shape and posture (Grounding note 7).
 *
 * All three actions are owner-only. offer()/list() each resolve the target
 * (offered) agent via AgentQuery::findAgent() themselves, before ever
 * calling CapabilityOfferingService/CapabilityOfferingQuery, so a caller
 * who does not own it gets the uniform 404 without either service class
 * needing a bespoke exception to distinguish "not owned" from a validation
 * failure. Neither action ever reaches for
 * AgentQuery::findAccessibleAgent() -- mere access to an agent (a
 * `use`/`use_and_edit` grant) is deliberately never enough to manage its
 * capability offerings.
 *
 * Unlike a single catch-all 422 shape, offer() here renders two distinct
 * 422 bodies per rejection reason: CapabilityOfferingCycleException
 * (capability_offering_cycle, caught BEFORE the generic \RuntimeException
 * catch, exactly as AgentHelperController orders its own specific-before-
 * generic catches) and a plain \RuntimeException (self_offering).
 */
class CapabilityOfferingController extends Controller
{
    public function __construct(
        private readonly CapabilityOfferingService $service,
        private readonly CapabilityOfferingQuery $query,
        private readonly AgentQuery $agentQuery,
    ) {}

    /**
     * POST /agents/{offeredAgentId}/capability-offerings (contracts
     * "POST"). `200` for both a first-ever offering of this
     * (offered_agent_id, caller_agent_id) pair and a re-offer after a prior
     * withdrawal -- both are the same upsert.
     */
    public function offer(Request $request, string $offeredAgentId): JsonResponse
    {
        $offered = $this->agentQuery->findAgent(Auth::id(), $offeredAgentId);

        if ($offered === null) {
            return $this->notFoundResponse();
        }

        $callerAgentId = (string) $request->input('caller_agent_id');
        $caller = $this->agentQuery->findAgent(Auth::id(), $callerAgentId);

        if ($caller === null) {
            // Deliberately the identical 404 shape as the offered-agent
            // check above -- a caller can never probe for another user's
            // agent ids via this endpoint by distinguishing "offered agent
            // not found" from "caller agent not found".
            return $this->notFoundResponse();
        }

        try {
            $offering = $this->service->offer(
                Auth::id(),
                $offeredAgentId,
                $callerAgentId,
                (string) $request->input('capability_name'),
                (string) $request->input('capability_description'),
                (string) $request->input('input_description'),
            );
        } catch (CapabilityOfferingCycleException $e) {
            return response()->json([
                'error' => 'capability_offering_cycle',
                'message' => $e->getMessage(),
                'cycle_path' => $e->cyclePath,
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => 'self_offering',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json($this->rowResource((object) [
            'id' => $offering->id,
            'offered_agent_id' => $offering->offered_agent_id,
            'offered_agent_name' => $offered->name,
            'caller_agent_id' => $offering->caller_agent_id,
            'caller_agent_name' => $caller->name,
            'capability_name' => $offering->capability_name,
            'capability_description' => $offering->capability_description,
            'input_description' => $offering->input_description,
            'created_at' => $offering->created_at,
            'updated_at' => $offering->updated_at,
        ]), 200);
    }

    /**
     * GET /agents/{offeredAgentId}/capability-offerings (contracts "GET").
     * Owner-only, `404` identically to offer() for a non-owner caller. Only
     * currently-active offerings are ever returned.
     */
    public function list(Request $request, string $offeredAgentId): JsonResponse
    {
        $rows = $this->query->offeringsFor(Auth::id(), $offeredAgentId);

        if ($rows === null) {
            return $this->notFoundResponse();
        }

        return response()->json([
            'data' => $rows->map(fn (object $row) => $this->rowResource($row))->all(),
        ]);
    }

    /**
     * DELETE /agents/{offeredAgentId}/capability-offerings/{callerAgentId}
     * (contracts "DELETE"). Owner-only for the offered-agent side -- `404`
     * identically to offer()/list() when the caller doesn't own
     * {offeredAgentId}. Idempotent: `200 {"removed": true}` if an active
     * offering existed for this pair and was withdrawn, `200 {"removed":
     * false}` if none existed -- distinct from
     * AgentHelperController::remove()'s always-`204` shape, matching this
     * feature's own contract.
     */
    public function withdraw(Request $request, string $offeredAgentId, string $callerAgentId): JsonResponse
    {
        if ($this->agentQuery->findAgent(Auth::id(), $offeredAgentId) === null) {
            return $this->notFoundResponse();
        }

        $removed = $this->service->withdraw(Auth::id(), $offeredAgentId, $callerAgentId);

        return response()->json(['removed' => $removed], 200);
    }

    private function rowResource(object $row): array
    {
        return [
            'id' => $row->id,
            'offered_agent_id' => $row->offered_agent_id,
            'offered_agent_name' => $row->offered_agent_name,
            'caller_agent_id' => $row->caller_agent_id,
            'caller_agent_name' => $row->caller_agent_name,
            'capability_name' => $row->capability_name,
            'capability_description' => $row->capability_description,
            'input_description' => $row->input_description,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The uniform "not found" body for an absent or not-owned-by-the-
     * caller agent id -- mirrors AgentHelperController::notFoundResponse()'s
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
