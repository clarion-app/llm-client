<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Models\EpisodicMemory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to clean up expired episodic memories based on retention policy.
 *
 * Removes non-protected entries older than the configured retention period.
 * Protected entries are always exempted regardless of age.
 *
 * Usage:
 *   php artisan episodic-memory:cleanup [--days=90] [--user-id=...] [--dry-run]
 */
class CleanupExpiredEpisodicMemoriesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'episodic-memory:cleanup
                            {--days= : Retention period in days (default: from config)}
                            {--user-id= : Only clean up memories for a specific user}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up expired episodic memories based on retention policy';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('llm-client.episodic_memory.retention_days', 90));
        $userId = $this->option('user-id');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Retention policy: {$days} days");
        if ($userId) {
            $this->info("Scoped to user: {$userId}");
        }
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        // Build the query for expired, non-protected memories
        $query = EpisodicMemory::where('protected', false)
            ->where('created_at', '<', now()->subDays($days))
            ->withTrashed();

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $expiredMemories = $query->get();
        $softDeletedCount = 0;
        $forceDeletedCount = 0;
        $skippedCount = 0;

        // Count protected entries that would have been affected (for reporting)
        $protectedQuery = EpisodicMemory::where('protected', true)
            ->where('created_at', '<', now()->subDays($days));
        if ($userId) {
            $protectedQuery->where('user_id', $userId);
        }
        $protectedCount = $protectedQuery->count();

        foreach ($expiredMemories as $memory) {
            // Double-check protection flag (in case it changed during iteration)
            if ($memory->protected) {
                $skippedCount++;
                continue;
            }

            if ($dryRun) {
                $this->output->writeln("  Would delete: {$memory->id} (conversation: {$memory->conversation_id})");
                $softDeletedCount++;
                continue;
            }

            // Soft delete if not already deleted
            if (!$memory->trashed()) {
                $memory->delete();
                $softDeletedCount++;
                Log::info('Episodic memory soft-deleted during cleanup', [
                    'memory_id' => $memory->id,
                    'conversation_id' => $memory->conversation_id,
                    'user_id' => $memory->user_id,
                    'age_days' => $memory->created_at->diffInDays(now()),
                ]);
            }
        }

        // Permanent cleanup: forceDelete entries that have been soft-deleted for 7+ days
        if (!$dryRun) {
            $permanentQuery = EpisodicMemory::onlyTrashed()
                ->where('deleted_at', '<', now()->subDays(7));
            if ($userId) {
                $permanentQuery->where('user_id', $userId);
            }

            $forceDeletedCount = $permanentQuery->forceDelete();

            if ($forceDeletedCount > 0) {
                Log::info('Episodic memories permanently deleted during cleanup', [
                    'count' => $forceDeletedCount,
                    'user_id' => $userId ?? 'all',
                ]);
            }
        } else {
            // In dry-run mode, count how many would be force-deleted
            $permanentQuery = EpisodicMemory::onlyTrashed()
                ->where('deleted_at', '<', now()->subDays(7));
            if ($userId) {
                $permanentQuery->where('user_id', $userId);
            }
            $forceDeletedCount = $permanentQuery->count();
        }

        // Output summary
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Expired memories (soft-deleted)', $softDeletedCount],
                ['Old soft-deleted (force-deleted)', $forceDeletedCount],
                ['Protected entries (exempted)', $protectedCount],
                ['Skipped (re-protected)', $skippedCount],
            ]
        );

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
        }

        return self::SUCCESS;
    }
}
