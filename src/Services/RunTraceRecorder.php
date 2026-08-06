<?php

namespace ClarionApp\LlmClient\Services;

use Carbon\CarbonInterface;
use ClarionApp\LlmClient\Events\RunActionUpdated;
use ClarionApp\LlmClient\Events\RunStepUpdated;
use ClarionApp\LlmClient\Events\RunUpdated;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\RunRelation;
use ClarionApp\LlmClient\ValueObjects\TraceExportConfig;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunTraceRecorder
{
    /**
     * Wall-clock format for started_at/ended_at. Sub-second precision, because
     * duration_ms is derived from the two endpoints (data-model.md §3) and a
     * second-precision endpoint would make the derived figure meaningless.
     *
     * Microseconds rather than milliseconds: the endpoints are truncated on the
     * way in, so a millisecond column would inflate each step's derived duration
     * by up to 1 ms, and a run's steps could then out-total the run itself —
     * tripping the C6 clamp on rounding noise rather than on real disagreement.
     */
    private const TIMESTAMP_FORMAT = 'Y-m-d H:i:s.u';

    private ContentSanitizer $sanitizer;

    public function __construct(?ContentSanitizer $sanitizer = null)
    {
        $this->sanitizer = $sanitizer ?? app(ContentSanitizer::class);
    }

    /**
     * Fire a Phase 6 (US3) live-update event, isolated in its own try/catch
     * so a broadcast failure (e.g. Pusher unreachable, or — as
     * RunTraceRecorderBroadcastTest proves — a listener that throws) can
     * never undo or mask the recording write it's reporting on, nor change
     * the calling method's return value (standing rule 7). A narrower inner
     * try/catch immediately around the event() call, rather than relying on
     * the surrounding method's own try/catch, per research.md D3's
     * documented alternative — the surrounding catch exists to log a failed
     * *write*, and reusing it here would misreport a broadcast failure as a
     * write failure and, for openStep()/openAction(), would turn an
     * already-successful insert's id into a null return value.
     */
    private function broadcast(\Closure $emit): void
    {
        try {
            $emit();
        } catch (\Throwable $e) {
            Log::warning('RunTraceRecorder: broadcast failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Enqueue this run for external forwarding (Phase 4, US2), in its own
     * inner try/catch mirroring broadcast()'s isolation pattern immediately
     * above: a failure here must never undo or mask closeRun()'s own write,
     * nor change closeRun()'s return value (void here, but the same standing
     * rule as broadcast()). A single local write only -- no network I/O, no
     * payload assembly on this path; that happens later, on the scheduler
     * tick, in ForwardRunTracesCommand.
     */
    private function enqueueForwarding(string $runId): void
    {
        try {
            $config = TraceExportConfig::resolve();

            if (!in_array('external', $config->destinations, true)) {
                return;
            }

            DB::table('agent_run_export_queue')->insert([
                'id' => (string) Str::uuid(),
                'run_id' => $runId,
                'attempts' => 0,
                'next_attempt_at' => null,
                'created_at' => now()->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('RunTraceRecorder: enqueueForwarding failed', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Open a new run record.
     *
     * @return string|null The new run id, or null if tracing is off or the write failed.
     */
    public function openRun(
        RunKind $kind,
        string $userId,
        ?string $conversationId = null,
        ?string $source = null,
    ): ?string {
        if (!$this->enabled()) {
            return null;
        }

        try {
            $runId = (string) Str::uuid();
            $now = now()->format(self::TIMESTAMP_FORMAT);

            DB::table('agent_runs')->insert([
                'id' => $runId,
                'kind' => $kind->value,
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'source' => $source,
                'end_state' => RunEndState::InProgress->value,
                'end_reason' => null,
                'started_at' => $now,
                'ended_at' => null,
                'duration_ms' => null,
                'step_count' => 0,
                'created_at' => $now,
            ]);

            Context::add('run_id', $runId);

            return $runId;
        } catch (\Throwable $e) {
            Log::warning('RunTraceRecorder: failed to open run', [
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Open a new step within a run.
     *
     * @return string|null The new step id. Null run id is accepted and returns null.
     */
    public function openStep(
        ?string $runId,
        ?int $position = null,
        ?string $attemptGroupId = null,
    ): ?string {
        if ($runId === null || !$this->enabled()) {
            return null;
        }

        try {
            $stepId = (string) Str::uuid();
            $now = now()->format(self::TIMESTAMP_FORMAT);

            // Derive position from 1 + COUNT(*) if not provided (contract C11)
            if ($position === null) {
                $count = (int) DB::table('agent_run_steps')
                    ->where('run_id', $runId)
                    ->count();
                $position = $count + 1;
            }

            DB::table('agent_run_steps')->insert([
                'id' => $stepId,
                'run_id' => $runId,
                'position' => $position,
                'attempt_group_id' => $attemptGroupId,
                'end_state' => RunEndState::InProgress->value,
                'end_reason' => null,
                'started_at' => $now,
                'ended_at' => null,
                'duration_ms' => null,
                'wait_ms' => null,
                'attempt_count' => 1,
            ]);

            $this->broadcast(fn () => event(new RunStepUpdated($stepId)));

            return $stepId;
        } catch (\Throwable $e) {
            Log::warning('RunTraceRecorder: failed to open step', [
                'run_id' => $runId,
                'position' => $position,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Increment a still-open step's attempt count without closing it.
     * Called on a retry that re-enters the loop, so a retried round stays one step (FR-011).
     * No-op on a terminal step (contract C12).
     */
    public function recordStepAttempt(?string $stepId): void
    {
        if ($stepId === null || !$this->enabled()) {
            return;
        }

        try {
            $current = DB::table('agent_run_steps')
                ->where('id', $stepId)
                ->value('end_state');

            // No-op on terminal steps (contract C12)
            if ($current !== null && $current !== RunEndState::InProgress->value) {
                Log::warning('RunTraceRecorder: recordStepAttempt on terminal step', [
                    'step_id' => $stepId,
                    'current_state' => $current,
                ]);
                return;
            }

            DB::table('agent_run_steps')
                ->where('id', $stepId)
                ->where('end_state', RunEndState::InProgress->value)
                ->increment('attempt_count');
        } catch (\Throwable $e) {
            Log::warning('RunTraceRecorder: failed to record step attempt', [
                'step_id' => $stepId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Close a step with an end state.
     * attempt_count is preserved from recordStepAttempt calls; closeStep does not modify it.
     */
    public function closeStep(
        ?string $stepId,
        RunEndState $endState,
        ?string $reason = null,
        ?int $waitMs = null,
    ): void {
        if ($stepId === null || !$this->enabled()) {
            return;
        }

        try {
            // Check for re-transition of terminal row (contract C3)
            $current = DB::table('agent_run_steps')
                ->where('id', $stepId)
                ->value('end_state');

            if ($current !== null && $current !== RunEndState::InProgress->value) {
                Log::warning('RunTraceRecorder: closeStep on already-terminal step', [
                    'step_id' => $stepId,
                    'current_state' => $current,
                    'requested_state' => $endState->value,
                ]);
                return;
            }

            // Enforce reason requirement (contract C2)
            if ($endState->requiresReason() && $reason === null) {
                $reason = 'no reason provided';
                Log::warning('RunTraceRecorder: closeStep missing required reason', [
                    'step_id' => $stepId,
                    'end_state' => $endState->value,
                ]);
            }

            // Compute duration from started_at, clamped to >= 0 (contract C5)
            $endedAt = now();
            $startedAt = DB::table('agent_run_steps')
                ->where('id', $stepId)
                ->value('started_at');

            $durationMs = $this->elapsedMs($startedAt, $endedAt, ['step_id' => $stepId]);

            DB::table('agent_run_steps')
                ->where('id', $stepId)
                ->update([
                    'end_state' => $endState->value,
                    'end_reason' => $reason,
                    'ended_at' => $endedAt->format(self::TIMESTAMP_FORMAT),
                    'duration_ms' => $durationMs,
                    'wait_ms' => $waitMs,
                ]);

            $this->broadcast(fn () => event(new RunStepUpdated($stepId)));
        } catch (\Throwable $e) {
            Log::warning('RunTraceRecorder: failed to close step', [
                'step_id' => $stepId,
                'end_state' => $endState->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Close a run with an end state, computing step_count and duration_ms in the same UPDATE (contract C4).
     */
    public function closeRun(
        ?string $runId,
        RunEndState $endState,
        ?string $reason = null,
        ?string $replyMessageId = null,
    ): void {
        if ($runId === null || !$this->enabled()) {
            return;
        }

        try {
            // Check for re-transition of terminal row (contract C3)
            $current = DB::table('agent_runs')
                ->where('id', $runId)
                ->value('end_state');

            if ($current !== null && $current !== RunEndState::InProgress->value) {
                Log::warning('RunTraceRecorder: closeRun on already-terminal run', [
                    'run_id' => $runId,
                    'current_state' => $current,
                    'requested_state' => $endState->value,
                ]);
                return;
            }

            // Enforce reason requirement (contract C2)
            if ($endState->requiresReason() && $reason === null) {
                $reason = 'no reason provided';
                Log::warning('RunTraceRecorder: closeRun missing required reason', [
                    'run_id' => $runId,
                    'end_state' => $endState->value,
                ]);
            }

            // Compute step_count from current steps
            $stepCount = (int) DB::table('agent_run_steps')
                ->where('run_id', $runId)
                ->count();

            // Compute duration from started_at, clamped to >= 0 (contract C5)
            $endedAt = now();
            $startedAt = DB::table('agent_runs')
                ->where('id', $runId)
                ->value('started_at');

            $durationMs = $this->elapsedMs($startedAt, $endedAt, ['run_id' => $runId]);

            // Clamp run duration upward to at least the sum of its steps (contract C6, SC-012)
            $stepDurationSum = (int) DB::table('agent_run_steps')
                ->where('run_id', $runId)
                ->sum('duration_ms');

            if ($durationMs < $stepDurationSum) {
                Log::warning('RunTraceRecorder: run duration clamped upward to sum of step durations', [
                    'run_id' => $runId,
                    'raw_duration_ms' => $durationMs,
                    'step_duration_sum' => $stepDurationSum,
                ]);
                $durationMs = $stepDurationSum;
            }

            // Flush in-progress actions to 'unfinished' before the run's terminal UPDATE (contract C17).
            $this->flushUnfinishedActions($runId);

            // Single UPDATE for end_state, step_count, duration_ms (contract C4)
            DB::table('agent_runs')
                ->where('id', $runId)
                ->update([
                    'end_state' => $endState->value,
                    'end_reason' => $reason,
                    'ended_at' => $endedAt->format(self::TIMESTAMP_FORMAT),
                    'duration_ms' => $durationMs,
                    'step_count' => $stepCount,
                ]);

            $this->enqueueForwarding($runId);

            $this->broadcast(fn () => event(new RunUpdated($runId)));

            // Link reply message if provided (done after the run close so the run is terminal first)
            if ($replyMessageId !== null) {
                $this->linkMessage($runId, $replyMessageId, RunRelation::Reply);
            }
        } catch (\Throwable $e) {
            Log::warning('RunTraceRecorder: failed to close run', [
                'run_id' => $runId,
                'end_state' => $endState->value,
                'error' => $e->getMessage(),
            ]);
        } finally {
            Context::forget('run_id');
        }
    }

    /**
     * Associate a message with a run.
     * Silently replaces an existing association for the same (run, relation) pair.
     */
    public function linkMessage(
        ?string $runId,
        string $messageId,
        RunRelation $relation,
    ): void {
        if ($runId === null || !$this->enabled()) {
            return;
        }

        try {
            $assocId = (string) Str::uuid();

            // Delete any existing association for this (run_id, relation) pair, then insert.
            DB::table('agent_run_messages')
                ->where('run_id', $runId)
                ->where('relation', $relation->value)
                ->delete();

            DB::table('agent_run_messages')->insert([
                'id' => $assocId,
                'run_id' => $runId,
                'message_id' => $messageId,
                'relation' => $relation->value,
                'created_at' => now()->format(self::TIMESTAMP_FORMAT),
            ]);
        } catch (\Throwable $e) {
            Log::warning('RunTraceRecorder: failed to link message', [
                'run_id' => $runId,
                'message_id' => $messageId,
                'relation' => $relation->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convenience for one-call system-initiated work (FR-012).
     * Opens run + single step, brackets $work as an action (FR-014), closes both from the callable's outcome,
     * and rethrows the callable's exception unchanged after recording `failed`.
     *
     * The action opens after openStep() and resolves before closeStep()/closeRun() (C26),
     * so flushUnfinishedActions() never observes it open.
     */
    public function traceSystemRun(
        string $source,
        string $userId,
        ?string $conversationId,
        callable $work,
        ActionType $actionType = ActionType::LlmRequest,
        ?string $target = null,
    ): mixed {
        $runId = $this->openRun(
            RunKind::SystemInitiated,
            $userId,
            $conversationId,
            $source,
        );
        $stepId = null;
        if ($runId !== null) {
            $stepId = $this->openStep($runId);
        }

        // Open action for the callable (FR-014, D12).
        $actionId = null;
        if ($stepId !== null) {
            $actionId = $this->openAction($stepId, $actionType, $target);
        }

        try {
            $result = $work();

            if ($runId !== null) {
                // Close action Success before closeStep/closeRun (C26).
                if ($actionId !== null) {
                    $this->closeAction($actionId, ActionOutcome::Success);
                }
                if ($stepId !== null) {
                    $this->closeStep($stepId, RunEndState::Completed);
                }
                $this->closeRun($runId, RunEndState::Completed);
            }

            return $result;
        } catch (\Throwable $e) {
            if ($runId !== null) {
                // Close action Failure before closeStep/closeRun (C26).
                if ($actionId !== null) {
                    $this->closeAction($actionId, ActionOutcome::Failure, $e->getMessage());
                }
                if ($stepId !== null) {
                    $this->closeStep($stepId, RunEndState::Failed, $e->getMessage());
                }
                $this->closeRun($runId, RunEndState::Failed, $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Elapsed milliseconds from a stored start timestamp to $endedAt, clamped to >= 0
     * (contract C5). A negative raw value means the wall clock moved backwards mid-run;
     * it is clamped and logged, and both endpoints stay on the row so the raw figures
     * remain auditable (data-model.md §3).
     */
    private function elapsedMs(?string $startedAt, CarbonInterface $endedAt, array $logContext): int
    {
        if ($startedAt === null) {
            return 0;
        }

        // Truncated, not rounded: a rounded-up step duration could exceed the real
        // elapsed time and out-total its own run.
        $rawMs = (int) \Carbon\Carbon::parse($startedAt)->diffInMilliseconds($endedAt, false);

        if ($rawMs < 0) {
            Log::warning('RunTraceRecorder: negative duration clamped to 0', $logContext + [
                'raw_duration_ms' => $rawMs,
            ]);

            return 0;
        }

        return $rawMs;
    }

    /**
     * Check if tracing is enabled via config.
     */
    protected function enabled(): bool
    {
        return (bool) config('llm-client.run_trace.enabled', true);
    }

    /**
     * Open a new action within a step.
     *
     * @return string|null The new action id, or null if tracing is off, the step is null,
     *                     the run's action cap is exceeded, or the write failed.
     */
    public function openAction(
        ?string $stepId,
        ActionType $actionType,
        ?string $target = null,
        ?string $attemptGroupId = null,
        ?string $parentActionId = null,
    ): ?string {
        if ($stepId === null || !$this->enabled()) {
            return null;
        }

        try {
            // Look up run_id from the step row (denormalized for query efficiency).
            $runId = (string) DB::table('agent_run_steps')
                ->where('id', $stepId)
                ->value('run_id');

            if ($runId === null) {
                return null;
            }

            // Per-run action count cap check (contract C14).
            $cap = (int) config('llm-client.run_trace.action_row_cap', 500);
            if ($cap > 0) {
                $count = (int) DB::table('agent_run_actions')
                    ->where('run_id', $runId)
                    ->count();

                if ($count >= $cap) {
                    Log::warning('RunTraceRecorder: action row cap exceeded', [
                        'run_id' => $runId,
                        'cap' => $cap,
                        'current' => $count,
                    ]);
                    return null;
                }
            }

            $actionId = (string) Str::uuid();
            $now = now()->format(self::TIMESTAMP_FORMAT);

            DB::table('agent_run_actions')->insert([
                'id' => $actionId,
                'run_id' => $runId,
                'step_id' => $stepId,
                'action_type' => $actionType->value,
                'target' => $target,
                'attempt_group_id' => $attemptGroupId,
                'parent_action_id' => $parentActionId,
                'outcome' => ActionOutcome::InProgress->value,
                'failure_reason' => null,
                'paused_at' => null,
                'started_at' => $now,
                'ended_at' => null,
                'duration_ms' => null,
                'content' => null,
                'created_at' => $now,
            ]);

            $this->broadcast(fn () => event(new RunActionUpdated($actionId)));

            return $actionId;
        } catch (\Throwable $e) {
            Log::warning('RunTraceRecorder: failed to open action', [
                'step_id' => $stepId,
                'action_type' => $actionType->value,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Close an action with an outcome.
     *
     * $content is passed through ContentSanitizer before persistence (FR-006, FR-007).
     * $target is NOT sanitized or truncated (FR-016) — it is set at open and never rewritten.
     * Null $actionId is accepted and returns void (contract C1).
     */
    public function closeAction(
        ?string $actionId,
        ActionOutcome $outcome,
        ?string $failureReason = null,
        ?string $content = null,
    ): void {
        if ($actionId === null || !$this->enabled()) {
            return;
        }

        try {
            $current = DB::table('agent_run_actions')
                ->where('id', $actionId)
                ->value('outcome');

            if ($current === null) {
                return;
            }

            // Suspend path: AwaitingConfirmation stamps paused_at and leaves ended_at/duration_ms null.
            if ($outcome === ActionOutcome::AwaitingConfirmation) {
                // Only transition from in_progress to awaiting_confirmation.
                if ($current !== ActionOutcome::InProgress->value) {
                    Log::warning('RunTraceRecorder: closeAction AwaitingConfirmation from non-in_progress state', [
                        'action_id' => $actionId,
                        'current_state' => $current,
                    ]);
                    return;
                }

                DB::table('agent_run_actions')
                    ->where('id', $actionId)
                    ->update([
                        'outcome' => ActionOutcome::AwaitingConfirmation->value,
                        'paused_at' => now()->format(self::TIMESTAMP_FORMAT),
                    ]);

                $this->broadcast(fn () => event(new RunActionUpdated($actionId)));

                return;
            }

            // Resolve path: transitioning from awaiting_confirmation to Success/Failure (C23).
            $resolvingPaused = $current === ActionOutcome::AwaitingConfirmation->value;

            // Terminal-state guard (C16): refuse to transition an already-terminal action.
            // AwaitingConfirmation is the sole exception (C23).
            if (!$resolvingPaused) {
                $currentOutcome = ActionOutcome::from($current);
                if ($currentOutcome->isTerminal()) {
                    Log::warning('RunTraceRecorder: closeAction on already-terminal action', [
                        'action_id' => $actionId,
                        'current_state' => $current,
                        'requested_state' => $outcome->value,
                    ]);
                    return;
                }
            }

            // When resolving from awaiting_confirmation, only Success or Failure are permitted (C23).
            if ($resolvingPaused && $outcome !== ActionOutcome::Success && $outcome !== ActionOutcome::Failure) {
                Log::warning('RunTraceRecorder: closeAction from awaiting_confirmation to non-terminal', [
                    'action_id' => $actionId,
                    'requested_state' => $outcome->value,
                ]);
                return;
            }

            // Enforce reason requirement.
            if ($outcome->requiresReason() && $failureReason === null) {
                $failureReason = 'no reason provided';
                Log::warning('RunTraceRecorder: closeAction missing required failure_reason', [
                    'action_id' => $actionId,
                ]);
            }

            // Compute duration.
            $endedAt = now();
            $startedAt = DB::table('agent_run_actions')
                ->where('id', $actionId)
                ->value('started_at');

            if ($resolvingPaused) {
                // Exclude the [paused_at, resume] window from duration (C24).
                $pausedAt = DB::table('agent_run_actions')
                    ->where('id', $actionId)
                    ->value('paused_at');

                // Duration = (paused_at - started_at) + (endedAt - now_as_resume)
                // The "resume" point is the current now() — the pause was broken when we entered this close.
                $durationMs = 0;
                if ($startedAt && $pausedAt) {
                    $prePauseMs = $this->elapsedMs($startedAt, \Carbon\Carbon::parse($pausedAt), [
                        'action_id' => $actionId,
                        'phase' => 'pre_pause',
                    ]);
                    // Post-resume portion: from now() (resume) to now() (close) — effectively 0 in practice,
                    // but we measure it correctly.
                    $resumeTime = $endedAt;
                    $postResumeMs = $this->elapsedMs($resumeTime->format(self::TIMESTAMP_FORMAT), $endedAt, [
                        'action_id' => $actionId,
                        'phase' => 'post_resume',
                    ]);
                    $durationMs = $prePauseMs + $postResumeMs;
                }
            } else {
                $durationMs = $this->elapsedMs($startedAt, $endedAt, ['action_id' => $actionId]);
            }

            // Sanitize content (contract C15).
            if ($content !== null) {
                $content = $this->sanitizer->prepare($content);
            }

            DB::table('agent_run_actions')
                ->where('id', $actionId)
                ->update([
                    'outcome' => $outcome->value,
                    'failure_reason' => $failureReason,
                    'ended_at' => $endedAt->format(self::TIMESTAMP_FORMAT),
                    'duration_ms' => $durationMs,
                    'content' => $content,
                ]);

            $this->broadcast(fn () => event(new RunActionUpdated($actionId)));
        } catch (\Throwable $e) {
            Log::warning('RunTraceRecorder: failed to close action', [
                'action_id' => $actionId,
                'outcome' => $outcome->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write an already-finished action in a single INSERT, with explicit timestamps.
     *
     * @return string|null The action id, or null on no-op/failure.
     */
    public function recordCompletedAction(
        ?string $stepId,
        ActionType $actionType,
        ActionOutcome $outcome,
        \DateTimeInterface $startedAt,
        \DateTimeInterface $endedAt,
        ?string $target = null,
        ?string $attemptGroupId = null,
        ?string $parentActionId = null,
        ?string $failureReason = null,
        ?string $content = null,
    ): ?string {
        if ($stepId === null || !$this->enabled()) {
            return null;
        }

        try {
            $runId = (string) DB::table('agent_run_steps')
                ->where('id', $stepId)
                ->value('run_id');

            if ($runId === null) {
                return null;
            }

            // Per-run cap check (C14).
            $cap = (int) config('llm-client.run_trace.action_row_cap', 500);
            if ($cap > 0) {
                $count = (int) DB::table('agent_run_actions')
                    ->where('run_id', $runId)
                    ->count();

                if ($count >= $cap) {
                    Log::warning('RunTraceRecorder: action row cap exceeded (recordCompletedAction)', [
                        'run_id' => $runId,
                        'cap' => $cap,
                    ]);
                    return null;
                }
            }

            // Compute duration from caller's measured times, never the write time (C25).
            $durationMs = $this->elapsedMs($startedAt->format(self::TIMESTAMP_FORMAT), \Carbon\Carbon::instance($endedAt), [
                'method' => 'recordCompletedAction',
            ]);

            // Enforce reason requirement.
            if ($outcome->requiresReason() && $failureReason === null) {
                $failureReason = 'no reason provided';
            }

            // Sanitize content (C15).
            if ($content !== null) {
                $content = $this->sanitizer->prepare($content);
            }

            $actionId = (string) Str::uuid();
            $now = now()->format(self::TIMESTAMP_FORMAT);

            DB::table('agent_run_actions')->insert([
                'id' => $actionId,
                'run_id' => $runId,
                'step_id' => $stepId,
                'action_type' => $actionType->value,
                'target' => $target,
                'attempt_group_id' => $attemptGroupId,
                'parent_action_id' => $parentActionId,
                'outcome' => $outcome->value,
                'failure_reason' => $failureReason,
                'paused_at' => null,
                'started_at' => $startedAt->format(self::TIMESTAMP_FORMAT),
                'ended_at' => $endedAt->format(self::TIMESTAMP_FORMAT),
                'duration_ms' => $durationMs,
                'content' => $content,
                'created_at' => $now,
            ]);

            return $actionId;
        } catch (\Throwable $e) {
            Log::warning('RunTraceRecorder: failed to record completed action', [
                'step_id' => $stepId,
                'action_type' => $actionType->value,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Flush all in-progress actions under a run to 'unfinished'.
     * Called from closeRun() — not called independently.
     * Never touches rows in 'awaiting_confirmation' (FR-015, C22).
     */
    public function flushUnfinishedActions(?string $runId): void
    {
        if ($runId === null || !$this->enabled()) {
            return;
        }

        try {
            $timeoutMinutes = (int) config('llm-client.run_trace.action_timeout_minutes', 5);
            $now = now();

            // Fetch all in_progress actions for this run (C22: only 'in_progress', never 'awaiting_confirmation').
            $actions = DB::table('agent_run_actions')
                ->where('run_id', $runId)
                ->where('outcome', ActionOutcome::InProgress->value)
                ->get();

            if ($actions->isEmpty()) {
                return;
            }

            foreach ($actions as $action) {
                $startedAt = \Carbon\Carbon::parse($action->started_at);
                $durationMs = $this->elapsedMs($action->started_at, $now, [
                    'action_id' => $action->id,
                ]);

                $failureReason = null;

                // Check 5-minute timeout (FR-009).
                if ($startedAt->diffInMinutes($now) >= $timeoutMinutes) {
                    $failureReason = sprintf('action exceeded %d-minute timeout', $timeoutMinutes);
                }

                DB::table('agent_run_actions')
                    ->where('id', $action->id)
                    ->update([
                        'outcome' => ActionOutcome::Unfinished->value,
                        'failure_reason' => $failureReason,
                        'ended_at' => $now->format(self::TIMESTAMP_FORMAT),
                        'duration_ms' => $durationMs,
                    ]);
            }
        } catch (\Throwable $e) {
            Log::warning('RunTraceRecorder: failed to flush unfinished actions', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
