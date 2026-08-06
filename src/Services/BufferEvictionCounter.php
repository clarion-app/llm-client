<?php

namespace ClarionApp\LlmClient\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Cross-process atomic counter for agent_run_export_queue buffer-overflow
 * evictions (FR-018), following this codebase's established "add-then-
 * increment" atomic-counter primitive for cache-backed bookkeeping (see
 * life-log-backend/src/Sync/RequestBudget.php's minute/day counters):
 * Cache::add() seeds the key with a TTL only if it doesn't already exist,
 * then Cache::increment() is called unconditionally so the increment is
 * never lost to a race between "does the key exist" and "create it."
 *
 * Unlike RequestBudget's time-bucketed keys (which self-reset by rolling to
 * a new key name each minute/day and simply expire), this counter is read
 * and explicitly reset by ForwardRunTracesCommand once per scheduler tick
 * via readAndReset() -- there is no natural time bucket here, since an
 * eviction can happen on any web request between scheduler ticks. The TTL
 * below is only a safety net against the counter accumulating forever if
 * the scheduled command is ever not running at all.
 */
final class BufferEvictionCounter
{
    private const CACHE_KEY = 'llm-client:trace-export:buffer_evicted';

    private const TTL_HOURS = 24;

    /**
     * Record $n newly-evicted rows. A no-op for $n <= 0.
     */
    public static function increment(int $n): void
    {
        if ($n <= 0) {
            return;
        }

        Cache::add(self::CACHE_KEY, 0, now()->addHours(self::TTL_HOURS));
        Cache::increment(self::CACHE_KEY, $n);
    }

    /**
     * Read the accumulated count and reset it to zero in the same call
     * (Cache::pull() is Laravel's atomic get-then-forget), so the next
     * command invocation starts from zero rather than double-counting.
     */
    public static function readAndReset(): int
    {
        return (int) Cache::pull(self::CACHE_KEY, 0);
    }
}
