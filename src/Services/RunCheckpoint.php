<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\CheckpointWriteFailed;
use ClarionApp\LlmClient\Exceptions\LeaseLost;
use ClarionApp\LlmClient\ValueObjects\ResumeDriver;
use ClarionApp\LlmClient\ValueObjects\ResumeMode;
use ClarionApp\LlmClient\ValueObjects\ToolEffect;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The strict checkpoint writer (checkpoint-ledger contract §1). It writes
 * the run's and step's checkpoint state at the five write points (C0–C5)
 * and the pause point (C6), and it is strict: it throws instead of
 * swallowing (a checkpoint that cannot be written stops the run), it
 * refuses inside a transaction, it is a no-op when the run is absent or
 * resume is disabled, and a superseded process loses its next write with
 * a LeaseLost. The first state-changing write after a resume lands the
 * run back in `working`, in the same UPDATE that renews the lease.
 */
class RunCheckpoint
{
    /** D11: the only options that may be stored in resume_options. */
    private const OPTION_WHITELIST = [
        'unattended',
        'retry_limit',
        'max_iterations',
        'deadline_at',
        'preset',
        'preset_params',
        'schema_overrides',
        'schema',
        'retry_on_validation_failure',
        'max_schema_retries',
        'on_complete',
        'delivery',
    ];

    public function __construct(
        private readonly RunLease $lease,
    ) {
    }

    /**
     * C0 — the run is checkpointed: version 1, its mode and driver, its
     * whitelisted options, its first dispatch id, and this process's
     * lease for the detection window. An options set too large to store
     * marks the run not resumable (driver `none`) rather than truncated.
     */
    public function openRun(string $runId, ResumeMode $mode, ResumeDriver $driver, array $options): string
    {
        $this->assertOutsideTransaction();
        if (!$this->enabled()) {
            return $this->lease->nonce();
        }

        $whitelisted = array_intersect_key($options, array_flip(self::OPTION_WHITELIST));
        $json = json_encode($whitelisted);
        $oversized = $json !== false && strlen($json) > (int) config('llm-client.resume.max_options_bytes', 65536);

        DB::table('agent_runs')->where('id', $runId)->update([
            'checkpoint_version' => 1,
            'resume_state' => 'working',
            'resume_mode' => $mode->value,
            'resume_driver' => $oversized ? ResumeDriver::None->value : $driver->value,
            'resume_options' => $oversized ? null : $json,
            'dispatch_id' => (string) Str::uuid(),
            'lease_holder' => $this->lease->nonce(),
            'lease_expires_at' => $this->timestamp(now()->addSeconds($this->window())),
        ]);

        $this->announce($runId);

        return $this->lease->nonce();
    }

    /**
     * C1 — a step is opened: its loop iteration and its fresh checkpoint.
     * The write does not claim a released lease (a stream hand-off): the
     * finishing worker's dispatch runs after the hand-off, and the next
     * worker's first write is the claim. This process only renews a
     * lease it already holds; a foreign holder fails.
     */
    public function openStep(?string $runId, string $stepId, int $iteration): void
    {
        $this->assertOutsideTransaction();
        if ($runId === null || !$this->enabled()) {
            return;
        }

        $this->guardLeasePassive($runId);

        DB::table('agent_run_steps')->where('id', $stepId)->update([
            'iteration' => $iteration,
            'checkpoint_state' => 'open',
            'checkpoint' => json_encode(['v' => 1, 'iteration' => $iteration, 'model' => null, 'calls' => []]),
        ]);

        $this->announce($runId);
    }

    /**
     * C2 — the model response is recorded: its content, every call marked
     * not started with its label, the provider-attempt count reset, and
     * the lease renewed.
     */
    public function recordModelResponse(string $stepId, string $content, array $calls): void
    {
        $this->assertOutsideTransaction();
        if (!$this->enabled()) {
            return;
        }

        $runId = $this->runIdOfStep($stepId);
        if ($runId === null) {
            return;
        }

        $this->guardLease($runId, [
            'last_progress_at' => $this->timestamp(now()),
            'provider_attempts' => 0,
        ]);

        $checkpoint = $this->checkpointOf($stepId);
        // The model response exactly as received: a resume that finishes
        // this step's tool batch re-reads the calls (with their arguments)
        // from here, never from the per-call entries below.
        $checkpoint['model'] = [
            'recorded_at' => $this->timestamp(now()),
            'content' => $content,
            'tool_calls' => array_values($calls),
        ];
        foreach (array_values($calls) as $index => $call) {
            $id = $call['id'] ?? null;
            if ($id === null) {
                continue;
            }
            $checkpoint['calls'][$id] = [
                'index' => $index,
                'tool' => mb_substr((string) ($call['function']['name'] ?? ''), 0, 256),
                'label' => $this->labelForCall($call),
                'state' => 'not_started',
            ];
        }

        DB::table('agent_run_steps')->where('id', $stepId)->update([
            'checkpoint_state' => 'model_recorded',
            'checkpoint' => json_encode($checkpoint),
        ]);

        $this->announce($runId);
    }

    /**
     * C3 — a call is started: its effect class, the action it opened, and
     * the lease extended over the action's bound.
     */
    public function markStarted(string $stepId, string $callId, ToolEffect $effect, string $actionId, int $leaseSeconds): void
    {
        $this->assertOutsideTransaction();
        if (!$this->enabled()) {
            return;
        }

        $runId = $this->runIdOfStep($stepId);
        if ($runId === null) {
            return;
        }

        $this->guardLease($runId, [
            'lease_expires_at' => $this->timestamp(now()->addSeconds($leaseSeconds)),
            'last_progress_at' => $this->timestamp(now()),
        ]);

        $checkpoint = $this->checkpointOf($stepId);
        $checkpoint['calls'][$callId]['state'] = 'started';
        $checkpoint['calls'][$callId]['started_at'] = $this->timestamp(now());
        $checkpoint['calls'][$callId]['effect'] = $effect->value;
        $checkpoint['calls'][$callId]['action_id'] = $actionId;

        DB::table('agent_run_steps')->where('id', $stepId)->update(['checkpoint' => json_encode($checkpoint)]);

        $this->announce($runId);
    }

    /**
     * C4 — a call is completed: its result, and progress.
     */
    public function markCompleted(string $stepId, string $callId, array $result): void
    {
        $this->assertOutsideTransaction();
        if (!$this->enabled()) {
            return;
        }

        $runId = $this->runIdOfStep($stepId);
        if ($runId === null) {
            return;
        }

        $this->guardLease($runId, [
            'last_progress_at' => $this->timestamp(now()),
        ]);

        $checkpoint = $this->checkpointOf($stepId);
        $checkpoint['calls'][$callId]['state'] = 'completed';
        $checkpoint['calls'][$callId]['result'] = $result;

        DB::table('agent_run_steps')->where('id', $stepId)->update(['checkpoint' => json_encode($checkpoint)]);

        $this->announce($runId);
    }

    /**
     * C5 — a step is committed: its message id, its results stripped (they
     * live on the message), and the consecutive-interruption count reset
     * (a completed step is progress, quickstart M8).
     */
    public function commitStep(?string $runId, string $stepId, string $messageId): void
    {
        $this->assertOutsideTransaction();
        if ($runId === null || !$this->enabled()) {
            return;
        }

        $this->guardLease($runId, [
            'last_progress_at' => $this->timestamp(now()),
            'consecutive_interruptions' => 0,
        ]);

        $checkpoint = $this->checkpointOf($stepId);
        foreach ($checkpoint['calls'] ?? [] as $id => $call) {
            unset($checkpoint['calls'][$id]['result']);
        }

        DB::table('agent_run_steps')->where('id', $stepId)->update([
            'message_id' => $messageId,
            'checkpoint_state' => 'committed',
            'checkpoint' => json_encode($checkpoint),
        ]);

        $this->announce($runId);
    }

    /**
     * C6 — the run is paused: the step and the paused call marked paused,
     * the run's state `paused`, and the lease released — a paused run is
     * worked by nobody, until its resumer claims it.
     */
    public function markPaused(?string $runId, ?string $stepId, ?string $callId): void
    {
        $this->assertOutsideTransaction();
        if ($runId === null || !$this->enabled()) {
            return;
        }

        $affected = DB::table('agent_runs')
            ->where('id', $runId)
            ->where(fn ($q) => $q->where('lease_holder', $this->lease->nonce())->orWhere('lease_holder', null))
            ->update(['resume_state' => 'paused', 'lease_holder' => null]);

        if ($affected === 0 && DB::table('agent_runs')->where('id', $runId)->exists()) {
            throw new LeaseLost();
        }
        if ($affected === 0) {
            return;
        }

        // No step row (run tracing off, or the pause sits before a step was
        // opened): the run is paused, and there is nothing to mark on a step.
        if ($stepId !== null) {
            $checkpoint = $this->checkpointOf($stepId);
            if ($callId !== null) {
                $checkpoint['calls'][$callId]['state'] = 'paused';
            }

            DB::table('agent_run_steps')->where('id', $stepId)->update([
                'checkpoint_state' => 'paused',
                'checkpoint' => json_encode($checkpoint),
            ]);
        }

        $this->announce($runId);
    }

    /**
     * A call's outcome is unknown (its in-flight effect may have happened):
     * it is marked so a resume neither repeats it blindly nor assumes it
     * did not happen.
     */
    public function markOutcomeUnknown(string $stepId, string $callId): void
    {
        $this->assertOutsideTransaction();
        if (!$this->enabled()) {
            return;
        }

        $runId = $this->runIdOfStep($stepId);
        if ($runId === null) {
            return;
        }

        $this->guardLease($runId);

        $checkpoint = $this->checkpointOf($stepId);
        $checkpoint['calls'][$callId]['state'] = 'outcome_unknown';

        DB::table('agent_run_steps')->where('id', $stepId)->update(['checkpoint' => json_encode($checkpoint)]);

        $this->announce($runId);
    }

    /**
     * The step's message row exists (the stream's first partial row): the
     * step names it, so the step's final write updates that row rather
     * than creating a duplicate.
     */
    public function recordPartialMessage(string $stepId, string $messageId): void
    {
        $this->assertOutsideTransaction();
        if (!$this->enabled()) {
            return;
        }

        $runId = $this->runIdOfStep($stepId);
        if ($runId === null) {
            return;
        }

        // A partial row is progress, not a state change: no lease guard, no
        // resume_state change — only the step names the row.
        DB::table('agent_run_steps')->where('id', $stepId)->update(['message_id' => $messageId]);

        $this->announce($runId);
    }

    /**
     * Renew the lease this process holds, for the given seconds. Strict:
     * a foreign holder (or no lease at all) is a LeaseLost.
     */
    public function renewLease(?string $runId, int $seconds): void
    {
        $this->assertOutsideTransaction();
        if ($runId === null || !$this->enabled()) {
            return;
        }

        $affected = DB::table('agent_runs')
            ->where('id', $runId)
            ->where('lease_holder', $this->lease->nonce())
            ->update(['lease_expires_at' => $this->timestamp(now()->addSeconds($seconds))]);

        if ($affected === 0) {
            throw new LeaseLost();
        }
    }

    /**
     * Extend this process's lease over a model call, so a helper run's
     * short base lease does not expire while the model is still working
     * (a parent waiting on a live member must not take it over). The
     * extension is the model-call bound, capped by the run's own deadline
     * when it has one.
     */
    public function extendForModelCall(string $runId, ResumeDriver $driver): void
    {
        $this->assertOutsideTransaction();
        if ($runId === null || !$this->enabled()) {
            return;
        }

        $seconds = app(ToolLivenessBound::class)->modelCallLeaseSeconds($driver, $this->deadlineOf($runId));
        $this->renewLease($runId, $seconds);
    }

    /** The run's `deadline_at` option as a datetime, or null when it has none. */
    private function deadlineOf(string $runId): ?\DateTimeInterface
    {
        $options = json_decode((string) DB::table('agent_runs')->where('id', $runId)->value('resume_options'), true);
        if (!is_array($options) || !isset($options['deadline_at'])) {
            return null;
        }

        try {
            return Carbon::parse((string) $options['deadline_at']);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The C1 guard (the passive variant): renews this process's lease on
     * the run and lands a `resuming` run back in `working`, but does not
     * claim a released lease. A stream hand-off releases the lease and the
     * finishing worker's own step write must leave it released — the next
     * worker's first write (C2 and after, the claiming guard) is the claim.
     * A foreign nonce fails — a superseded process writes nothing and stops.
     */
    private function guardLeasePassive(string $runId, array $extra = []): void
    {
        $holder = DB::table('agent_runs')->where('id', $runId)->value('lease_holder');

        if ($holder !== null && $holder !== $this->lease->nonce()) {
            throw new LeaseLost();
        }

        $set = array_merge([
            'resume_state' => DB::raw("CASE WHEN resume_state = 'resuming' THEN 'working' ELSE resume_state END"),
        ], $holder === $this->lease->nonce() ? [
            'lease_holder' => $this->lease->nonce(),
            'lease_expires_at' => $this->timestamp(now()->addSeconds($this->window())),
        ] : [], $extra);

        $affected = DB::table('agent_runs')
            ->where('id', $runId)
            ->where(fn ($q) => $q->where('lease_holder', $this->lease->nonce())->orWhere('lease_holder', null))
            ->update($set);

        if ($affected === 0 && DB::table('agent_runs')->where('id', $runId)->exists()) {
            throw new LeaseLost();
        }
    }

    /**
     * The C2–C5 guard: one UPDATE that renews this process's lease on the
     * run and lands a `resuming` run back in `working`. The NULL branch is
     * load-bearing: after a stream hand-off the lease is released, and the
     * next worker's first write re-claims it. A foreign nonce fails — a
     * superseded process writes nothing and stops.
     */
    private function guardLease(string $runId, array $extra = []): void
    {
        $set = array_merge([
            'lease_holder' => $this->lease->nonce(),
            'lease_expires_at' => $this->timestamp(now()->addSeconds($this->window())),
            'resume_state' => DB::raw("CASE WHEN resume_state = 'resuming' THEN 'working' ELSE resume_state END"),
        ], $extra);

        $affected = DB::table('agent_runs')
            ->where('id', $runId)
            ->where(fn ($q) => $q->where('lease_holder', $this->lease->nonce())->orWhere('lease_holder', null))
            ->update($set);

        if ($affected === 0 && DB::table('agent_runs')->where('id', $runId)->exists()) {
            throw new LeaseLost();
        }
    }

    /**
     * The label of a tool call, resolved through TaskResumeText: the
     * operation's summary (the registry's title annotation) → the operation
     * id → the tool name. Never argument values.
     */
    private function labelForCall(array $call): string
    {
        $arguments = json_decode((string) ($call['function']['arguments'] ?? ''), true);

        return app(TaskResumeText::class)->actionLabel(
            (string) ($call['function']['name'] ?? ''),
            is_array($arguments) ? $arguments : [],
        );
    }

    /** The step's run, or null when the step is gone (a no-op, not an error). */
    private function runIdOfStep(string $stepId): ?string
    {
        return DB::table('agent_run_steps')->where('id', $stepId)->value('run_id');
    }

    /** The step's checkpoint, decoded, or a fresh one when it has none. */
    private function checkpointOf(string $stepId): array
    {
        $checkpoint = json_decode((string) DB::table('agent_run_steps')->where('id', $stepId)->value('checkpoint'), true);

        return is_array($checkpoint) ? $checkpoint : ['v' => 1, 'model' => null, 'calls' => []];
    }

    /** A visible change announces the run as changed; a failed announcement never blocks the write. */
    private function announce(?string $runId): void
    {
        if ($runId === null) {
            return;
        }

        try {
            app(RunTraceRecorder::class)->announceRunChanged($runId);
        } catch (\Throwable) {
        }
    }

    private function assertOutsideTransaction(): void
    {
        if (DB::transactionLevel() > 0) {
            throw new CheckpointWriteFailed('A checkpoint write cannot happen inside a transaction.', 'inside_transaction');
        }
    }

    private function enabled(): bool
    {
        return (bool) config('llm-client.resume.enabled', true);
    }

    private function window(): int
    {
        return (int) config('llm-client.resume.detection_window_seconds', 300);
    }

    private function timestamp(\Illuminate\Support\Carbon $at): string
    {
        return $at->format('Y-m-d H:i:s.u');
    }
}
