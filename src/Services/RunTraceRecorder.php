<?php

namespace ClarionApp\LlmClient\Services;

use Carbon\CarbonInterface;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\RunRelation;
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
     * Opens run + single step, closes both from the callable's outcome,
     * and rethrows the callable's exception unchanged after recording `failed`.
     */
    public function traceSystemRun(
        string $source,
        string $userId,
        ?string $conversationId,
        callable $work,
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

        try {
            $result = $work();

            if ($runId !== null) {
                if ($stepId !== null) {
                    $this->closeStep($stepId, RunEndState::Completed);
                }
                $this->closeRun($runId, RunEndState::Completed);
            }

            return $result;
        } catch (\Throwable $e) {
            if ($runId !== null) {
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
}
