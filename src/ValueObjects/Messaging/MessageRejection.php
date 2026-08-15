<?php

namespace ClarionApp\LlmClient\ValueObjects\Messaging;

/**
 * 107-agent-message-protocol, Phase 7 (US5), data-model.md §2.3 — the
 * outcome of AgentMessageService::send() when the envelope's
 * content/context/expectedResponse combined exceed
 * config('llm-client.messaging.max_message_bytes') (contracts §1 step 2).
 * Carries the measured size in bytes so a caller can report exactly how
 * far over the bound the attempt was, without re-measuring anything
 * itself — the persisted row's content/context/expected_response are all
 * null; nothing oversized is ever stored, truncated or otherwise.
 */
final class MessageRejection implements MessageOutcome
{
    public function __construct(
        public readonly string $agentMessageId,
        public readonly int $sizeBytes,
    ) {}

    public function status(): string
    {
        return 'rejected_oversized';
    }
}
