<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Support\Decimal;
use ClarionApp\LlmClient\ValueObjects\ConsumptionSummary;

/**
 * Pure read-time aggregation of what one eval run has consumed
 * (data-model.md §6, research.md D11). No new write path, no cache — every
 * call re-derives the figure from UsageRecord/ToolInvocationRecord/
 * agent_runs, so it is identical whether the run is still in_progress or
 * already completed (FR-011).
 *
 * Scoped by the run's own case conversation_ids, resolved via
 * EvalCaseResult::where('run_id', ...)->pluck('conversation_id') — never by
 * user_id. Every eval-run conversation carries the structurally-identical
 * user_id = '' ((string) null === ''), so filtering by user_id would sum
 * every eval run ever executed, not just this one (mutation-checklist
 * row 12).
 */
class EvalRunConsumptionQuery
{
    /** Column scale usage_records.total_cost is written/read at (PlainDecimalCast). */
    private const COST_SCALE = 10;

    public function summarize(EvalRun $run): ConsumptionSummary
    {
        $conversationIds = EvalCaseResult::where('run_id', $run->id)
            ->pluck('conversation_id')
            ->unique()
            ->values()
            ->all();

        if (empty($conversationIds)) {
            return new ConsumptionSummary(
                totalCost: Decimal::round('0', self::COST_SCALE),
                totalTokens: 0,
                toolInvocationCount: 0,
                totalDurationMs: 0,
                costUnpriced: false,
            );
        }

        $usage = UsageRecord::whereIn('conversation_id', $conversationIds)
            ->selectRaw('SUM(total_tokens) as tokens, SUM(total_cost) as cost, MAX(cost_unpriced) as any_unpriced')
            ->first();

        $toolInvocationCount = ToolInvocationRecord::whereIn('conversation_id', $conversationIds)->count();

        $totalDurationMs = (int) AgentRun::whereIn('conversation_id', $conversationIds)->sum('duration_ms');

        return new ConsumptionSummary(
            // fromNumeric()/round(), not (string) — SUM() over a decimal
            // column comes back from SQLite as a float (the PlainDecimalCast
            // docblock's own warning); rounding to the column's own scale
            // guarantees an exact plain-decimal string either way.
            totalCost: Decimal::round(Decimal::fromNumeric($usage->cost ?? '0'), self::COST_SCALE),
            totalTokens: (int) ($usage->tokens ?? 0),
            toolInvocationCount: $toolInvocationCount,
            totalDurationMs: $totalDurationMs,
            costUnpriced: (bool) ($usage->any_unpriced ?? false),
        );
    }
}
