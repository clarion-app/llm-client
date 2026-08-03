<?php

namespace ClarionApp\LlmClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to purge expired agent run traces.
 *
 * Deletes steps and message associations for runs older than the configured
 * retention period, then deletes the runs themselves. Child rows (steps and
 * associations) are always deleted before their parent runs to avoid foreign
 * key violations.
 *
 * Usage:
 *   php artisan llm-client:purge-run-traces [--days=90] [--dry-run]
 */
class PurgeExpiredRunTracesCommand extends Command
{
    protected $signature = 'llm-client:purge-run-traces
                            {--days= : Retention period in days (default: from config)}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Purge expired agent run traces (runs, steps, and message associations)';

    /** Runs deleted per pass. Keeps each `whereIn` well inside SQLite's 999-parameter cap. */
    private const CHUNK_SIZE = 500;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('llm-client.run_trace.retention_days', 90));
        $dryRun = (bool) $this->option('dry-run');

        $cutoffDate = now()->subDays($days);

        $this->info("Retention period: {$days} days (cutoff: {$cutoffDate->toDateTimeString()})");
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        $totalExpiredRuns = $this->expiredRunsQuery($cutoffDate)->count();

        if ($totalExpiredRuns === 0) {
            $this->info('No expired run traces to purge');
            if ($dryRun) {
                $this->comment('Dry-run complete — no changes were made');
            }
            return self::SUCCESS;
        }

        $this->info("Found {$totalExpiredRuns} expired run(s) to purge");

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
            return self::SUCCESS;
        }

        // Deleted in chunks (contract §5): after a full retention period this table
        // can hold far more ids than belong in one `whereIn` — SQLite caps bound
        // parameters at 999 by default, and MySQL is bounded by max_allowed_packet.
        // Materialising the whole id list would also hold it all in memory at once.
        $deletedRuns = 0;
        $deletedSteps = 0;
        $deletedAssociations = 0;

        do {
            $chunk = $this->expiredRunsQuery($cutoffDate)
                ->limit(self::CHUNK_SIZE)
                ->pluck('id')
                ->all();

            if (empty($chunk)) {
                break;
            }

            // Children before parents, so a purge interrupted midway never leaves
            // a step or association pointing at a run that is already gone.
            $deletedAssociations += DB::table('agent_run_messages')
                ->whereIn('run_id', $chunk)
                ->delete();

            $deletedSteps += DB::table('agent_run_steps')
                ->whereIn('run_id', $chunk)
                ->delete();

            $deletedRuns += DB::table('agent_runs')
                ->whereIn('id', $chunk)
                ->delete();
        } while (count($chunk) === self::CHUNK_SIZE);

        $this->info("Message associations purged: {$deletedAssociations}");
        $this->info("Steps purged: {$deletedSteps}");
        $this->info("Runs purged: {$deletedRuns}");

        Log::info('Agent run traces purged', [
            'runs' => $deletedRuns,
            'steps' => $deletedSteps,
            'associations' => $deletedAssociations,
            'cutoff' => $cutoffDate->toDateTimeString(),
        ]);

        return self::SUCCESS;
    }

    /**
     * Runs past the cutoff: closed runs by `ended_at`, still-open ones by their
     * own `started_at` so an abandoned run the sweep never reached still ages out.
     */
    private function expiredRunsQuery($cutoffDate)
    {
        return DB::table('agent_runs')
            ->select('id')
            ->where(function ($query) use ($cutoffDate) {
                $query->where(function ($q) use ($cutoffDate) {
                    $q->whereNull('ended_at')
                        ->where('started_at', '<', $cutoffDate);
                })->orWhere('ended_at', '<', $cutoffDate);
            });
    }
}
