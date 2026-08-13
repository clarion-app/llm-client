<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Services\AgentQuery;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionParseErrorKind;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionResolutionErrorKind;
use Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for `agents`/`agent_versions` (contracts §1/§4). store()
 * and update() are this file's Phase 3/US1 scope — index()/show()/
 * versions()/versionDetail()/restore() are User Story 2's own scope
 * (Phase 4), link()/unlink()/divergence()/syncFromFile() User Story 3's
 * (Phase 5).
 *
 * AgentService is the sole write path this controller ever calls; every
 * ownership-scoped lookup goes through AgentQuery, whose null return is
 * the uniform "not found or not yours" signal for a 404 (research.md D5).
 */
class StoredAgentController extends Controller
{
    public function __construct(
        private readonly AgentService $service,
        private readonly AgentQuery $query,
    ) {}

    /**
     * POST /agents (contracts §1, FR-001/SC-001). Creates a new agent
     * that already has exactly one version the moment it is created.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'definition' => 'required|string',
        ]);

        try {
            $agent = $this->service->create(Auth::id(), $request->input('definition'));
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException $e) {
            return $this->definitionErrorResponse($e);
        }

        return response()->json($this->agentResource($agent), 201);
    }

    /**
     * PUT /agents/{id} (contracts §4, FR-002/SC-002). Every definition
     * change through this path produces a new, distinct, attributed
     * version, never altering a prior one.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $agent = $this->query->findAgent(Auth::id(), $id);

        if ($agent === null) {
            return $this->notFoundResponse();
        }

        $request->validate([
            'definition' => 'required|string',
        ]);

        try {
            $agent = $this->service->update($agent, Auth::id(), $request->input('definition'));
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException $e) {
            return $this->definitionErrorResponse($e);
        }

        return response()->json($this->agentResource($agent), 200);
    }

    /**
     * The shape store()/update() share (contracts §1's 201 body / §4's
     * "response shape identical to §3's non-divergence fields," minus the
     * `definition`/`link`/`divergence` blocks Phase 4/5's own read surface
     * adds).
     */
    private function agentResource(Agent $agent): array
    {
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'current_version_number' => $agent->currentVersion?->version_number,
            'linked' => $agent->linked_repository_path !== null,
            'created_at' => $agent->created_at?->toIso8601String(),
        ];
    }

    /**
     * The uniform "not found" body for an absent or not-owned-by-the-
     * caller agent id (research.md D5) — mirrors
     * RunController::notFoundResponse()'s exact shape.
     */
    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'Agent not found',
            'code' => 'agent_not_found',
        ], 404);
    }

    /**
     * The uniform 422 body for a definition that fails to parse or
     * resolve (contracts §1), reusing 086's own exception kind/message
     * verbatim rather than inventing a new shape.
     */
    private function definitionErrorResponse(AgentDefinitionParseException|AgentDefinitionResolutionException $e): JsonResponse
    {
        return response()->json([
            'error' => $this->errorSlugFor($e),
            'message' => $e->getMessage(),
            'kind' => $e->kind->name,
        ], 422);
    }

    private function errorSlugFor(AgentDefinitionParseException|AgentDefinitionResolutionException $e): string
    {
        if ($e instanceof AgentDefinitionParseException) {
            return match ($e->kind) {
                AgentDefinitionParseErrorKind::MalformedYaml => 'malformed_yaml',
                AgentDefinitionParseErrorKind::UnrecognizedFormatVersion => 'unrecognized_format_version',
                AgentDefinitionParseErrorKind::MissingName => 'missing_name',
                AgentDefinitionParseErrorKind::UnknownKey => 'unrecognized_setting',
                AgentDefinitionParseErrorKind::InstructionsTooLong => 'instructions_too_long',
            };
        }

        return match ($e->kind) {
            AgentDefinitionResolutionErrorKind::UnknownModel => 'unknown_model',
            AgentDefinitionResolutionErrorKind::UnknownCapability => 'unknown_capability',
            AgentDefinitionResolutionErrorKind::EmptyOperationPattern => 'empty_operation_pattern',
        };
    }
}
