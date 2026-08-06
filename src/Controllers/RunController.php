<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use Auth;
use Illuminate\Http\JsonResponse;

/**
 * RunController
 *
 * Read-only endpoints for a caller's own agent runs: the runs list, a
 * single run's summary, its ordered steps, and the lazy-expand
 * step-actions / action-children / action-detail endpoints.
 *
 * Every method resolves the caller via Auth::user()->id and re-derives
 * ownership through RunTraceQuery's existing findRun()-based checks — no
 * new identifier-comparison code is introduced by this controller
 * (research.md D1/D2).
 */
class RunController extends Controller
{
    public function __construct(
        private readonly RunTraceQuery $runTraceQuery
    ) {}

    /**
     * The single uniform "not found" body every endpoint on this
     * controller returns for an absent, purged, or not-owned-by-the-caller
     * run/step/action id (FR-014, research.md D2).
     */
    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'Run not found',
            'code' => 'run_not_found',
        ], 404);
    }
}
