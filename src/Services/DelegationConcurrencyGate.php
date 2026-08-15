<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Services\Concerns\RetriesConcurrencyAborts;
use Illuminate\Support\Facades\DB;

/**
 * 101-parallel-subagent-execution (US1, contracts §4, research.md D2).
 *
 * The ONLY code path that ever transitions an agent_delegations row from
 * `queued` to `in_progress` (data-model.md §1's state machine). Admission
 * state lives entirely in the shared agent_delegations table -- a "slot"
 * is nothing but "this row's own status column reads in_progress" -- never
 * on an instance or static property, so two independently-constructed
 * instances (one per queue worker resolving this class fresh out of its
 * own container) always agree on the same shared ceiling.
 *
 * Wraps its one write in RetriesConcurrencyAborts (extracted from
 * MetricsRecorder, already used by ReservationLedger) so a concurrent
 * admission race under MariaDB's snapshot isolation retries rather than
 * silently under-admitting.
 */
class DelegationConcurrencyGate
{
    use RetriesConcurrencyAborts;

    /**
     * Attempt to admit one queued batch member to in_progress.
     *
     * Precondition (contracts §4): the row identified by $delegationId
     * exists, has batch_id = $batchId, and is currently status = 'queued'
     * -- the caller (RunDelegationBatchMemberJob::handle()) is responsible
     * for checking this itself before ever calling tryAdmit().
     *
     * Postcondition on true: the row's status is already 'in_progress' by
     * the time this method returns -- written inside the same transaction
     * the admission decision was made in, so there is no window where the
     * decision and the write can disagree under concurrent callers.
     *
     * Postcondition on false: no row is written at all -- a full ceiling
     * is an ordinary, expected condition under load, never a failure.
     */
    public function tryAdmit(string $batchId, string $delegationId): bool
    {
        // RetriesConcurrencyAborts::transactionWithConcurrencyRetries()
        // returns void, so the work closure captures its own outcome via a
        // captured-by-reference variable rather than a method return value.
        $admitted = false;

        $this->transactionWithConcurrencyRetries(function () use ($batchId, $delegationId, &$admitted) {
            $maxPerBatch = (int) config('llm-client.delegation.concurrency.max_concurrent_per_batch', 5);
            $maxInstallation = (int) config('llm-client.delegation.concurrency.max_concurrent_per_installation', 20);

            // FR-006: how many of THIS batch's own members are currently
            // in_progress.
            $perBatchInProgress = DB::table('agent_delegations')
                ->where('batch_id', $batchId)
                ->where('status', 'in_progress')
                ->count();

            // FR-007: how many batch members, across every batch and every
            // user, are currently in_progress -- a real, separate axis from
            // the per-batch count above (mutation-checklist row 3).
            $installationInProgress = DB::table('agent_delegations')
                ->whereNotNull('batch_id')
                ->where('status', 'in_progress')
                ->count();

            // Redelivery guard: only a row still genuinely 'queued' may be
            // admitted -- a redelivered job whose row already moved on must
            // never be re-admitted or double-counted.
            $currentStatus = DB::table('agent_delegations')
                ->where('id', $delegationId)
                ->value('status');

            if ($currentStatus !== 'queued' || $perBatchInProgress >= $maxPerBatch || $installationInProgress >= $maxInstallation) {
                $admitted = false;

                return;
            }

            DB::table('agent_delegations')
                ->where('id', $delegationId)
                ->update(['status' => 'in_progress']);

            $admitted = true;
        });

        return $admitted;
    }
}
