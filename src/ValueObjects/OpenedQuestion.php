<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Message;

/**
 * A question that was opened: its row, the message showing it in the
 * conversation, and how delivery went. `resumeResult` is set when delivery
 * failed on the sync path and the run was resumed inline as undeliverable.
 */
final readonly class OpenedQuestion
{
    public function __construct(
        public AgentQuestion $question,
        public Message $displayMessage,
        public DeliveryResult $delivery,
        public ?array $resumeResult = null,
    ) {
    }
}
