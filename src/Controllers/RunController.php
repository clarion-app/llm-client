<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Services\ContentSanitizer;
use ClarionApp\LlmClient\Services\DelegationQuery;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        private readonly RunTraceQuery $runTraceQuery,
        private readonly ContentSanitizer $contentSanitizer,
        private readonly DelegationQuery $delegationQuery,
    ) {}

    /**
     * GET /agent-runs — the caller's own runs, most recent first, paginated
     * (US6, FR-024). `runsForUserPaginated()` (T083) only ever queries
     * `WHERE user_id = ?`, so this never returns another user's rows by
     * construction — there is no ownership gate to fail, and therefore no
     * 404 case on this endpoint (FR-014/consistency note, tasks.md Phase 8).
     * `200` with empty `data` for a caller with zero runs (FR-025).
     */
    public function index(Request $request): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        [$page, $perPage] = $this->paginationParams($request, 20, 100);

        $result = $this->runTraceQuery->runsForUserPaginated($callerUserId, $page, $perPage);

        $runIds = array_map(fn (AgentRun $run) => $run->id, $result['data']);
        $actionCounts = empty($runIds) ? collect() : DB::table('agent_run_actions')
            ->select('run_id', DB::raw('COUNT(*) as cnt'))
            ->whereIn('run_id', $runIds)
            ->groupBy('run_id')
            ->pluck('cnt', 'run_id');

        $data = array_map(
            fn (AgentRun $run) => $this->runSummary($run, (int) ($actionCounts[$run->id] ?? 0)),
            $result['data'],
        );

        return response()->json($this->envelope($data, $result['total'], $page, $perPage));
    }

    /**
     * GET /agent-runs/{runId} — a single run's O(1) metadata, including the
     * cheap COUNT(*) action_count aggregate (contracts/run-read-api.md,
     * data-model.md §1.1).
     */
    public function show(Request $request, string $runId): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $run = $this->runTraceQuery->findRun($callerUserId, $runId);
        if ($run === null) {
            return $this->notFoundResponse();
        }

        $actionCount = DB::table('agent_run_actions')
            ->where('run_id', $runId)
            ->count();

        return response()->json($this->runSummary($run, $actionCount));
    }

    /**
     * GET /agent-runs/{runId}/steps — ordered step list (position asc), no
     * action content, paginated at the call site (default 100, cap 200)
     * over stepsForRun()'s existing unpaginated result (data-model.md §2).
     * Zero-step run: 200 with empty data (FR-018), never a 404.
     */
    public function steps(Request $request, string $runId): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $allSteps = $this->runTraceQuery->stepsForRun($callerUserId, $runId);
        if ($allSteps === null) {
            return $this->notFoundResponse();
        }

        [$page, $perPage] = $this->paginationParams($request, 100, 200);

        $total = count($allSteps);
        $slice = array_slice($allSteps, ($page - 1) * $perPage, $perPage);

        $stepIds = array_map(fn ($step) => $step->id, $slice);
        $actionCounts = empty($stepIds) ? collect() : DB::table('agent_run_actions')
            ->select('step_id', DB::raw('COUNT(*) as cnt'))
            ->whereIn('step_id', $stepIds)
            ->groupBy('step_id')
            ->pluck('cnt', 'step_id');

        $data = array_map(function ($step) use ($actionCounts) {
            return [
                'id' => $step->id,
                'run_id' => $step->run_id,
                'position' => $step->position,
                'end_state' => $step->end_state,
                'end_reason' => $step->end_reason,
                'started_at' => $step->started_at,
                'ended_at' => $step->ended_at,
                'duration_ms' => $step->duration_ms,
                'wait_ms' => $step->wait_ms,
                'attempt_count' => $step->attempt_count,
                'action_count' => (int) ($actionCounts[$step->id] ?? 0),
            ];
        }, $slice);

        return response()->json($this->envelope($data, $total, $page, $perPage));
    }

    /**
     * GET /agent-runs/{runId}/steps/{stepId}/actions — top-level actions
     * under one step, paginated (default 50, cap 100). Never includes
     * `content` (FR-011).
     */
    public function stepActions(Request $request, string $runId, string $stepId): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        [$page, $perPage] = $this->paginationParams($request, 50, 100);

        $result = $this->runTraceQuery->actionSummariesForStep($callerUserId, $stepId, $page, $perPage);
        if ($result === null) {
            return $this->notFoundResponse();
        }

        return response()->json($this->envelope($result['data'], $result['total'], $page, $perPage));
    }

    /**
     * GET /agent-runs/{runId}/actions/{actionId}/children — nested actions
     * under one action, same shape/pagination as stepActions() above.
     */
    public function actionChildren(Request $request, string $runId, string $actionId): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        [$page, $perPage] = $this->paginationParams($request, 50, 100);

        $result = $this->runTraceQuery->actionSummaryChildren($callerUserId, $actionId, $page, $perPage);
        if ($result === null) {
            return $this->notFoundResponse();
        }

        return response()->json($this->envelope($result['data'], $result['total'], $page, $perPage));
    }

    /**
     * GET /agent-runs/{runId}/actions/{actionId} — the single selected
     * action's full detail: every ActionSummary field plus `content` and
     * `content_truncated` (US2, FR-005, FR-006, FR-007). The only endpoint
     * on this controller that returns `content`. `content_truncated` is
     * computed at read time via ContentSanitizer::isTruncated() (T036,
     * research.md D4) against the already sanitized/truncated-at-write-time
     * `content` column — no re-sanitization happens here.
     */
    public function actionDetail(Request $request, string $runId, string $actionId): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $action = $this->runTraceQuery->actionDetailRow($callerUserId, $runId, $actionId);
        if ($action === null) {
            return $this->notFoundResponse();
        }

        $content = $action['content'];

        return response()->json([
            'id' => $action['id'],
            'run_id' => $action['run_id'],
            'step_id' => $action['step_id'],
            'parent_action_id' => $action['parent_action_id'],
            'action_type' => $action['action_type'],
            'target' => $action['target'],
            'outcome' => $action['outcome'],
            'failure_reason' => $action['failure_reason'],
            'started_at' => $action['started_at'],
            'ended_at' => $action['ended_at'],
            'duration_ms' => $action['duration_ms'],
            'has_children' => $action['has_children'],
            'content' => $content,
            'content_truncated' => $content !== null && $this->contentSanitizer->isTruncated($content),
        ]);
    }

    /**
     * GET /agent-runs/{runId}/arrangement — the full shape of the
     * multi-agent collaboration rooted at $runId (106-multi-agent-run-view,
     * US1, contracts/arrangement-api.md §1): the entry-point run, every
     * transitively-reachable delegation, and a RunSummary for every run
     * referenced along the way. Same uniform-404 contract as every other
     * endpoint on this controller for an absent, purged, or foreign-owned
     * run (FR-014, research.md D2).
     */
    public function arrangement(Request $request, string $runId): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $arrangement = $this->delegationQuery->arrangementForRun($callerUserId, $runId);
        if ($arrangement === null) {
            return $this->notFoundResponse();
        }

        return response()->json($arrangement);
    }

    /**
     * Project an AgentRun (+ its cheap action_count aggregate) to the
     * RunSummary wire shape (data-model.md §1.1, extended by
     * 074-latency-metrics data-model.md §5 with eight latency fields).
     * Delegates to RunTraceQuery::runSummaryRow() — the same mapping
     * runSummaryById() uses for the RunUpdated broadcast payload — so this
     * endpoint and that broadcast can never disagree on shape or value.
     */
    private function runSummary(AgentRun $run, int $actionCount): array
    {
        return $this->runTraceQuery->runSummaryRow($run, $actionCount);
    }

    /**
     * Resolve `page`/`per_page` query params against a per-endpoint default
     * and cap (contracts/run-read-api.md). `per_page` below 1 falls back to
     * the default; above the cap is clamped down to it (FR-011/SC-004,
     * enforced server-side regardless of what the client requests).
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
     * Build the paginated envelope every list endpoint on this controller
     * returns (data-model.md §1.5) — `data` + a `meta` block with no other
     * top-level keys.
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
