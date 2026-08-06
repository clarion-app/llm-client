<?php

namespace ClarionApp\LlmClient\Events;

use ClarionApp\LlmClient\Services\RunTraceQuery;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Broadcast event fired by RunTraceRecorder::openAction() (after insert) and
 * by every outcome-changing branch of closeAction() — the
 * awaiting_confirmation suspend branch and the shared terminal
 * success/failure/resolve-from-paused update (research.md D3,
 * data-model.md §4.3). Delivered on the action's run owner's
 * already-hardened PrivateChannel('User.{id}') (research.md D1), resolved
 * via the same action -> run -> user_id lookup shape childActions() already
 * established (068) — a plain id lookup, not a comparison (standing rule 5).
 *
 * broadcastWith() never includes `content` — it projects the ActionSummary
 * shape only, reusing RunTraceQuery::actionSummaryById()'s exact
 * actionSummaryRows() projection (the same one the REST action-list
 * endpoints use), preserving FR-011 on the push channel too.
 */
class RunActionUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(public readonly string $actionId)
    {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $runId = DB::table('agent_run_actions')
            ->where('id', $this->actionId)
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
        return app(RunTraceQuery::class)->actionSummaryById($this->actionId) ?? [];
    }
}
