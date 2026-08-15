<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
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
        return $this->notImplementedResponse();
    }

    /**
     * GET /sequence-definitions (contracts §2). Phase 3 (US1).
     */
    public function index(Request $request): JsonResponse
    {
        return $this->notImplementedResponse();
    }

    /**
     * GET /sequence-definitions/{id} (contracts §2). Phase 3 (US1).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->notImplementedResponse();
    }

    /**
     * POST /sequence-definitions/{id}/runs (contracts §3). Phase 3 (US1).
     */
    public function storeRun(Request $request, string $id): JsonResponse
    {
        return $this->notImplementedResponse();
    }

    /**
     * GET /sequence-runs/{id} (contracts §4). Phase 3 (US1).
     */
    public function showRun(Request $request, string $id): JsonResponse
    {
        return $this->notImplementedResponse();
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
