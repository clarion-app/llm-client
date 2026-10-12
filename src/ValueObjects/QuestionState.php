<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Lifecycle of an agent question.
 *
 * Two states are waiting states: `pending` (inside the answerable period)
 * and `stopped` (a stop-policy question whose period passed; the task
 * waits for a late answer or for the conversation to be ended). Both keep
 * the conversation's question slot and both accept the owner's answer.
 * Every other state is terminal; each is reached at most once, through a
 * single compare-and-set transition.
 *
 * `expired` is reached only by questions asked before unanswered policies
 * existed; a proceed-policy question that expires becomes
 * `expired_assumed`.
 */
enum QuestionState: string
{
    case Pending = 'pending';
    case Answered = 'answered';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';
    case Undeliverable = 'undeliverable';
    case ExpiredAssumed = 'expired_assumed';
    case Stopped = 'stopped';

    public function isTerminal(): bool
    {
        return !in_array($this, [self::Pending, self::Stopped], true);
    }

    /** Whether the question's owner may still answer it. */
    public function acceptsAnswers(): bool
    {
        return $this === self::Pending || $this === self::Stopped;
    }

    /** Whether the question still occupies its conversation's single question slot. */
    public function holdsSlot(): bool
    {
        return $this === self::Pending || $this === self::Stopped;
    }

    /**
     * Whether reaching this state continues the paused run with another
     * model turn. A withdrawal ends the run instead; a waiting state
     * continues nothing yet.
     */
    public function continuesRun(): bool
    {
        return match ($this) {
            self::Answered, self::Expired, self::Undeliverable, self::ExpiredAssumed => true,
            self::Pending, self::Stopped, self::Withdrawn => false,
        };
    }
}
