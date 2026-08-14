<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\AgentShareGrant;
use ClarionApp\LlmClient\Services\AgentQuery;
use ClarionApp\LlmClient\Services\AgentShareQuery;
use ClarionApp\LlmClient\Services\AgentShareService;
use Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for agent-sharing grants (contracts/agent-sharing-api.md
 * §1/§2, 096-agent-sharing, Phase 3/US1).
 *
 * Both actions are owner-only. share() resolves the target agent via
 * AgentQuery::findAgent() itself, before ever calling AgentShareService,
 * so a caller who does not own the agent gets the uniform 404 without the
 * service needing a bespoke exception hierarchy to distinguish "not
 * owned" from a validation failure — the same pattern every other
 * mutating action in StoredAgentController already uses. shares() relies
 * on AgentShareQuery::grantsForAgent()'s own identical ownership check.
 * Neither action ever reaches for AgentQuery::findAccessibleAgent() — mere
 * access to an agent (a `use`/`use_and_edit` grant) is deliberately never
 * enough to grant further access to it or to see who else has access.
 */
class AgentShareController extends Controller
{
    public function __construct(
        private readonly AgentShareService $service,
        private readonly AgentShareQuery $query,
        private readonly AgentQuery $agentQuery,
    ) {}

    /**
     * POST /agents/{id}/shares (contracts §1). `201` for a first-ever grant
     * of this (agent, recipient) pair, `200` for a re-grant or a
     * permission-level change on an already-active grant — both are the
     * same upsert, distinguished only by whether the row existed before
     * this call.
     */
    public function share(Request $request, string $id): JsonResponse
    {
        if ($this->agentQuery->findAgent(Auth::id(), $id) === null) {
            return $this->notFoundResponse();
        }

        $recipientUserId = (string) $request->input('recipient_user_id');
        $permission = (string) $request->input('permission');

        $existed = AgentShareGrant::withTrashed()
            ->where('agent_id', $id)
            ->where('recipient_user_id', $recipientUserId)
            ->exists();

        try {
            $grant = $this->service->grant(Auth::id(), $id, $recipientUserId, $permission);
        } catch (\RuntimeException $e) {
            return $this->validationFailedResponse($e);
        }

        return response()->json($this->grantResource($grant), $existed ? 200 : 201);
    }

    /**
     * GET /agents/{id}/shares (contracts §2). Owner-only, `404` identically
     * to share() for a non-owner caller. Only currently-active grants are
     * ever returned.
     */
    public function shares(Request $request, string $id): JsonResponse
    {
        $grants = $this->query->grantsForAgent(Auth::id(), $id);

        if ($grants === null) {
            return $this->notFoundResponse();
        }

        return response()->json([
            'data' => $grants->map(fn (AgentShareGrant $grant) => $this->grantResource($grant))->all(),
        ]);
    }

    /**
     * The shape share()/shares() both return per grant (contracts §1/§2).
     */
    private function grantResource(AgentShareGrant $grant): array
    {
        return [
            'id' => $grant->id,
            'agent_id' => $grant->agent_id,
            'recipient_user_id' => $grant->recipient_user_id,
            'recipient_name' => $grant->recipient?->name,
            'permission' => $grant->permission,
            'created_at' => $grant->created_at?->toIso8601String(),
            'updated_at' => $grant->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The `422` body for every one of AgentShareService::grant()'s
     * validation rejections (contracts §1) — a missing/unknown
     * `recipient_user_id`, a self-share attempt, or an invalid
     * `permission` value. Ownership failures never reach here; share()
     * checks that itself before ever calling the service.
     */
    private function validationFailedResponse(\RuntimeException $e): JsonResponse
    {
        return response()->json([
            'error' => 'invalid_share_request',
            'message' => $e->getMessage(),
        ], 422);
    }

    /**
     * The uniform "not found" body for an absent or not-owned-by-the-
     * caller agent id (research.md D5) — mirrors
     * StoredAgentController::notFoundResponse()'s exact shape.
     */
    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'Agent not found',
            'code' => 'agent_not_found',
        ], 404);
    }
}
