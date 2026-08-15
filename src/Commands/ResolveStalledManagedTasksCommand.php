<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Services\ManagerService;
use Illuminate\Console\Command;

/**
 * 103-manager-agent (US4, research.md D7, contracts/manager-agent-meta-tools.md
 * §6), mirroring ResolveStalledDelegationBatchesCommand's exact shape
 * (Grounding note item 12) but with 101's abandon-and-stop shape replaced
 * by a genuine resume-or-force-finalize decision (research.md D7's own
 * "sweep-and-re-drive, not abandon-and-stop" rationale) -- a managed task
 * spans many short-lived RunManagedTaskStepJob invocations by design
 * (research.md D6), so a worker dying mid-task is an INTERRUPTION, not an
 * abandonment: everything needed to continue (accepted parts, rounds
 * spent, outstanding assignment rows) already survived on disk.
 *
 * Eligibility: managed_tasks rows status = 'in_progress' whose
 * last_progress_at is older than
 * config('llm-client.manager.stale_after_minutes'). For each eligible
 * row: if the task's own max_seconds wall-clock bound (measured from
 * started_at) has NOT yet been reached, re-dispatch a fresh
 * RunManagedTaskStepJob (crash recovery -- resuming is strictly cheaper
 * than starting over, since every accepted part and spent round is
 * already on disk); if it HAS been reached, call ManagerService::
 * finalizeWithShortfall() directly, dispatching no job -- so a task
 * cannot be resumed forever by a sweep that keeps re-dispatching it. This
 * is the SAME terminal path RunManagedTaskStepJob::handle()'s own
 * pre-step ceiling check (T050) takes, so an observer cannot tell which
 * of the two callers actually force-finalized a given task.
 *
 * Usage:
 *   php artisan llm-client:resolve-stalled-managed-tasks [--dry-run]
 */
class ResolveStalledManagedTasksCommand extends Command
{
    protected $signature = 'llm-client:resolve-stalled-managed-tasks {--dry-run}';

    protected $description = 'Resume (crash recovery) or force-finalize stalled (stale in_progress) managed tasks';

    public function handle(ManagerService $managerService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $staleMinutes = (int) config('llm-client.manager.stale_after_minutes', 10);
        $cutoff = now()->subMinutes($staleMinutes);

        $this->info("Stale threshold: {$staleMinutes} minutes (cutoff: {$cutoff->toDateTimeString()})");
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        $staleTasks = ManagedTask::where('status', 'in_progress')
            ->where('last_progress_at', '<', $cutoff)
            ->get();

        if ($staleTasks->isEmpty()) {
            $this->info('No stalled managed tasks to resolve');
            if ($dryRun) {
                $this->comment('Dry-run complete — no changes were made');
            }

            return self::SUCCESS;
        }

        $resumed = 0;
        $forceFinalized = 0;

        foreach ($staleTasks as $task) {
            $wallClockCeilingReached = $task->started_at->diffInSeconds(now(), false) >= $task->max_seconds;

            if ($dryRun) {
                if ($wallClockCeilingReached) {
                    $this->line("Task {$task->id}: would force-finalize with shortfall (max_seconds exceeded)");
                } else {
                    $this->line("Task {$task->id}: would re-dispatch a fresh step job (crash recovery)");
                }

                continue;
            }

            if ($wallClockCeilingReached) {
                $managerService->finalizeWithShortfall(
                    $task,
                    "The task's time limit was reached before this part could be completed."
                );
                $forceFinalized++;
            } else {
                RunManagedTaskStepJob::dispatch($task->id)->onQueue(config('llm-client.manager.queue', 'managed-tasks'));
                $resumed++;
            }
        }

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made. Stale tasks found: '.$staleTasks->count());

            return self::SUCCESS;
        }

        $this->info("Resumed (fresh step job dispatched): {$resumed}");
        $this->info("Force-finalized with shortfall (max_seconds exceeded): {$forceFinalized}");

        return self::SUCCESS;
    }
}
