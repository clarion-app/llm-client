<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The result of comparing two versions of the same agent — which settings
 * differ between them and how, with unchanged settings excluded from the
 * result (data-model.md §2). Computed fresh on every call by
 * AgentVersionComparer::compare() — never stored.
 */
final readonly class AgentVersionComparison
{
    /**
     * $identical is true exactly when both $fieldDifferences and
     * $listDifferences are empty (FR-008/SC-004) — it is never a
     * separately-tracked flag that could drift from the arrays' own
     * contents; it is always computed by the caller from those same
     * arrays, never independently settable.
     *
     * @param list<AgentVersionFieldDifference> $fieldDifferences
     * @param list<AgentVersionListDifference> $listDifferences
     */
    public function __construct(
        public string $leftVersionId,
        public string $rightVersionId,
        public bool $identical,
        public array $fieldDifferences,
        public array $listDifferences,
    ) {}
}
