<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\ManagedTaskPart;

/**
 * 103-manager-agent (data-model.md §6). Owner-scoped read path over the
 * ManagedTask rows ManagerService writes -- mirrors DelegationQuery::
 * findDelegation()'s exact "null collapses both absent and not-the-caller's"
 * contract.
 *
 * Phase 3 (US1) adds findManagedTask(). Phase 5 (US3) adds partsForTask().
 * costForTask() is added by a later phase (US7).
 */
class ManagedTaskQuery
{
    /**
     * @return ManagedTask|null Null when absent or owned by another user.
     */
    public function findManagedTask(string $callerUserId, string $managedTaskId): ?ManagedTask
    {
        return ManagedTask::where('id', $managedTaskId)
            ->where('owner_user_id', $callerUserId)
            ->first();
    }

    /**
     * 103-manager-agent (US3, contracts/manager-agent-api.md §3, data-model.md
     * §6, tasks.md T043). Every ManagedTaskPart for the task, ordered by
     * sequence, ownership-checked via findManagedTask() first -- available
     * while the task is still in progress (FR-008/US3 AC2, Edge Cases'
     * "shows the state of each part as of that moment"), not only once
     * terminal.
     *
     * assigned_helper_agent_id/_name reflect current_delegation_id
     * (outstanding/most recent attempt -- overwritten on every
     * assign_part call, so a reassigned part shows the NEW helper, not
     * the one that failed) or, once a part is accepted and
     * current_delegation_id is no longer touched, accepted_delegation_id
     * (contracts §3).
     *
     * @return array<int, array<string, mixed>>|null Null when the task is
     *   absent or owned by another user.
     */
    public function partsForTask(string $callerUserId, string $managedTaskId): ?array
    {
        $task = $this->findManagedTask($callerUserId, $managedTaskId);
        if ($task === null) {
            return null;
        }

        $parts = ManagedTaskPart::where('managed_task_id', $managedTaskId)
            ->orderBy('sequence')
            ->get();

        $delegationIds = $parts
            ->map(fn (ManagedTaskPart $part) => $part->current_delegation_id ?? $part->accepted_delegation_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $delegations = empty($delegationIds)
            ? collect()
            : Delegation::whereIn('id', $delegationIds)->get()->keyBy('id');

        $agentIds = $delegations->pluck('helper_agent_id')->filter()->unique()->values()->all();
        $names = empty($agentIds) ? [] : Agent::whereIn('id', $agentIds)->pluck('name', 'id')->all();

        return $parts->map(function (ManagedTaskPart $part) use ($delegations, $names) {
            $delegationId = $part->current_delegation_id ?? $part->accepted_delegation_id;
            $delegation = $delegationId !== null ? ($delegations[$delegationId] ?? null) : null;

            return [
                'part_id' => $part->id,
                'sequence' => $part->sequence,
                'description' => $part->description,
                'state' => $part->state,
                'assigned_helper_agent_id' => $delegation?->helper_agent_id,
                'assigned_helper_agent_name' => $delegation !== null ? ($names[$delegation->helper_agent_id] ?? null) : null,
                'assignment_count' => $part->assignment_count,
                'accepted_summary' => $part->accepted_summary,
                'shortfall_reason' => $part->shortfall_reason,
            ];
        })->all();
    }
}
