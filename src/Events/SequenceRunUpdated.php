<?php

namespace ClarionApp\LlmClient\Events;

use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * 105-stage-pipeline (research.md D8, contracts/stage-pipeline-api.md §6,
 * Grounding note item 8). Broadcast event fired by SequenceService's
 * private broadcast() helper, immediately after each SequenceRun/
 * StageResult write. Mirrors ManagedTaskUpdated exactly: delivered on the
 * same already-hardened PrivateChannel('User.{id}') RunUpdated/
 * ManagedTaskUpdated already use -- zero new channel authorization
 * predicates, zero new identifier-comparison code.
 *
 * broadcastOn()/broadcastWith() both re-resolve the SequenceRun (and its
 * StageResult rows) from the database at broadcast time rather than
 * trusting any caller/constructor-supplied value, so a pushed payload can
 * never disagree with what GET /sequence-runs/{id} would return for the
 * same id at the same instant.
 *
 * Note: the owning column is owner_user_id, not user_id (data-model.md
 * §3, matching agent_delegations.owner_user_id/managed_tasks.
 * owner_user_id) -- getting this wrong is exactly the class of
 * identifier-comparison defect 070's own reconciliation twice found and
 * fixed (a prior (int)-cast UUID collision).
 */
class SequenceRunUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(public readonly string $sequenceRunId)
    {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $run = SequenceRun::find($this->sequenceRunId);
        if ($run === null) {
            // Run purged between the write this event reports on and the
            // broadcast itself -- no-op, never a guessed channel.
            return [];
        }

        return [new PrivateChannel('User.' . $run->owner_user_id)];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $run = SequenceRun::find($this->sequenceRunId);
        if ($run === null) {
            return [];
        }

        $totalStageCount = Stage::where('sequence_definition_id', $run->sequence_definition_id)->count();
        $completedStageCount = StageResult::where('sequence_run_id', $run->id)
            ->where('status', 'completed')
            ->count();

        return [
            'sequence_run_id' => $run->id,
            'status' => $run->status,
            'current_stage_position' => $run->current_stage_position,
            'completed_stage_count' => $completedStageCount,
            'total_stage_count' => $totalStageCount,
            'resume_count' => $run->resume_count,
        ];
    }
}
