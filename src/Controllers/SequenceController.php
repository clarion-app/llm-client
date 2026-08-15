<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Exceptions\SequenceDefinitionValidationException;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageSequenceDefinition;
use ClarionApp\LlmClient\Services\SequenceQuery;
use ClarionApp\LlmClient\Services\SequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 105-stage-pipeline (contracts/stage-pipeline-api.md §1-§5). Skeleton
 * only in Phase 2 (Foundational) — every method is a 501 placeholder,
 * filled in across Phases 3-6:
 *   - store()/index()/show()   -> Phase 3 (US1), definitions
 *   - storeRun()/showRun()     -> Phase 3 (US1), runs
 *   - resume()                 -> Phase 6 (US4)
 *
 * Mirrors ManagedTaskController's own shape: a local private
 * notFoundResponse() helper (no shared base-class helper exists across
 * controllers in this package, Grounding note item 9).
 */
class SequenceController extends Controller
{
    public function __construct(
        private readonly SequenceQuery $sequenceQuery,
        private readonly SequenceService $sequenceService,
    ) {}

    /**
     * POST /sequence-definitions (contracts §1). Phase 3 (US1).
     */
    public function store(Request $request): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $stages = $request->input('stages', []);

        try {
            $definition = $this->sequenceService->defineSequence(
                $callerUserId,
                (string) $request->input('name'),
                $request->input('description'),
                (string) $request->input('coordinator_agent_id'),
                is_array($stages) ? $stages : [],
            );
        } catch (SequenceDefinitionValidationException $e) {
            $body = ['error' => $e->errorCode, 'message' => $e->getMessage()];
            if ($e->stagePosition !== null) {
                $body['stage_position'] = $e->stagePosition;
            }

            return response()->json($body, 422);
        }

        return response()->json([
            'sequence_definition_id' => $definition->id,
            'name' => $definition->name,
            'stages' => $definition->stages->map(fn (Stage $stage) => [
                'stage_id' => $stage->id,
                'position' => $stage->position,
                'name' => $stage->name,
            ])->all(),
        ], 201);
    }

    /**
     * GET /sequence-definitions (contracts §2). Phase 3 (US1).
     */
    public function index(Request $request): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $definitions = StageSequenceDefinition::where('owner_user_id', $callerUserId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($definitions->map(fn (StageSequenceDefinition $definition) => [
            'sequence_definition_id' => $definition->id,
            'name' => $definition->name,
            'description' => $definition->description,
            'coordinator_agent_id' => $definition->coordinator_agent_id,
        ])->all());
    }

    /**
     * GET /sequence-definitions/{id} (contracts §2). Phase 3 (US1). A
     * definition absent or owned by another user returns the SAME
     * uniform 404 either way -- never distinguishing the two
     * (SequenceQuery::findDefinition()'s own contract).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $definition = $this->sequenceQuery->findDefinition($callerUserId, $id);
        if ($definition === null) {
            return $this->notFoundResponse('Sequence definition not found', 'sequence_definition_not_found');
        }

        return response()->json([
            'sequence_definition_id' => $definition->id,
            'name' => $definition->name,
            'description' => $definition->description,
            'coordinator_agent_id' => $definition->coordinator_agent_id,
            'stages' => $definition->stages->map(fn (Stage $stage) => [
                'stage_id' => $stage->id,
                'position' => $stage->position,
                'name' => $stage->name,
                'helper_agent_id' => $stage->helper_agent_id,
                'input_schema' => $stage->input_schema,
                'output_schema' => $stage->output_schema,
                'is_idempotent' => $stage->is_idempotent,
            ])->all(),
        ]);
    }

    /**
     * POST /sequence-definitions/{id}/runs (contracts §3). Phase 3 (US1).
     * starting_input is required -- even an intentionally-empty object
     * must be supplied explicitly (contracts §3), so a caller can never
     * invoke a sequence by accident with no input at all.
     */
    public function storeRun(Request $request, string $id): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $definition = $this->sequenceQuery->findDefinition($callerUserId, $id);
        if ($definition === null) {
            return $this->notFoundResponse('Sequence definition not found', 'sequence_definition_not_found');
        }

        if (!$request->has('starting_input')) {
            return response()->json([
                'error' => 'starting_input_required',
                'message' => 'starting_input is required.',
            ], 422);
        }

        $startingInput = $request->input('starting_input');

        $result = $this->sequenceService->invoke($callerUserId, $id, is_array($startingInput) ? $startingInput : []);

        if (isset($result['error'])) {
            $body = ['error' => $result['error'], 'message' => $result['message'] ?? ''];
            if (isset($result['stage_id'])) {
                $body['stage_id'] = $result['stage_id'];
            }
            if (isset($result['stage_position'])) {
                $body['stage_position'] = $result['stage_position'];
            }

            return response()->json($body, 422);
        }

        $run = $result['sequence_run'];

        return response()->json([
            'sequence_run_id' => $run->id,
            'sequence_definition_id' => $run->sequence_definition_id,
            'status' => $run->status,
        ], 202);
    }

    /**
     * GET /sequence-runs/{id} (contracts §4). Phase 3 (US1). Every stage
     * is always present in `stages` regardless of run status
     * (data-model.md §4's pre-created-`pending` design) -- FR-009's
     * "which stages completed, which failed, which were never reached" is
     * this same array read once.
     */
    public function showRun(Request $request, string $id): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $run = $this->sequenceQuery->findRun($callerUserId, $id);
        if ($run === null) {
            return $this->notFoundResponse('Sequence run not found', 'sequence_run_not_found');
        }

        $stages = $this->sequenceQuery->stageResultsForRun($callerUserId, $id);

        return response()->json([
            'sequence_run_id' => $run->id,
            'sequence_definition_id' => $run->sequence_definition_id,
            'status' => $run->status,
            'current_stage_position' => $run->current_stage_position,
            'resume_count' => $run->resume_count,
            'failure_reason' => $run->failure_reason,
            'stages' => $stages,
        ]);
    }

    /**
     * POST /sequence-runs/{id}/resume (contracts §5). Phase 6 (US4).
     */
    public function resume(Request $request, string $id): JsonResponse
    {
        return $this->notImplementedResponse();
    }

    private function notImplementedResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'not_implemented',
        ], 501);
    }

    /**
     * The uniform "not found" body shape (matches ManagedTaskController's
     * own precedent) -- every controller in this package declares its own
     * private copy, there is no shared base-class helper (Grounding note
     * item 9).
     */
    private function notFoundResponse(string $error, string $code): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'code' => $code,
        ], 404);
    }
}
