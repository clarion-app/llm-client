<?php

namespace ClarionApp\LlmClient\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * A single read of the fixed-window conversation work counter, returned by
 * ConversationWorkCounter::increment().
 *
 * `available = false` is the counter-store-unreadable case: every other
 * field is then genuinely null, never a fabricated zero. A fabricated zero
 * would read as "no work done yet" and could let a caller reason about a
 * count that was never actually measured.
 *
 * `maxWorkUnits` is left null by every reading this class's own counter
 * produces — the counter never receives a ConversationWorkCeiling row.
 * ConversationWorkGate pairs the reading with the resolved ceiling
 * separately.
 */
final readonly class ConversationWorkReading
{
    public function __construct(
        public ?int $count,
        public ?int $maxWorkUnits,
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
            maxWorkUnits: null,
            windowSeconds: null,
            windowStart: null,
            resetsAt: null,
            available: false,
        );
    }
}
