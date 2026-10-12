<?php

namespace ClarionApp\LlmClient\Contracts;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;

/**
 * Delivers text to a conversation's user on one channel. Implemented once
 * per channel and registered with ConversationChannelRegistry.
 */
interface ConversationChannelDelivery
{
    /** The conversations.channel value this adapter serves, e.g. "web", "telegram". */
    public function channel(): string;

    /**
     * Deliver already-persisted, already-rendered plain text to the conversation's user on this channel.
     * MUST NOT persist messages (llm-client already did). MUST NOT throw: failures are returned.
     * MUST deliver the full text even when it exceeds the channel's single-message limit.
     */
    public function deliver(Conversation $conversation, string $text): DeliveryResult;
}
