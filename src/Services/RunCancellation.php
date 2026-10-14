<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\TaskBudget;
use Illuminate\Support\Facades\DB;

/**
 * Cancellation. An explicit user stop —
 * ending or deleting a conversation — cancels the conversation's in-progress
 * runs and, iteratively through the delegation chain, their helper runs. A
 * cancelled run is closed `stopped_early` at its next loop head (or refused
 * by the resumer) and is never resumed.
 *
 * `isCancelled()` is the single check every loop head and the resumer use:
 * it returns the cause that cancelled the run, or null when the run may
 * continue.
 */
class RunCancellation
{
    /**
     * Cancel the conversation's in-progress runs and, iteratively through
     * `agent_delegations.parent_run_id`, every helper run whose delegation
     * chain root is one of them. Called from ConversationController::end()
     * and destroy() next to withdrawForConversation().
     */
    public function cancelForConversation(Conversation $conversation, string $cause): void
    {
        $now = now();

        $rootRunIds = DB::table('agent_runs')
            ->where('conversation_id', $conversation->id)
            ->where('end_state', 'in_progress')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (empty($rootRunIds)) {
            return;
        }

        DB::table('agent_runs')
            ->where('conversation_id', $conversation->id)
            ->where('end_state', 'in_progress')
            ->update(['cancelled_at' => $now]);

        // The delegation tree rooted at the conversation's runs: every helper
        // run, however deep, is cancelled with its root. Walk it level by
        // level (a helper's helper is in the helper's own conversation, not
        // the root's, so a single WHERE conversation_id would miss it).
        $frontier = $rootRunIds;
        $seen = [];
        while (!empty($frontier)) {
            $helperRunIds = DB::table('agent_delegations')
                ->whereIn('parent_run_id', $frontier)
                ->whereNotNull('helper_run_id')
                ->distinct()
                ->pluck('helper_run_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $newHelpers = array_values(array_diff($helperRunIds, $seen));
            if (empty($newHelpers)) {
                break;
            }

            DB::table('agent_runs')
                ->whereIn('id', $newHelpers)
                ->where('end_state', 'in_progress')
                ->update(['cancelled_at' => $now]);

            $seen = array_merge($seen, $newHelpers);
            $frontier = $newHelpers;
        }
    }

    /**
     * The cause that cancelled the run, or null when it may continue.
     * Checked at every loop head (immediately before the budget gate), by
     * the resumer, and in the claim WHERE.
     */
    public function isCancelled(AgentRun $run): ?string
    {
        $conversation = Conversation::find($run->conversation_id);

        // 1. Explicitly cancelled: cancelForConversation() stamped
        //    cancelled_at. The cause is the conversation's end or delete.
        if ($run->cancelled_at !== null) {
            return $conversation === null ? 'conversation_deleted' : 'conversation_ended';
        }

        // 2. The conversation is gone (a delete that out-raced the stamp).
        if ($conversation === null) {
            return 'conversation_deleted';
        }

        // 3. The run's task budget cancelled the work (a stop or an accept
        //    at the ceiling). `completed` and `ended` are not cancellations
        //    (the work finished or the task's lifecycle ended otherwise),
        //    so an interrupted run is still resumed.
        if ($conversation->active_task_budget_id !== null) {
            $budget = TaskBudget::find($conversation->active_task_budget_id);
            if ($budget !== null && $budget->state->isWorkCancelled()) {
                return 'task_budget_'.$budget->state->value;
            }
        }

        // 4. A helper whose delegation chain root was cancelled (a race: the
        //    helper started after the cancel, so its own cancelled_at was
        //    never stamped).
        if ($this->chainRootCancelled($run)) {
            return 'chain_root_cancelled';
        }

        return null;
    }

    /**
     * Walk up the delegation chain to its root and report whether the root
     * was cancelled. A run with no parent is its own root (never cancelled
     * this way).
     */
    private function chainRootCancelled(AgentRun $run): bool
    {
        $currentRunId = (string) $run->id;
        $seen = [$currentRunId];

        while (true) {
            $delegation = Delegation::where('helper_run_id', $currentRunId)->first();
            if ($delegation === null) {
                // No parent: this run is the root.
                return false;
            }

            $parentRunId = (string) $delegation->parent_run_id;
            if (in_array($parentRunId, $seen, true)) {
                // A cycle in the delegation chain: stop rather than loop.
                return false;
            }
            $seen[] = $parentRunId;

            $parentRun = AgentRun::find($parentRunId);
            if ($parentRun === null) {
                return false;
            }
            if ($parentRun->cancelled_at !== null) {
                return true;
            }

            $currentRunId = $parentRunId;
        }
    }
}
