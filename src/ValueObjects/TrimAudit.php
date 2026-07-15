<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Aggregated audit record for a trimming operation.
 * Emitted as payload in the SmartHistoryTrimmed event.
 */
final class TrimAudit
{
    /**
     * @param string $conversationId Conversation identifier
     * @param int $messagesBefore Message count before trimming
     * @param int $messagesAfter Message count after trimming
     * @param int $tokensBefore Token count before trimming
     * @param int $tokensAfter Token count after trimming
     * @param list<TrimDecision> $decisions Per-message discard/retain decisions
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly int $messagesBefore,
        public readonly int $messagesAfter,
        public readonly int $tokensBefore,
        public readonly int $tokensAfter,
        public readonly array $decisions = [],
    ) {}
}
