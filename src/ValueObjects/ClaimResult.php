<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The outcome of `RunLease::claim()` (lease contract §1). A `Won` names
 * the process nonce that now holds the lease, whether the claim was a
 * takeover (it replaced a live or sweep-marked lease), and whether this
 * claim itself detected the interruption (it replaced an expired,
 * still-held lease that no sweep had processed). A `Lost` names the
 * reason the claim did not win.
 */
final class ClaimResult
{
    /** The four reasons a claim loses (lease contract §1), nothing else. */
    public const LOST_REASONS = ['taken', 'stale_dispatch', 'terminal', 'cancelled'];

    private function __construct(
        public readonly bool $won,
        public readonly ?string $nonce,
        public readonly bool $takeover,
        public readonly bool $detectedHere,
        public readonly ?string $reason,
    ) {
    }

    public static function won(string $nonce, bool $takeover, bool $detectedHere): self
    {
        return new self(true, $nonce, $takeover, $detectedHere, null);
    }

    public static function lost(string $reason): self
    {
        return new self(false, null, false, false, $reason);
    }
}
