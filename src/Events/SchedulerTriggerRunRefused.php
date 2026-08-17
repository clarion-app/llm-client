<?php

namespace ClarionApp\LlmClient\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * An unattended trigger run stopped because it reached an action it could
 * not proceed with — not permitted, or a destructive action that was not
 * pre-authorized in advance. Fired exactly once per stopped-and-refused
 * run, from the same catch site that closes the run itself, always for a
 * single concrete owning user (a triggered run always belongs to the user
 * who owns the trigger, unlike SpendingCeilingReached's own installation-
 * wide operator fan-out for a null user, which does not apply here).
 *
 * Channel resolution mirrors SpendingCeilingReached/RunUpdated exactly:
 * PrivateChannel('User.{id}'), zero new identifier comparisons, zero new
 * broadcast-authorization predicate.
 */
final class SchedulerTriggerRunRefused implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public readonly string $userId,
        public readonly string $runId,
        public readonly string $operationId,
        public readonly string $reason,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('User.' . $this->userId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'operation_id' => $this->operationId,
            'reason' => $this->reason,
        ];
    }
}
