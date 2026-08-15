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
}
