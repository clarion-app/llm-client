<?php

namespace ClarionApp\LlmClient\Events;

use ClarionApp\LlmClient\Services\RunTraceQuery;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Broadcast event fired by RunTraceRecorder::openStep() (after insert) and
 * closeStep() (after update) (research.md D3, data-model.md §4.2).
 * Delivered on the step's run owner's already-hardened
 * PrivateChannel('User.{id}') (research.md D1), resolved via the same
 * step -> run -> user_id lookup shape actionsForStep() already established
 * (068) — a plain id lookup, not a comparison, so this adds no new
 * identifier-comparison code (standing rule 5).
 */
class RunStepUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(public readonly string $stepId)
    {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $runId = DB::table('agent_run_steps')
            ->where('id', $this->stepId)
            ->value('run_id');

        if ($runId === null) {
            return [];
        }

        $ownerUserId = DB::table('agent_runs')
            ->where('id', $runId)
            ->value('user_id');

        if ($ownerUserId === null) {
            return [];
        }

        return [new PrivateChannel('User.' . $ownerUserId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return app(RunTraceQuery::class)->stepSummaryById($this->stepId) ?? [];
    }
}
