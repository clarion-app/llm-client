<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Lifecycle of an agent question. `pending` is the only non-terminal
 * state; each terminal state is reached at most once, through a single
 * compare-and-set transition.
 */
enum QuestionState: string
{
    case Pending = 'pending';
    case Answered = 'answered';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';
    case Undeliverable = 'undeliverable';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Whether reaching this state continues the paused run with another
     * model turn. A withdrawal ends the run instead.
     */
    public function continuesRun(): bool
    {
        return match ($this) {
            self::Answered, self::Expired, self::Undeliverable => true,
            self::Pending, self::Withdrawn => false,
        };
    }
}
