<?php

namespace ClarionApp\LlmClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to resolve abandoned (stale in_progress) agent runs.
 *
 * Finds runs that are still `in_progress` but whose latest activity exceeds
 * the abandonment threshold, and transitions them and their open steps to
 * `abandoned`. Runs with a pending human confirmation are exempted.
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

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes')
            ?? config('llm-client.run_trace.abandonment_minutes', 60));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subMinutes($minutes);

        $this->info("Abandonment threshold: {$minutes} minutes (cutoff: {$cutoff->toDateTimeString()})");
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        // Collect run ids that have a pending confirmation still within the
        // confirmation_timeout window. These runs are exempt from the sweep.
        $exemptRunIds = $this->getPendingConfirmationRunIds();

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
            $now = now();
            $startedAt = \Carbon\Carbon::parse($run->started_at);
            $durationMs = max(0, $startedAt->diffInMilliseconds($now, false));
            $stepCount = (int) DB::table('agent_run_steps')->where('run_id', $runId)->count();

            if (!$dryRun) {
                DB::table('agent_runs')
                    ->where('id', $runId)
                    ->where('end_state', 'in_progress')
                    ->update([
                        'end_state' => 'abandoned',
                        'end_reason' => 'resolved by sweep: no activity for ' . $minutes . ' minutes',
                        'ended_at' => $now->format('Y-m-d H:i:s.u'),
                        'duration_ms' => $durationMs,
                        'step_count' => $stepCount,
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
                    // Last observed activity for this step.
                    $stepEndedAt = \Carbon\Carbon::parse($step->ended_at ?? $step->started_at);
                    $stepDurationMs = max(0, $stepEndedAt->diffInMilliseconds($now, false));

                    DB::table('agent_run_steps')
                        ->where('id', $stepId)
                        ->where('end_state', 'in_progress')
                        ->update([
                            'end_state' => 'abandoned',
                            'end_reason' => 'resolved by sweep: run abandoned',
                            'ended_at' => $stepEndedAt,
                            'duration_ms' => $stepDurationMs,
                        ]);
                    $resolvedSteps++;
                }

                $resolvedRuns++;

                Log::info('Abandoned run resolved', [
                    'run_id' => $runId,
                    'started_at' => $startedAt,
                    'duration_ms' => $durationMs,
                    'step_count' => $stepCount,
                    'open_steps_closed' => count($openSteps),
                ]);
            }
        }

        $this->info("Runs resolved: {$resolvedRuns}");
        $this->info("Open steps closed: {$resolvedSteps}");

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
        }

        return self::SUCCESS;
    }

    /**
     * Get run ids that have a pending confirmation still within the timeout window.
     *
     * These runs are exempt from the sweep because the step is legitimately
     * waiting on a human response.
     *
     * @return array<string>
     */
    private function getPendingConfirmationRunIds(): array
    {
        if (!DB::getSchemaBuilder()->hasTable('messages')) {
            return [];
        }

        // Find messages with pending confirmations that haven't expired yet.
        // tool_data is JSON; we need to check pending=true and expires_at in the future.
        // SQLite doesn't have native JSON extraction for WHERE clauses in all versions,
        // so we fetch and parse.
        $pendingMessages = DB::table('messages')
            ->whereNotNull('tool_data')
            ->get();

        $nowIso = now()->toIso8601String();
        $exemptRunIds = [];
        foreach ($pendingMessages as $msg) {
            $toolData = is_string($msg->tool_data)
                ? json_decode($msg->tool_data, true)
                : $msg->tool_data;

            if (!is_array($toolData)) {
                continue;
            }

            if (($toolData['pending'] ?? false)
                && ($toolData['expires_at'] ?? null) !== null
                && $toolData['expires_at'] > $nowIso
            ) {
                $runId = $toolData['run_id'] ?? null;
                if ($runId !== null) {
                    $exemptRunIds[] = $runId;
                }
            }
        }

        return array_values(array_unique($exemptRunIds));
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
        // First, get all in_progress runs.
        $inProgressRuns = DB::table('agent_runs')
            ->where('end_state', 'in_progress')
            ->select('id', 'started_at')
            ->get();

        if ($inProgressRuns->isEmpty()) {
            return collect();
        }

        $eligibleIds = [];

        foreach ($inProgressRuns as $run) {
            $runId = (string) $run->id;

            // Skip exempt runs (pending confirmation).
            if (in_array($runId, $exemptRunIds)) {
                continue;
            }

            // Find the latest activity for this run.
            // Use MAX(COALESCE(ended_at, started_at)) from steps, or the run's started_at if no steps.
            $latestStepActivity = DB::table('agent_run_steps')
                ->where('run_id', $runId)
                ->selectRaw('MAX(COALESCE(ended_at, started_at)) as latest_activity')
                ->value('latest_activity');

            $latestActivity = $latestStepActivity ?: $run->started_at;

            // Check if the latest activity is older than the cutoff.
            if ($latestActivity < $cutoff->toDateTimeString()) {
                $eligibleIds[] = $runId;
            }
        }

        if (empty($eligibleIds)) {
            return collect();
        }

        return DB::table('agent_runs')
            ->whereIn('id', $eligibleIds)
            ->select('id', 'started_at', 'end_state')
            ->get();
    }
}
