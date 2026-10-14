<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Services\LegacyRunAssessment;
use ClarionApp\LlmClient\Services\PausedRunQuery;
use ClarionApp\LlmClient\Services\ResumeEventLog;
use ClarionApp\LlmClient\Services\RunResumer;
use ClarionApp\LlmClient\ValueObjects\Unresumable;
use ClarionApp\LlmClient\ValueObjects\ResumeDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The resume-detection sweep. Every minute it
 * finds runs that are still `in_progress` but whose lease has expired (or
 * whose delayed work was lost), marks them `interrupted` on a compare-and-set
 * (minting the dispatch id and counting the interruption), and dispatches a
 * `ResumeInterruptedRunJob` for the `root`-driven ones. Helper runs are
 * resumed by their parent (or closed when the parent is terminal); the
 * sequence-stage and consensus drivers resume their own work.
 *
 * Usage:
 *   php artisan llm-client:resume-interrupted-runs [--dry-run]
 */
class ResumeInterruptedRunsCommand extends Command
{
    protected $signature = 'llm-client:resume-interrupted-runs
                            {--dry-run : Show what would be resumed without actually resuming}';

    protected $description = 'Detect interrupted (lease-expired) agent runs and resume them';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $window = (int) config('llm-client.resume.detection_window_seconds', 300);
        $now = now();
        $cutoff = $now->copy()->subSeconds($window);

        $this->info("Detection window: {$window} s (cutoff: {$cutoff->toDateTimeString()})");
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        // Runs waiting on a pending, still-answerable confirmation or question
        // are exempt (shared with the abandonment sweep).
        $exemptRunIds = app(PausedRunQuery::class)->exemptRunIds($cutoff);

        $eligible = $this->findEligibleRuns($now, $cutoff, $exemptRunIds);
        $legacy = $this->findLegacyRuns($cutoff, $exemptRunIds);

        if (empty($eligible) && empty($legacy)) {
            $this->info('No interrupted runs to resume');
            if ($dryRun) {
                $this->comment('Dry-run complete — no changes were made');
            }

            return self::SUCCESS;
        }

        $resumed = 0;
        $skipped = 0;

        foreach ($eligible as $run) {
            $runId = (string) $run->id;
            $driver = ResumeDriver::tryFrom((string) ($run->resume_driver ?? ResumeDriver::Root->value)) ?? ResumeDriver::Root;

            switch ($driver) {
                case ResumeDriver::Root:
                    $resumed += $this->resumeRoot($run, $now, $cutoff, $window, $dryRun);
                    break;
                case ResumeDriver::Parent:
                case ResumeDriver::BatchMember:
                    $this->closeOrphanedHelper($run, $dryRun);
                    $skipped++;
                    break;
                case ResumeDriver::SequenceStage:
                case ResumeDriver::Consensus:
                    // the sequence-stage and consensus drivers resume
                    // their own work; this sweep leaves them to their own
                    // re-drive.
                    $skipped++;
                    break;
                case ResumeDriver::None:
                default:
                    // Left to the abandonment / eval sweeps.
                    $skipped++;
                    break;
            }
        }

        foreach ($legacy as $legacyRun) {
            $resumed += $this->assessLegacy((string) $legacyRun->id, $dryRun);
        }

        $verb = $dryRun ? 'would be resumed' : 'resumed';
        $this->info("Runs {$verb}: {$resumed}");
        $this->info("Runs skipped: {$skipped}");

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
        }

        return self::SUCCESS;
    }

    /**
     * The root driver: the compare-and-set (mint the dispatch id, count the
     * interruption, mark `interrupted`), then the event and the job. When the
     * interruption bound is reached the job is still dispatched: the resumer
     * stops the run with its report and records `stopped_unresumable`.
     */
    private function resumeRoot(object $run, $now, $cutoff, int $window, bool $dryRun): int
    {
        $runId = (string) $run->id;
        $max = (int) config('llm-client.resume.max_consecutive_interruptions', 3);

        if ($dryRun) {
            $this->line("  would resume {$runId}");

            return 1;
        }

        $newDispatch = (string) Str::uuid();
        $oldDispatch = $run->dispatch_id !== null ? (string) $run->dispatch_id : null;
        $seenState = (string) $run->resume_state;

        // The compare-and-set: mint the dispatch id, count the interruption,
        // mark `interrupted`, release the lease. The dispatch_id comparison is
        // written portably for SQLite (a null-safe equality). The eligibility
        // predicate is re-evaluated in the WHERE so a run that recovered in the
        // gap is not swept.
        // One placeholder per occurrence: the real engine (native prepares)
        // rejects a named parameter used twice.
        $nowStamp = $now->format('Y-m-d H:i:s.u');
        $cutoffStamp = $cutoff->format('Y-m-d H:i:s.u');
        $affected = DB::connection()->update(
            "UPDATE agent_runs
                SET resume_state = 'interrupted',
                    interrupted_at = ?,
                    dispatch_id = ?,
                    lease_holder = NULL,
                    consecutive_interruptions = consecutive_interruptions + 1
              WHERE id = ?
                AND (dispatch_id = ? OR (dispatch_id IS NULL AND ? = 1))
                AND resume_state = ?
                AND end_state = 'in_progress'
                AND cancelled_at IS NULL
                AND checkpoint_version = 1
                AND (
                      (resume_state IN ('working','resuming') AND lease_expires_at < ?)
                   OR (resume_state = 'waiting_provider_retry' AND next_attempt_at < ?)
                   OR (resume_state = 'interrupted' AND interrupted_at < ?)
                )",
            [
                $nowStamp,
                $newDispatch,
                $runId,
                $oldDispatch ?? '',
                $oldDispatch === null ? 1 : 0,
                $seenState,
                $nowStamp,
                $cutoffStamp,
                $cutoffStamp,
            ],
        );

        if ($affected === 0) {
            return 0;
        }

        // The CAS just counted this interruption. At the bound the
        // resume job stops the run with its report instead of resuming it,
        // so only the detection itself is not recorded here.
        $count = (int) DB::table('agent_runs')->where('id', $runId)->value('consecutive_interruptions');
        if ($count < $max) {
            app(ResumeEventLog::class)->record($runId, 'interruption_detected', null, $count, [
                'last_progress_at' => $run->last_progress_at !== null ? (string) $run->last_progress_at : null,
                'lease_expired_at' => $run->lease_expires_at !== null ? (string) $run->lease_expires_at : null,
                'resume_state_before' => $seenState,
                'detected_by' => 'sweep',
            ]);
        }

        ResumeInterruptedRunJob::dispatch($runId, $newDispatch);

        return 1;
    }

    /**
     * A task that was running when this version was deployed: adopted and
     * resumed when its record suffices, otherwise stopped with the report
     * (instead of being swept as abandoned). Returns 1 when it is resumed.
     */
    private function assessLegacy(string $runId, bool $dryRun): int
    {
        $run = AgentRun::find($runId);
        if ($run === null) {
            return 0;
        }

        $assessment = app(LegacyRunAssessment::class)->assess($run);

        if ($assessment instanceof Unresumable) {
            $this->line(($dryRun ? '  would stop ' : '  stopping ')."{$runId} (started before this version)");
            if (!$dryRun) {
                app(RunResumer::class)->stopUnresumable($run, 'legacy_insufficient');
            }

            return 0;
        }

        if ($dryRun) {
            $this->line("  would adopt and resume {$runId}");

            return 1;
        }

        $dispatch = app(LegacyRunAssessment::class)->adoptInterrupted($run, $assessment);
        if ($dispatch === null) {
            return 0;
        }

        ResumeInterruptedRunJob::dispatch($runId, $dispatch);

        return 1;
    }

    /**
     * The parent / batch_member driver: the helper is resumed by its parent
     * (a live parent's own resume re-joins it). When the parent run is
     * terminal, the helper is closed `stopped_early` ("its parent task
     * ended") — never left running.
     */
    private function closeOrphanedHelper(object $run, bool $dryRun): void
    {
        $runId = (string) $run->id;

        $parentRunId = DB::table('agent_delegations')
            ->where('helper_run_id', $runId)
            ->value('parent_run_id');

        if ($parentRunId === null) {
            return;
        }

        $parent = DB::table('agent_runs')->where('id', $parentRunId)->first(['end_state']);
        if ($parent === null || $parent->end_state === 'in_progress') {
            return; // A live parent: its own resume re-joins the helper.
        }

        if ($dryRun) {
            return;
        }

        // The parent is terminal: close the helper stopped_early.
        app(\ClarionApp\LlmClient\Services\RunTraceRecorder::class)->closeRun(
            $runId,
            \ClarionApp\LlmClient\ValueObjects\RunEndState::StoppedEarly,
            'its parent task ended',
        );
    }

    /**
     * The eligible interrupted runs:
     * in_progress, not cancelled, checkpointed, and either lease-expired or a
     * lost delayed unit of work, and not exempt.
     *
     * @return array<int, object>
     */
    private function findEligibleRuns($now, $cutoff, array $exemptRunIds): array
    {
        $query = DB::table('agent_runs')
            ->where('end_state', 'in_progress')
            ->whereNull('cancelled_at')
            ->where('checkpoint_version', 1)
            ->where(function ($q) use ($now, $cutoff) {
                $q->where(function ($q) use ($now) {
                    $q->whereIn('resume_state', ['working', 'resuming'])
                        ->where('lease_expires_at', '<', $now->format('Y-m-d H:i:s.u'));
                })->orWhere(function ($q) use ($cutoff) {
                    $q->where('resume_state', 'waiting_provider_retry')
                        ->where('next_attempt_at', '<', $cutoff->format('Y-m-d H:i:s.u'));
                })->orWhere(function ($q) use ($cutoff) {
                    $q->where('resume_state', 'interrupted')
                        ->where('interrupted_at', '<', $cutoff->format('Y-m-d H:i:s.u'));
                });
            });

        if (!empty($exemptRunIds)) {
            $query->whereNotIn('id', $exemptRunIds);
        }

        return $query
            ->select('id', 'resume_state', 'dispatch_id', 'resume_driver', 'last_progress_at', 'lease_expires_at')
            ->get()
            ->all();
    }

    /**
     * Runs that began before this version: in progress, never checkpointed,
     * not cancelled, with no activity for a whole detection window (the
     * abandonment sweep's own notion of latest activity), and not exempt.
     *
     * @return array<int, object>
     */
    private function findLegacyRuns($cutoff, array $exemptRunIds): array
    {
        if (!(bool) config('llm-client.resume.enabled', true)) {
            return [];
        }

        $latestActivity = DB::table('agent_run_steps')
            ->select('run_id', DB::raw('MAX(COALESCE(ended_at, started_at)) as step_activity'))
            ->groupBy('run_id');

        $greatest2 = fn (string $a, string $b) => "CASE WHEN {$a} >= {$b} THEN {$a} ELSE {$b} END";
        $latest = $greatest2(
            $greatest2('COALESCE(activity.step_activity, agent_runs.started_at)', 'COALESCE(agent_runs.last_progress_at, agent_runs.started_at)'),
            'COALESCE(agent_runs.lease_expires_at, agent_runs.started_at)',
        );

        $query = DB::table('agent_runs')
            ->leftJoinSub($latestActivity, 'activity', 'activity.run_id', '=', 'agent_runs.id')
            ->where('agent_runs.end_state', 'in_progress')
            ->whereNull('agent_runs.cancelled_at')
            ->whereNull('agent_runs.checkpoint_version')
            ->whereRaw("{$latest} < ?", [$cutoff->format('Y-m-d H:i:s.u')]);

        if (!empty($exemptRunIds)) {
            $query->whereNotIn('agent_runs.id', $exemptRunIds);
        }

        return $query->select('agent_runs.id')->get()->all();
    }
}
