<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;

/**
 * The built-in `web` channel. The text has already been stored as a
 * conversation message, so delivering it only tells an open conversation
 * screen to refetch: it broadcasts NewConversationMessageEvent for the
 * conversation's latest message.
 */
class WebChannelDelivery implements ConversationChannelDelivery
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
}
