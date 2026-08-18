<?php

namespace ClarionApp\LlmClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command purging expired mcp_client_connection_tests rows --
 * ephemeral, credential-bearing scratch state for a "test before saving"
 * connection attempt (FR-003, D3). Mirrors PurgeExpiredRunTracesCommand's
 * own shape (chunked delete, --dry-run, retention-input validation), but
 * against a single table with no children to order deletion around, and
 * a much shorter default retention appropriate for scratch state rather
 * than a durable audit trail.
 *
 * Usage:
 *   php artisan llm-client:purge-mcp-connection-tests [--hours=1] [--dry-run]
 */
class PurgeMcpClientConnectionTestsCommand extends Command
{
    protected $signature = 'llm-client:purge-mcp-connection-tests
                            {--hours= : Retention period in hours (default: from config)}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Purge expired MCP client connection-test rows';

    /** Rows deleted per pass. Keeps each `whereIn` well inside SQLite's 999-parameter cap. */
    private const CHUNK_SIZE = 500;

    /** Documented fallback retention window when the supplied value can't be trusted. */
    private const DEFAULT_RETENTION_HOURS = 1;

    public function handle(): int
    {
        $rawHours = $this->option('hours') ?? config('llm-client.mcp_client.connection_test_retention_hours');
        $hours = $this->resolveRetentionHours($rawHours);
        $dryRun = (bool) $this->option('dry-run');

        // subMinutes(int) rather than subHours($hours) directly -- $hours
        // may be a fractional value from a caller-supplied --hours
        // option, and Carbon's own float support for subHours() has
        // varied across versions, whereas an integer minute count is
        // unambiguous everywhere.
        $cutoff = now()->subMinutes((int) round($hours * 60));

        $this->info("Retention period: {$hours} hour(s) (cutoff: {$cutoff->toDateTimeString()})");
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        $totalExpired = $this->expiredRowsQuery($cutoff)->count();

        if ($totalExpired === 0) {
            $this->info('No expired connection tests to purge');
            if ($dryRun) {
                $this->comment('Dry-run complete — no changes were made');
            }
            return self::SUCCESS;
        }

        $this->info("Found {$totalExpired} expired connection test(s) to purge");

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
            return self::SUCCESS;
        }

        // Deleted in chunks: after a full retention period this table can
        // hold far more ids than belong in one `whereIn` -- SQLite caps
        // bound parameters at 999 by default, and MySQL is bounded by
        // max_allowed_packet. No child table to order around, unlike the
        // run-trace purge (mcp_client_connection_tests has no FK to or
        // from any other table, by design, D3).
        $deleted = 0;

        do {
            $chunk = $this->expiredRowsQuery($cutoff)
                ->limit(self::CHUNK_SIZE)
                ->pluck('id')
                ->all();

            if (empty($chunk)) {
                break;
            }

            $deleted += DB::table('mcp_client_connection_tests')
                ->whereIn('id', $chunk)
                ->delete();
        } while (count($chunk) === self::CHUNK_SIZE);

        $this->info("Connection tests purged: {$deleted}");

        Log::info('MCP client connection tests purged', [
            'deleted' => $deleted,
            'cutoff' => $cutoff->toDateTimeString(),
        ]);

        return self::SUCCESS;
    }

    /**
     * Validates the resolved retention-hours input (from `--hours`, else
     * config) before it drives the cutoff. Absent, non-numeric, zero, or
     * negative values are rejected in favor of the documented default,
     * with exactly one warning naming the rejected value; a valid
     * positive number passes through unchanged.
     */
    private function resolveRetentionHours(mixed $rawHours): int|float
    {
        if (is_numeric($rawHours) && (float) $rawHours > 0) {
            return (float) $rawHours;
        }

        Log::warning('PurgeMcpClientConnectionTestsCommand: invalid connection_test_retention_hours, using default', [
            'rejected_value' => $rawHours,
            'default' => self::DEFAULT_RETENTION_HOURS,
        ]);

        return self::DEFAULT_RETENTION_HOURS;
    }

    private function expiredRowsQuery($cutoff)
    {
        return DB::table('mcp_client_connection_tests')
            ->select('id')
            ->where('created_at', '<', $cutoff);
    }
}
