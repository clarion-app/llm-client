<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentMessage;

/**
 * Owner-scoped read path over the AgentMessage rows AgentMessageService
 * writes (107-agent-message-protocol, data-model.md §3,
 * contracts/agent-message-service.md §2). Mirrors
 * RunTraceQuery::findRun()'s exact "null collapses both absent and not-the-
 * caller's" contract.
 */
class AgentMessageQuery
{
    /**
     * @return AgentMessage|null Null when absent or owned by another user.
     */
    public function findMessage(string $callerUserId, string $messageId): ?AgentMessage
    {
        return AgentMessage::where('id', $messageId)
            ->where('owner_user_id', $callerUserId)
            ->first();
    }

    /**
     * Every message this user sent or received, newest first.
     *
     * @return AgentMessage[] Empty array, never null, when there are none.
     */
    public function messagesForOwner(string $callerUserId): array
    {
        return AgentMessage::where('owner_user_id', $callerUserId)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * Every message attributed to a run the caller owns, oldest first —
     * mirrors RunTraceQuery::messagesForRun()'s own name and ownership-check
     * shape (069, data-model.md §3): scoped directly by owner_user_id (every
     * message this feature persists is already owned by exactly one user
     * regardless of which side of it they're on), so there is no separate
     * "does this run belong to the caller" lookup to short-circuit.
     *
     * @return AgentMessage[] Empty array, never null, when the run produced
     *                        no messages or is not owned by the caller.
     */
    public function messagesForRun(string $callerUserId, string $runId): array
    {
        return AgentMessage::where('run_id', $runId)
            ->where('owner_user_id', $callerUserId)
            ->orderBy('created_at')
            ->get()
            ->all();
    }
}
