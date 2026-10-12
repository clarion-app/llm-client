<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\Log;

/**
 * Tells a user, once, that the reply they typed came after a question had
 * already expired on the assumption it stated.
 *
 * The reply itself is never applied to that question: it is handled as an
 * ordinary new message. The notice only explains why, naming what the agent
 * assumed, and is posted only for the conversation's most recently asked
 * question, only to its owner, and only once — the last decided by a
 * compare-and-set on the question, so concurrent replies produce one notice.
 */
class LateReplyNotifier
{
    public function __construct(private readonly AgentQuestionService $questions)
    {
    }

    /**
     * Post, at most once per question, the late-reply notice for
     * $conversation's most recently asked question when it is
     * expired_assumed and owned by $userId. Returns the notice message, or
     * null. Never throws (failures are logged).
     */
    public function noticeFor(Conversation $conversation, string $userId): ?Message
    {
        try {
            $question = AgentQuestion::where('conversation_id', $conversation->id)
                ->orderByDesc('asked_at')
                ->orderByDesc('created_at')
                ->first();

            if ($question === null
                || $question->state !== QuestionState::ExpiredAssumed
                || (string) $question->user_id !== $userId
                || $question->late_reply_noticed_at !== null) {
                return null;
            }

            return $this->questions->postLateReplyNotice($question, $conversation);
        } catch (\Throwable $e) {
            Log::warning('LateReplyNotifier: the late-reply notice could not be posted', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
