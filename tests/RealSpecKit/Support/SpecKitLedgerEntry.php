<?php

namespace Tests\RealSpecKit\Support;

/**
 * A single observed outcome for one (aiTarget, commandName) pair
 * (data-model.md §3). Immutable once observed -- SpecKitOutcomeLedger
 * enforces the write-once contract, not this value object itself.
 */
final readonly class SpecKitLedgerEntry
{
    public function __construct(
        public string $aiTarget,
        public string $commandName,
        public SpecKitCommandOutcome $outcome,
        public ?string $detail,
    ) {
    }
}
