<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The sole writer of eval_pass_rate_summaries — a day-bucketed rollup of an
 * agent's pass/fail/needs_human_review/errored/unjudged counts, upserted
 * with the same insertOrIgnore + atomic column = column + n idiom
 * MetricsRecorder::upsertCostSummary()/upsertToolReliabilitySummary()
 * already use for cost_summaries/tool_reliability_summaries.
 *
 * Two call sites, each wrapped in its own inner try/catch at the caller:
 * EvalCaseExecutor::recordResult() (a fresh result was just written) and
 * EvalJudgmentOverrideService::recomputeCaseOutcome() (a case's effective
 * outcome just changed). A rollup failure must never undo or mask the
 * write it reports on.
 */
class EvalPassRateRollupService
{
    private const OUTCOME_COLUMNS = [
        'pass' => 'pass_count',
        'fail' => 'fail_count',
        'needs_human_review' => 'needs_human_review_count',
        'errored' => 'errored_count',
        'unjudged' => 'unjudged_count',
    ];

    /**
     * Increments the bucket for ($run->agent_label, $result->created_at's
     * own date) at the column matching $result->outcome->value — never
     * outcome_override, since a freshly recorded result has none — plus
     * total_count.
     */
    public function recordResult(EvalRun $run, EvalCaseResult $result): void
    {
        $column = self::OUTCOME_COLUMNS[$result->outcome->value] ?? null;

        if ($column === null) {
            return;
        }

        $periodDate = $result->created_at->toDateString();

        $this->ensureBucket($run->agent_label, $periodDate);

        DB::table('eval_pass_rate_summaries')
            ->where('agent_label', $run->agent_label)
            ->where('period_date', $periodDate)
            ->update([
                $column => DB::raw("{$column} + 1"),
                'total_count' => DB::raw('total_count + 1'),
                'updated_at' => now(),
            ]);
    }

    /**
     * Moves one case's contribution from its old effective-outcome column
     * to its new one, at the original result's own date (via its run()
     * relation) — never "now" at the moment the override itself is
     * written. total_count is unchanged: the case is still exactly one
     * result. A no-op when the two outcomes are already equal.
     */
    public function adjustForOverride(EvalCaseResult $caseResult, string $oldEffective, string $newEffective): void
    {
        if ($oldEffective === $newEffective) {
            return;
        }

        $oldColumn = self::OUTCOME_COLUMNS[$oldEffective] ?? null;
        $newColumn = self::OUTCOME_COLUMNS[$newEffective] ?? null;

        if ($oldColumn === null || $newColumn === null) {
            return;
        }

        $agentLabel = $caseResult->run->agent_label;
        $periodDate = $caseResult->created_at->toDateString();

        $this->ensureBucket($agentLabel, $periodDate);

        DB::table('eval_pass_rate_summaries')
            ->where('agent_label', $agentLabel)
            ->where('period_date', $periodDate)
            ->update([
                $oldColumn => DB::raw("{$oldColumn} - 1"),
                $newColumn => DB::raw("{$newColumn} + 1"),
                'updated_at' => now(),
            ]);
    }

    /**
     * insertOrIgnore is a no-op when a row for this bucket already exists
     * (the unique(agent_label, period_date) constraint), so a concurrent
     * create by another request cannot produce a duplicate.
     */
    private function ensureBucket(string $agentLabel, string $periodDate): void
    {
        DB::table('eval_pass_rate_summaries')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'agent_label' => $agentLabel,
            'period_date' => $periodDate,
            'pass_count' => 0,
            'fail_count' => 0,
            'needs_human_review_count' => 0,
            'errored_count' => 0,
            'unjudged_count' => 0,
            'total_count' => 0,
            'updated_at' => now(),
        ]);
    }
}
