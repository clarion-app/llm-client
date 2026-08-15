<?php

namespace ClarionApp\LlmClient\ValueObjects\Messaging;

/**
 * The happy-path outcome of AgentMessageService::send() — the message was
 * structurally valid, within the size bound, and its recipient was
 * available (107-agent-message-protocol, data-model.md §2.3).
 */
final class MessageDelivered implements MessageOutcome
{
    public function __construct(
        public readonly string $agentMessageId,
    ) {
    }

    public function status(): string
    {
        return 'delivered';
    }
}
