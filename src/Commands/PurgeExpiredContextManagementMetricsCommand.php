<?php

namespace ClarionApp\LlmClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to purge expired context management metrics.
 *
 * Removes detail records older than the configured retention period,
 * and conversation summaries that have not been updated within the
 * retention window. User summaries are lifetime rollups and are
 * always exempted from purging.
 *
 * Usage:
 *   php artisan llm-client:purge-context-metrics [--days=90] [--dry-run]
 */
class PurgeExpiredContextManagementMetricsCommand extends Command
{
    protected $signature = 'llm-client:purge-context-metrics
                            {--days= : Retention period in days (default: from config)}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Purge expired context management metrics detail records and conversation summaries';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('llm-client.context_management_metrics.retention_days', 90));
        $dryRun = (bool) $this->option('dry-run');

        $cutoffDate = now()->subDays($days);

        $this->info("Retention period: {$days} days (cutoff: {$cutoffDate->toDateTimeString()})");
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        // Purge expired detail records (by created_at).
        $recordsQuery = DB::table('context_management_records')
            ->where('created_at', '<', $cutoffDate);
        $expiredRecordsCount = $recordsQuery->count();

        if ($expiredRecordsCount > 0) {
            if (!$dryRun) {
                $recordsQuery->delete();
                Log::info('Context management detail records purged', [
                    'count' => $expiredRecordsCount,
                    'cutoff' => $cutoffDate->toDateTimeString(),
                ]);
            }
            $this->info("Detail records purged: {$expiredRecordsCount}");
        } else {
            $this->info('No expired detail records to purge');
        }

        // Purge conversation summaries not updated within retention window.
        // User summaries are lifetime rollups — always exempted.
        $summariesQuery = DB::table('context_management_summaries')
            ->where('entity_type', 'conversation')
            ->where('updated_at', '<', $cutoffDate);
        $expiredSummariesCount = $summariesQuery->count();

        if ($expiredSummariesCount > 0) {
            if (!$dryRun) {
                $summariesQuery->delete();
                Log::info('Context management conversation summaries purged', [
                    'count' => $expiredSummariesCount,
                    'cutoff' => $cutoffDate->toDateTimeString(),
                ]);
            }
            $this->info("Conversation summaries purged: {$expiredSummariesCount}");
        } else {
            $this->info('No expired conversation summaries to purge');
        }

        // Report user summaries (exempted) count for visibility.
        $userSummariesCount = DB::table('context_management_summaries')
            ->where('entity_type', 'user')
            ->count();
        $this->info("User summaries retained (exempt): {$userSummariesCount}");

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
        }

        return self::SUCCESS;
    }
}
