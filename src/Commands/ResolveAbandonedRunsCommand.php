<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Services\PausedRunQuery;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to resolve abandoned (stale in_progress) agent runs.
 *
 * Finds runs that are still `in_progress` but whose latest activity exceeds
 * the abandonment threshold, and transitions them and their open steps to
 * `abandoned`. Runs with a pending human confirmation, or a pending
 * question their user can still answer, are exempted.
 *
 * Eligibility (data-model.md §4):
 *   end_state = 'in_progress' AND
 *   MAX(COALESCE(steps.ended_at, steps.started_at)) — or the run's own started_at
 *   when it has no steps — older than now() - abandonment_minutes.
 *
 * Usage:
 *   php artisan llm-client:resolve-abandoned-runs [--minutes=60] [--dry-run]
 */
class ResolveAbandonedRunsCommand extends Command
{
    protected $signature = 'llm-client:resolve-abandoned-runs
                            {--minutes= : Abandonment threshold in minutes (default: from config)}
                            {--dry-run : Show what would be resolved without actually resolving}';

    protected $description = 'Resolve abandoned (stale in_progress) agent runs and their open steps';

    public function handle(RunTraceRecorder $recorder): int
    {
        $minutes = (int) ($this->option('minutes')
            ?? config('llm-client.run_trace.abandonment_minutes', 60));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subMinutes($minutes);

        $this->info("Abandonment threshold: {$minutes} minutes (cutoff: {$cutoff->toDateTimeString()})");
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        // Runs waiting on a pending, still-answerable human confirmation, or
        // a pending question the user can still answer, are exempt from the
        // sweep (shared with the resume-detection sweep).
        $exemptRunIds = app(PausedRunQuery::class)->exemptRunIds($cutoff);

        // Find eligible abandoned runs: in_progress, latest activity older than cutoff,
        // and not exempt (pending confirmation).
        // The eligibility query uses the driving index on ['end_state', 'started_at'].
        $eligibleRuns = $this->findEligibleRuns($cutoff, $exemptRunIds);

        if (empty($eligibleRuns)) {
            $this->info('No abandoned runs to resolve');
            if ($dryRun) {
                $this->comment('Dry-run complete — no changes were made');
            }
            return self::SUCCESS;
        }

        $resolvedRuns = 0;
        $resolvedSteps = 0;

        foreach ($eligibleRuns as $run) {
            $runId = (string) $run->id;

            // Resolve the run: set end_state, end_reason, ended_at, duration_ms, step_count.
            // ended_at is the sweep's own now(), so the duration covers the whole
            // abandoned span — the honest figure for how long this was outstanding
            // before anyone noticed (data-model.md §4).
            $now = now();
            $startedAt = \Carbon\Carbon::parse($run->started_at);
            $durationMs = max(0, (int) $startedAt->diffInMilliseconds($now, false));
            $stepCount = (int) DB::table('agent_run_steps')->where('run_id', $runId)->count();

            if ($dryRun) {
                // Report what would be resolved. A dry run that reports zero is
                // indistinguishable from one that found nothing.
                $resolvedRuns++;
                $resolvedSteps += DB::table('agent_run_steps')
                    ->where('run_id', $runId)
                    ->where('end_state', 'in_progress')
                    ->count();
            }

            if (!$dryRun) {
                // FR-009: the sweep bypasses closeRun() by design (its own bulk
                // UPDATE, see the comment on enqueueForwarding() below), so it
                // must compute the four latency-breakdown columns itself rather
                // than leaving them null forever.
                $breakdown = $recorder->computeLatencyBreakdown($runId, $durationMs);

                DB::table('agent_runs')
                    ->where('id', $runId)
                    ->where('end_state', 'in_progress')
                    ->update([
                        'end_state' => 'abandoned',
                        'end_reason' => 'resolved by sweep: no activity for ' . $minutes . ' minutes',
                        'ended_at' => $now->format('Y-m-d H:i:s.u'),
                        'duration_ms' => $durationMs,
                        'step_count' => $stepCount,
                        'model_wait_ms' => $breakdown['model_wait_ms'],
                        'tool_exec_ms' => $breakdown['tool_exec_ms'],
                        'confirm_wait_ms' => $breakdown['confirm_wait_ms'],
                        'product_ms' => $breakdown['product_ms'],
                        'resume_state' => null,
                        'dispatch_id' => null,
                        'lease_holder' => null,
                    ]);

                // Close any still-open steps in the same pass.
                // Use the step's last observed activity (COALESCE(ended_at, started_at))
                // rather than now(), so the step's duration doesn't absorb detection lag.
                $openSteps = DB::table('agent_run_steps')
                    ->where('run_id', $runId)
                    ->where('end_state', 'in_progress')
                    ->get();

                foreach ($openSteps as $step) {
                    $stepId = (string) $step->id;

                    // The step ends at its last observed activity, not at now():
                    // it genuinely stopped when it stopped, and its duration must
                    // not absorb the sweep's detection lag (data-model.md §4).
                    // For a step that never closed, that activity is its own start,
                    // so the honest recorded duration is 0.
                    $stepStartedAt = \Carbon\Carbon::parse($step->started_at);
                    $stepEndedAt = \Carbon\Carbon::parse($step->ended_at ?? $step->started_at);
                    $stepDurationMs = max(0, (int) $stepStartedAt->diffInMilliseconds($stepEndedAt, false));

                    DB::table('agent_run_steps')
                        ->where('id', $stepId)
                        ->where('end_state', 'in_progress')
                        ->update([
                            'end_state' => 'abandoned',
                            'end_reason' => 'resolved by sweep: run abandoned',
                            'ended_at' => $stepEndedAt->format('Y-m-d H:i:s.u'),
                            'duration_ms' => $stepDurationMs,
                        ]);
                    $resolvedSteps++;
                }

                $resolvedRuns++;

                // The run was closed above via a raw UPDATE, not
                // RunTraceRecorder::closeRun() -- deliberately, to keep the
                // sweep's bulk-update shape (one grouped eligibility query,
                // then a per-run terminal UPDATE without closeRun()'s extra
                // re-transition/reason/step-duration bookkeeping). That means
                // closeRun()'s own enqueueForwarding() call never runs for a
                // swept run, so it is called explicitly here -- the same
                // "forwarded, not omitted" guarantee every other terminal
                // state gets (spec.md US2 Acceptance Scenario 2), without
                // rerouting the sweep through closeRun(). Isolated by
                // enqueueForwarding()'s own inner try/catch, so a forwarding
                // failure can never undo the abandonment resolution above.
                $recorder->enqueueForwarding($runId);

                // The swept run's task ends with it, as closeRun() would
                // have ended it. Best-effort: never throws.
                app(\ClarionApp\LlmClient\Services\TaskBudgetService::class)->rootRunClosed(
                    $runId,
                    \ClarionApp\LlmClient\ValueObjects\RunEndState::Abandoned,
                    'resolved by sweep: no activity for ' . $minutes . ' minutes',
                );

                Log::info('Abandoned run resolved', [
                    'run_id' => $runId,
                    'started_at' => $startedAt,
                    'duration_ms' => $durationMs,
                    'step_count' => $stepCount,
                    'open_steps_closed' => count($openSteps),
                ]);
            }
        }

        $verb = $dryRun ? 'would be resolved' : 'resolved';
        $this->info("Runs {$verb}: {$resolvedRuns}");
        $this->info(($dryRun ? 'Open steps that would be closed: ' : 'Open steps closed: ') . $resolvedSteps);

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
        }

        return self::SUCCESS;
    }

    /**
     * Find eligible abandoned runs.
     *
     * A run is eligible when:
     * - end_state = 'in_progress'
     * - MAX(COALESCE(steps.ended_at, steps.started_at)) or the run's started_at
     *   (when no steps exist) is older than the cutoff
     * - The run is not in the exempt list (pending confirmation)
     *
     * @param \Carbon\Carbon $cutoff The cutoff timestamp
     * @param array<string> $exemptRunIds Run ids to skip
     * @return \Illuminate\Support\Collection
     */
    private function findEligibleRuns($cutoff, array $exemptRunIds)
    {
        // One grouped query rather than a MAX() per in-progress run. The cutoff is
        // formatted at the same precision the endpoints are stored at, so a run is
        // not held back by a sub-second comparison artifact.
        //
        // The run's latest activity is the newest of four signals: its steps'
        // last observed activity, its last progress, its lease expiry, and its
        // next provider-retry attempt. A run still inside its lease window or
        // mid-retry (its next attempt still in the future) is not abandoned.
        // GREATEST is not portable (SQLite lacks it; MySQL's multi-argument
        // MAX is an aggregate), so it is built as nested CASE.
        $latestActivity = DB::table('agent_run_steps')
            ->select('run_id', DB::raw('MAX(COALESCE(ended_at, started_at)) as step_activity'))
            ->groupBy('run_id');

        $stepActivity = 'COALESCE(activity.step_activity, agent_runs.started_at)';
        $lastProgress = 'COALESCE(agent_runs.last_progress_at, agent_runs.started_at)';
        $leaseExpires = 'COALESCE(agent_runs.lease_expires_at, agent_runs.started_at)';
        $nextAttempt = 'COALESCE(agent_runs.next_attempt_at, agent_runs.started_at)';

        $greatest2 = fn (string $a, string $b) => "CASE WHEN {$a} >= {$b} THEN {$a} ELSE {$b} END";
        $latest = $greatest2(
            $greatest2($greatest2($stepActivity, $lastProgress), $leaseExpires),
            $nextAttempt,
        );

        $query = DB::table('agent_runs')
            ->leftJoinSub($latestActivity, 'activity', 'activity.run_id', '=', 'agent_runs.id')
            ->where('agent_runs.end_state', 'in_progress')
            ->whereRaw("{$latest} < ?", [$cutoff->format('Y-m-d H:i:s.u')]);

        if (!empty($exemptRunIds)) {
            $query->whereNotIn('agent_runs.id', $exemptRunIds);
        }

        return $query
            ->select('agent_runs.id', 'agent_runs.started_at', 'agent_runs.end_state')
            ->get();
    }
}
