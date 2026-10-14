<?php

namespace ClarionApp\LlmClient\ValueObjects;

use Carbon\CarbonInterface;

/**
 * The outcome of classifying a provider failure (provider-retry contract).
 * Exactly one of the shapes holds: fail (not transient: the caller fails as
 * it always did), deferred (a delayed job carries the retry), inline (the
 * nested caller sleeps between attempts), exhausted (the allowance is spent:
 * the run is already closed and reported) or lost (the caller no longer
 * holds the run: it stops without writing).
 */
final class RetryDecision
{
    private function __construct(
        private readonly ?CarbonInterface $deferredUntil,
        private readonly ?int $inlineDelaySeconds,
        private readonly string $shape = 'fail',
    ) {
    }

    /** The failure is not transient; fail as today. */
    public static function fail(): self
    {
        return new self(null, null, 'fail');
    }

    /** The allowance is spent: the scheduler has already closed and reported the run. */
    public static function exhausted(): self
    {
        return new self(null, null, 'exhausted');
    }

    /** The caller no longer holds the run: another process owns the decision. */
    public static function lost(): self
    {
        return new self(null, null, 'lost');
    }

    /** The root run defers: a delayed job retries at the named time. */
    public static function deferred(CarbonInterface $dueAt): self
    {
        return new self($dueAt, null, 'deferred');
    }

    /** A nested run retries inline after the named delay. */
    public static function inline(int $delaySeconds): self
    {
        return new self(null, $delaySeconds, 'inline');
    }

    public function deferredUntil(): ?CarbonInterface
    {
        return $this->deferredUntil;
    }

    public function inlineDelaySeconds(): ?int
    {
        return $this->inlineDelaySeconds;
    }

    public function isExhausted(): bool
    {
        return $this->shape === 'exhausted';
    }

    public function isLost(): bool
    {
        return $this->shape === 'lost';
    }
}
