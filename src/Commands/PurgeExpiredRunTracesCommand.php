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

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('llm-client.run_trace.retention_days', 90));
        $dryRun = (bool) $this->option('dry-run');

        $cutoffDate = now()->subDays($days);

        $this->info("Retention period: {$days} days (cutoff: {$cutoffDate->toDateTimeString()})");
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        // Find expired runs (by ended_at, falling back to started_at for in_progress runs).
        // These are runs where the work is definitively old.
        $expiredRunIds = DB::table('agent_runs')
            ->where(function ($query) use ($cutoffDate) {
                $query->whereNull('ended_at')
                    ->where('started_at', '<', $cutoffDate);
            })
            ->orWhere('ended_at', '<', $cutoffDate)
            ->pluck('id')
            ->toArray();

        $totalExpiredRuns = count($expiredRunIds);

        if ($totalExpiredRuns === 0) {
            $this->info('No expired run traces to purge');
            if ($dryRun) {
                $this->comment('Dry-run complete — no changes were made');
            }
            return self::SUCCESS;
        }

        $this->info("Found {$totalExpiredRuns} expired run(s) to purge");

        if (!$dryRun) {
            // Delete child rows first (associations, then steps), then parent runs.
            // This ordering avoids foreign key violations.

            // 1. Delete message associations for expired runs.
            $deletedAssociations = DB::table('agent_run_messages')
                ->whereIn('run_id', $expiredRunIds)
                ->delete();
            $this->info("Message associations purged: {$deletedAssociations}");

            // 2. Delete steps for expired runs.
            $deletedSteps = DB::table('agent_run_steps')
                ->whereIn('run_id', $expiredRunIds)
                ->delete();
            $this->info("Steps purged: {$deletedSteps}");

            // 3. Delete the expired runs themselves.
            $deletedRuns = DB::table('agent_runs')
                ->whereIn('id', $expiredRunIds)
                ->delete();
            $this->info("Runs purged: {$deletedRuns}");

            Log::info('Agent run traces purged', [
                'runs' => $deletedRuns,
                'steps' => $deletedSteps,
                'associations' => $deletedAssociations,
                'cutoff' => $cutoffDate->toDateTimeString(),
            ]);
        }

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
        }

        return self::SUCCESS;
    }
}
