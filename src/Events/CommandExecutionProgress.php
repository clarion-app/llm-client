<?php

namespace ClarionApp\LlmClient\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * 123-sandboxed-shell-execution, US3 (data-model.md §4, research.md D2,
 * FR-013). Fired by DockerCommandExecutor while a command it started is
 * still running, once elapsed wall-clock time has crossed the configured
 * command_progress_broadcast_after_seconds threshold -- a pure, ephemeral
 * "still running" heartbeat. No row is ever written for this event; the
 * durable record (CodingCommandExecution) is written exactly once, when
 * the command finally resolves.
 *
 * Unlike RunActionUpdated, both codingProjectId and userId are passed in
 * directly rather than resolved inside broadcastOn() via a database
 * lookup -- the caller (DockerCommandExecutor, invoked from inside the
 * already-authenticated runCommand() request) already has the acting
 * user's id in hand, and no agent_run_actions/agent_runs join exists for
 * this event to walk, since a command execution is not part of the
 * run-trace hierarchy. Delivered on the same already-hardened
 * PrivateChannel('User.{id}') shape every other live-update event in this
 * package already uses -- no new Broadcast::channel() predicate is added.
 */
class CommandExecutionProgress implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public readonly string $codingProjectId,
        public readonly string $userId,
        public readonly int $elapsedSeconds,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('User.'.$this->userId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'coding_project_id' => $this->codingProjectId,
            'status' => 'running',
            'elapsed_seconds' => $this->elapsedSeconds,
        ];
    }
}
