<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Terminal and non-terminal states for an agent run or step.
 */
enum RunEndState: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case StoppedEarly = 'stopped_early';
    case Abandoned = 'abandoned';

    /**
     * Whether this state is terminal (no further transitions allowed).
     */
    public function isTerminal(): bool
    {
        return $this !== self::InProgress;
    }

    /**
     * Whether a reason string is required when closing in this state.
     */
    public function requiresReason(): bool
    {
        return match ($this) {
            self::Failed, self::StoppedEarly, self::Abandoned => true,
            default => false,
        };
    }
}
