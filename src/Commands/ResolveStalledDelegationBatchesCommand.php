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
 * Eligibility: agent_delegations rows with batch_id IS NOT NULL, status IN
 * ('queued', 'in_progress'), whose started_at is older than
 * config('llm-client.delegation.concurrency.stale_after_minutes'). Each
 * eligible row is force-finalized via DelegationService::
 * forceFinalizeBatchJoinTimeout() -- the SAME 'exhausted'/
 * 'batch_join_timeout' shape the parent's own join-wait deadline uses, so
 * an observer cannot tell which of the two layers actually caught a given
 * stalled member. Idempotent: a row already terminal by the time the sweep
 * reaches it (the parent's own deadline check, or an earlier run of this
 * same command, won the race) is left untouched.
 *
 * Usage:
 *   php artisan llm-client:resolve-stalled-delegation-batches [--dry-run]
 */
class ResolveStalledDelegationBatchesCommand extends Command
{
    protected $signature = 'llm-client:resolve-stalled-delegation-batches {--dry-run}';

    protected $description = 'Force-finalize stalled (stale queued/in_progress) concurrent delegation batch members';

    public function handle(DelegationService $delegationService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $staleMinutes = (int) config('llm-client.delegation.concurrency.stale_after_minutes', 10);
        $cutoff = now()->subMinutes($staleMinutes);

        $this->info("Stale threshold: {$staleMinutes} minutes (cutoff: {$cutoff->toDateTimeString()})");
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        $staleRows = Delegation::whereNotNull('batch_id')
            ->whereIn('status', ['queued', 'in_progress'])
            ->where('started_at', '<', $cutoff)
            ->get();

        if ($staleRows->isEmpty()) {
            $this->info('No stalled delegation batch members to resolve');
            if ($dryRun) {
                $this->comment('Dry-run complete — no changes were made');
            }

            return self::SUCCESS;
        }

        $this->info(($dryRun ? 'Members that would be force-finalized: ' : 'Members force-finalized: ') . $staleRows->count());

        if (!$dryRun) {
            foreach ($staleRows as $delegation) {
                $delegationService->forceFinalizeBatchJoinTimeout($delegation);
            }
        }

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
        }

        return self::SUCCESS;
    }
}
