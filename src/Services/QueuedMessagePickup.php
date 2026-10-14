<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hands a message a user wrote while a task was running to the next task,
 * once that task has ended without answering it. A queued message is a user
 * message written since the ended run began that no run has taken as its
 * trigger. (Comparing it with the last assistant message would misread
 * messages written in the same second.) Failing to start it is logged, never
 * thrown: nobody is awaiting the answer.
 */
class QueuedMessagePickup
{
    public function startIfAny(Conversation $conversation, AgentRun $endedRun): void
    {
        try {
            $triggerCreatedAt = DB::table('agent_run_messages')
                ->join('messages', 'messages.id', '=', 'agent_run_messages.message_id')
                ->where('agent_run_messages.run_id', $endedRun->id)
                ->where('agent_run_messages.relation', 'trigger')
                ->value('messages.created_at');
            $since = $triggerCreatedAt !== null
                ? (string) $triggerCreatedAt
                : substr((string) ($endedRun->getRawOriginal('started_at') ?? now()->format('Y-m-d H:i:s')), 0, 19);

            $queued = Message::where('conversation_id', $conversation->id)
                ->where('role', 'user')
                ->where('created_at', '>=', $since)
                ->whereNotIn('id', DB::table('agent_run_messages')->where('relation', 'trigger')->select('message_id'))
                ->exists();

            if ($queued) {
                app(AgentLoopService::class)->start($conversation->fresh() ?? $conversation);
            }
        } catch (\Throwable $e) {
            Log::info('QueuedMessagePickup: a queued message was not started', [
                'conversation_id' => $conversation->id,
                'error' => get_class($e),
            ]);
        }
    }
}
