<?php

namespace ClarionApp\LlmClient\Events;

use ClarionApp\LlmClient\Models\Delegation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * 106-multi-agent-run-view, Phase 4 (US2), tasks.md T028 (research.md D4,
 * data-model.md §3.1). Broadcast event fired by DelegationService's private
 * broadcast() helper at every point an agent_delegations row's status is
 * written -- row creation ('queued' for an unadmitted batch member,
 * 'in_progress' for an admitted/solo delegation), the queued -> in_progress
 * admission transition (via the new DelegationService::broadcastDelegationAdmitted(),
 * called from RunDelegationBatchMemberJob::handle() -- research.md D4a,
 * since that write itself lives in DelegationConcurrencyGate::tryAdmit(),
 * not DelegationService), and every terminal transition
 * (completed/exhausted/failed).
 *
 * Delivered on the same already-hardened PrivateChannel('User.{id}')
 * RunUpdated/ManagedTaskUpdated/SequenceRunUpdated already use (research.md
 * D2) -- zero new channel authorization predicates, zero new
 * identifier-comparison code.
 *
 * broadcastOn()/broadcastWith() both re-resolve the Delegation from the
 * database at broadcast time rather than trusting any caller/constructor-
 * supplied value, so a pushed payload can never disagree with what a fresh
 * ArrangementResponse.delegations[] entry would show for the same row at the
 * same instant (research.md D2).
 *
 * Note: the owning column is owner_user_id, matching agent_delegations'
 * own schema (data-model.md §1) -- getting this wrong is exactly the class
 * of identifier-comparison defect 070 previously fixed (a prior (int)-cast
 * UUID collision).
 */
class DelegationUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(public readonly string $delegationId)
    {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $delegation = Delegation::find($this->delegationId);
        if ($delegation === null) {
            // Purged between the write this event reports on and the
            // broadcast itself -- no-op, never a guessed channel.
            return [];
        }

        return [new PrivateChannel('User.' . $delegation->owner_user_id)];
    }

    /**
     * The same per-row shape ArrangementResponse.delegations[] projects
     * (data-model.md §1.1/§3.1) -- a pushed payload can never disagree with
     * what a fresh arrangement fetch would show for the same delegation.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $delegation = Delegation::find($this->delegationId);
        if ($delegation === null) {
            return [];
        }

        return [
            'id' => $delegation->id,
            'parent_run_id' => $delegation->parent_run_id,
            'parent_action_id' => $delegation->parent_action_id,
            'helper_run_id' => $delegation->helper_run_id,
            'helper_agent_id' => $delegation->helper_agent_id,
            'depth' => $delegation->depth,
            'status' => $delegation->status,
            'batch_id' => $delegation->batch_id,
            'started_at' => $delegation->started_at?->toJSON(),
            'completed_at' => $delegation->completed_at?->toJSON(),
        ];
    }
}
