<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Exceptions\AgentFileUnreadableException;
use ClarionApp\LlmClient\Exceptions\AgentNameAlreadyInUseException;
use ClarionApp\LlmClient\Exceptions\LastActiveAgentException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentDefinitionValidator;
use ClarionApp\LlmClient\Services\AgentDivergenceChecker;
use ClarionApp\LlmClient\Services\AgentQuery;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\AgentSummaryQuery;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionParseErrorKind;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionResolutionErrorKind;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionValidationResult;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionWarning;
use ClarionApp\LlmClient\ValueObjects\FileDivergenceReport;
use Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for `agents`/`agent_versions` (contracts §1/§4/§5/§6/§7/§8/
 * §9/§10/§11). store()/update() are Phase 3/US1's own scope. index()/
 * show()/versions()/versionDetail()/restore() are Phase 4/US2's own
 * addition. link()/unlink()/divergence()/syncFromFile() are Phase 5/US3's
 * own addition — show() now also embeds the `link`/`divergence` blocks
 * contracts §3 describes, but only when the agent is actually linked
 * (never present-but-null).
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
        private readonly AgentDefinitionValidator $validator,
        private readonly AgentDivergenceChecker $divergenceChecker,
        private readonly AgentSummaryQuery $summaryQuery,
    ) {}

    /**
     * POST /agents/check (088-agent-definition-validator, contracts §1,
     * FR-005/FR-006). A stateless, pure check of content against live
     * installation state — no agent id, no save. Always 200: a completed
     * check that finds problems is itself a successful check (research.md
     * D6/D8). The only failure mode is an uncaught exception (a genuine
     * live-state read failure) surfacing as an ordinary 500 — never
     * converted into a 200 body describing it as a "problem".
     *
     * 'definition' uses `present|nullable|string` here rather than
     * store()/update()'s `required|string` — an empty-string definition is
     * a genuine, checkable document (it reports MissingName, research.md
     * D5) and must reach AgentDefinitionValidator::check() rather than
     * being rejected by Laravel's own `required` rule before ever getting
     * there. `nullable` is needed alongside it because the framework's own
     * global ConvertEmptyStringsToNull middleware turns an empty (or,
     * after TrimStrings, whitespace-only) string body value into `null`
     * before validation ever runs — `(string) null === ''` below restores
     * the same empty-document meaning the raw HTTP body actually carried.
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'definition' => 'present|nullable|string',
        ]);

        $result = $this->validator->check((string) $request->input('definition'));

        return response()->json($this->checkResultResource($result), 200);
    }

    /**
     * POST /agents (contracts §1, FR-001/SC-001). Creates a new agent
     * that already has exactly one version the moment it is created.
     *
     * 088-agent-definition-validator: checks the definition via
     * AgentDefinitionValidator::check() *before* ever calling
     * AgentService::create() (research.md D8) — on any blocking problem,
     * returns the byte-identical body POST /agents/check would return for
     * the same content, and AgentService is never invoked.
     *
     * `present|nullable|string` (not `required|string`) for the identical
     * reason check()'s own docblock explains: an empty-string or
     * whitespace-only body value is a genuine, checkable document (it
     * reports MissingName, research.md D5) that must reach
     * AgentDefinitionValidator::check() and produce the same
     * {valid, problems, warnings} 422 shape check() would report for the
     * same content (FR-006's "same terms" guarantee) — not Laravel's own,
     * differently-shaped `required` validation-error body, which the
     * global ConvertEmptyStringsToNull middleware would otherwise trigger
     * for an empty/whitespace-only definition.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'definition' => 'present|nullable|string',
        ]);

        $rawYaml = (string) $request->input('definition');
        $result = $this->validator->check($rawYaml);

        if (!$result->valid) {
            return response()->json($this->checkResultResource($result), 422);
        }

        $agent = $this->service->create(Auth::id(), $rawYaml);

        return response()->json([
            ...$this->agentResource($agent),
            'warnings' => $this->warningsResource($result->warnings),
        ], 201);
    }

    /**
     * PUT /agents/{id} (contracts §4, FR-002/SC-002). Every definition
     * change through this path produces a new, distinct, attributed
     * version, never altering a prior one.
     *
     * 088-agent-definition-validator: identical pre-save check treatment
     * as store() (research.md D8) — checked first, AgentService::update()
     * never called on a blocking problem. Uses the identical
     * `present|nullable|string` rule as store() for the identical reason
     * (see store()'s own docblock) — an empty/whitespace-only definition
     * must reach the validator and report MissingName in the standard
     * {valid, problems, warnings} shape, not Laravel's own differently-
     * shaped `required` validation error.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $agent = $this->query->findAgent(Auth::id(), $id);

        if ($agent === null) {
            return $this->notFoundResponse();
        }

        $request->validate([
            'definition' => 'present|nullable|string',
        ]);

        $rawYaml = (string) $request->input('definition');
        $result = $this->validator->check($rawYaml);

        if (!$result->valid) {
            return response()->json($this->checkResultResource($result), 422);
        }

        $agent = $this->service->update($agent, Auth::id(), $rawYaml);

        return response()->json([
            ...$this->agentResource($agent),
            'warnings' => $this->warningsResource($result->warnings),
        ], 200);
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
     * GET /agents/search (094-agent-search-listing, contracts/
     * agent-search-api.md §1). Serves both browsing every owned agent (`q`
     * omitted/empty) and narrowing by a free-text query over name/parsed
     * instructions (`q` present) — a single endpoint, one query method
     * (data-model.md §4). Paginated in RunController's own `{data, meta}`
     * envelope, plus a top-level `total_unfiltered` letting the caller
     * distinguish "you have zero agents" from "your search matched zero"
     * (research.md D7).
     */
    public function search(Request $request): JsonResponse
    {
        [$page, $perPage] = $this->paginationParams($request, 20, 100);

        $result = $this->query->searchForUser(Auth::id(), $request->input('q'), $page, $perPage);

        $summaries = $this->summaryQuery->summariesFor($result['data'], Auth::id());

        $data = array_map(fn (Agent $agent) => $this->agentSearchEntryResource($agent, $summaries[$agent->id]), $result['data']);

        return response()->json([
            ...$this->envelope($data, $result['total'], $page, $perPage),
            'total_unfiltered' => $result['total_unfiltered'],
        ]);
    }

    /**
     * GET /agents/{id} (contracts §3). Resolves the current definition
     * directly via AgentDefinitionParser::parse(), not through a cached or
     * denormalized copy (research.md D6). Embeds `link`/`divergence` only
     * when the agent is actually linked — never present-but-null.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $agent = $this->query->findAgent(Auth::id(), $id);

        if ($agent === null) {
            return $this->notFoundResponse();
        }

        return response()->json($this->agentDetailResource($agent));
    }

    /**
     * PUT /agents/{id}/link (contracts §8, US3 AC1). Reads the file's
     * current working-tree content, validates it, then always imports it
     * as a new version immediately — linking always starts in-step.
     */
    public function link(Request $request, string $id): JsonResponse
    {
        $agent = $this->query->findAgent(Auth::id(), $id);

        if ($agent === null) {
            return $this->notFoundResponse();
        }

        $request->validate([
            'repository_path' => 'required|string',
            'file_path' => 'required|string',
        ]);

        try {
            $agent = $this->service->link(
                $agent,
                Auth::id(),
                $request->input('repository_path'),
                $request->input('file_path')
            );
        } catch (AgentFileUnreadableException $e) {
            return $this->fileUnreadableResponse($e);
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException $e) {
            return $this->definitionErrorResponse($e);
        }

        return response()->json($this->agentDetailResource($agent), 200);
    }

    /**
     * DELETE /agents/{id}/link (contracts §9). Clears the link; touches no
     * agent_versions row — history is never rewritten by unlinking.
     */
    public function unlink(Request $request, string $id): JsonResponse
    {
        $agent = $this->query->findAgent(Auth::id(), $id);

        if ($agent === null) {
            return $this->notFoundResponse();
        }

        $agent = $this->service->unlink($agent);

        return response()->json($this->agentDetailResource($agent), 200);
    }

    /**
     * GET /agents/{id}/divergence (contracts §10, FR-009/FR-010). Always
     * 200 — an unreadable file is a reportable state, not an HTTP failure
     * of this endpoint itself.
     */
    public function divergence(Request $request, string $id): JsonResponse
    {
        $agent = $this->query->findAgent(Auth::id(), $id);

        if ($agent === null) {
            return $this->notFoundResponse();
        }

        $report = $this->divergenceChecker->check($agent);

        return response()->json($this->divergenceResource($report), 200);
    }

    /**
     * POST /agents/{id}/sync-from-file (contracts §11). The one explicit
     * action that resolves a FileAhead/BothChanged divergence — always
     * imports whatever the file currently holds, overwriting nothing (the
     * stored agent's own unreconciled changes remain fully readable as the
     * version immediately prior).
     */
    public function syncFromFile(Request $request, string $id): JsonResponse
    {
        $agent = $this->query->findAgent(Auth::id(), $id);

        if ($agent === null) {
            return $this->notFoundResponse();
        }

        if ($agent->linked_repository_path === null) {
            return response()->json([
                'error' => 'not_linked',
                'message' => 'This agent is not linked to a definition file.',
            ], 422);
        }

        try {
            $agent = $this->service->syncFromFile($agent, Auth::id());
        } catch (AgentFileUnreadableException $e) {
            return $this->fileUnreadableResponse($e);
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException $e) {
            return $this->definitionErrorResponse($e);
        }

        return response()->json($this->agentDetailResource($agent), 200);
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
     * POST /agents/{id}/clone (091-agent-clone-fork, contracts §1,
     * FR-002/FR-013/FR-014). Resolves the source via
     * AgentQuery::findAgentIncludingTrashed() — a retired source is found,
     * not 404'd (FR-013). A colliding destination name is refused with a
     * 409, distinct from the 422 definition-error shape.
     */
    public function clone(Request $request, string $id): JsonResponse
    {
        $agent = $this->query->findAgentIncludingTrashed(Auth::id(), $id);

        if ($agent === null) {
            return $this->notFoundResponse();
        }

        $request->validate([
            'name' => 'required|string',
        ]);

        try {
            $clone = $this->service->clone($agent, Auth::id(), $request->input('name'));
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException $e) {
            return $this->definitionErrorResponse($e);
        } catch (AgentNameAlreadyInUseException $e) {
            return response()->json([
                'error' => 'agent_name_already_in_use',
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json($this->agentResource($clone), 201);
    }

    /**
     * POST /agents/{id}/activate (092-agent-activation, contracts §2,
     * FR-002). Resolves via the *ordinary* AgentQuery::findAgent() — a
     * soft-deleted agent is never a valid target (research.md D7). Always
     * 200: activate() is idempotent, so there is no failure mode beyond
     * "not found."
     */
    public function activate(Request $request, string $id): JsonResponse
    {
        $agent = $this->query->findAgent(Auth::id(), $id);

        if ($agent === null) {
            return $this->notFoundResponse();
        }

        $agent = $this->service->activate($agent);

        return response()->json($this->agentResource($agent), 200);
    }

    /**
     * POST /agents/{id}/deactivate (092-agent-activation, contracts §1,
     * FR-001/FR-013, research.md D6/D7). Resolves via the *ordinary*
     * AgentQuery::findAgent() — a soft-deleted agent is never a valid
     * target. Refuses with a 409 when deactivating would leave the caller
     * with no remaining active agents and `confirm` was not passed;
     * otherwise 200.
     */
    public function deactivate(Request $request, string $id): JsonResponse
    {
        $agent = $this->query->findAgent(Auth::id(), $id);

        if ($agent === null) {
            return $this->notFoundResponse();
        }

        $confirmed = $request->boolean('confirm');

        try {
            $agent = $this->service->deactivate($agent, $confirmed);
        } catch (LastActiveAgentException $e) {
            return response()->json([
                'error' => 'last_active_agent',
                'code' => 'last_active_agent',
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json($this->agentResource($agent), 200);
    }

    /**
     * The shape show()/link()/unlink()/syncFromFile() all share (contracts
     * §3): the current definition, plus `link`/`divergence` embedded only
     * when the agent is actually linked — never present-but-null
     * (contracts §3's own "omit the whole block when the concept doesn't
     * apply" convention).
     *
     * 088-agent-definition-validator (research.md D10): also surfaces the
     * current version's own `warnings` via AgentDefinitionValidator::check()
     * — this one shared helper is used by show()/link()/unlink()/
     * syncFromFile(), so this single addition surfaces warnings on all
     * four. Deliberately not added to agentResource() (used by restore()),
     * which stays unchanged per research.md D11.
     */
    private function agentDetailResource(Agent $agent): array
    {
        $resource = [
            ...$this->agentResource($agent),
            'definition' => $this->parser->parse($agent->currentVersion->raw_definition),
            'warnings' => $this->warningsResource($this->validator->check($agent->currentVersion->raw_definition)->warnings),
        ];

        if ($agent->linked_repository_path !== null) {
            $resource['link'] = $this->linkResource($agent);
            $resource['divergence'] = $this->divergenceResource($this->divergenceChecker->check($agent));
        }

        return $resource;
    }

    /**
     * The `link` block embedded in agentDetailResource() (contracts §3).
     */
    private function linkResource(Agent $agent): array
    {
        return [
            'repository_path' => $agent->linked_repository_path,
            'file_path' => $agent->linked_file_path,
        ];
    }

    /**
     * The shape both the embedded `divergence` block (contracts §3) and
     * the standalone GET /agents/{id}/divergence endpoint (contracts §10)
     * share. `unavailable_reason` is included only when set — omitted
     * entirely for every state but Unavailable, matching contracts §10's
     * own worked examples (the in_step/not_linked examples show only
     * three keys).
     */
    private function divergenceResource(FileDivergenceReport $report): array
    {
        $resource = [
            'state' => $report->state->value,
            'governs' => $report->governs,
            'checked_at' => $report->checkedAt->format(\DateTimeInterface::ATOM),
        ];

        if ($report->unavailableReason !== null) {
            $resource['unavailable_reason'] = $report->unavailableReason;
        }

        return $resource;
    }

    /**
     * The 422 body for `link`()/syncFromFile() when the file itself
     * cannot be read (contracts §8's distinct `error: "file_unreadable"`
     * — a filesystem-level failure, distinct from definitionErrorResponse()'s
     * content-validation failure).
     */
    private function fileUnreadableResponse(AgentFileUnreadableException $e): JsonResponse
    {
        return response()->json([
            'error' => 'file_unreadable',
            'message' => $e->getMessage(),
        ], 422);
    }

    /**
     * The `{valid, problems, warnings}` shape (088-agent-definition-validator,
     * contracts §1/§2/§3) shared verbatim by check()'s own 200 body and by
     * store()/update()'s 422 body — the single serialization point behind
     * FR-006's "same terms" guarantee (research.md D8/D9). `category` is
     * derived from the problem's own PHP class, never a separately-tracked
     * field that could drift from it.
     */
    private function checkResultResource(AgentDefinitionValidationResult $result): array
    {
        return [
            'valid' => $result->valid,
            'problems' => array_map(fn (AgentDefinitionParseException|AgentDefinitionResolutionException $e): array => [
                'category' => $e instanceof AgentDefinitionParseException ? 'structural' : 'semantic',
                'kind' => $e->kind->name,
                'key' => $e instanceof AgentDefinitionParseException ? $e->key : null,
                'value' => $e->value,
                'message' => $e->getMessage(),
            ], $result->problems),
            'warnings' => $this->warningsResource($result->warnings),
        ];
    }

    /**
     * The per-warning shape checkResultResource() and every successful
     * save/read response embed (088-agent-definition-validator, contracts
     * §1). Kept separate from checkResultResource() so a success body can
     * add `warnings` alongside agentResource()'s own fields without
     * building a full {valid, problems, warnings} envelope around it.
     *
     * @param list<AgentDefinitionWarning> $warnings
     */
    private function warningsResource(array $warnings): array
    {
        return array_map(fn (AgentDefinitionWarning $w): array => [
            'kind' => $w->kind->name,
            'operation_id' => $w->operationId,
            'method' => $w->method,
            'message' => $w->message,
        ], $warnings);
    }

    /**
     * The shape store()/update()/restore() share (contracts §1's 201 body
     * / §4's "response shape identical to §3's non-divergence fields,"
     * minus the `definition`/`link`/`divergence` blocks Phase 4/5's own
     * read surface adds).
     */
    private function agentResource(Agent $agent): array
    {
        $resource = [
            'id' => $agent->id,
            'name' => $agent->name,
            'current_version_number' => $agent->currentVersion?->version_number,
            'linked' => $agent->linked_repository_path !== null,
            'created_at' => $agent->created_at?->toIso8601String(),
            'is_active' => $agent->is_active,
        ];

        if ($agent->cloned_from_agent_id !== null) {
            $origin = $this->query->findAgentIncludingTrashed(Auth::id(), $agent->cloned_from_agent_id);
            $resource['cloned_from'] = [
                'id' => $agent->cloned_from_agent_id,
                'name' => $origin?->name,
            ];
        }

        return $resource;
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
            'is_active' => $agent->is_active,
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

    /**
     * Resolve `page`/`per_page` query params against a per-endpoint default
     * and cap (094-agent-search-listing, research.md D4) — copied verbatim
     * in shape from RunController::paginationParams(). `per_page` below 1
     * falls back to the default; above the cap is clamped down to it
     * (FR-009, enforced server-side regardless of what the client requests).
     *
     * @return array{0: int, 1: int} [page, perPage]
     */
    private function paginationParams(Request $request, int $default, int $cap): array
    {
        $page = max(1, (int) $request->input('page', 1));

        $perPage = (int) $request->input('per_page', $default);
        if ($perPage < 1) {
            $perPage = $default;
        }
        $perPage = min($perPage, $cap);

        return [$page, $perPage];
    }

    /**
     * The paginated `{data, meta}` envelope search() returns (data-model.md
     * §5) — copied verbatim in shape from RunController::envelope(). The
     * caller merges in `total_unfiltered` at the call site, exactly as
     * RunController::index()'s own `action_count` merge-in pattern for a
     * value this shared helper doesn't own.
     */
    private function envelope(array $data, int $total, int $page, int $perPage): array
    {
        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * The shape search()'s `data` entries use (094-agent-search-listing,
     * data-model.md §5, contracts/agent-search-api.md §1, extended by
     * 095-agent-summary-cards, data-model.md §8, contracts/
     * agent-summary-cards-api.md §1). `can_use` is always `true` today
     * (research.md D6, FR-003). `$summary` is the per-agent shape
     * AgentSummaryQuery::summariesFor() produces, keyed by this same
     * agent's id at the call site.
     */
    private function agentSearchEntryResource(Agent $agent, array $summary): array
    {
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'is_active' => $agent->is_active,
            'can_use' => true,
            'current_version_number' => $agent->currentVersion?->version_number,
            'purpose' => $summary['purpose'],
            'capabilities' => $summary['capabilities'],
            'operation_count' => $summary['operation_count'],
            'memory_enabled' => $summary['memory_enabled'],
            'usage' => $summary['usage'],
        ];
    }
}
