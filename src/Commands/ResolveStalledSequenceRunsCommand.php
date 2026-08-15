<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Jobs\RunSequenceStageJob;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Services\SequenceService;
use Illuminate\Console\Command;

/**
 * 105-stage-pipeline (Phase 6, US4, research.md D6, Grounding note item 6),
 * mirroring ResolveStalledManagedTasksCommand's exact shape -- the
 * crash-recovery layer distinct from the user-initiated
 * SequenceController::resume() action: a SequenceRun spans many
 * short-lived RunSequenceStageJob invocations by design, so a worker dying
 * mid-stage is an INTERRUPTION, not an abandonment. Everything needed to
 * continue (every earlier stage's stored output, the blocking StageResult's
 * own status) already survived on disk.
 *
 * Eligibility: sequence_runs rows status IN ('in_progress', 'resumed')
 * whose last_progress_at is older than
 * config('llm-client.pipeline.stale_after_minutes') -- 'resumed' is swept
 * identically to 'in_progress' (data-model.md §3 treats them the same for
 * every "is this run still running" check; Grounding note item 6). For each
 * eligible row, SequenceService::resumeSafety() (T057) is applied -- the
 * IDENTICAL idempotency-shaped check SequenceController::resume() itself
 * uses, so the automatic sweep and the explicit user action can never
 * disagree about what is safe: resumable (the blocking stage is
 * failed/handoff_rejected/pending, or 'running'-and-idempotent) ->
 * re-dispatch a fresh RunSequenceStageJob (never touches the run's own
 * status -- RunSequenceStageJob's own resume-point logic, T059, resets and
 * re-invokes the blocking stage from scratch); not resumable (a 'running'
 * stage that is NOT idempotent) -> mark the run 'failed' with a
 * failure_reason naming the specific blocking stage, dispatching nothing --
 * a non-idempotent crashed stage is never silently retried (FR-013/SC-008).
 *
 * Usage:
 *   php artisan llm-client:resolve-stalled-sequence-runs [--dry-run]
 */
class ResolveStalledSequenceRunsCommand extends Command
{
    protected $signature = 'llm-client:resolve-stalled-sequence-runs {--dry-run}';

    protected $description = 'Resume (crash recovery) or force-fail stalled (stale in_progress/resumed) sequence runs';

    public function handle(SequenceService $sequenceService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $staleMinutes = (int) config('llm-client.pipeline.stale_after_minutes', 10);
        $cutoff = now()->subMinutes($staleMinutes);

        $this->info("Stale threshold: {$staleMinutes} minutes (cutoff: {$cutoff->toDateTimeString()})");
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        $staleRuns = SequenceRun::whereIn('status', ['in_progress', 'resumed'])
            ->where('last_progress_at', '<', $cutoff)
            ->get();

        if ($staleRuns->isEmpty()) {
            $this->info('No stalled sequence runs to resolve');
            if ($dryRun) {
                $this->comment('Dry-run complete — no changes were made');
            }

            return self::SUCCESS;
        }

        $resumed = 0;
        $failed = 0;

        foreach ($staleRuns as $run) {
            $safety = $sequenceService->resumeSafety($run);

            if ($dryRun) {
                if ($safety['resumable']) {
                    $this->line("Run {$run->id}: would re-dispatch a fresh stage job (crash recovery)");
                } else {
                    $this->line("Run {$run->id}: would mark failed (blocking stage not safe to auto-resume)");
                }

                continue;
            }

            if ($safety['resumable']) {
                RunSequenceStageJob::dispatch($run->id)->onQueue(config('llm-client.pipeline.queue', 'sequence-runs'));
                $resumed++;
            } else {
                $blockingStage = $safety['blocking_stage'];

                $run->status = 'failed';
                $run->failure_reason = "Stage '{$blockingStage->name}' was interrupted mid-execution and is not marked safe to repeat -- this run could not be resumed automatically.";
                $run->completed_at = now();
                $run->last_progress_at = now();
                $run->save();

                $sequenceService->broadcastRunUpdated($run->id);
                $failed++;
            }
        }

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made. Stale runs found: '.$staleRuns->count());

            return self::SUCCESS;
        }

        $this->info("Resumed (fresh stage job dispatched): {$resumed}");
        $this->info("Force-failed (unsafe to auto-resume): {$failed}");

        return self::SUCCESS;
    }
}
