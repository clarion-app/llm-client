<?php

namespace ClarionApp\LlmClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rebuilds eval_pass_rate_summaries from eval_case_results (joined to
 * eval_runs for agent_label) — the backfill/reconciliation-repair tool for
 * the rollup table. Truncates first (the given agent's rows, or every
 * row) and then re-derives every (agent_label, period_date) bucket from
 * COALESCE(outcome_override, outcome) and created_at's own date — never
 * the raw outcome column alone. Running it twice in a row reproduces the
 * same counts, never doubled, since it rebuilds from scratch rather than
 * incrementing on top of whatever was already there.
 *
 * Usage:
 *   php artisan llm-client:recompute-eval-pass-rate-summaries [--agent-label=X] [--dry-run]
 */
class RecomputeEvalPassRateSummariesCommand extends Command
{
    protected $signature = 'llm-client:recompute-eval-pass-rate-summaries {--agent-label=} {--dry-run}';

    protected $description = 'Rebuild eval_pass_rate_summaries from eval_case_results, for one agent label or every agent';

    /** Rows pulled per source-scan chunk — memory stays flat as history grows. */
    private const SCAN_CHUNK_SIZE = 1000;

    private const OUTCOME_COLUMNS = [
        'pass' => 'pass_count',
        'fail' => 'fail_count',
        'needs_human_review' => 'needs_human_review_count',
        'errored' => 'errored_count',
        'unjudged' => 'unjudged_count',
    ];

    public function handle(): int
    {
        $agentLabel = $this->option('agent-label');
        $dryRun = (bool) $this->option('dry-run');

        $buckets = [];
        $scanned = 0;

        // Chunked, never one unbounded get(): this command exists to be run
        // against an installation that may already hold months of
        // eval_case_results history, so the source scan's memory cost must
        // stay flat regardless of how many rows it walks. Only the derived
        // buckets accumulate, and those are bounded by
        // (agent labels x days active), never by result volume.
        DB::table('eval_case_results')
            ->join('eval_runs', 'eval_runs.id', '=', 'eval_case_results.run_id')
            ->when($agentLabel !== null, fn ($query) => $query->where('eval_runs.agent_label', $agentLabel))
            ->select(
                'eval_runs.agent_label',
                'eval_case_results.outcome',
                'eval_case_results.outcome_override',
                'eval_case_results.created_at',
                'eval_case_results.id',
            )
            ->orderBy('eval_case_results.id')
            ->chunk(self::SCAN_CHUNK_SIZE, function ($rows) use (&$buckets, &$scanned) {
                $scanned += count($rows);
                $this->accumulateBuckets($rows, $buckets);
            });

        $this->info($scanned.' case result(s) scanned; '.count($buckets).' bucket(s) would be written.');

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($agentLabel, $buckets) {
            $truncate = DB::table('eval_pass_rate_summaries');

            if ($agentLabel !== null) {
                $truncate->where('agent_label', $agentLabel);
            }

            $truncate->delete();

            $now = now();

            foreach ($buckets as $bucket) {
                DB::table('eval_pass_rate_summaries')->insert(array_merge($bucket, [
                    'id' => (string) Str::uuid(),
                    'updated_at' => $now,
                ]));
            }
        });

        $this->info('eval_pass_rate_summaries rebuilt.');

        return self::SUCCESS;
    }

    /**
     * Folds one chunk of source rows into the running bucket set, keyed by
     * (agent_label, the result's own created_at date) and counted by
     * COALESCE(outcome_override, outcome) — never the raw outcome alone.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @param  array<string, array<string, mixed>>  $buckets
     */
    private function accumulateBuckets($rows, array &$buckets): void
    {
        foreach ($rows as $row) {
            $periodDate = Carbon::parse($row->created_at)->toDateString();
            $key = $row->agent_label.'|'.$periodDate;
            $effective = $row->outcome_override ?? $row->outcome;
            $column = self::OUTCOME_COLUMNS[$effective] ?? null;

            if ($column === null) {
                continue;
            }

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'agent_label' => $row->agent_label,
                    'period_date' => $periodDate,
                    'pass_count' => 0,
                    'fail_count' => 0,
                    'needs_human_review_count' => 0,
                    'errored_count' => 0,
                    'unjudged_count' => 0,
                    'total_count' => 0,
                ];
            }

            $buckets[$key][$column]++;
            $buckets[$key]['total_count']++;
        }
    }
}
