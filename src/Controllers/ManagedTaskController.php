<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Services\ManagedTaskQuery;
use ClarionApp\LlmClient\Services\ManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 103-manager-agent (US1, contracts/manager-agent-api.md §1/§2).
 *
 * Read/write-thin, mirroring DelegationController's/RunController's own
 * precedent: store() is the ONLY endpoint that ever creates a ManagedTask,
 * and is deliberately thin -- it does not decompose the task itself (the
 * manager's own first turn does, via plan_parts) and does not run any part
 * of the task before returning (research.md D6, contracts §1 -- "202, not
 * 201 or 200"). show() is owner-scoped via ManagedTaskQuery, matching
 * every other read endpoint in this package's uniform-404 convention.
 */
class ManagedTaskController extends Controller
{
    public function __construct(
        private readonly ManagedTaskQuery $managedTaskQuery,
        private readonly ManagerService $managerService,
    ) {}

    /**
     * POST /managed-tasks -- body {agent_id, request} (contracts §1).
     */
    public function store(Request $request): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $agentId = $request->input('agent_id');
        $taskRequest = $request->input('request');

        $agent = $agentId !== null
            ? Agent::where('id', $agentId)->where('user_id', $callerUserId)->first()
            : null;

        if ($agent === null) {
            return $this->notFoundResponse('Agent not found', 'agent_not_found');
        }

        $hasActiveHelper = AgentHelperAssignment::where('parent_agent_id', $agent->id)
            ->whereNull('deleted_at')
            ->exists();

        if (!$hasActiveHelper) {
            return response()->json([
                'error' => 'no_assigned_helpers',
                'message' => 'This agent has no active assigned helpers, so it cannot usefully manage a task.',
            ], 422);
        }

        if (empty(trim((string) $taskRequest))) {
            return response()->json([
                'error' => 'empty_request',
                'message' => 'request must be a non-empty description of the task.',
            ], 422);
        }

        $task = $this->managerService->createManagedTask($callerUserId, $agent->id, $taskRequest);

        RunManagedTaskStepJob::dispatch($task->id)->onQueue(config('llm-client.manager.queue', 'managed-tasks'));

        return response()->json([
            'managed_task_id' => $task->id,
            'conversation_id' => $task->conversation_id,
            'status' => $task->status,
        ], 202);
    }

    /**
     * GET /managed-tasks/{id} -- task status and outcome (contracts §2).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $task = $this->managedTaskQuery->findManagedTask($callerUserId, $id);
        if ($task === null) {
            return $this->notFoundResponse('Managed task not found', 'managed_task_not_found');
        }

        return response()->json([
            'managed_task_id' => $task->id,
            'status' => $task->status,
            'rounds_used' => $task->rounds_used,
            'round_ceiling' => $task->round_ceiling,
            'final_response' => $task->final_response,
            'shortfall_note' => $task->shortfall_note,
            'conflict_note' => $task->conflict_note,
            'started_at' => $task->started_at?->toJSON(),
            'completed_at' => $task->completed_at?->toJSON(),
        ]);
    }

    /**
     * GET /managed-tasks/{id}/parts -- the breakdown (contracts §3,
     * FR-008/US3 AC2). ManagedTaskQuery::partsForTask() itself
     * ownership-checks via findManagedTask() first, so a task not owned
     * by the caller and an unknown task id both collapse to the same
     * uniform 404 as show()'s own.
     */
    public function parts(Request $request, string $id): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $parts = $this->managedTaskQuery->partsForTask($callerUserId, $id);
        if ($parts === null) {
            return $this->notFoundResponse('Managed task not found', 'managed_task_not_found');
        }

        return response()->json($parts);
    }

    /**
     * GET /managed-tasks/{id}/cost -- tree-wide cost attribution (contracts
     * §4, US7, SC-010/SC-011). ManagedTaskQuery::costForTask() itself
     * ownership-checks via findManagedTask() first, so a task not owned
     * by the caller and an unknown task id both collapse to the same
     * uniform 404 as show()'s/parts()'s own. Available while the task is
     * still in progress -- the same query either way.
     */
    public function cost(Request $request, string $id): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $cost = $this->managedTaskQuery->costForTask($callerUserId, $id);
        if ($cost === null) {
            return $this->notFoundResponse('Managed task not found', 'managed_task_not_found');
        }

        return response()->json($cost);
    }

    /**
     * The uniform "not found" body shape (matches DelegationController's
     * own precedent) -- every controller in this package declares its own
     * private copy, there is no shared base-class helper (Grounding note
     * item 13).
     */
    private function notFoundResponse(string $error, string $code): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'code' => $code,
        ], 404);
    }
}
