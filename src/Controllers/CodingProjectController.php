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
     * GET coding-project (122-workspace-browser-ui, US1, contracts/
     * workspace-list-api.md). Lists the requesting user's own registered
     * projects only, each annotated with a live-recomputed `reachable`
     * flag, wrapped in the flat paginated envelope T006 settled on.
     */
    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = $this->paginationParams($request, 50, 100);

        $total = CodingProject::where('user_id', Auth::id())->count();

        $projects = CodingProject::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->forPage($page, $perPage)
            ->get()
            ->map(function (CodingProject $project) {
                $data = $project->toArray();
                // `reachable` is computed fresh, from the live filesystem,
                // on every single call -- never stored on the model or
                // cached from a prior call (FR-002/FR-014, research.md D3).
                // A directory that goes missing between two GETs must
                // report differently on each one.
                $data['reachable'] = is_dir($project->root_path) && is_readable($project->root_path);

                return $data;
            })
            ->values()
            ->all();

        return response()->json($this->envelope($projects, $total, $page, $perPage), 200);
    }

    /**
     * Resolve `page`/`per_page` query params against a per-endpoint
     * default and cap (122-workspace-browser-ui T006 decision -- mirrors
     * only RunController::paginationParams()'s floor/cap/default *logic*,
     * not its nested envelope shape; this controller is not shared with
     * RunController and has no cross-controller pagination helper to
     * extract into).
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
     * The flat `{data, total, page, per_page}` envelope T006 settled on
     * for this feature's list endpoints -- deliberately NOT
     * RunController::envelope()'s nested `{data, meta: {...}}` shape
     * (Grounding note 6).
     */
    private function envelope(array $data, int $total, int $page, int $perPage): array
    {
        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
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

    /**
     * PATCH coding-project/{id}/confirmation-setting
     * (121-workspace-boundary-hardening, US3, contracts/
     * confirmation-relaxation.md §1). Ownership-checked identically to
     * destroy() — a project belonging to another user is reported not
     * found, never distinguished from a genuinely absent id. Never listed
     * in coding.yaml's tools.allow, matching this controller's other
     * human-only actions.
     */
    public function updateConfirmationSetting(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'relaxed' => 'required|boolean',
        ]);

        $project = CodingProject::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if ($project === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $project->confirmation_relaxed = $validated['relaxed'];
        $project->save();

        return response()->json($project, 200);
    }

    /**
     * PATCH coding-project/{id}/command-allowlist (123-sandboxed-shell-
     * execution, US2, contracts/command-allowlist.md §1). Replaces the
     * workspace's entire allowlist (FR-004/FR-005) -- not an incremental
     * add/remove; the caller sends the full desired list each time.
     * Duplicate patterns in the submitted array are deduplicated before
     * storage, not rejected. Ownership-checked identically to
     * updateConfirmationSetting()/destroy(). Never listed in coding.yaml's
     * tools.allow, matching this controller's other human-only actions.
     */
    public function updateCommandAllowlist(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'patterns' => 'present|array',
            'patterns.*' => 'required|string',
        ]);

        $project = CodingProject::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if ($project === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $project->command_allowlist = array_values(array_unique($validated['patterns']));
        $project->save();

        return response()->json($project, 200);
    }

    /**
     * PATCH coding-project/{id}/network-policy (123-sandboxed-shell-
     * execution, US4, contracts/network-policy.md §1, research.md D7).
     * `true` allows commands run in this workspace to reach the network
     * (FR-012); `false` (the default) denies it (FR-011). Ownership-
     * checked identically to updateConfirmationSetting()/
     * updateCommandAllowlist(). Never listed in coding.yaml's
     * tools.allow, matching this controller's other human-only actions
     * -- a human, not an agent, decides whether a workspace may reach
     * the network.
     */
    public function updateNetworkPolicy(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'network_enabled' => 'required|boolean',
        ]);

        $project = CodingProject::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if ($project === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $project->network_enabled = $validated['network_enabled'];
        $project->save();

        return response()->json($project, 200);
    }

    /**
     * PATCH coding-project/{id}/resource-limits (124-command-limit-
     * controls, US1, contracts/resource-limits.md §1). Each of the six
     * override keys is validated and applied independently -- a subset of
     * keys may be sent, and each is nullable: an explicit `null` clears
     * that one override (FR-004), while a key entirely ABSENT from the
     * request body leaves that column (set or unset) completely untouched.
     *
     * This distinction is why $request->has($key) is used per key rather
     * than $request->filled() (which would treat "present with null" the
     * same as "absent" -- exactly the partial-update semantic this
     * endpoint must not collapse) and rather than a single
     * $request->validate() over the whole body assigned wholesale (which
     * would have no way to distinguish "not sent" from "sent as null").
     * Ownership-checked identically to updateConfirmationSetting()/
     * updateCommandAllowlist()/updateNetworkPolicy(). Never listed in
     * coding.yaml's tools.allow, matching this controller's other
     * human-only actions -- raising or lowering a workspace's own limits
     * is a human, operator-level action (spec.md Assumptions).
     */
    public function updateResourceLimits(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'time_limit_override_seconds' => 'nullable|integer|min:1',
            'memory_limit_override_mb' => 'nullable|integer|min:1',
            'cpu_limit_override' => 'nullable|regex:/^\d+(\.\d+)?$/',
            'pids_limit_override' => 'nullable|integer|min:1',
            'disk_limit_override_mb' => 'nullable|integer|min:1',
            'output_cap_override_bytes' => 'nullable|integer|min:1',
        ]);

        $project = CodingProject::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if ($project === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        foreach ([
            'time_limit_override_seconds',
            'memory_limit_override_mb',
            'cpu_limit_override',
            'pids_limit_override',
            'disk_limit_override_mb',
            'output_cap_override_bytes',
        ] as $key) {
            // $request->has($key) is true both for a present non-null
            // value AND for a present explicit null -- exactly the "was
            // this key sent at all" check this partial-update contract
            // needs. $request->filled($key) would be false for an
            // explicit null too, indistinguishable from "absent" -- the
            // one bug this endpoint must never have (FR-003/FR-004).
            if ($request->has($key)) {
                $project->{$key} = $validated[$key] ?? null;
            }
        }

        $project->save();

        return response()->json($project, 200);
    }

    private function notFoundResponse(string $error, string $code): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'code' => $code,
        ], 404);
    }
}
