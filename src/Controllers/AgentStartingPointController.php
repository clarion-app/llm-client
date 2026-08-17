<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Exceptions\AgentStartingPointNotFoundException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Services\AgentDefinitionValidator;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\AgentStartingPointCatalog;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionWarning;
use ClarionApp\LlmClient\ValueObjects\AgentStartingPointSummary;
use Illuminate\Http\JsonResponse;

/**
 * HTTP surface for ready-made agent starting points -- index()/store()
 * mirror StoredAgentController::store()'s own three-step shape (find,
 * check, create) exactly, reusing AgentDefinitionValidator::check() and
 * AgentService::create() unmodified rather than a parallel
 * implementation of either.
 *
 * store() re-checks with a fresh AgentDefinitionValidator::check() call
 * rather than trusting an earlier list() response -- the same
 * check-then-create discipline store() already applies to hand-written
 * definitions, closing the same window between "looked satisfied" and
 * "was satisfied at creation time."
 */
class AgentStartingPointController extends Controller
{
    public function __construct(
        private readonly AgentStartingPointCatalog $catalog,
        private readonly AgentDefinitionValidator $validator,
        private readonly AgentService $service,
    ) {
    }

    /**
     * GET /agent-starting-points. Read-only -- creates nothing. Always
     * 200, including when zero starting points are currently registered.
     */
    public function index(): JsonResponse
    {
        $data = array_map(
            fn (AgentStartingPointSummary $summary): array => [
                'slug' => $summary->slug,
                'description' => $summary->description,
                'requirements_satisfied' => $summary->requirementsSatisfied,
                'problems' => $this->problemsResource($summary->problems),
            ],
            $this->catalog->list(),
        );

        return response()->json(['data' => $data]);
    }

    /**
     * POST /agent-starting-points/{slug}. 404 when the slug is not
     * currently registered; 422 (identical shape to POST /agents' own
     * 422) when its requirements are not currently satisfied, with no
     * Agent/AgentVersion row written; otherwise 201 with the created
     * agent plus an ephemeral starting_point_slug echo, never persisted.
     */
    public function store(string $slug): JsonResponse
    {
        try {
            $startingPoint = $this->catalog->find($slug);
        } catch (AgentStartingPointNotFoundException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'starting_point_not_found',
            ], 404);
        }

        $rawYaml = $this->catalog->rawYamlFor($startingPoint->slug);
        $result = $this->validator->check($rawYaml);

        if (!$result->valid) {
            return response()->json([
                'valid' => false,
                'problems' => $this->problemsResource($result->problems),
                'warnings' => $this->warningsResource($result->warnings),
            ], 422);
        }

        $agent = $this->service->create(Auth::id(), $rawYaml);

        return response()->json([
            ...$this->agentResource($agent),
            'warnings' => $this->warningsResource($result->warnings),
            'starting_point_slug' => $startingPoint->slug,
        ], 201);
    }

    /**
     * The shape StoredAgentController::agentResource() already produces
     * for store()'s own 201 body, duplicated here rather than shared
     * since the source method is private to that controller.
     */
    private function agentResource(Agent $agent): array
    {
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'current_version_number' => $agent->currentVersion?->version_number,
            'linked' => $agent->linked_repository_path !== null,
            'created_at' => $agent->created_at?->toIso8601String(),
            'is_active' => $agent->is_active,
            'is_default_handler' => $agent->is_default_handler,
        ];
    }

    /**
     * The per-problem shape StoredAgentController::checkResultResource()
     * already produces (category/kind/key/value/message), duplicated
     * here rather than shared since the source method is private to that
     * controller -- kept byte-for-byte identical so a starting point's
     * unmet requirement reads exactly like any other definition's
     * validation problem.
     *
     * @param list<AgentDefinitionParseException|AgentDefinitionResolutionException> $problems
     */
    private function problemsResource(array $problems): array
    {
        return array_map(fn (AgentDefinitionParseException|AgentDefinitionResolutionException $e): array => [
            'category' => $e instanceof AgentDefinitionParseException ? 'structural' : 'semantic',
            'kind' => $e->kind->name,
            'key' => $e instanceof AgentDefinitionParseException ? $e->key : null,
            'value' => $e->value,
            'message' => $e->getMessage(),
        ], $problems);
    }

    /**
     * The per-warning shape StoredAgentController::warningsResource()
     * already produces, duplicated here for the identical reason as
     * problemsResource() above.
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
}
