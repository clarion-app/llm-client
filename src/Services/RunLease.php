<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\CheckpointWriteFailed;
use ClarionApp\LlmClient\Exceptions\LeaseLost;
use ClarionApp\LlmClient\ValueObjects\ClaimResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The execution lease (lease contract §1). One run is worked by at most one
 * process at a time: the claim is one conditional UPDATE that exactly one
 * worker wins, a takeover is detected from the row the claim itself left,
 * and a lost claim names why (live lease, stale dispatch, terminal,
 * cancelled). The hand-off releases the lease and mints the next dispatch
 * id on a compare-and-set, so the next worker's claim is the only way to
 * take the run over. The nonce is per process: every write a process makes
 * carries it, and a process that no longer holds the lease loses its next
 * write.
 */
class RunLease
{
    /** The process nonce: one per process, shared by every instance in it. */
    private static ?string $nonce = null;

    /**
     * Claim the run's lease for this process (lease contract §1).
     *
     * One statement, outside any transaction. The SET order is load-bearing:
     * MySQL and SQLite evaluate SET left to right, so every CASE that reads
     * `lease_holder` precedes `lease_holder = :nonce` and sees the lease as
     * it was before this claim.
     */
    public function claim(string $runId, string $dispatchId): ClaimResult
    {
        $this->assertOutsideTransaction();

        $now = now()->format('Y-m-d H:i:s.u');
        $window = (int) config('llm-client.resume.detection_window_seconds', 300);
        $expires = now()->addSeconds($window)->format('Y-m-d H:i:s.u');

        // Whether a live holder's expired lease is about to be replaced: read
        // under this dispatch id, so only a row this claim can win is read (a
        // sweep that marks the run interrupted also mints a new dispatch id,
        // which this claim then loses).
        $heldBefore = DB::table('agent_runs')
            ->where('id', $runId)
            ->where('dispatch_id', $dispatchId)
            ->value('lease_holder') !== null;

        // One placeholder per occurrence: the real engine (native prepares)
        // rejects a named parameter used twice.
        $affected = DB::connection()->update(
            "UPDATE agent_runs
                SET consecutive_interruptions = consecutive_interruptions
                        + CASE WHEN lease_holder IS NOT NULL THEN 1 ELSE 0 END,
                    interrupted_at = CASE WHEN lease_holder IS NOT NULL THEN :stamp ELSE interrupted_at END,
                    resume_state = CASE
                        WHEN resume_state = 'interrupted' OR lease_holder IS NOT NULL THEN 'resuming'
                        ELSE 'working' END,
                    lease_holder = :nonce,
                    lease_expires_at = :expires
              WHERE id = :run
                AND end_state = 'in_progress'
                AND cancelled_at IS NULL
                AND dispatch_id = :dispatch
                AND (lease_holder IS NULL OR lease_expires_at < :now)",
            [
                'stamp' => $now,
                'now' => $now,
                'nonce' => $this->nonce(),
                'expires' => $expires,
                'run' => $runId,
                'dispatch' => $dispatchId,
            ],
        );

        if ($affected === 0) {
            return ClaimResult::lost($this->lostReason($runId, $dispatchId));
        }

        // The claim is done; the row it left says whether it was a takeover.
        // It detected the interruption itself only when it replaced an
        // expired, still-held lease (the statement counted it); a claim of a
        // sweep-marked row (lease already released) counts nothing more.
        $row = DB::table('agent_runs')->where('id', $runId)->first();
        $takeover = $row !== null && $row->resume_state === 'resuming';

        return ClaimResult::won(
            $this->nonce(),
            $takeover,
            $takeover && $heldBefore,
        );
    }

    /**
     * Renew the lease this process holds. Strict: it fails — LeaseLost —
     * when another process holds the lease or the run has no lease at all.
     */
    public function renew(string $runId, int $seconds): void
    {
        $this->assertOutsideTransaction();

        $affected = DB::table('agent_runs')
            ->where('id', $runId)
            ->where('lease_holder', $this->nonce())
            ->update(['lease_expires_at' => now()->addSeconds($seconds)->format('Y-m-d H:i:s.u')]);

        if ($affected === 0) {
            throw new LeaseLost();
        }
    }

    /** Release the lease this process holds (a pause, or a clean stop). */
    public function release(string $runId): void
    {
        $this->assertOutsideTransaction();

        DB::table('agent_runs')
            ->where('id', $runId)
            ->where('lease_holder', $this->nonce())
            ->update(['lease_holder' => null]);
    }

    /**
     * Hand the run to the next worker (a stream iteration's hand-off): mint
     * the next dispatch id, release the lease, and refresh it to the
     * detection window. One compare-and-set on this process's nonce — a
     * process that no longer holds the lease throws LeaseLost and writes
     * nothing.
     */
    public function handOff(string $runId): string
    {
        $this->assertOutsideTransaction();

        $nextDispatch = (string) Str::uuid();
        $window = (int) config('llm-client.resume.detection_window_seconds', 300);

        $affected = DB::table('agent_runs')
            ->where('id', $runId)
            ->where('lease_holder', $this->nonce())
            ->update([
                'dispatch_id' => $nextDispatch,
                'lease_holder' => null,
                'lease_expires_at' => now()->addSeconds($window)->format('Y-m-d H:i:s.u'),
            ]);

        if ($affected === 0) {
            throw new LeaseLost();
        }

        return $nextDispatch;
    }

    /**
     * Mint the next dispatch id, but only while the run is in one of the
     * expected states (a compare-and-set). Null means the run is not in an
     * expected state — a legacy run, or a caller that already minted — and
     * the caller continues without claiming.
     */
    public function mintDispatch(string $runId, array $expectState): ?string
    {
        $this->assertOutsideTransaction();

        $nextDispatch = (string) Str::uuid();

        $affected = DB::table('agent_runs')
            ->where('id', $runId)
            ->whereIn('resume_state', $expectState)
            ->update(['dispatch_id' => $nextDispatch]);

        return $affected > 0 ? $nextDispatch : null;
    }

    /**
     * Mint the next dispatch id for a run that is live (in_progress, not
     * cancelled) regardless of its resume_state — the fallback for a
     * continuation that rewinds a run which is not in one of the
     * expected paused states (a pre-lease run, or a test that resets
     * end_state without resetting resume_state). The claim() that follows
     * still enforces every safety check (a live lease held by another
     * process loses), so this only widens the mint, not the claim.
     */
    public function mintDispatchForLiveRun(string $runId): ?string
    {
        $this->assertOutsideTransaction();

        $nextDispatch = (string) Str::uuid();

        $affected = DB::table('agent_runs')
            ->where('id', $runId)
            ->where('end_state', 'in_progress')
            ->whereNull('cancelled_at')
            ->update(['dispatch_id' => $nextDispatch]);

        return $affected > 0 ? $nextDispatch : null;
    }

    /** The process nonce: one per process, stable across calls. */
    public function nonce(): string
    {
        return self::$nonce ??= (string) Str::uuid();
    }

    /**
     * Name the reason a claim lost, from the row as it is now: a terminal
     * run, a cancelled run, a stale dispatch id, or a live lease held by
     * another process.
     */
    private function lostReason(string $runId, string $dispatchId): string
    {
        $row = DB::table('agent_runs')->where('id', $runId)->first();

        if ($row === null) {
            return 'taken';
        }
        if ($row->end_state !== 'in_progress') {
            return 'terminal';
        }
        if ($row->cancelled_at !== null) {
            return 'cancelled';
        }
        if ($row->dispatch_id !== $dispatchId) {
            return 'stale_dispatch';
        }

        return 'taken';
    }

    private function assertOutsideTransaction(): void
    {
        if (DB::transactionLevel() > 0) {
            throw new CheckpointWriteFailed('A checkpoint write cannot happen inside a transaction.', 'inside_transaction');
        }
    }
}
