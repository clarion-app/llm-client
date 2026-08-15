<?php

namespace ClarionApp\LlmClient\Events;

use ClarionApp\LlmClient\Models\ManagedTask;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired by ManagerService's private broadcast() helper,
 * immediately after each ManagedTask/ManagedTaskPart write (research.md
 * D8, data-model.md §5). Delivered on the same already-hardened
 * PrivateChannel('User.{id}') 070's RunUpdated/RunStepUpdated/
 * RunActionUpdated already use — this feature adds zero new channel
 * authorization predicates and zero new identifier-comparison code.
 *
 * broadcastOn()/broadcastWith() both re-resolve the ManagedTask from the
 * database at broadcast time rather than trusting any caller/constructor-
 * supplied value, so a pushed payload can never disagree with what
 * GET /managed-tasks/{id} would return for the same id at the same
 * instant.
 *
 * Note: the owning column is owner_user_id, not user_id (data-model.md
 * §1, matching agent_delegations.owner_user_id) — getting this wrong is
 * exactly the class of identifier-comparison defect 070 previously fixed
 * (a prior (int)-cast UUID collision).
 */
class ManagedTaskUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(public readonly string $managedTaskId)
    {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $managedTask = ManagedTask::find($this->managedTaskId);
        if ($managedTask === null) {
            // Task purged between the write this event reports on and the
            // broadcast itself — no-op, never a guessed channel.
            return [];
        }

        return [new PrivateChannel('User.' . $managedTask->owner_user_id)];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $managedTask = ManagedTask::find($this->managedTaskId);
        if ($managedTask === null) {
            return [];
        }

        return [
            'managed_task_id' => $managedTask->id,
            'status' => $managedTask->status,
            'rounds_used' => $managedTask->rounds_used,
            'round_ceiling' => $managedTask->round_ceiling,
            'final_response' => $managedTask->final_response,
            'shortfall_note' => $managedTask->shortfall_note,
            'conflict_note' => $managedTask->conflict_note,
            'completed_at' => optional($managedTask->completed_at)->toISOString(),
        ];
    }
}
