<?php

namespace Tests\Integration\Harness;

use Closure;

/**
 * T008: LaneRule — pure rule for reactive scripting.
 *
 * S3: Rules are pure — no internal counters, no clock, no randomness.
 * A rule's predicate and responder are functions of the captured request
 * and the current turn index only.
 */
class LaneRule
{
    public function __construct(
        public readonly RequestLane $lane,
        public readonly Closure $predicate,
        public readonly Closure $respond,
        public readonly string $label,
    ) {
    }

    /**
     * Check if this rule matches the given payload at the given turn.
     */
    public function matches(CapturedPayload $payload, int $turn): bool
    {
        return (bool) ($this->predicate)($payload, $turn);
    }

    /**
     * Produce a wire-shaped response for the given payload at the given turn.
     *
     * @return array<string, mixed>
     */
    public function respond(CapturedPayload $payload, int $turn): array
    {
        return ($this->respond)($payload, $turn);
    }
}
