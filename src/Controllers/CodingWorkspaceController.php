<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 112-coding-agent (contracts/coding-workspace-operations.md §1-§3).
 * Skeleton only in Phase 2 (Foundational) — every method is a 501
 * placeholder, filled in across Phase 3 (US1):
 *   - listFiles()/readFile()/gitStatus()/gitDiff() -> reads (§1)
 *   - writeFile()/deleteFile()                      -> confirmed mutations (§2)
 *   - runTests()                                    -> test execution (§3)
 *
 * Every route below carries `{project}` as a path parameter, but the
 * value that actually governs access is `$conversation->coding_project_id`,
 * enforced at the AgentLoopService seam (data-model.md §4) before any of
 * these methods ever run. Mirrors SequenceController's own Phase 2 shape:
 * a local private notFoundResponse() helper, no shared base-class helper
 * exists across controllers in this package.
 */
class CodingWorkspaceController extends Controller
{
    /**
     * GET coding-project/{project}/files (contracts §1). Phase 3 (US1).
     */
    public function listFiles(Request $request, string $project): JsonResponse
    {
        return $this->notImplementedResponse();
    }

    /**
     * GET coding-project/{project}/file (contracts §1). Phase 3 (US1).
     */
    public function readFile(Request $request, string $project): JsonResponse
    {
        return $this->notImplementedResponse();
    }

    /**
     * POST coding-project/{project}/file (contracts §2). Phase 3 (US1).
     */
    public function writeFile(Request $request, string $project): JsonResponse
    {
        return $this->notImplementedResponse();
    }

    /**
     * DELETE coding-project/{project}/file (contracts §2). Phase 3 (US1).
     */
    public function deleteFile(Request $request, string $project): JsonResponse
    {
        return $this->notImplementedResponse();
    }

    /**
     * POST coding-project/{project}/run-tests (contracts §3). Phase 3 (US1).
     */
    public function runTests(Request $request, string $project): JsonResponse
    {
        return $this->notImplementedResponse();
    }

    /**
     * GET coding-project/{project}/git-status (contracts §1). Phase 3 (US1).
     */
    public function gitStatus(Request $request, string $project): JsonResponse
    {
        return $this->notImplementedResponse();
    }

    /**
     * GET coding-project/{project}/git-diff (contracts §1). Phase 3 (US1).
     */
    public function gitDiff(Request $request, string $project): JsonResponse
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
     * The uniform "not found" body shape (matches SequenceController's/
     * ManagedTaskController's own precedent) -- every controller in this
     * package declares its own private copy, there is no shared
     * base-class helper.
     */
    private function notFoundResponse(string $error, string $code): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'code' => $code,
        ], 404);
    }
}
