<?php

namespace ClarionApp\LlmClient\Events;

/**
 * Dispatched when the context window budgeter trims or truncates history.
 *
 * Read-only signal — no listener registered in this phase.
 * Gives Phase 2.5 metrics a ready attach point.
 */
class ContextWindowTrimmed
{
    public function __construct(
        public readonly string $conversationId,
        public readonly ?string $model,
        public readonly string $provider,
        public readonly string $budgetSource,
        public readonly int $context,
        public readonly int $historyBudget,
        public readonly int $tokensBefore,
        public readonly int $tokensAfter,
        public readonly int $messagesBefore,
        public readonly int $messagesAfter,
        public readonly int $unitsDropped,
        public readonly bool $truncated,
    ) {}
}
