<?php

namespace ClarionApp\LlmClient\Services\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Extracted verbatim from MetricsRecorder (research.md D5): the retry/
 * jitter/abort-detection logic a write transaction on a single hot row per
 * scope needs is identical whether the row being written is
 * cost_summaries (MetricsRecorder) or budget_reservation_ledger
 * (ReservationLedger). A trait — not inheritance — is what lets both reuse
 * the exact same logic without ReservationLedger's internals becoming
 * reachable through MetricsRecorder, or vice versa.
 */
trait RetriesConcurrencyAborts
{
    /**
     * Run a write transaction, retrying a bounded number of times when the
     * engine aborts it for concurrency rather than for anything wrong with
     * the write itself.
     *
     * This exists because "the increment is atomic, so nothing is lost" is
     * only half the guarantee. MariaDB 11.6 turned innodb_snapshot_isolation
     * on by default, and under it a REPEATABLE READ transaction whose UPDATE
     * meets a row that a concurrent transaction has already committed is not
     * made to wait — it is aborted outright with ER_CHECKREAD, "Record has
     * changed since last read". Every summary maintained above is a single
     * hot row per scope, so simultaneous completions collide on exactly that
     * path, and recordUsage()'s outer catch would turn each abort into a
     * silently dropped usage record. For a spending ceiling that is the worst
     * available failure: consumption under-reported by however many units of
     * work happened at once, and therefore a limit that is never reached.
     * Measured before this wrapper existed, fifty concurrent completions
     * recorded five.
     *
     * Laravel's own $attempts retry does not cover it. Its concurrency
     * detection predates snapshot isolation and matches deadlock and
     * lock-wait messages only.
     *
     * Retrying is safe because an aborted transaction has already been rolled
     * back in full — there is no partial write to reconcile — and because
     * every statement inside it either inserts a fresh row or applies an
     * atomic increment, neither of which is sensitive to being re-derived.
     */
    private function transactionWithConcurrencyRetries(callable $work, int $attempts = 40): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction($work);

                return;
            } catch (\Throwable $e) {
                if ($attempt >= $attempts || !$this->isConcurrencyAbort($e)) {
                    throw $e;
                }

                // A short, growing, jittered pause. Without the jitter every
                // loser of a collision retries in lockstep and collides again.
                usleep(random_int(2_000, 10_000) * min($attempt, 6));
            }
        }
    }

    /**
     * Was this transaction abandoned because something else was writing at
     * the same time, rather than because the write was wrong?
     *
     * Matched on message text because the SQLSTATE is not reliably
     * distinguishing: MariaDB reports ER_CHECKREAD under the generic HY000.
     */
    private function isConcurrencyAbort(\Throwable $e): bool
    {
        $message = $e->getMessage();

        foreach ([
            // MariaDB 11.6+ snapshot isolation.
            'Record has changed since last read',
            'Deadlock found when trying to get lock',
            'deadlock detected',
            'Lock wait timeout exceeded',
            // PostgreSQL serialization failure, for completeness.
            'could not serialize access',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
