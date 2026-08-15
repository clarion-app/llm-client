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
 * Phase 3 (US1) implements createManagedTask()/planParts()/assignPart() --
 * the decomposition and suitability-based assignment half of the feature.
 * Phase 4 (US2) adds acceptPart()/acceptPartRefusal() -- judging a part's
 * outstanding result. reportShortfall()/finalize()/finalizeWithShortfall()
 * are added by later phases (US3/US4/US5).
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
 *
 * Batched calls (two or more assign_part/delegate_to_helper tool calls in
 * one manager turn) do NOT go through a batch entry point on this class --
 * admitAssignmentRound() is the sole, public admission decision point, and
 * AgentLoopService::resolveDelegateToHelperBatchResults() (research.md D2)
 * calls it once per assign_part call directly, merging admitted calls with
 * any delegate_to_helper calls in the same turn before making exactly one
 * DelegationService::delegateBatch() call for the combined, ordered set --
 * required because a mixed batch cannot be split into two delegateBatch()
 * calls without breaking 101's own single-batch concurrency ceiling. An
 * earlier, assign_part-only assignParts() convenience method existed here
 * but was never wired to that call site and could not itself merge with
 * delegate_to_helper calls, so it was removed rather than left as dead,
 * untested code that data-model.md §5 inaccurately described as the actual
 * batched-call path.
 */
class ManagerService
{
    use RetriesConcurrencyAborts;

    public function __construct(
        private readonly AgentQuery $agentQuery,
        private readonly ResultAggregationService $resultAggregationService,
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
     * research.md D3, contracts/manager-agent-meta-tools.md §3. The ONE
     * decision point for whether accept_part may proceed -- exposed
     * publicly (mirrors admitAssignmentRound()'s own "one shared guard"
     * shape) so AgentLoopService::handleAcceptPart() can surface the
     * SAME structured refusal reason contracts §3 specifies without
     * duplicating this method's own logic; acceptPart() itself calls
     * this first and simply writes nothing when it returns non-null.
     *
     * `no_outstanding_result`: the part's own state is not
     * out_for_assignment/out_for_correction -- including a part that is
     * already accepted/reported_as_shortfall, re-exercising
     * admitAssignmentRound()'s own "already finalized" guard so the two
     * methods agree on what that means (tasks.md T030's own terminal
     * check).
     *
     * `cannot_accept_failed_result`: a structural backstop (FR-013)
     * against a model that miscalls accept_part on a delegation whose
     * own result_status already says failure -- reassignment
     * (assign_part again) or, later, report_shortfall are the only
     * correct responses to a failure.
     *
     * @return array{error: string, message: string}|null Null means the
     *   part's outstanding delegation may be accepted.
     */
    public function acceptPartRefusal(ManagedTaskPart $part): ?array
    {
        if (!in_array($part->state, ['out_for_assignment', 'out_for_correction'], true)) {
            return ['error' => 'no_outstanding_result', 'message' => 'This part has no outstanding result to judge.'];
        }

        $delegation = $part->current_delegation_id !== null
            ? Delegation::find($part->current_delegation_id)
            : null;

        if ($delegation === null || $delegation->result_status === 'failure') {
            return ['error' => 'cannot_accept_failed_result', 'message' => 'This result reports failure — accept only a success or an adequate partial result, or use assign_part/report_shortfall instead.'];
        }

        return null;
    }

    /**
     * research.md D3, contracts/manager-agent-meta-tools.md §3, tasks.md
     * T032. On success (acceptPartRefusal() returns null): state =
     * 'accepted', accepted_delegation_id/accepted_summary stamped from
     * the outstanding delegation's own result_status ('success'/
     * 'partial')/result_summary. Terminal -- a later assignPart() on
     * this part_id is refused by admitAssignmentRound()'s own "already
     * finalized" check.
     */
    public function acceptPart(ManagedTask $task, ManagedTaskPart $part): void
    {
        if ($this->acceptPartRefusal($part) !== null) {
            return;
        }

        $delegation = Delegation::find($part->current_delegation_id);

        $part->state = 'accepted';
        $part->accepted_delegation_id = $delegation->id;
        $part->accepted_summary = $delegation->result_summary;
        $part->save();

        $task->last_progress_at = now();
        $task->save();

        $this->broadcast(fn () => event(new ManagedTaskUpdated($task->id)));
    }

    /**
     * 103-manager-agent (US3, contracts/manager-agent-meta-tools.md §5,
     * tasks.md T036/T040). The ONE decision point for whether
     * finalize_task may proceed -- exposed publicly (mirrors
     * acceptPartRefusal()'s own "one shared guard" shape) so
     * AgentLoopService::handleFinalizeTask() can surface the SAME
     * structured refusal reason contracts §5 specifies before ever
     * calling the void finalize() write.
     *
     * `parts_outstanding`: any part is still not_yet_assigned/
     * out_for_assignment/out_for_correction -- UNLESS the round ceiling
     * has already been reached (ManagedTask.rounds_used >= round_ceiling,
     * the numeric check only, T042), in which case this refusal is
     * bypassed and finalize_task is admitted despite the outstanding
     * part (the system-forced finalizeWithShortfall() path itself is
     * Phase 6/US4's own addition -- this bypass only stops the guard
     * from blocking the MODEL's own finalize_task call once the ceiling
     * condition is already true).
     *
     * `shortfall_note_required`: any part is reported_as_shortfall and
     * no $shortfallNote was given (FR-010).
     *
     * @return array{error: string, message: string}|null Null means
     *   finalize() may proceed.
     */
    public function finalizeRefusal(ManagedTask $task, ?string $shortfallNote): ?array
    {
        $parts = ManagedTaskPart::where('managed_task_id', $task->id)->get();

        $hasOutstandingPart = $parts->contains(
            fn (ManagedTaskPart $part) => in_array($part->state, ['not_yet_assigned', 'out_for_assignment', 'out_for_correction'], true)
        );

        if ($hasOutstandingPart && $task->rounds_used < $task->round_ceiling) {
            return ['error' => 'parts_outstanding', 'message' => 'Every part must be accepted or reported as a shortfall before the task can be finalized.'];
        }

        $hasShortfallPart = $parts->contains(fn (ManagedTaskPart $part) => $part->state === 'reported_as_shortfall');

        if ($hasShortfallPart && empty($shortfallNote)) {
            return ['error' => 'shortfall_note_required', 'message' => 'A shortfall_note is required when any part was reported as a shortfall.'];
        }

        return null;
    }

    /**
     * research.md D3, contracts/manager-agent-meta-tools.md §4, tasks.md
     * T054. The ONE decision point for whether report_shortfall may
     * proceed -- exposed publicly (mirrors acceptPartRefusal()'s own "one
     * shared guard" shape) so AgentLoopService::handleReportShortfall()
     * can surface the SAME structured refusal reason contracts §4
     * specifies before ever calling the void reportShortfall() write.
     *
     * `part_already_finalized`: the part's own state is already
     * 'accepted' or 'reported_as_shortfall' -- the SAME "already
     * finalized" condition admitAssignmentRound() refuses assign_part
     * for (a part is closed once, by either accept_part or
     * report_shortfall, never both -- contracts §4's own "same guard as
     * §3's first row" -- so this method and admitAssignmentRound() must
     * agree on what "already finalized" means).
     *
     * @return array{error: string, message: string}|null Null means the
     *   part may be reported as a shortfall.
     */
    public function reportShortfallRefusal(ManagedTaskPart $part): ?array
    {
        if (in_array($part->state, ['accepted', 'reported_as_shortfall'], true)) {
            return ['error' => 'part_already_finalized', 'message' => 'This part has already been finalized and cannot be reported as a shortfall.'];
        }

        return null;
    }

    /**
     * research.md D3, contracts/manager-agent-meta-tools.md §4, tasks.md
     * T056. On success (reportShortfallRefusal() returns null): state =
     * 'reported_as_shortfall', shortfall_reason = $reason. Terminal -- a
     * later assignPart() on this part_id is refused by
     * admitAssignmentRound()'s own "already finalized" check, exactly as
     * an accepted part's would be.
     *
     * Reassignment (a further assign_part call naming a different
     * helper_agent_id) and adaptation (the same helper_agent_id with a
     * narrowed task) are both already-supported assign_part calls --
     * research.md D2's "no new dispatch mechanism" -- so this method's
     * only job is closing a part honestly once neither of those is
     * workable.
     */
    public function reportShortfall(ManagedTask $task, ManagedTaskPart $part, string $reason): void
    {
        if ($this->reportShortfallRefusal($part) !== null) {
            return;
        }

        $part->state = 'reported_as_shortfall';
        $part->shortfall_reason = $reason;
        $part->save();

        $task->last_progress_at = now();
        $task->save();

        $this->broadcast(fn () => event(new ManagedTaskUpdated($task->id)));
    }

    /**
     * research.md D6, contracts/manager-agent-meta-tools.md §5, tasks.md
     * T040. On success (finalizeRefusal() returns null):
     * ResultAggregationService::combineForManagedTask()'s conflict check
     * populates conflict_note (FR-016) when two or more accepted parts'
     * outputs genuinely conflict; status is set to completed_with_shortfalls
     * when any part is reported_as_shortfall, completed otherwise;
     * final_response/shortfall_note are written verbatim (the model's own
     * composed text -- this method never rewrites or augments it); and
     * completed_at is stamped.
     */
    public function finalize(ManagedTask $task, string $finalResponse, ?string $shortfallNote): void
    {
        if ($this->finalizeRefusal($task, $shortfallNote) !== null) {
            return;
        }

        $conflictNote = null;
        $combined = $this->resultAggregationService->combineForManagedTask($task->id);
        if ($combined !== null && !empty($combined['conflicts'])) {
            $conflictNote = $this->describeConflicts($combined['conflicts']);
        }

        $hasShortfallPart = ManagedTaskPart::where('managed_task_id', $task->id)
            ->where('state', 'reported_as_shortfall')
            ->exists();

        $task->status = $hasShortfallPart ? 'completed_with_shortfalls' : 'completed';
        $task->final_response = $finalResponse;
        $task->shortfall_note = $shortfallNote;
        $task->conflict_note = $conflictNote;
        $task->completed_at = now();
        $task->last_progress_at = now();
        $task->save();

        $this->broadcast(fn () => event(new ManagedTaskUpdated($task->id)));
    }

    /**
     * A plain, human-readable account of every conflicting key
     * combineForManagedTask() reported -- never silently favoring one
     * part's value (FR-016). Kept deliberately simple: the conflict's own
     * structured shape (key/values/provenance) is what a caller wanting
     * the full detail should read from combineForManagedTask() directly;
     * this text is only the ManagedTask.conflict_note summary.
     */
    private function describeConflicts(array $conflicts): string
    {
        $lines = [];
        foreach ($conflicts as $conflict) {
            $key = $conflict['key'];
            $valueDescriptions = array_map(function (array $occurrence) {
                $helper = $occurrence['helper_agent_name'] ?? $occurrence['helper_agent_id'] ?? 'an unknown helper';

                return sprintf('%s (from %s)', json_encode($occurrence['value']), $helper);
            }, $conflict['values']);

            $lines[] = sprintf('%s: %s', $key, implode(' vs. ', $valueDescriptions));
        }

        return 'Conflicting values were found across accepted parts: '.implode('; ', $lines).'.';
    }

    /**
     * research.md D5/D7, contracts/manager-agent-meta-tools.md §6, tasks.md
     * T049. The SYSTEM-forced finalize path -- no model call, no
     * ResultAggregationService call (there is no free text from the model
     * to conflict-check; the response is composed directly from
     * already-accepted summaries). Called by RunManagedTaskStepJob::
     * handle() (T050) when a step is about to start but the round or
     * wall-clock ceiling has already been reached, and by
     * ResolveStalledManagedTasksCommand (T051) when its own sweep finds a
     * stale task already past its wall-clock bound.
     *
     * Every part not already 'accepted' is closed 'reported_as_shortfall'
     * with $reason (a plain, generic account of WHY the system stepped
     * in -- ceiling or wall-clock) stamped as that part's own
     * shortfall_reason; final_response is assembled from every
     * already-accepted part's accepted_summary (US4 AC2 -- "the best
     * available answer using every accepted part"), or a plain fallback
     * sentence if no part was ever accepted; ManagedTask.shortfall_note
     * additionally names each still-unaccepted part specifically (by its
     * own sequence/description), so a caller reading only the task-level
     * fields -- never the per-part breakdown -- still learns exactly what
     * fell short. status = 'completed_with_shortfalls'; completed_at set.
     * Never touches rounds_used -- a forced finalize is not itself a
     * round.
     */
    public function finalizeWithShortfall(ManagedTask $task, string $reason): void
    {
        $parts = ManagedTaskPart::where('managed_task_id', $task->id)->get();

        $acceptedSummaries = [];
        $shortfallParts = [];

        foreach ($parts as $part) {
            if ($part->state === 'accepted') {
                if (!empty($part->accepted_summary)) {
                    $acceptedSummaries[] = $part->accepted_summary;
                }

                continue;
            }

            $part->state = 'reported_as_shortfall';
            $part->shortfall_reason = $reason;
            $part->save();

            $shortfallParts[] = $part;
        }

        $task->status = 'completed_with_shortfalls';
        $task->final_response = empty($acceptedSummaries)
            ? 'No part of this task could be completed within the allowed rounds or time.'
            : implode(' ', $acceptedSummaries);
        $task->shortfall_note = $this->composeSystemShortfallNote($shortfallParts, $reason);
        $task->completed_at = now();
        $task->last_progress_at = now();
        $task->save();

        $this->broadcast(fn () => event(new ManagedTaskUpdated($task->id)));
    }

    /**
     * Task-level shortfall_note for finalizeWithShortfall() -- names each
     * still-unaccepted part specifically (its own sequence and
     * description), never just a generic "some parts were incomplete"
     * (US4 AC2/quickstart scenario 4's own "shortfall_note names the
     * still-unaccepted part(s) specifically").
     *
     * @param ManagedTaskPart[] $shortfallParts
     */
    private function composeSystemShortfallNote(array $shortfallParts, string $reason): string
    {
        if (empty($shortfallParts)) {
            return $reason;
        }

        $descriptions = array_map(
            fn (ManagedTaskPart $part) => sprintf('part %d ("%s")', $part->sequence, $part->description),
            $shortfallParts,
        );

        return sprintf('%s The following part(s) could not be completed: %s.', $reason, implode(', ', $descriptions));
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
