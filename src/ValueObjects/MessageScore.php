<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Score result for a single message or turn unit.
 */
final class MessageScore
{
    /**
     * @param int $messageIndex Position in original message array
     * @param float $score 0.0 (drop first) to 1.0 (keep)
     * @param string $reason Human-readable classification
     * @param array<int> $dependsOn Indices this message depends on (tool_call ↔ tool_result links)
     * @param bool $pinned User-pinned content (exempt from trimming)
     */
    public function __construct(
        public readonly int $messageIndex,
        public readonly float $score,
        public readonly string $reason,
        public readonly array $dependsOn = [],
        public readonly bool $pinned = false,
    ) {
        // Clamp score to valid range
        $clamped = max(0.0, min(1.0, $score));
        if ($clamped !== $score) {
            throw new \InvalidArgumentException("Score must be between 0.0 and 1.0, got {$score}");
        }
    }
}
