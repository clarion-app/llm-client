<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\Contracts\PresentsQuestions;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
use ClarionApp\LlmClient\ValueObjects\PresentationFallbackReason;
use ClarionApp\LlmClient\ValueObjects\PresentationMode;
use ClarionApp\LlmClient\ValueObjects\QuestionPresentation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a newly opened question to its user's channel: with its choices
 * as controls when the channel can present them, otherwise as the
 * plain-text question. A rich send that fails (or throws) is followed at
 * once by the plain text, so a question is undeliverable only when the
 * plain-text attempt itself failed. How the question was presented, and
 * why plain text was used, is recorded on its row and in the waiting
 * `ask_user` run action.
 */
class QuestionPresenter
{
    public function __construct(
        private readonly ConversationChannelRegistry $registry,
        private readonly QuestionPresentationPlanner $planner,
        private readonly RunTraceRecorder $recorder,
    ) {
    }

    /**
     * @param  string  $plainText  the plain-text question, exactly as stored
     *   in the conversation's display message
     * @return DeliveryResult the result of the last attempt; never throws
     */
    public function deliverQuestion(Conversation $conversation, AgentQuestion $question, string $plainText, ?string $preamble): DeliveryResult
    {
        $channel = (string) ($conversation->channel ?? 'web');
        $adapter = $this->registry->for($channel);

        if ($adapter === null) {
            $this->record($question, PresentationMode::PlainText, PresentationFallbackReason::ChannelLacksOptions);

            return DeliveryResult::failed("no delivery is registered for the '{$channel}' channel");
        }

        $plan = $this->planner->plan($question, $adapter, $preamble);
        $fallbackReason = $plan->fallbackReason;

        if ($plan->mode === PresentationMode::Rich && $adapter instanceof PresentsQuestions && $plan->presentation !== null) {
            $rich = $this->presentRichly($adapter, $conversation, $plan->presentation);

            if ($rich->delivered) {
                $this->record($question, PresentationMode::Rich, null);

                return $rich;
            }

            Log::warning('QuestionPresenter: the question could not be presented with options; sending it as plain text', [
                'question_id' => $question->id,
                'conversation_id' => $conversation->id,
                'channel' => $channel,
                'reason' => $rich->failureReason,
            ]);

            $fallbackReason = PresentationFallbackReason::RichSendRejected;
        }

        $plain = $this->deliverPlainly($adapter, $conversation, $plainText);
        $this->record($question, PresentationMode::PlainText, $fallbackReason ?? PresentationFallbackReason::ChannelLacksOptions);

        return $plain;
    }

    private function presentRichly(PresentsQuestions $adapter, Conversation $conversation, QuestionPresentation $presentation): DeliveryResult
    {
        try {
            return $adapter->presentQuestion($conversation, $presentation);
        } catch (\Throwable $e) {
            return DeliveryResult::failed(get_class($e).': '.$e->getMessage());
        }
    }

    private function deliverPlainly(ConversationChannelDelivery $adapter, Conversation $conversation, string $text): DeliveryResult
    {
        try {
            return $adapter->deliver($conversation, $text);
        } catch (\Throwable $e) {
            return DeliveryResult::failed(get_class($e).': '.$e->getMessage());
        }
    }

    private function record(AgentQuestion $question, PresentationMode $mode, ?PresentationFallbackReason $reason): void
    {
        DB::table('agent_questions')->where('id', $question->id)->update([
            'presentation' => $mode->value,
            'presentation_fallback_reason' => $reason?->value,
        ]);

        $question->presentation = $mode;
        $question->presentation_fallback_reason = $reason;
        $question->syncOriginalAttributes(['presentation', 'presentation_fallback_reason']);

        $this->rewriteWaitingAction($question);
    }

    /**
     * Shows how the question was presented in its `ask_user` run action
     * while the run waits. The recorder only rewrites an action that is
     * still waiting, so an already resolved record is left alone.
     */
    private function rewriteWaitingAction(AgentQuestion $question): void
    {
        try {
            $this->recorder->updateSuspendedActionContent($question->action_id, $question->fresh()?->runRecordSnapshot() ?? $question->runRecordSnapshot());
        } catch (\Throwable $e) {
            Log::warning('QuestionPresenter: could not show the question\'s presentation in its run record', [
                'question_id' => $question->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
