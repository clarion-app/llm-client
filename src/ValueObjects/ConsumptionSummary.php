<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * What one eval run has consumed — cost, tokens, tool invocations,
 * processing time (data-model.md §6, research.md D11). No storage of its
 * own: a pure data carrier, populated at read time by
 * EvalRunConsumptionQuery from the run's own case conversations, whether
 * the run is in_progress or completed (FR-011).
 */
final class ConsumptionSummary
{
    public function __construct(
        // A PlainDecimalCast-style plain-decimal string, never a float
        // (076's D1 precedent) — no float re-enters the pipeline here.
        public readonly string $totalCost,
        public readonly int $totalTokens,
        public readonly int $toolInvocationCount,
        // Sum of each case's agent_runs.duration_ms (062).
        public readonly int $totalDurationMs,
        // True if any contributing UsageRecord.cost_unpriced is true (076
        // precedent — never silently drop the caveat).
        public readonly bool $costUnpriced,
        // What rubric-based judging itself consumed for this run, kept
        // entirely separate from the five agent-under-test fields above —
        // never merged into them. Defaulted so no existing call site
        // building a ConsumptionSummary without these breaks.
        public readonly string $judgingCost = '0.0000000000',
        public readonly int $judgingTokens = 0,
        public readonly int $judgingInvocationCount = 0,
        public readonly bool $judgingCostUnpriced = false,
    ) {
    }
}
