<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\AgentRunStep;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\ValueObjects\RunRelation;
use Illuminate\Support\Facades\DB;

class RunTraceQuery
{
    /**
     * Find a run by id, filtered by caller's user ownership.
     *
     * @return AgentRun|null Null when absent, purged, or owned by another user.
     */
    public function findRun(string $callerUserId, string $runId): ?AgentRun
    {
        return AgentRun::where('id', $runId)
            ->where('user_id', $callerUserId)
            ->first();
    }

    /**
     * Ordered steps for a run the caller owns.
     * Returns null if the run doesn't exist or isn't owned by the caller.
     * Returns empty array for a zero-step run (FR-025).
     *
     * @return AgentRunStep[]|null Ordered by position, null if run not accessible.
     */
    public function stepsForRun(string $callerUserId, string $runId): ?array
    {
        $run = $this->findRun($callerUserId, $runId);
        if ($run === null) {
            return null;
        }

        return AgentRunStep::where('run_id', $runId)
            ->orderBy('position', 'asc')
            ->get()
            ->all();
    }

    /**
     * Runs for a conversation, ordered by started_at ascending (FR-022).
     * Includes runs that produced no reply message.
     *
     * @return AgentRun[]
     */
    public function runsForConversation(
        string $callerUserId,
        string $conversationId,
        int $limit = 100,
    ): array {
        return AgentRun::where('user_id', $callerUserId)
            ->where('conversation_id', $conversationId)
            ->orderBy('started_at', 'asc')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Runs for a user, including system-initiated runs with no conversation (FR-023).
     *
     * @return AgentRun[]
     */
    public function runsForUser(
        string $callerUserId,
        int $limit = 100,
    ): array {
        return AgentRun::where('user_id', $callerUserId)
            ->orderBy('started_at', 'desc')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Resolve a message to its run — the reply the run produced, or the user message
     * that triggered it. Null when the message predates the feature, was never
     * associated, its run was purged, or the caller does not own it.
     *
     * @return AgentRun|null
     */
    public function findRunForMessage(
        string $callerUserId,
        string $messageId,
        ?RunRelation $relation = null,
    ): ?AgentRun {
        $query = DB::table('agent_run_messages')
            ->join('agent_runs', 'agent_run_messages.run_id', '=', 'agent_runs.id')
            ->where('agent_run_messages.message_id', $messageId)
            ->where('agent_runs.user_id', $callerUserId);

        if ($relation !== null) {
            $query->where('agent_run_messages.relation', $relation->value);
        }

        $row = $query->select('agent_runs.id as run_id')->first();

        if ($row === null) {
            return null;
        }

        return $this->findRun($callerUserId, (string) $row->run_id);
    }

    /**
     * Every message written during a run the caller owns, oldest first.
     *
     * @return Message[]|null Null when the run is absent, purged, or not owned by the caller (FR-018, FR-020).
     */
    public function messagesForRun(string $callerUserId, string $runId): ?array
    {
        $run = $this->findRun($callerUserId, $runId);
        if ($run === null) {
            return null;
        }

        return Message::where('run_id', $runId)
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    /**
     * Every tool-execution record produced during a run the caller owns, oldest first.
     *
     * @return ToolInvocationRecord[]|null Same ownership contract as messagesForRun().
     */
    public function toolInvocationsForRun(string $callerUserId, string $runId): ?array
    {
        $run = $this->findRun($callerUserId, $runId);
        if ($run === null) {
            return null;
        }

        return ToolInvocationRecord::where('run_id', $runId)
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    /**
     * Every usage record produced during a run the caller owns, oldest first.
     *
     * @return UsageRecord[]|null Same ownership contract as messagesForRun().
     */
    public function usageRecordsForRun(string $callerUserId, string $runId): ?array
    {
        $run = $this->findRun($callerUserId, $runId);
        if ($run === null) {
            return null;
        }

        return UsageRecord::where('run_id', $runId)
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    // ========================================================================
    // Actions
    // ========================================================================

    /**
     * Get all actions for a step (user ownership filter).
     *
     * @return array<int, array{
     *     id: string,
     *     run_id: string,
     *     step_id: string,
     *     parent_action_id: string|null,
     *     type: string,
     *     outcome: string,
     *     target: string|null,
     *     content: string|null,
     *     reason: string|null,
     *     started_at: string,
     *     ended_at: string|null,
     *     duration_ms: int|null,
     *     token_cost: float|null,
     *     wait_ms: int|null,
     *     cost_cents: int|null,
     *     currency: string|null,
     *     pending_confirmation: bool
     * }>
     */
    public function actionsForStep(string $callerUserId, string $stepId): array
    {
        // Verify step belongs to a run owned by the calling user.
        $runId = DB::table('agent_run_steps')
            ->where('id', $stepId)
            ->value('run_id');

        if ($runId === null) {
            return [];
        }

        $ownerUserId = DB::table('agent_runs')
            ->where('id', $runId)
            ->value('user_id');

        if ($ownerUserId !== $callerUserId) {
            return [];
        }

        return DB::table('agent_run_actions')
            ->where('step_id', $stepId)
            ->orderBy('started_at')
            ->get()
            ->map(function ($row) {
                return $this->actionRowToArray($row);
            })
            ->all();
    }

    /**
     * Get all actions for a run (user ownership filter).
     *
     * @return array<int, array{
     *     id: string,
     *     run_id: string,
     *     step_id: string,
     *     parent_action_id: string|null,
     *     type: string,
     *     outcome: string,
     *     target: string|null,
     *     content: string|null,
     *     reason: string|null,
     *     started_at: string,
     *     ended_at: string|null,
     *     duration_ms: int|null,
     *     token_cost: float|null,
     *     wait_ms: int|null,
     *     cost_cents: int|null,
     *     currency: string|null,
     *     pending_confirmation: bool
     * }>
     */
    public function actionsForRun(string $callerUserId, string $runId): array
    {
        $ownerUserId = DB::table('agent_runs')
            ->where('id', $runId)
            ->value('user_id');

        if ($ownerUserId !== $callerUserId) {
            return [];
        }

        return DB::table('agent_run_actions')
            ->where('run_id', $runId)
            ->orderBy('started_at')
            ->get()
            ->map(function ($row) {
                return $this->actionRowToArray($row);
            })
            ->all();
    }

    /**
     * Get child actions of a given action (user ownership filter via run JOIN).
     *
     * @return array<int, array{
     *     id: string,
     *     run_id: string,
     *     step_id: string,
     *     parent_action_id: string|null,
     *     type: string,
     *     outcome: string,
     *     target: string|null,
     *     content: string|null,
     *     reason: string|null,
     *     started_at: string,
     *     ended_at: string|null,
     *     duration_ms: int|null,
     *     token_cost: float|null,
     *     wait_ms: int|null,
     *     cost_cents: int|null,
     *     currency: string|null,
     *     pending_confirmation: bool
     * }>
     */
    public function childActions(string $callerUserId, string $actionId): array
    {
        // Verify the parent action belongs to a run owned by the calling user.
        $runId = DB::table('agent_run_actions')
            ->where('id', $actionId)
            ->value('run_id');

        if ($runId === null) {
            return [];
        }

        $ownerUserId = DB::table('agent_runs')
            ->where('id', $runId)
            ->value('user_id');

        if ($ownerUserId !== $callerUserId) {
            return [];
        }

        return DB::table('agent_run_actions')
            ->where('parent_action_id', $actionId)
            ->orderBy('started_at')
            ->get()
            ->map(function ($row) {
                return $this->actionRowToArray($row);
            })
            ->all();
    }

    /**
     * Lightweight (no-content) action projection for a step, paginated,
     * top-level actions only (`parent_action_id IS NULL`) — the lazy-expand
     * call for a step node (data-model.md §1.3, §2).
     *
     * Mirrors actionsForStep()'s existing step -> run -> user_id ownership
     * check, but — unlike actionsForStep(), which returns an empty array for
     * both "step inaccessible" and "step accessible, zero actions" — returns
     * null specifically for the inaccessible case, so
     * RunController::stepActions() can tell the two apart (FR-018's
     * zero-step/zero-action 200-empty vs FR-014's uniform 404).
     *
     * @return array{data: array<int, array<string, mixed>>, total: int}|null
     */
    public function actionSummariesForStep(string $callerUserId, string $stepId, int $page, int $perPage): ?array
    {
        $runId = DB::table('agent_run_steps')
            ->where('id', $stepId)
            ->value('run_id');

        if ($runId === null) {
            return null;
        }

        $ownerUserId = DB::table('agent_runs')
            ->where('id', $runId)
            ->value('user_id');

        if ($ownerUserId !== $callerUserId) {
            return null;
        }

        $baseQuery = DB::table('agent_run_actions')
            ->where('step_id', $stepId)
            ->whereNull('parent_action_id');

        $total = (clone $baseQuery)->count();

        $rows = $baseQuery
            ->orderBy('started_at')
            ->forPage($page, $perPage)
            ->get();

        return [
            'data' => $this->actionSummaryRows($rows),
            'total' => $total,
        ];
    }

    /**
     * Lightweight (no-content) action projection for an action's direct
     * children, paginated — the lazy-expand call for an action node nested
     * under another action (data-model.md §1.3, §2).
     *
     * Mirrors childActions()'s existing ownership check; same null-for-
     * inaccessible / array-for-accessible contract as
     * actionSummariesForStep() above.
     *
     * @return array{data: array<int, array<string, mixed>>, total: int}|null
     */
    public function actionSummaryChildren(string $callerUserId, string $actionId, int $page, int $perPage): ?array
    {
        // Verify the parent action belongs to a run owned by the calling user.
        $runId = DB::table('agent_run_actions')
            ->where('id', $actionId)
            ->value('run_id');

        if ($runId === null) {
            return null;
        }

        $ownerUserId = DB::table('agent_runs')
            ->where('id', $runId)
            ->value('user_id');

        if ($ownerUserId !== $callerUserId) {
            return null;
        }

        $baseQuery = DB::table('agent_run_actions')
            ->where('parent_action_id', $actionId);

        $total = (clone $baseQuery)->count();

        $rows = $baseQuery
            ->orderBy('started_at')
            ->forPage($page, $perPage)
            ->get();

        return [
            'data' => $this->actionSummaryRows($rows),
            'total' => $total,
        ];
    }

    /**
     * Project a batch of raw agent_run_actions rows to the no-content
     * ActionSummary shape (data-model.md §1.3), computing `has_children` for
     * the whole batch in one query rather than N+1.
     *
     * @param \Illuminate\Support\Collection $rows
     * @return array<int, array<string, mixed>>
     */
    private function actionSummaryRows($rows): array
    {
        $ids = $rows->pluck('id')->all();

        $idsWithChildren = empty($ids) ? [] : DB::table('agent_run_actions')
            ->whereIn('parent_action_id', $ids)
            ->distinct()
            ->pluck('parent_action_id')
            ->all();

        return $rows->map(function ($row) use ($idsWithChildren) {
            return [
                'id' => $row->id,
                'run_id' => $row->run_id,
                'step_id' => $row->step_id,
                'parent_action_id' => $row->parent_action_id,
                'action_type' => $row->action_type,
                'target' => $row->target,
                'outcome' => $row->outcome,
                'failure_reason' => $row->failure_reason,
                'started_at' => $row->started_at,
                'ended_at' => $row->ended_at,
                'duration_ms' => $row->duration_ms,
                'has_children' => in_array($row->id, $idsWithChildren, true),
            ];
        })->all();
    }

    /**
     * Convert a raw DB row to the standardized action array shape.
     */
    private function actionRowToArray($row): array
    {
        return [
            'id' => $row->id ?? null,
            'run_id' => $row->run_id ?? null,
            'step_id' => $row->step_id ?? null,
            'parent_action_id' => $row->parent_action_id,
            'type' => $row->type ?? '',
            'outcome' => $row->outcome ?? '',
            'target' => $row->target,
            'content' => $row->content,
            'reason' => $row->reason,
            'started_at' => $row->started_at ?? '',
            'ended_at' => $row->ended_at,
            'duration_ms' => $row->duration_ms,
            'token_cost' => $row->token_cost,
            'wait_ms' => $row->wait_ms,
            'cost_cents' => $row->cost_cents,
            'currency' => $row->currency,
            'pending_confirmation' => $row->pending_confirmation ?? false,
        ];
    }
}
