<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 112-coding-agent (contracts/coding-workspace-operations.md §0,
 * data-model.md §1) — human-driven project registration. Never listed in
 * coding.yaml's tools.allow; registering/removing a project is a human
 * action, the same way a user (not an agent) registers a Server.
 */
class CodingProjectController extends Controller
{
    /**
     * POST coding-project (contracts §0). `root_path` must `realpath()`-
     * resolve to an existing, readable directory or the request is
     * refused with a specific reason — never silently accepted as a
     * broken pointer. The RESOLVED path is stored, never the raw input.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'root_path' => 'required|string',
            'test_command' => 'nullable|string',
        ]);

        $resolved = realpath($validated['root_path']);

        if ($resolved === false || !is_dir($resolved) || !is_readable($resolved)) {
            return response()->json([
                'errors' => [
                    'root_path' => ["\"{$validated['root_path']}\" does not resolve to an existing, readable directory."],
                ],
            ], 422);
        }

        $project = CodingProject::create([
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'root_path' => $resolved,
            'test_command' => $validated['test_command'] ?? null,
        ]);

        return response()->json($project, 201);
    }

    /**
     * GET coding-project (contracts §0). Lists the requesting user's own
     * registered projects only.
     */
    public function index(Request $request): JsonResponse
    {
        $projects = CodingProject::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($projects, 200);
    }

    /**
     * DELETE coding-project/{id} (contracts §0). Ownership-checked soft
     * delete — a project belonging to another user is reported not found,
     * never distinguished from a genuinely absent id.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $project = CodingProject::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if ($project === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $project->delete();

        return response()->json([], 204);
    }

    private function notFoundResponse(string $error, string $code): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'code' => $code,
        ], 404);
    }
}
