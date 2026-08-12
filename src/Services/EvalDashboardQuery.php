<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The two read paths behind the dashboard overview: currentPassRate()
 * reuses EvalRunService::summarize()'s already-tested effective-outcome
 * rule for the agent's most recently Completed run — no rollup read at
 * all — and trend() reads eval_pass_rate_summaries only, scoped to a
 * clamped window, so its cost is O(days in window), never O(results ever
 * recorded).
 */
class EvalDashboardQuery
{
    public function __construct(
        private readonly EvalRunService $runService,
    ) {
    }

    /**
     * The pass rate of the agent's most recently completed run, or null
     * when no such run exists — an in_progress/incomplete run has no
     * final, stable outcome set yet and is never consulted for "current".
     *
     * @return array{run_id: string, pass_rate: float, pass_count: int, fail_count: int, errored_count: int, needs_human_review_count: int, unjudged_count: int, completed_at: ?string}|null
     */
    public function currentPassRate(string $agentLabel): ?array
    {
        $run = EvalRun::where('agent_label', $agentLabel)
            ->where('status', EvalRunStatus::Completed)
            ->orderByDesc('completed_at')
            ->first();

        if ($run === null) {
            return null;
        }

        $summary = $this->runService->summarize($run);

        // pass / (pass + fail + errored) — errored counts against the
        // agent (a real quality signal), needs_human_review/unjudged are
        // excluded from both the numerator and the denominator (they are
        // pending/indeterminate, never folded into a pass or fail count).
        $denominator = $summary['pass'] + $summary['fail'] + $summary['errored'];
        $passRate = $denominator > 0 ? round($summary['pass'] / $denominator, 4) : 0.0;

        return [
            'run_id' => $run->id,
            'pass_rate' => $passRate,
            'pass_count' => $summary['pass'],
            'fail_count' => $summary['fail'],
            'errored_count' => $summary['errored'],
            'needs_human_review_count' => $summary['needs_human_review'],
            'unjudged_count' => $summary['unjudged'],
            'completed_at' => optional($run->completed_at)->toJSON(),
        ];
    }

    /**
     * One query against eval_pass_rate_summaries only, scoped to
     * (agent_label, period_date BETWEEN start AND end). The requested
     * window is clamped to config('llm-client.eval_dashboard.max_trend_window_days')
     * regardless of what the caller asks for. A day with zero activity is
     * simply absent, never a zero-row.
     *
     * @return array<int, array{period_date: string, pass_count: int, fail_count: int, needs_human_review_count: int, errored_count: int, unjudged_count: int, total_count: int}>
     */
    public function trend(string $agentLabel, int $windowDays): array
    {
        $maxWindowDays = (int) config('llm-client.eval_dashboard.max_trend_window_days', 180);
        $clampedWindowDays = max(1, min($windowDays, $maxWindowDays));

        $end = Carbon::now()->toDateString();
        $start = Carbon::now()->subDays($clampedWindowDays)->toDateString();

        return DB::table('eval_pass_rate_summaries')
            ->where('agent_label', $agentLabel)
            ->whereBetween('period_date', [$start, $end])
            ->orderBy('period_date')
            ->get()
            ->map(fn ($row) => [
                'period_date' => Carbon::parse($row->period_date)->toDateString(),
                'pass_count' => (int) $row->pass_count,
                'fail_count' => (int) $row->fail_count,
                'needs_human_review_count' => (int) $row->needs_human_review_count,
                'errored_count' => (int) $row->errored_count,
                'unjudged_count' => (int) $row->unjudged_count,
                'total_count' => (int) $row->total_count,
            ])
            ->all();
    }
}
