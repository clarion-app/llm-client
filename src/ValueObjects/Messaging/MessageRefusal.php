<?php

namespace ClarionApp\LlmClient\ValueObjects\Messaging;

/**
 * The outcome of AgentMessageService::send() when the envelope fails
 * structural validation — missing sender, missing recipient, empty
 * content, or a blank expected response (107-agent-message-protocol,
 * data-model.md §2.3, contracts §1 step 1). `reason` is one of
 * `missing_sender`|`missing_recipient`|`missing_content`|
 * `missing_expected_response`.
 */
final class MessageRefusal implements MessageOutcome
{
    public function __construct(
        public readonly string $agentMessageId,
        public readonly string $reason,
    ) {
    }

    public function status(): string
    {
        return 'refused';
    }
}
