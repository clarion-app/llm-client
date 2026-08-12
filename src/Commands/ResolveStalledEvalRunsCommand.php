<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Events\EvalRunUpdated;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Models\EvalRunCase;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use ClarionApp\LlmClient\Services\EvalRunService;
use ClarionApp\LlmClient\ValueObjects\EvalRunCaseStatus;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to resolve stalled (stale in_progress) eval runs
 * (research.md D8), mirroring ResolveAbandonedRunsCommand's shape.
 *
 * Eligibility: eval_runs rows with status = in_progress whose updated_at
 * is older than config('llm-client.eval_runs.stale_after_minutes', 30) —
 * the same "no fresh activity" signal ResolveAbandonedRunsCommand uses
 * for agent_runs, applied here to a run whose case dispatch may itself
 * have been interrupted rather than merely still working.
 *
 * For each eligible run, its still-incomplete eval_run_cases rows (no
 * matching eval_case_results row yet) are split two ways:
 *   - Exhausted: dispatch_attempts already reached
 *     config('llm-client.eval_runs.max_stale_sweeps', 3) with no
 *     progress — a genuine, not transient, stall. That case is recorded
 *     errored via EvalCaseExecutor::recordTimeoutOrFailure() (the same
 *     path a hung job's own failed() hook uses) and the run is marked
 *     incomplete, overriding whatever EvalCaseExecutor's own "last job
 *     out" completion check set it to — a run that had to give up
 *     recovering a case is never presented as an ordinary completed
 *     result (FR-017).
 *   - Recoverable: still under the sweep-attempt ceiling. Its stale
 *     `dispatched` rows are reset to `pending` — mirroring what
 *     EvalRunService::resume() itself only ever redispatches — then
 *     resume() is called to redispatch them under its own
 *     Cache::lock("eval-run:{$run->id}", 30), the same lock a concurrent
 *     operator-triggered resume shares, so the two can never
 *     double-dispatch the same case.
 *
 * Both branches leave an operator watching a run live with exactly one
 * accurate announcement of what happened: the exhausted branch fires its
 * own EvalRunUpdated after the incomplete override lands (suppressing the
 * transient "completed" broadcast EvalCaseExecutor's own last-job-out
 * check would otherwise have fired first); the recoverable branch fires
 * none of its own, since EvalRunService::resume() already announces its
 * own redispatch when it does real work — adding a second one here would
 * double-announce the same event.
 *
 * Usage:
 *   php artisan llm-client:resolve-stalled-eval-runs [--dry-run]
 */
class ResolveStalledEvalRunsCommand extends Command
{
    protected $signature = 'llm-client:resolve-stalled-eval-runs {--dry-run}';

    protected $description = 'Resolve stalled (stale in_progress) eval runs by redispatching recoverable cases or marking exhausted ones incomplete';

    public function handle(EvalRunService $runService, EvalCaseExecutor $executor): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $staleMinutes = (int) config('llm-client.eval_runs.stale_after_minutes', 30);
        $maxSweeps = (int) config('llm-client.eval_runs.max_stale_sweeps', 3);
        $cutoff = now()->subMinutes($staleMinutes);

        $this->info("Stale threshold: {$staleMinutes} minutes (cutoff: {$cutoff->toDateTimeString()})");
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        $staleRuns = EvalRun::where('status', EvalRunStatus::InProgress)
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($staleRuns->isEmpty()) {
            $this->info('No stalled eval runs to resolve');
            if ($dryRun) {
                $this->comment('Dry-run complete — no changes were made');
            }

            return self::SUCCESS;
        }

        $resumedRuns = 0;
        $exhaustedCasesCount = 0;
        $incompleteRuns = 0;

        foreach ($staleRuns as $run) {
            $incompleteCases = EvalRunCase::where('run_id', $run->id)
                ->where('status', '!=', EvalRunCaseStatus::Completed)
                ->get();

            $exhaustedCases = $incompleteCases->filter(
                fn (EvalRunCase $case) => $case->dispatch_attempts >= $maxSweeps
            );

            if ($exhaustedCases->isNotEmpty()) {
                $exhaustedCasesCount += $exhaustedCases->count();
                $incompleteRuns++;

                if (!$dryRun) {
                    foreach ($exhaustedCases as $case) {
                        // The completion broadcast is suppressed here: if
                        // this happens to be the run's last outstanding
                        // case, EvalCaseExecutor's own "last job out"
                        // check would otherwise announce the run as
                        // completed a moment before the very next line
                        // overrides it back to incomplete. This command's
                        // own broadcast below is the single, accurate
                        // announcement of what actually happened.
                        $executor->recordTimeoutOrFailure(
                            $run->id,
                            $case->id,
                            new \RuntimeException('This case did not complete after repeated recovery attempts.'),
                            suppressRunCompletionBroadcast: true,
                        );
                    }

                    // FR-017: a run that had to give up recovering a case
                    // is marked incomplete, never completed — even if
                    // this was incidentally the last outstanding case,
                    // which EvalCaseExecutor's own "last job out" check
                    // (run above via recordTimeoutOrFailure) has no way
                    // to distinguish from an ordinary case failure.
                    $run->fresh()->update(['status' => EvalRunStatus::Incomplete]);

                    try {
                        event(new EvalRunUpdated($run->id));
                    } catch (\Throwable $e) {
                        Log::warning('ResolveStalledEvalRunsCommand: failed to broadcast EvalRunUpdated', [
                            'run_id' => $run->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    Log::info('Eval run case exhausted recovery attempts', [
                        'run_id' => $run->id,
                        'exhausted_case_ids' => $exhaustedCases->pluck('id')->all(),
                        'max_stale_sweeps' => $maxSweeps,
                    ]);
                }

                continue;
            }

            $resumedRuns++;

            if (!$dryRun) {
                // Stale `dispatched` rows (a dead worker never came back
                // to complete them) are reset to `pending` so
                // EvalRunService::resume()'s own pending-only query picks
                // them up — the identical redispatch mechanism a manual
                // resume call uses.
                EvalRunCase::where('run_id', $run->id)
                    ->where('status', EvalRunCaseStatus::Dispatched)
                    ->update(['status' => EvalRunCaseStatus::Pending]);

                $runService->resume($run->fresh());

                Log::info('Stalled eval run resumed by sweep', [
                    'run_id' => $run->id,
                    'redispatched_case_ids' => $incompleteCases->pluck('id')->all(),
                ]);
            }
        }

        $verb = $dryRun ? 'would be resumed' : 'resumed';
        $this->info("Stalled runs {$verb}: {$resumedRuns}");
        $this->info(($dryRun ? 'Cases that would exhaust recovery: ' : 'Cases that exhausted recovery: ') . $exhaustedCasesCount);
        $this->info(($dryRun ? 'Runs that would be marked incomplete: ' : 'Runs marked incomplete: ') . $incompleteRuns);

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
        }

        return self::SUCCESS;
    }
}
