<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\Contracts\PresentsQuestions;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\ValueObjects\ChannelQuestionCapabilities;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
use ClarionApp\LlmClient\ValueObjects\QuestionOutcomeView;
use ClarionApp\LlmClient\ValueObjects\QuestionPresentation;

/**
 * The built-in `web` channel. The text has already been stored as a
 * conversation message, so delivering it only tells an open conversation
 * screen to refetch: it broadcasts NewConversationMessageEvent for the
 * conversation's latest message.
 *
 * It presents questions the same way: the screen renders a pending
 * question as a form from the question's stored state, so presenting one
 * is the same refetch broadcast, with no limit on options or label length.
 * A resolution needs nothing more than the refresh every resolution
 * already broadcasts.
 */
class WebChannelDelivery implements ConversationChannelDelivery, PresentsQuestions
{
    public const CHANNEL = 'web';

    public function channel(): string
    {
        return self::CHANNEL;
    }

    public function deliver(Conversation $conversation, string $text): DeliveryResult
    {
        try {
            $latestId = Message::where('conversation_id', $conversation->id)
                ->orderByDesc('created_at')
                ->value('id');

            if ($latestId !== null) {
                event(new NewConversationMessageEvent($conversation->id, $latestId));
            }

            return DeliveryResult::ok();
        } catch (\Throwable $e) {
            return DeliveryResult::failed(get_class($e).': '.$e->getMessage());
        }
    }

    public function questionCapabilities(): ChannelQuestionCapabilities
    {
        return ChannelQuestionCapabilities::unlimited();
    }

    public function presentQuestion(Conversation $conversation, QuestionPresentation $presentation): DeliveryResult
    {
        return $this->deliver($conversation, $presentation->bodyText);
    }

    public function presentResolution(Conversation $conversation, QuestionOutcomeView $outcome): void
    {
    }
}
