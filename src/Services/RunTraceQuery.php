<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\AgentRunStep;
use ClarionApp\LlmClient\ValueObjects\RunRelation;
use Illuminate\Support\Facades\DB;

class RunTraceQuery
{
    /**
     * Find a run by id, filtered by caller's user ownership.
     *
     * @return AgentRun|null Null when absent, purged, or owned by another user.
     */
    public function findRun(string $callerUserId, string $runId): ?AgentRun
    {
        return AgentRun::where('id', $runId)
            ->where('user_id', $callerUserId)
            ->first();
    }

    /**
     * Ordered steps for a run the caller owns.
     * Returns null if the run doesn't exist or isn't owned by the caller.
     * Returns empty array for a zero-step run (FR-025).
     *
     * @return AgentRunStep[]|null Ordered by position, null if run not accessible.
     */
    public function stepsForRun(string $callerUserId, string $runId): ?array
    {
        $run = $this->findRun($callerUserId, $runId);
        if ($run === null) {
            return null;
        }

        return AgentRunStep::where('run_id', $runId)
            ->orderBy('position', 'asc')
            ->get()
            ->all();
    }

    /**
     * Runs for a conversation, ordered by started_at ascending (FR-022).
     * Includes runs that produced no reply message.
     *
     * @return AgentRun[]
     */
    public function runsForConversation(
        string $callerUserId,
        string $conversationId,
        int $limit = 100,
    ): array {
        return AgentRun::where('user_id', $callerUserId)
            ->where('conversation_id', $conversationId)
            ->orderBy('started_at', 'asc')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Runs for a user, including system-initiated runs with no conversation (FR-023).
     *
     * @return AgentRun[]
     */
    public function runsForUser(
        string $callerUserId,
        int $limit = 100,
    ): array {
        return AgentRun::where('user_id', $callerUserId)
            ->orderBy('started_at', 'desc')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Resolve a message to its run — the reply the run produced, or the user message
     * that triggered it. Null when the message predates the feature, was never
     * associated, its run was purged, or the caller does not own it.
     *
     * @return AgentRun|null
     */
    public function findRunForMessage(
        string $callerUserId,
        string $messageId,
        ?RunRelation $relation = null,
    ): ?AgentRun {
        $query = DB::table('agent_run_messages')
            ->join('agent_runs', 'agent_run_messages.run_id', '=', 'agent_runs.id')
            ->where('agent_run_messages.message_id', $messageId)
            ->where('agent_runs.user_id', $callerUserId);

        if ($relation !== null) {
            $query->where('agent_run_messages.relation', $relation->value);
        }

        $row = $query->select('agent_runs.id as run_id')->first();

        if ($row === null) {
            return null;
        }

        return $this->findRun($callerUserId, (string) $row->run_id);
    }
}
