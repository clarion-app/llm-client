<?php

namespace ClarionApp\LlmClient\ValueObjects\Messaging;

/**
 * 107-agent-message-protocol, Phase 8 (US6), data-model.md §2.3 — the
 * outcome of AgentMessageService::send() when structural validation and
 * the size bound have both already passed, but the addressed recipient
 * cannot be reached: `AgentQuery::findAgent()` (research.md D8, reused
 * unchanged) either found nothing owned by the caller (`reason =
 * 'not_found'` — no distinguishable leak between "doesn't exist" and
 * "belongs to someone else," matching findAgent()'s own established
 * contract) or found an agent whose `is_active` is false (`reason =
 * 'inactive'`). Either way the persisted row stores the message's full
 * content/context/expected_response — the message itself was well-formed
 * and within bounds, only its destination is unreachable (contracts §1
 * step 3).
 */
final class MessageUnavailabilityReport implements MessageOutcome
{
    public function __construct(
        public readonly string $agentMessageId,
        public readonly string $reason,
    ) {}

    public function status(): string
    {
        return 'unavailable';
    }
}
