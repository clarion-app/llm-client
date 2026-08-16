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
     * Runs for a user, paginated, ordered started_at descending (US6,
     * FR-024). `WHERE user_id = ?` only — by construction, this can never
     * return another user's rows (data-model.md §5); there is no separate
     * ownership gate to short-circuit, unlike the single-resource read
     * paths this class also exposes.
     *
     * @return array{data: AgentRun[], total: int}
     */
    public function runsForUserPaginated(string $callerUserId, int $page, int $perPage): array
    {
        $baseQuery = AgentRun::where('user_id', $callerUserId);

        $total = (clone $baseQuery)->count();

        $runs = $baseQuery
            ->orderBy('started_at', 'desc')
            ->forPage($page, $perPage)
            ->get()
            ->all();

        return [
            'data' => $runs,
            'total' => $total,
        ];
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
     * Derive the consulted-source manifest for a run (feature 111, US1/US4).
     *
     * The manifest is the distinct set of URLs the run fetched via the page/text
     * operation, in fetch order (action started_at). Ownership is checked by
     * delegating to actionsForRun(), which compares agent_runs.user_id before
     * returning any rows — a run id alone never grants access to another user's
     * sources (data-model.md §4).
     *
     * @return string[] distinct source URLs, in fetch order
     */
    public function consultedSourcesForRun(string $callerUserId, string $runId): array
    {
        $actions = $this->actionsForRun($callerUserId, $runId);

        $urls = [];
        foreach ($actions as $action) {
            if (($action['type'] ?? null) !== 'tool_invocation') {
                continue;
            }
            if (($action['target'] ?? null) !== 'execute_operation') {
                continue;
            }

            $content = $action['content'] ?? null;
            if ($content === null) {
                continue;
            }

            $decoded = json_decode($content, true);
            $url = $decoded['source']['url'] ?? null;

            if (is_string($url) && $url !== '' && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
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
     * Full detail for a single action the caller owns — the only projection
     * that includes `content` (data-model.md §1.4, FR-006/FR-007). Mirrors
     * actionSummaryChildren()'s action -> run -> user_id ownership check,
     * plus a defense-in-depth check that the action's own run_id matches the
     * `runId` segment of the request path (contracts/run-read-api.md).
     * `content_truncated` is computed by the caller (RunController) via
     * ContentSanitizer::isTruncated() at read time — this method surfaces
     * the raw (already sanitized/truncated-at-write-time) `content` column.
     *
     * @return array{
     *     id: string, run_id: string, step_id: string,
     *     parent_action_id: string|null, action_type: string,
     *     target: string|null, outcome: string, failure_reason: string|null,
     *     started_at: string, ended_at: string|null, duration_ms: int|null,
     *     has_children: bool, content: string|null
     * }|null Null when absent, not owned by the caller, or under a different
     *        run than $runId (FR-014).
     */
    public function actionDetailRow(string $callerUserId, string $runId, string $actionId): ?array
    {
        $row = DB::table('agent_run_actions')
            ->where('id', $actionId)
            ->first();

        if ($row === null || $row->run_id !== $runId) {
            return null;
        }

        $ownerUserId = DB::table('agent_runs')
            ->where('id', $row->run_id)
            ->value('user_id');

        if ($ownerUserId !== $callerUserId) {
            return null;
        }

        $hasChildren = DB::table('agent_run_actions')
            ->where('parent_action_id', $actionId)
            ->exists();

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
            'has_children' => $hasChildren,
            'content' => $row->content,
        ];
    }

    // ========================================================================
    // Phase 6 (US3) — broadcast-payload projections, by id only. No
    // ownership check here: each of RunUpdated/RunStepUpdated/
    // RunActionUpdated's broadcastWith() is only ever invoked for a channel
    // its own broadcastOn() has already resolved to the row's real owner
    // (research.md D1/D3) — these three methods exist purely to guarantee a
    // pushed payload can never disagree in shape or value with what the
    // matching REST endpoint would return for the same id, by sharing the
    // exact same field mapping / row projection those endpoints use.
    // ========================================================================

    /**
     * RunSummary shape (data-model.md §1.1, extended by 074-latency-metrics
     * data-model.md §5 with eight latency fields) for a single run by id —
     * the same field mapping RunController::show()/index() use via
     * runSummaryRow() below, so RunUpdated's broadcast payload never
     * disagrees with a fresh GET /agent-runs/{id}.
     *
     * @return array<string, mixed>|null Null when the run has since been purged.
     */
    public function runSummaryById(string $runId): ?array
    {
        $run = AgentRun::find($runId);
        if ($run === null) {
            return null;
        }

        $actionCount = DB::table('agent_run_actions')
            ->where('run_id', $runId)
            ->count();

        return $this->runSummaryRow($run, (int) $actionCount);
    }

    /**
     * Project an already-loaded AgentRun (+ its action_count aggregate) to
     * the RunSummary wire shape — the single source of truth both
     * RunController (GET /agent-runs, GET /agent-runs/{id}) and
     * runSummaryById() above (the RunUpdated broadcast payload) build their
     * response from, so the two can never drift apart (074-latency-metrics
     * data-model.md §5).
     *
     * @return array<string, mixed>
     */
    public function runSummaryRow(AgentRun $run, int $actionCount): array
    {
        return [
            'id' => $run->id,
            'kind' => $run->kind->value,
            'end_state' => $run->end_state->value,
            'end_reason' => $run->end_reason,
            'started_at' => $run->started_at?->toJSON(),
            'ended_at' => $run->ended_at?->toJSON(),
            'duration_ms' => $run->duration_ms,
            'step_count' => $run->step_count,
            'action_count' => $actionCount,
            'conversation_id' => $run->conversation_id,
            'is_streamed' => $run->is_streamed,
            'first_output_ms' => $run->first_output_ms,
            'model' => $run->model,
            'agent_id' => $run->agent_id,
            'model_wait_ms' => $run->model_wait_ms,
            'tool_exec_ms' => $run->tool_exec_ms,
            'confirm_wait_ms' => $run->confirm_wait_ms,
            'product_ms' => $run->product_ms,
        ];
    }

    /**
     * StepSummary shape (data-model.md §1.2) for a single step by id — the
     * same field mapping RunController::steps() projects per row, so
     * RunStepUpdated's broadcast payload never disagrees with a fresh
     * GET /agent-runs/{runId}/steps entry for the same step.
     *
     * @return array<string, mixed>|null Null when the step has since been purged.
     */
    public function stepSummaryById(string $stepId): ?array
    {
        $step = AgentRunStep::find($stepId);
        if ($step === null) {
            return null;
        }

        $actionCount = DB::table('agent_run_actions')
            ->where('step_id', $stepId)
            ->count();

        return [
            'id' => $step->id,
            'run_id' => $step->run_id,
            'position' => $step->position,
            'end_state' => $step->end_state->value,
            'end_reason' => $step->end_reason,
            'started_at' => $step->started_at?->toJSON(),
            'ended_at' => $step->ended_at?->toJSON(),
            'duration_ms' => $step->duration_ms,
            'wait_ms' => $step->wait_ms,
            'attempt_count' => $step->attempt_count,
            'action_count' => (int) $actionCount,
        ];
    }

    /**
     * ActionSummary shape (data-model.md §1.3) for a single action by id —
     * never `content`. Reuses actionSummaryRows()'s exact projection (the
     * same one actionSummariesForStep()/actionSummaryChildren() use), so
     * RunActionUpdated's broadcast payload is byte-identical to the matching
     * row in a fresh GET .../actions or .../children response.
     *
     * @return array<string, mixed>|null Null when the action has since been purged.
     */
    public function actionSummaryById(string $actionId): ?array
    {
        $row = DB::table('agent_run_actions')->where('id', $actionId)->first();
        if ($row === null) {
            return null;
        }

        return $this->actionSummaryRows(collect([$row]))[0] ?? null;
    }

    /**
     * Convert a raw DB row to the standardized action array shape.
     */
    private function actionRowToArray($row): array
    {
        // Defensive: the agent_run_actions schema (src/Migrations) has
        // action_type / failure_reason and no cost columns, so map the real
        // columns and null-coalesce anything a raw row may not carry.
        return [
            'id' => $row->id ?? null,
            'run_id' => $row->run_id ?? null,
            'step_id' => $row->step_id ?? null,
            'parent_action_id' => $row->parent_action_id ?? null,
            'type' => $row->action_type ?? $row->type ?? '',
            'outcome' => $row->outcome ?? '',
            'target' => $row->target ?? null,
            'content' => $row->content ?? null,
            'reason' => $row->failure_reason ?? $row->reason ?? null,
            'started_at' => $row->started_at ?? '',
            'ended_at' => $row->ended_at ?? null,
            'duration_ms' => $row->duration_ms ?? null,
            'token_cost' => $row->token_cost ?? null,
            'wait_ms' => $row->wait_ms ?? null,
            'cost_cents' => $row->cost_cents ?? null,
            'currency' => $row->currency ?? null,
            'pending_confirmation' => $row->pending_confirmation ?? false,
        ];
    }
}
