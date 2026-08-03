<?php

namespace Tests\RealDatabase\Support;

/**
 * Per-run counters for executed / skipped / distinct reasons.
 *
 * Backs FR-017: the run states its coverage.
 * Static counters flushed at the end of a run.
 */
class SkipReport
{
    private static int $executed = 0;
    private static int $skipped = 0;
    private static array $skipReasons = [];

    public static function recordExecuted(): void
    {
        self::$executed++;
    }

    public static function recordSkipped(string $reason): void
    {
        self::$skipped++;
        self::$skipReasons[$reason] = (self::$skipReasons[$reason] ?? 0) + 1;
    }

    /**
     * Flush the report to stdout. Called from a shutdown function.
     */
    public static function flush(): void
    {
        $total = self::$executed + self::$skipped;
        if ($total === 0) {
            return;
        }

        $lines = [
            '',
            '--- Real-Database Test Report ---',
            'Executed: ' . self::$executed,
            'Skipped:  ' . self::$skipped,
        ];

        if (!empty(self::$skipReasons)) {
            $lines[] = 'Skip reasons:';
            foreach (self::$skipReasons as $reason => $count) {
                $lines[] = "  - {$reason} ({$count})";
            }
        }

        $lines[] = '-------------------------------';
        echo implode("\n", $lines) . "\n";
    }

    /**
     * Register the flush as a shutdown function.
     */
    public static function registerFlush(): void
    {
        register_shutdown_function([self::class, 'flush']);
    }

    /**
     * Reset counters (for test isolation of the report itself).
     */
    public static function reset(): void
    {
        self::$executed = 0;
        self::$skipped = 0;
        self::$skipReasons = [];
    }

    public static function getExecuted(): int
    {
        return self::$executed;
    }

    public static function getSkipped(): int
    {
        return self::$skipped;
    }

    public static function getSkipReasons(): array
    {
        return self::$skipReasons;
    }
}
