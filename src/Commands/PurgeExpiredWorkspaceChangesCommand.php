<?php

namespace ClarionApp\LlmClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to purge expired coding workspace change records
 * (122-workspace-browser-ui, US3, research.md D2, FR-012).
 *
 * Mirrors PurgeExpiredRunTracesCommand's exact shape -- chunked delete,
 * --dry-run, a resolveRetentionDays() guard rejecting non-numeric/zero/
 * negative input in favor of the documented default -- but reads its own,
 * independently configured retention key
 * (coding_agent.change_record_retention_days, 365-day default) so a
 * future change to run_trace.retention_days can never silently shorten
 * this command's own retention window. Unlike run traces,
 * coding_workspace_changes has no child table to order deletion around --
 * every row is deleted directly, in one pass per chunk.
 *
 * Usage:
 *   php artisan llm-client:purge-workspace-changes [--days=365] [--dry-run]
 */
class PurgeExpiredWorkspaceChangesCommand extends Command
{
    protected $signature = 'llm-client:purge-workspace-changes
                            {--days= : Retention period in days (default: from config)}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Purge expired coding workspace change records';

    /** Rows deleted per pass. Keeps each `whereIn` well inside SQLite's 999-parameter cap. */
    private const CHUNK_SIZE = 500;

    /** Documented fallback retention window (FR-012) when the supplied value can't be trusted. */
    private const DEFAULT_RETENTION_DAYS = 365;

    public function handle(): int
    {
        $rawDays = $this->option('days') ?? config('llm-client.coding_agent.change_record_retention_days');
        $days = $this->resolveRetentionDays($rawDays);
        $dryRun = (bool) $this->option('dry-run');

        $cutoffDate = now()->subDays($days);

        $this->info("Retention period: {$days} days (cutoff: {$cutoffDate->toDateTimeString()})");
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        $totalExpired = $this->expiredChangesQuery($cutoffDate)->count();

        if ($totalExpired === 0) {
            $this->info('No expired workspace change records to purge');
            if ($dryRun) {
                $this->comment('Dry-run complete — no changes were made');
            }
            return self::SUCCESS;
        }

        $this->info("Found {$totalExpired} expired workspace change record(s) to purge");

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
            return self::SUCCESS;
        }

        // Deleted in chunks, mirroring PurgeExpiredRunTracesCommand's own
        // rationale: after a full retention period this table can hold
        // far more ids than belong in one `whereIn` -- SQLite caps bound
        // parameters at 999 by default, and MySQL is bounded by
        // max_allowed_packet. Materialising the whole id list would also
        // hold it all in memory at once.
        $deleted = 0;

        do {
            $chunk = $this->expiredChangesQuery($cutoffDate)
                ->limit(self::CHUNK_SIZE)
                ->pluck('id')
                ->all();

            if (empty($chunk)) {
                break;
            }

            $deleted += DB::table('coding_workspace_changes')
                ->whereIn('id', $chunk)
                ->delete();
        } while (count($chunk) === self::CHUNK_SIZE);

        $this->info("Workspace change records purged: {$deleted}");

        Log::info('Coding workspace change records purged', [
            'deleted' => $deleted,
            'cutoff' => $cutoffDate->toDateTimeString(),
        ]);

        return self::SUCCESS;
    }

    /**
     * Validates the resolved retention-days input (from `--days`, else
     * config) before it drives the cutoff (FR-012). Absent, non-numeric,
     * zero, or negative values are rejected in favor of the documented
     * default, with exactly one warning naming the rejected value; a
     * valid positive integer passes through unchanged.
     */
    private function resolveRetentionDays(mixed $rawDays): int
    {
        if (is_numeric($rawDays) && (int) $rawDays > 0) {
            return (int) $rawDays;
        }

        Log::warning('PurgeExpiredWorkspaceChangesCommand: invalid retention_days, using default', [
            'rejected_value' => $rawDays,
            'default' => self::DEFAULT_RETENTION_DAYS,
        ]);

        return self::DEFAULT_RETENTION_DAYS;
    }

    private function expiredChangesQuery($cutoffDate)
    {
        return DB::table('coding_workspace_changes')
            ->select('id')
            ->where('created_at', '<', $cutoffDate);
    }
}
