<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;

/**
 * Maps a conversation's channel name to the adapter that can deliver text
 * on it. A channel with no registered adapter cannot be reached, so an
 * agent in such a conversation cannot ask its user a question.
 *
 * Bound as a container singleton while llm-client registers (not boots),
 * so another package can add its adapter from its own boot() whatever
 * order providers boot in.
 */
class ConversationChannelRegistry
{
    /** @var array<string, ConversationChannelDelivery> */
    private array $adapters = [];

    public function register(ConversationChannelDelivery $delivery): void
    {
        $this->adapters[$delivery->channel()] = $delivery;
    }

    public function has(string $channel): bool
    {
        return isset($this->adapters[$channel]);
    }

    public function for(string $channel): ?ConversationChannelDelivery
    {
        return $this->adapters[$channel] ?? null;
    }
}
