<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * One changed set-shaped setting between two agent versions (research.md
 * D6): capabilities, toolsAllow, toolsDeny, safetyConfirmationRequired,
 * safetyDenylist. Compared as sets — order carries no meaning.
 *
 * A field with no added/removed entries is omitted from
 * AgentVersionComparison::$listDifferences entirely — never included with
 * two empty arrays (FR-009).
 */
final readonly class AgentVersionListDifference
{
    /**
     * @param list<string> $added   present on the right, absent on the left
     * @param list<string> $removed present on the left, absent on the right
     */
    public function __construct(
        public string $field,   // e.g. "toolsAllow", "capabilities"
        public array $added,
        public array $removed,
    ) {}
}
