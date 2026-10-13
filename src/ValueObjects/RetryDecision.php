<?php

namespace ClarionApp\LlmClient\ValueObjects;

use Carbon\CarbonInterface;

/**
 * The outcome of classifying a provider failure (provider-retry contract).
 * Exactly one of the three shapes holds: fail (not transient), deferred
 * (a delayed job carries the retry), or inline (the nested caller sleeps
 * between attempts).
 */
final class RetryDecision
{
    private function __construct(
        private readonly ?CarbonInterface $deferredUntil,
        private readonly ?int $inlineDelaySeconds,
    ) {
    }

    /** The failure is not transient; fail as today. */
    public static function fail(): self
    {
        return new self(null, null);
    }

    /** The root run defers: a delayed job retries at the named time. */
    public static function deferred(CarbonInterface $dueAt): self
    {
        return new self($dueAt, null);
    }

    /** A nested run retries inline after the named delay. */
    public static function inline(int $delaySeconds): self
    {
        return new self(null, $delaySeconds);
    }

    public function deferredUntil(): ?CarbonInterface
    {
        return $this->deferredUntil;
    }

    public function inlineDelaySeconds(): ?int
    {
        return $this->inlineDelaySeconds;
    }
}
