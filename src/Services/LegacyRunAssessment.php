<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\ResumeMode;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\Unresumable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Decides whether a task that was already running when this version was
 * deployed (a run with no checkpoint) can be continued, and adopts it when
 * it can.
 *
 * A legacy run is resumable only when its record suffices: an interactive
 * root run whose conversation is still working, with either no open step or
 * an open step holding only a lost model call (no tool was started, so
 * nothing can have taken effect unrecorded). Unattended roots and helpers
 * need options and deadlines their record never held. Every other legacy run
 * is stopped with a report instead of being swept as abandoned.
 */
class LegacyRunAssessment
{
    public const BETWEEN_STEPS = 'between_steps';

    public const LOST_MODEL_CALL = 'lost_model_call';

    /**
     * The rule that makes the run's record sufficient, or the verdict that
     * it is not.
     */
    public function assess(AgentRun $run): string|Unresumable
    {
        $insufficient = fn (string $detail) => new Unresumable('legacy_insufficient', $detail);

        if ($run->kind !== RunKind::Interactive) {
            return $insufficient('unattended');
        }

        if ($this->isHelperRun($run)) {
            return $insufficient('helper');
        }

        $conversation = Conversation::find($run->conversation_id);
        if ($conversation === null || !$conversation->is_processing) {
            return $insufficient('conversation_not_working');
        }

        $openStepIds = DB::table('agent_run_steps')
            ->where('run_id', $run->id)
            ->where('end_state', 'in_progress')
            ->pluck('id');

        if ($openStepIds->isEmpty()) {
            return self::BETWEEN_STEPS;
        }
        if ($openStepIds->count() > 1) {
            return $insufficient('several_open_steps');
        }

        $toolStarted = DB::table('agent_run_actions')
            ->where('step_id', $openStepIds->first())
            ->where('action_type', 'tool_invocation')
            ->exists();

        return $toolStarted ? $insufficient('tool_started') : self::LOST_MODEL_CALL;
    }

    /**
     * Whether the run is a delegated helper's. A delegation names its helper
     * run only once it ends, so the helper's conversation is matched too.
     */
    public function isHelperRun(AgentRun $run): bool
    {
        return DB::table('agent_delegations')
            ->where(fn ($q) => $q->where('helper_run_id', $run->id)->orWhere('helper_conversation_id', $run->conversation_id))
            ->exists();
    }

    /**
     * Adopt a legacy run found interrupted by the sweep: it is marked
     * interrupted with a fresh dispatch id (counting that interruption), so
     * the resume job's claim takes it over like any other.
     *
     * Returns the dispatch id, or null when another process adopted or ended
     * the run first.
     */
    public function adoptInterrupted(AgentRun $run, string $assessment): ?string
    {
        $dispatch = (string) Str::uuid();

        $adopted = $this->adopt($run, $assessment, 'sweep', [
            'resume_state' => 'interrupted',
            'interrupted_at' => now()->format('Y-m-d H:i:s.u'),
            'dispatch_id' => $dispatch,
            'lease_holder' => null,
            'lease_expires_at' => null,
            'consecutive_interruptions' => 1,
        ]);

        return $adopted ? $dispatch : null;
    }

    /**
     * Adopt a run at a stream step boundary: the process finishing a step of
     * a run that began before the deployment takes its lease so its
     * hand-off to the next step works as for any run. A run that already
     * carries a checkpoint (or when resuming is off) is left alone.
     */
    public function adoptAtBoundary(string $runId): bool
    {
        if (!(bool) config('llm-client.resume.enabled', true)) {
            return false;
        }

        $run = AgentRun::find($runId);
        if ($run === null || $run->checkpoint_version !== null || $run->end_state?->value !== 'in_progress') {
            return false;
        }

        $window = (int) config('llm-client.resume.detection_window_seconds', 300);

        return $this->adopt($run, 'stream_boundary', 'boundary', [
            'resume_state' => 'working',
            'dispatch_id' => (string) Str::uuid(),
            'lease_holder' => app(RunLease::class)->nonce(),
            'lease_expires_at' => now()->addSeconds($window)->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * @param array<string, mixed> $state the lease / dispatch columns for the adopted run
     */
    private function adopt(AgentRun $run, string $assessment, string $via, array $state): bool
    {
        $mode = $run->is_streamed ? ResumeMode::Stream : ResumeMode::Sync;

        $affected = DB::table('agent_runs')
            ->where('id', $run->id)
            ->whereNull('checkpoint_version')
            ->where('end_state', 'in_progress')
            ->update($state + [
                'checkpoint_version' => 1,
                'resume_mode' => $mode->value,
                'resume_driver' => 'root',
                'resume_options' => '{}',
            ]);
        if ($affected === 0) {
            return false;
        }

        $this->backfillSteps((string) $run->id);

        app(ResumeEventLog::class)->record((string) $run->id, 'legacy_adopted', null, null, [
            'assessment' => $assessment,
            'via' => $via,
            'mode' => $mode->value,
        ]);

        return true;
    }

    /**
     * The steps of a pre-checkpoint run carry no iteration number and the
     * open one no checkpoint. The loop's iteration is the step's position in
     * every run that never paused, so that is what is recorded; the open
     * step is checkpointed as waiting on its model call.
     */
    private function backfillSteps(string $runId): void
    {
        DB::table('agent_run_steps')
            ->where('run_id', $runId)
            ->whereNull('iteration')
            ->update(['iteration' => DB::raw('position')]);

        $open = DB::table('agent_run_steps')
            ->where('run_id', $runId)
            ->where('end_state', 'in_progress')
            ->whereNull('checkpoint')
            ->get(['id', 'iteration']);

        foreach ($open as $step) {
            DB::table('agent_run_steps')->where('id', $step->id)->update([
                'checkpoint_state' => 'open',
                'checkpoint' => json_encode(['v' => 1, 'iteration' => (int) $step->iteration, 'model' => null, 'calls' => []]),
            ]);
        }
    }
}
