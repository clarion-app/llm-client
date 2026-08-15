<?php

namespace ClarionApp\LlmClient\ValueObjects\Messaging;

/**
 * Marker interface for the four possible results of
 * AgentMessageService::send() (107-agent-message-protocol, data-model.md
 * §2.3, research.md D6): MessageDelivered, MessageRefusal, MessageRejection,
 * MessageUnavailabilityReport. Every variant carries `agentMessageId` — the
 * id of the row send() always persists, regardless of outcome — so a caller
 * (or a test) can look the attempt back up via AgentMessageQuery
 * immediately, without a separate success/failure code path for "was it
 * recorded."
 */
interface MessageOutcome
{
    public function status(): string;
}
