<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\ManagedTask;

/**
 * 103-manager-agent (data-model.md §6). Owner-scoped read path over the
 * ManagedTask rows ManagerService writes -- mirrors DelegationQuery::
 * findDelegation()'s exact "null collapses both absent and not-the-caller's"
 * contract.
 *
 * Phase 3 (US1) adds findManagedTask() only. partsForTask()/costForTask()
 * are added by later phases (US3/US7).
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
}
