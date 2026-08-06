<?php

namespace ClarionApp\LlmClient\ValueObjects;

enum ActionOutcome: string
{
    case InProgress           = 'in_progress';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Success              = 'success';
    case Failure              = 'failure';
    case Unfinished           = 'unfinished';

    /**
     * Whether this state is terminal (no further transitions allowed).
     *
     * AwaitingConfirmation is suspended, not terminal — it may still
     * transition to Success or Failure on resume.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Success, self::Failure, self::Unfinished => true,
            default => false,
        };
    }

    /**
     * Whether a failure_reason string is required when closing in this state.
     */
    public function requiresReason(): bool
    {
        return $this === self::Failure;
    }

    /**
     * True for InProgress and AwaitingConfirmation — states closeAction() may transition.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::InProgress, self::AwaitingConfirmation => true,
            default => false,
        };
    }

    /**
     * True only for AwaitingConfirmation — exempt from the timeout and the run-close flush.
     */
    public function isExemptFromSweep(): bool
    {
        return $this === self::AwaitingConfirmation;
    }
}
