<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * Posts a task notice or report into a conversation and puts it in front of
 * the user: an assistant message the model never sees (a
 * `task_resume_notice`), broadcast on the web and delivered through the
 * conversation's channel adapter elsewhere when the user can be reached. An
 * unattended run keeps it in the history only. A failure to post or deliver
 * never stops the work that wanted to say it.
 */
class TaskNoticePoster
{
    public function post(Conversation $conversation, AgentRun $run, string $notice, string $text): ?Message
    {
        try {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'user' => $conversation->character,
                'content' => $text,
                'responseTime' => 0,
                'tool_data' => ['kind' => AgentQuestionService::KIND_TASK_RESUME_NOTICE, 'notice' => $notice, 'run_id' => (string) $run->id],
            ]);

            $this->deliver($conversation, $run, $text, (string) $message->id);

            return $message;
        } catch (\Throwable $e) {
            Log::warning('TaskNoticePoster: a task notice could not be posted', [
                'run_id' => $run->id,
                'error' => get_class($e),
            ]);

            return null;
        }
    }

    /**
     * Put a message the task wrote in front of the user: broadcast on the
     * web, delivered through the conversation's channel adapter elsewhere
     * when the user can be reached.
     */
    public function deliver(Conversation $conversation, AgentRun $run, string $text, ?string $messageId): void
    {
        if ((string) ($conversation->channel ?? 'web') === 'web') {
            if ($messageId !== null) {
                event(new NewConversationMessageEvent($conversation->id, $messageId));
            }

            return;
        }

        $options = is_array($run->resume_options) ? $run->resume_options : json_decode((string) $run->resume_options, true);
        $unattended = is_array($options) && !empty($options['unattended']);
        if (app(UserReachability::class)->refusal($conversation, $unattended) !== null) {
            return;
        }

        $delivery = app(AgentQuestionService::class)->deliver($conversation, $text);
        if (!$delivery->delivered) {
            Log::warning('TaskNoticePoster: a message could not be delivered on the conversation\'s channel', [
                'run_id' => $run->id,
                'reason' => $delivery->failureReason,
            ]);
        }
    }
}
