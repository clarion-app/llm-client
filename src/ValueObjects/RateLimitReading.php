<?php

namespace ClarionApp\LlmClient\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * A single read of the fixed-window request counter, returned by
 * RateLimitCounter::increment().
 *
 * `available = false` is the counter-store-unreadable case: every other
 * field is then genuinely null, never a fabricated zero. A fabricated zero
 * would read as "no requests yet" and could let a caller reason about a
 * count that was never actually measured.
 */
final readonly class RateLimitReading
{
    public function __construct(
        public ?int $count,
        public ?int $maxRequests,
        public ?int $windowSeconds,
        public ?CarbonImmutable $windowStart,
        public ?CarbonImmutable $resetsAt,
        public bool $available = true,
    ) {
    }

    /**
     * The counter could not be read or written. Every field but
     * `available` is genuinely null — not zero, not a guess.
     */
    public static function unavailable(): self
    {
        return new self(
            count: null,
            maxRequests: null,
            windowSeconds: null,
            windowStart: null,
            resetsAt: null,
            available: false,
        );
    }
}
