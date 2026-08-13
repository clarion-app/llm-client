<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The result of AgentDivergenceChecker::check() (data-model.md §5) — plain
 * data, no logic. Computed fresh on every call, never stored (research.md
 * D9/D10).
 *
 * `governs` is always the literal 'stored_agent' whenever `state` is not
 * NotLinked (research.md D10 — the stored agent is always what actually
 * governs behavior, regardless of drift direction); null for NotLinked.
 * `liveFileHash` is null for NotLinked/Unavailable. `currentVersionHash` is
 * null only for NotLinked. `unavailableReason` is set only for
 * Unavailable.
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
