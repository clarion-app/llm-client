<?php

namespace ClarionApp\LlmClient\Events;

use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired by RunTraceRecorder::closeRun(), immediately after
 * its terminal UPDATE to agent_runs succeeds (research.md D3,
 * data-model.md §4.1). Delivered on the run owner's already-hardened
 * PrivateChannel('User.{id}') (research.md D1) — this feature adds zero new
 * channel-authorization predicates and zero new identifier-comparison code
 * (standing rule 5): broadcastOn() below performs no comparison at all, it
 * only looks up the owner's id and hands it to the already-authorized
 * channel name.
 *
 * broadcastOn()/broadcastWith() both re-resolve the run from the database at
 * broadcast time rather than trusting any caller/constructor-supplied
 * value, so a pushed payload can never disagree with what
 * GET /agent-runs/{runId} would return for the same id at the same instant.
 */
class RunUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(public readonly string $runId)
    {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $run = AgentRun::find($this->runId);
        if ($run === null) {
            // Run purged between the write this event reports on and the
            // broadcast itself — no-op, never a guessed channel.
            return [];
        }

        return [new PrivateChannel('User.' . $run->user_id)];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return app(RunTraceQuery::class)->runSummaryById($this->runId) ?? [];
    }
}
