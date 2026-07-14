<?php

namespace ClarionApp\LlmClient\Events;

/**
 * Dispatched when a tool result is condensed before entering agent context.
 *
 * Read-only signal — no listener registered in this phase.
 * Provides metrics data for cost analysis and optimization.
 */
class ToolResultCondensed
{
    public function __construct(
        public readonly string $conversationId,
        public readonly string $toolName,
        public readonly string $referenceId,
        public readonly int $originalTokens,
        public readonly int $condensedTokens,
        public readonly int $tokensSaved,
        public readonly string $method,
        public readonly bool $fallback,
    ) {}
}
