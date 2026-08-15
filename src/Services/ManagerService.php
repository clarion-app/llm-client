<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Events\ManagedTaskUpdated;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\Services\Concerns\RetriesConcurrencyAborts;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Support\Facades\Log;

/**
 * 103-manager-agent (data-model.md §5, research.md D1-D10). The single
 * write path for ManagedTask/ManagedTaskPart -- mirrors DelegationService's
 * own role as the sole owner of its table(s).
 *
 * Phase 3 (US1) implements createManagedTask()/planParts()/assignPart()/
 * assignParts() -- the decomposition and suitability-based assignment
 * half of the feature. acceptPart()/reportShortfall()/finalize()/
 * finalizeWithShortfall() are added by later phases (US2/US3/US4/US5).
 *
 * assignPart()'s transactional guard (research.md D4) ships complete in
 * this phase, not split across the phases that later add dedicated proof
 * for each half (tasks.md "Ordering rationale") -- both the state check
 * (FR-014: accepted/reported_as_shortfall refused as already-finalized,
 * out_for_assignment/out_for_correction refused as already-outstanding)
 * and the round-ceiling check (FR-009/FR-017, evaluated against
 * ManagedTask.rounds_used, never a part's own assignment_count) are
 * enforced inside one locked transaction, admitAssignmentRound(), before
 * the nested DelegationService::delegate()/delegateBatch() call is ever
 * made outside that transaction.
 */
class ManagerService
{
    use RetriesConcurrencyAborts;

    public function __construct(
        private readonly AgentQuery $agentQuery,
    ) {}

    /**
     * FR-001/FR-002, US1. Creates the manager's own dedicated Conversation
     * (channel = 'managed-task', one per managed task, research.md D1) and
     * the ManagedTask row (status = 'in_progress', round_ceiling/
     * max_seconds snapshotted from config at creation time -- research.md
     * D5 -- so a later config change never retroactively changes an
     * in-flight task's own bound).
     */
    public function createManagedTask(string $ownerUserId, string $managerAgentId, string $request): ManagedTask
    {
        $managerAgent = $this->agentQuery->findAgent($ownerUserId, $managerAgentId);
        if ($managerAgent === null) {
            throw new \RuntimeException('Manager agent not found or not owned by the caller.');
        }

        // Same RoleResolver/server-model resolution recipe
        // DelegationService::createDelegationRow() already uses for its own
        // ephemeral helper conversation (Grounding note item 1).
        $resolution = app(RoleResolver::class)->resolve(ModelRole::Inference, $ownerUserId);
        $serverId = $resolution->hasEffectiveModel() ? $resolution->server->id : null;
        $modelName = $resolution->hasEffectiveModel() ? $resolution->model : null;

        $conversation = Conversation::create([
            'user_id' => $ownerUserId,
            'server_id' => $serverId,
            'model' => $modelName,
            'character' => 'Clarion',
            'channel' => 'managed-task',
            'agent_id' => $managerAgent->id,
            'agent_version_id' => $managerAgent->current_version_id,
        ]);

        $task = ManagedTask::create([
            'conversation_id' => $conversation->id,
            'owner_user_id' => $ownerUserId,
            'manager_agent_id' => $managerAgent->id,
            'original_request' => $request,
            'status' => 'in_progress',
            'round_ceiling' => (int) config('llm-client.manager.max_rounds', 30),
            'rounds_used' => 0,
            'max_seconds' => (int) config('llm-client.manager.max_seconds', 1800),
            'last_progress_at' => now(),
            'started_at' => now(),
        ]);

        $this->broadcast(fn () => event(new ManagedTaskUpdated($task->id)));

        return $task;
    }

    /**
     * Additive, append-only across a task's lifetime (data-model.md §4) --
     * new parts always get the next sequence, existing parts are never
     * deleted or renumbered. Callable more than once (a manager may
     * discover mid-task that further decomposition is needed).
     *
     * @param string[] $descriptions
     * @return ManagedTaskPart[]
     */
    public function planParts(ManagedTask $task, array $descriptions): array
    {
        $nextSequence = (int) (ManagedTaskPart::where('managed_task_id', $task->id)->max('sequence') ?? 0) + 1;

        $parts = [];
        foreach ($descriptions as $description) {
            $parts[] = ManagedTaskPart::create([
                'managed_task_id' => $task->id,
                'sequence' => $nextSequence,
                'description' => $description,
                'state' => 'not_yet_assigned',
                'assignment_count' => 0,
            ]);
            $nextSequence++;
        }

        $task->last_progress_at = now();
        $task->save();

        $this->broadcast(fn () => event(new ManagedTaskUpdated($task->id)));

        return $parts;
    }

    /**
     * research.md D2/D4, contracts/manager-agent-meta-tools.md §2. The
     * solo assign_part path: admit the round transactionally
     * (admitAssignmentRound()), then -- outside that transaction -- hand
     * off to the SAME DelegationService::delegate() call delegate_to_helper
     * already uses (research.md D2's own "no parallel row-creation path"),
     * stamping this part's managed_task_id/part_id onto the resulting
     * Delegation row.
     *
     * @return array<string, mixed> JSON-encodable -- either a refusal
     *   ({"error": ..., "message": ...}) from the guard or from
     *   DelegationService::delegate() itself, or the completed-delegation
     *   six-field result shape (contracts §2).
     */
    public function assignPart(ManagedTask $task, ManagedTaskPart $part, string $helperAgentId, string $taskText, ?string $context): array
    {
        $refusal = $this->admitAssignmentRound($task, $part);
        if ($refusal !== null) {
            return $refusal;
        }

        $conversation = Conversation::find($task->conversation_id);

        $result = app(DelegationService::class)->delegate($conversation, $helperAgentId, $taskText, $context, $task->id, $part->id);

        if (isset($result['delegation_id'])) {
            $part->current_delegation_id = $result['delegation_id'];
            $part->save();
        }

        return $result;
    }

    /**
     * research.md D2/D4, data-model.md §5. Mirrors
     * DelegationService::delegateBatch()'s own "validate all up front,
     * batch id shared, dispatch after" shape for several assign_part calls
     * landing in one manager turn: every call's admission is decided (and,
     * for an admitted call, written) before any of them are dispatched, so
     * the round ceiling is always evaluated against the batch's true
     * arrival order rather than racing itself.
     *
     * @param array<int, array{tool_call_id: string, part_id: string, helper_agent_id: string, task: string, context: ?string}> $calls
     * @return array<string, array<string, mixed>> keyed by, and in, the
     *   original $calls order.
     */
    public function assignParts(ManagedTask $task, array $calls): array
    {
        $results = [];
        $validCalls = [];

        foreach ($calls as $call) {
            $toolCallId = $call['tool_call_id'];

            $part = ManagedTaskPart::where('managed_task_id', $task->id)
                ->where('id', $call['part_id'] ?? null)
                ->first();

            if ($part === null) {
                $results[$toolCallId] = ['error' => 'unknown_part', 'message' => 'The named part could not be found for this managed task.'];
                continue;
            }

            $refusal = $this->admitAssignmentRound($task, $part);
            if ($refusal !== null) {
                $results[$toolCallId] = $refusal;
                continue;
            }

            $validCalls[$toolCallId] = [
                'tool_call_id' => $toolCallId,
                'helper_agent_id' => $call['helper_agent_id'],
                'task' => $call['task'],
                'context' => $call['context'] ?? null,
                'managed_task_id' => $task->id,
                'part_id' => $part->id,
            ];
        }

        if (!empty($validCalls)) {
            $conversation = Conversation::find($task->conversation_id);
            $batchResults = app(DelegationService::class)->delegateBatch($conversation, array_values($validCalls));

            foreach ($batchResults as $toolCallId => $result) {
                $results[$toolCallId] = $result;

                if (isset($result['delegation_id'], $validCalls[$toolCallId])) {
                    ManagedTaskPart::where('id', $validCalls[$toolCallId]['part_id'])
                        ->update(['current_delegation_id' => $result['delegation_id']]);
                }
            }
        }

        $ordered = [];
        foreach ($calls as $call) {
            $ordered[$call['tool_call_id']] = $results[$call['tool_call_id']];
        }

        return $ordered;
    }

    /**
     * research.md D4's transactional guard -- the ONE admission decision
     * point every assign_part call (solo or batched) goes through. Locks
     * both the part and the task row before deciding, so two concurrent
     * callers racing the same part or the same task's own ceiling can
     * never both be admitted (mirrors DelegationConcurrencyGate::
     * tryAdmit()'s exact shape, Grounding note item 10).
     *
     * On success: increments ManagedTask.rounds_used by exactly 1
     * (evaluated -- and incremented -- against the WHOLE TASK's counter,
     * never a part's own assignment_count, tasks.md T016/mutation-checklist
     * row 2), sets the part's state to out_for_assignment (never before
     * assigned) or out_for_correction (previously assigned), increments
     * the part's own assignment_count, and updates
     * ManagedTask.last_progress_at -- all inside one transaction, and all
     * visible before returning, i.e. before any caller ever makes the
     * nested DelegationService::delegate()/delegateBatch() call.
     *
     * @return array{error: string, message: string}|null Null means
     *   admitted -- $task/$part are refreshed in place to reflect the
     *   just-written state.
     */
    public function admitAssignmentRound(ManagedTask $task, ManagedTaskPart $part): ?array
    {
        $refusal = null;

        $this->transactionWithConcurrencyRetries(function () use ($task, $part, &$refusal) {
            $lockedPart = ManagedTaskPart::where('id', $part->id)->lockForUpdate()->first();
            $lockedTask = ManagedTask::where('id', $task->id)->lockForUpdate()->first();

            if ($lockedPart === null || $lockedTask === null) {
                $refusal = ['error' => 'unknown_part', 'message' => 'The named part or managed task could not be found.'];

                return;
            }

            if (in_array($lockedPart->state, ['accepted', 'reported_as_shortfall'], true)) {
                $refusal = ['error' => 'part_already_finalized', 'message' => 'This part has already been finalized and cannot be assigned again.'];

                return;
            }

            // FR-014: "outstanding" means genuinely unresolved -- the
            // part's own current delegation has not yet reached a terminal
            // status. The solo assignPart() path resolves its delegation
            // SYNCHRONOUSLY (DelegationService::delegate() does not return
            // until the nested run() call finishes), so by the time a
            // LATER assign_part() call on the SAME part_id is made -- a
            // correction (US2) or reassignment (US5), research.md D2 --
            // the prior delegation has already resolved and this check
            // must let the new round through even though the part's own
            // bookkeeping state is still out_for_assignment/
            // out_for_correction (nothing else moves it, since accept_part/
            // report_shortfall are the ONLY transitions to a terminal
            // state, and neither has fired yet). What this guard actually
            // protects against is a genuinely CONCURRENT second
            // assign_part() call on the same part -- e.g. two assign_part
            // entries in one batched turn both naming the same part_id
            // (quickstart scenario 6, US6) -- caught here because the
            // first admitted call's own Delegation row is still 'queued'/
            // 'in_progress' at the moment the second one takes this same
            // lock.
            if (in_array($lockedPart->state, ['out_for_assignment', 'out_for_correction'], true)) {
                $currentDelegation = $lockedPart->current_delegation_id !== null
                    ? Delegation::find($lockedPart->current_delegation_id)
                    : null;

                $stillOutstanding = $currentDelegation === null
                    || in_array($currentDelegation->status, ['queued', 'in_progress'], true);

                if ($stillOutstanding) {
                    $refusal = ['error' => 'assignment_already_outstanding', 'message' => 'This part already has an assignment outstanding.'];

                    return;
                }
            }

            if ($lockedTask->rounds_used >= $lockedTask->round_ceiling) {
                $refusal = ['error' => 'round_ceiling_reached', 'message' => 'This managed task has reached its round ceiling.'];

                return;
            }

            $wasNeverAssigned = $lockedPart->state === 'not_yet_assigned';

            $lockedTask->rounds_used += 1;
            $lockedTask->last_progress_at = now();
            $lockedTask->save();

            $lockedPart->state = $wasNeverAssigned ? 'out_for_assignment' : 'out_for_correction';
            $lockedPart->assignment_count += 1;
            $lockedPart->save();
        });

        $task->refresh();
        $part->refresh();

        if ($refusal !== null) {
            return $refusal;
        }

        $this->broadcast(fn () => event(new ManagedTaskUpdated($task->id)));

        return null;
    }

    /**
     * RunTraceRecorder::broadcast()'s exact three-line shape (Grounding
     * note item 9) -- a private copy on ManagerService itself (not a
     * shared trait), so a broadcast failure can never turn an
     * already-successful write's return value into null.
     */
    private function broadcast(\Closure $emit): void
    {
        try {
            $emit();
        } catch (\Throwable $e) {
            Log::warning('ManagerService: broadcast failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
