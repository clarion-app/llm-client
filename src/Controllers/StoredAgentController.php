<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentQuery;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionParseErrorKind;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionResolutionErrorKind;
use Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for `agents`/`agent_versions` (contracts §1/§4/§5/§6/§7).
 * store()/update() are Phase 3/US1's own scope. index()/show()/versions()/
 * versionDetail()/restore() are Phase 4/US2's own addition — show() is
 * deliberately built here *without* the `link`/`divergence` blocks
 * contracts §3 also describes, since those are User Story 3's own scope
 * (Phase 5). link()/unlink()/divergence()/syncFromFile() remain Phase 5's.
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
        private readonly AgentDefinitionParser $parser,
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
     * GET /agents (contracts §2). Not paginated — contracts §2's own
     * "scale/scope expects a small per-user count" note.
     */
    public function index(Request $request): JsonResponse
    {
        $agents = $this->query->listForUser(Auth::id());

        return response()->json([
            'data' => $agents->map(fn (Agent $agent) => $this->agentListResource($agent))->all(),
        ]);
    }

    /**
     * GET /agents/{id} (contracts §3, minus the `link`/`divergence` blocks
     * — Phase 5/US3's own addition). Resolves the current definition
     * directly via AgentDefinitionParser::parse(), not through a cached or
     * denormalized copy (research.md D6).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $agent = $this->query->findAgent(Auth::id(), $id);

        if ($agent === null) {
            return $this->notFoundResponse();
        }

        $definition = $this->parser->parse($agent->currentVersion->raw_definition);

        return response()->json([
            ...$this->agentResource($agent),
            'definition' => $definition,
        ]);
    }

    /**
     * GET /agents/{id}/versions (contracts §5) — every version of an
     * agent, in order, paginated, never including raw_definition (a
     * version list can be long; each entry's full definition is a
     * separate, deliberate fetch via versionDetail()).
     */
    public function versions(Request $request, string $id): JsonResponse
    {
        $page = max(1, (int) $request->input('page', 1));

        $versions = $this->query->versionsForAgent(Auth::id(), $id, $page);

        if ($versions === null) {
            return $this->notFoundResponse();
        }

        return response()->json([
            'data' => collect($versions->items())->map(fn (AgentVersion $version) => $this->versionListResource($version))->all(),
            'current_page' => $versions->currentPage(),
            'last_page' => $versions->lastPage(),
            'per_page' => $versions->perPage(),
            'total' => $versions->total(),
        ]);
    }

    /**
     * GET /agents/{id}/versions/{versionId} (contracts §6, FR-005/SC-003).
     * raw_definition is present unconditionally — reading history never
     * fails because of today's installation state (research.md D7). The
     * `resolved` block is a best-effort parse whose exception is caught
     * into `resolution_error` rather than propagated (mutation-checklist
     * row 5).
     */
    public function versionDetail(Request $request, string $id, string $versionId): JsonResponse
    {
        $version = $this->query->findVersion(Auth::id(), $id, $versionId);

        if ($version === null) {
            return $this->notFoundResponse();
        }

        $resolved = null;
        $resolutionError = null;

        try {
            $resolved = $this->parser->parse($version->raw_definition);
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException $e) {
            $resolutionError = [
                'kind' => $e->kind->name,
                'value' => $e->value,
            ];
        }

        return response()->json([
            ...$this->versionListResource($version),
            'git_author_name' => $version->git_author_name,
            'git_committed_at' => $version->git_committed_at?->toIso8601String(),
            'raw_definition' => $version->raw_definition,
            'resolved' => $resolved,
            'resolution_error' => $resolutionError,
        ]);
    }

    /**
     * POST /agents/{id}/versions/{versionId}/restore (contracts §7,
     * FR-006/FR-007). Both the agent and the version are 404-checked
     * before AgentService::restore() is called; the same 422/200 posture
     * as update() applies to a target that no longer resolves against
     * current installation state (research.md D7).
     */
    public function restore(Request $request, string $id, string $versionId): JsonResponse
    {
        $agent = $this->query->findAgent(Auth::id(), $id);

        if ($agent === null) {
            return $this->notFoundResponse();
        }

        $version = $this->query->findVersion(Auth::id(), $id, $versionId);

        if ($version === null) {
            return $this->notFoundResponse();
        }

        try {
            $agent = $this->service->restore($agent, Auth::id(), $version);
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException $e) {
            return $this->definitionErrorResponse($e);
        }

        return response()->json($this->agentResource($agent), 200);
    }

    /**
     * The shape store()/update()/restore() share (contracts §1's 201 body
     * / §4's "response shape identical to §3's non-divergence fields,"
     * minus the `definition`/`link`/`divergence` blocks Phase 4/5's own
     * read surface adds).
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
     * The shape index()'s `data` entries use (contracts §2).
     */
    private function agentListResource(Agent $agent): array
    {
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'current_version_number' => $agent->currentVersion?->version_number,
            'linked' => $agent->linked_repository_path !== null,
        ];
    }

    /**
     * The shape versions()'s `data` entries use verbatim (contracts §5),
     * and versionDetail()'s own starting point before the additional
     * git_author_name/git_committed_at/raw_definition/resolved/
     * resolution_error fields are merged in (contracts §6) — deliberately
     * never includes raw_definition.
     */
    private function versionListResource(AgentVersion $version): array
    {
        return [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'source' => $version->source,
            'changed_by_user_id' => $version->changed_by_user_id,
            'git_commit_hash' => $version->git_commit_hash,
            'created_at' => $version->created_at?->toIso8601String(),
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
