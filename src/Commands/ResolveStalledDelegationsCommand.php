<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\DelegationService;
use Illuminate\Console\Command;

/**
 * 101-parallel-subagent-execution (US3, contracts §6, research.md D4 layer
 * 3), mirroring ResolveStalledEvalRunsCommand's shape (Grounding note item
 * 9): the crash-recovery backstop for the case DelegationService::
 * delegateBatch()'s own join-wait deadline (layer 2) cannot cover -- the
 * *parent's own* process dying (worker killed, deploy, OOM) before its
 * join-wait deadline check ever runs, leaving 'queued'/'in_progress' rows
 * with no one left to finalize them.
 *
 * Batch-member eligibility (unchanged): agent_delegations rows with
 * batch_id IS NOT NULL, status IN ('queued', 'in_progress'), whose
 * started_at is older than
 * config('llm-client.delegation.concurrency.stale_after_minutes'). Each
 * eligible row is force-finalized via DelegationService::
 * forceFinalizeBatchJoinTimeout() -- the SAME 'exhausted'/
 * 'batch_join_timeout' shape the parent's own join-wait deadline uses, so
 * an observer cannot tell which of the two layers actually caught a given
 * stalled member.
 *
 * 110-delegation-deadlock-timeout (Phase 4/US2, research.md D2/D3,
 * contracts/delegation-chain-bounds.md §2): generalized here (renamed from
 * ResolveStalledDelegationBatchesCommand /
 * llm-client:resolve-stalled-delegation-batches -- the prior name is not a
 * public contract, CLI-only/internal-scheduler use, so no alias is kept)
 * to also sweep SOLO (non-batch) delegations: agent_delegations rows with
 * batch_id IS NULL, status = 'in_progress', whose started_at is older than
 * config('llm-client.delegation.stale_after_minutes'), AND idle -- no
 * agent_run_actions activity on the delegation's own helper run within
 * config('llm-client.delegation.idle_after_minutes')
 * (DelegationService::isIdle()). A row that is old but still actively
 * producing run-trace actions is left alone every sweep run (FR-005/
 * SC-004) -- idleness is checked in addition to, never instead of,
 * staleness.
 *
 * Idempotent for both branches: a row already terminal by the time the
 * sweep reaches it (a parent's own deadline check, or an earlier run of
 * this same command, won the race) is left untouched --
 * forceFinalizeBatchJoinTimeout()/forceFinalizeStalledDelegation() both
 * guard on this internally.
 *
 * Usage:
 *   php artisan llm-client:resolve-stalled-delegations [--dry-run]
 */
class ResolveStalledDelegationsCommand extends Command
{
    protected $signature = 'llm-client:resolve-stalled-delegations {--dry-run}';

    protected $description = 'Force-finalize stalled concurrent delegation batch members and stalled (stale + idle) solo delegations';

    public function handle(DelegationService $delegationService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        $this->resolveBatchMembers($delegationService, $dryRun);
        $this->resolveSoloDelegations($delegationService, $dryRun);

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
        }

        return self::SUCCESS;
    }

    /**
     * Batch-member branch -- unchanged behavior from
     * ResolveStalledDelegationBatchesCommand.
     */
    private function resolveBatchMembers(DelegationService $delegationService, bool $dryRun): void
    {
        $staleMinutes = (int) config('llm-client.delegation.concurrency.stale_after_minutes', 10);
        $cutoff = now()->subMinutes($staleMinutes);

        $this->info("Batch members — stale threshold: {$staleMinutes} minutes (cutoff: {$cutoff->toDateTimeString()})");

        $staleRows = Delegation::whereNotNull('batch_id')
            ->whereIn('status', ['queued', 'in_progress'])
            ->where('started_at', '<', $cutoff)
            ->get();

        if ($staleRows->isEmpty()) {
            $this->info('No stalled delegation batch members to resolve');

            return;
        }

        $this->info(($dryRun ? 'Batch members that would be force-finalized: ' : 'Batch members force-finalized: ') . $staleRows->count());

        if (!$dryRun) {
            foreach ($staleRows as $delegation) {
                $delegationService->forceFinalizeBatchJoinTimeout($delegation);
            }
        }
    }

    /**
     * 110-delegation-deadlock-timeout (Phase 4/US2): the new solo-delegation
     * branch -- batch_id IS NULL, status = 'in_progress', stale by age AND
     * idle by activity (DelegationService::isIdle()).
     *
     * 110-delegation-deadlock-timeout (Phase 5/US3, tasks.md T034,
     * research.md D3, contracts/delegation-chain-bounds.md §2
     * "Whole-subtree finalization (new)"): each stale+idle row this
     * branch's own flat query finds is passed to
     * DelegationService::finalizeStalledChain() rather than
     * forceFinalizeStalledDelegation() directly -- finalizeStalledChain()
     * finalizes the row itself and then walks its parent_conversation_id
     * ancestry, finalizing every still-in_progress ancestor found along the
     * way too, so a dead process that left an entire chain stranded
     * in_progress is unwound in one sweep pass, not just the one row this
     * branch's own eligibility check happened to match directly (FR-007).
     */
    private function resolveSoloDelegations(DelegationService $delegationService, bool $dryRun): void
    {
        $staleMinutes = (int) config('llm-client.delegation.stale_after_minutes', 10);
        $cutoff = now()->subMinutes($staleMinutes);

        $this->info("Solo delegations — stale threshold: {$staleMinutes} minutes (cutoff: {$cutoff->toDateTimeString()})");

        $staleRows = Delegation::whereNull('batch_id')
            ->where('status', 'in_progress')
            ->where('started_at', '<', $cutoff)
            ->get();

        $idleRows = $staleRows->filter(fn (Delegation $delegation) => $delegationService->isIdle($delegation));

        if ($idleRows->isEmpty()) {
            $this->info('No stalled solo delegations to resolve');

            return;
        }

        $this->info(($dryRun ? 'Solo delegations that would be force-finalized: ' : 'Solo delegations force-finalized: ') . $idleRows->count());

        if (!$dryRun) {
            foreach ($idleRows as $delegation) {
                $delegationService->finalizeStalledChain($delegation);
            }
        }
    }
}
