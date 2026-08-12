<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * What a scope currently has held in reservation — the reservation-side
 * twin of ConsumptionSnapshot, read by ReservationLedger and consumed by
 * BudgetGate::assess() (data-model.md §3).
 *
 * Deliberately smaller than ConsumptionSnapshot: no period, no reset time,
 * no unpriced/estimated disclosure fields, because a reservation carries
 * none of those concepts (research.md D3 — it is not period-bucketed, and
 * D8's unpriced handling resolves entirely at the estimation step, before a
 * reservation is ever attempted).
 *
 * Unlike ConsumptionSnapshot's toArray(), whose available=false omits its
 * numeric fields entirely, ReservationSnapshot's smaller shape always
 * includes both `amount` and `available` — `amount` is simply null when
 * unavailable (contracts §1: "held.available = false... and
 * held.amount = null").
 */
final readonly class ReservationSnapshot
{
    public function __construct(
        public ?string $amount,
        public bool $available = true,
    ) {
    }

    /**
     * The figure could not be read.
     */
    public static function unavailable(): self
    {
        return new self(
            amount: null,
            available: false,
        );
    }

    public function toArray(): array
    {
        return [
            'currency' => (string) config('llm-client.cost.currency', 'USD'),
            'amount' => $this->amount,
            'available' => $this->available,
        ];
    }
}
