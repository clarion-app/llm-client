<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\PresentsQuestions;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\ValueObjects\QuestionOutcomeView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes every place a question is shown once it has been resolved.
 *
 * Runs after the resolving transaction commits (AgentQuestionService
 * schedules it from the winning compare-and-set), so it happens exactly
 * once per question, whichever route resolved it, and never for a
 * resolution that rolled back. An open conversation screen is told to
 * refetch on every channel; a messaging channel that presents questions is
 * also shown the outcome. Best effort throughout: a failure is logged and
 * can never undo or delay the resolution.
 */
class QuestionPresentationUpdater
{
    public function __construct(private readonly ConversationChannelRegistry $registry)
    {
    }

    public function resolved(string $questionId): void
    {
        try {
            $question = $this->load($questionId);
            $conversation = $question === null ? null : Conversation::find($question->conversation_id);
        } catch (\Throwable $e) {
            $this->failed($questionId, 'load the resolved question', $e);

            return;
        }

        if ($question === null || $conversation === null) {
            return;
        }

        try {
            $messageId = $question->display_message_id
                ?? Message::where('conversation_id', $conversation->id)->orderByDesc('created_at')->value('id');

            if ($messageId !== null) {
                event(new NewConversationMessageEvent($conversation->id, $messageId));
            }
        } catch (\Throwable $e) {
            $this->failed($questionId, 'broadcast the refresh', $e);
        }

        $channel = (string) ($conversation->channel ?? WebChannelDelivery::CHANNEL);

        if ($channel === WebChannelDelivery::CHANNEL) {
            // The web screen renders from server state; the broadcast above
            // is all it needs.
            return;
        }

        try {
            $adapter = $this->registry->for($channel);

            if ($adapter instanceof PresentsQuestions) {
                $adapter->presentResolution($conversation, QuestionOutcomeView::fromQuestion($question));
            }
        } catch (\Throwable $e) {
            $this->failed($questionId, 'show the outcome on its channel', $e);
        }
    }

    /**
     * The row as it is now, without firing model events: this runs inside
     * other operations' after-commit phase and must not re-enter listeners
     * that watch questions being loaded.
     */
    private function load(string $questionId): ?AgentQuestion
    {
        $row = DB::table('agent_questions')->where('id', $questionId)->first();

        if ($row === null) {
            return null;
        }

        $question = new AgentQuestion();
        $question->setRawAttributes((array) $row, true);
        $question->exists = true;

        return $question;
    }

    private function failed(string $questionId, string $what, \Throwable $e): void
    {
        Log::warning("QuestionPresentationUpdater: could not {$what}", [
            'question_id' => $questionId,
            'error' => get_class($e).': '.$e->getMessage(),
        ]);
    }
}
