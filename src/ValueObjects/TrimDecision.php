<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Records a single discard or retain decision for debuggability.
 */
final class TrimDecision
{
    /**
     * @param int $messageIndex Position in original message array
     * @param string $action 'dropped' | 'retained' | 'dropped_cascade' | 'pinned_protected'
     * @param float $score Message score at time of decision
     * @param string $reason Human-readable explanation
     * @param int $tokenSavings Tokens freed by this decision (0 if retained)
     */
    public function __construct(
        public readonly int $messageIndex,
        public readonly string $action,
        public readonly float $score,
        public readonly string $reason,
        public readonly int $tokenSavings = 0,
    ) {
        $validActions = ['dropped', 'retained', 'dropped_cascade', 'pinned_protected'];
        if (!in_array($action, $validActions, true)) {
            throw new \InvalidArgumentException("Action must be one of: " . implode(', ', $validActions));
        }
    }
}
