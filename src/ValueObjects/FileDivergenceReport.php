<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The result of AgentDivergenceChecker::check() (data-model.md §5) — plain
 * data, no logic. Computed fresh on every call, never stored (research.md
 * D9/D10).
 *
 * `governs` is the literal 'stored_agent' for the four "linked and
 * successfully checked" states (InStep/FileAhead/StoredAhead/BothChanged
 * — research.md D10, never varies with drift direction); null for
 * NotLinked and for Unavailable alike (contracts §10's own worked
 * examples), since neither has a meaningful drift direction to report
 * governance for. `liveFileHash` is null for NotLinked/Unavailable.
 * `currentVersionHash` is null only for NotLinked. `unavailableReason` is
 * set only for Unavailable.
 */
final readonly class FileDivergenceReport
{
    public function __construct(
        public DivergenceState $state,
        public ?string $governs,
        public ?string $liveFileHash,
        public ?string $currentVersionHash,
        public \DateTimeInterface $checkedAt,
        public ?string $unavailableReason = null,
    ) {
    }
}
