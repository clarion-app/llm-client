<?php

namespace Tests\Feature\Resume;

use ClarionApp\LlmClient\Exceptions\CheckpointWriteFailed;
use ClarionApp\LlmClient\Exceptions\LeaseLost;
use ClarionApp\LlmClient\Services\RunCheckpoint;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ResumeDriver;
use ClarionApp\LlmClient\ValueObjects\ResumeMode;
use ClarionApp\LlmClient\ValueObjects\ToolEffect;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RunCheckpoint — the strict checkpoint writer (checkpoint-ledger contract §1).
 *
 * Every write point (C0–C6) is tested for its exact effect on the run and
 * step rows, and the writer's strictness: it throws instead of swallowing
 * (a checkpoint that cannot be written stops the run), it refuses inside a
 * transaction, it is a no-op when the run is absent or resume is disabled,
 * it hands a superseded process a LeaseLost at its next write, and the first
 * state-changing write after a resume lands the run back in `working`.
 */
class RunCheckpointTest extends TestCase
{
    private string $userId = '11111111-1111-1111-1111-111111111111';

    // ---------------------------------------------------------------
    // Row fixtures
    // ---------------------------------------------------------------

    /** Insert a minimal in-progress run row. */
    private function makeRun(string $id, array $overrides = []): void
    {
        DB::table('agent_runs')->insert(array_merge([
            'id' => $id,
            'kind' => 'interactive',
            'user_id' => $this->userId,
            'started_at' => now()->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
        ], $overrides));
    }

    /** Insert a minimal step row. */
    private function makeStep(string $id, string $runId, int $position, array $overrides = []): void
    {
        DB::table('agent_run_steps')->insert(array_merge([
            'id' => $id,
            'run_id' => $runId,
            'position' => $position,
            'started_at' => now()->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
        ], $overrides));
    }

    /** @return object the run row */
    private function runRow(string $id): object
    {
        $row = DB::table('agent_runs')->where('id', $id)->first();
        $this->assertNotNull($row, "run {$id} must exist");

        return $row;
    }

    /** @return object the step row */
    private function step(string $id): object
    {
        $row = DB::table('agent_run_steps')->where('id', $id)->first();
        $this->assertNotNull($row, "step {$id} must exist");

        return $row;
    }

    /** The writer under test. */
    private function checkpoint(): RunCheckpoint
    {
        return app(RunCheckpoint::class);
    }

    /** The two tool calls a model turn makes, with a resolvable label each. */
    private function twoCalls(): array
    {
        return [
            ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'execute_operation', 'arguments' => json_encode(['operationId' => 'storeNote'])]],
            ['id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'list_applications', 'arguments' => '[]']],
        ];
    }

    // ---------------------------------------------------------------
    // C0 — openRun
    // ---------------------------------------------------------------

    #[Test]
    public function openRun_sets_version_mode_driver_options_and_claims(): void
    {
        $this->makeRun('run-1');
        $options = [
            'unattended' => true,
            'deadline_at' => '2026-10-15T00:00:00Z',
            'task_budget' => ['max_steps' => 5], // spec 133 owns it: dropped
            'unknown_key' => 'x',               // not whitelisted: dropped
        ];

        $nonce = $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, $options);

        $this->assertNotEmpty($nonce, 'the process nonce is returned');
        $row = $this->runRow('run-1');
        $this->assertSame(1, (int) $row->checkpoint_version, 'checkpoint_version = 1');
        $this->assertSame('working', $row->resume_state);
        $this->assertSame('sync', $row->resume_mode);
        $this->assertSame('root', $row->resume_driver);
        $this->assertNotNull($row->dispatch_id, 'the first dispatch id is minted');
        $this->assertSame($nonce, $row->lease_holder, 'the lease is claimed by this process');
        $this->assertNotNull($row->lease_expires_at);
        $this->assertWithin(
            Carbon::now()->addSeconds(300),
            $row->lease_expires_at,
            60,
            'the initial lease is the detection window (≈ now + 300 s)',
        );

        $stored = json_decode((string) $row->resume_options, true);
        $this->assertIsArray($stored, 'resume_options is the whitelisted JSON');
        $this->assertArrayHasKey('unattended', $stored, 'a whitelisted option is kept');
        $this->assertArrayHasKey('deadline_at', $stored, 'deadline_at is kept');
        $this->assertArrayNotHasKey('task_budget', $stored, 'task_budget is never stored (spec 133 owns it)');
        $this->assertArrayNotHasKey('unknown_key', $stored, 'unknown keys are dropped');
    }

    #[Test]
    public function openRun_with_oversized_options_marks_driver_none(): void
    {
        $this->makeRun('run-1');
        // A whitelisted option large enough to push the set past max_options_bytes.
        $options = ['unattended' => str_repeat('x', (int) config('llm-client.resume.max_options_bytes', 65536) + 1)];

        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, $options);

        $row = $this->runRow('run-1');
        $this->assertSame(1, (int) $row->checkpoint_version, 'still a checkpointed run');
        $this->assertSame('none', $row->resume_driver, 'a too-large options set marks the run not resumable, not truncated');
    }

    // ---------------------------------------------------------------
    // C1 — openStep
    // ---------------------------------------------------------------

    #[Test]
    public function openStep_writes_open_checkpoint(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);

        $this->checkpoint()->openStep('run-1', 'step-1', 3);

        $step = $this->step('step-1');
        $this->assertSame(3, (int) $step->iteration);
        $this->assertSame('open', $step->checkpoint_state);
        $checkpoint = json_decode((string) $step->checkpoint, true);
        $this->assertSame(['v' => 1, 'iteration' => 3, 'model' => null, 'calls' => []], $checkpoint);
    }

    // ---------------------------------------------------------------
    // C2 — recordModelResponse
    // ---------------------------------------------------------------

    #[Test]
    public function recordModelResponse_marks_every_call_not_started(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $checkpoint = $this->checkpoint();
        $checkpoint->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $checkpoint->openStep('run-1', 'step-1', 1);
        $before = $this->runRow('run-1')->lease_expires_at;

        $checkpoint->recordModelResponse('step-1', 'I will check the file, then update it.', $this->twoCalls());

        $step = $this->step('step-1');
        $this->assertSame('model_recorded', $step->checkpoint_state);
        $checkpointJson = json_decode((string) $step->checkpoint, true);
        $this->assertNotNull($checkpointJson['model'], 'the model response is recorded');
        $this->assertSame('I will check the file, then update it.', $checkpointJson['model']['content']);
        $this->assertCount(2, $checkpointJson['calls']);
        $this->assertSame('not_started', $checkpointJson['calls']['call_a']['state']);
        $this->assertSame('not_started', $checkpointJson['calls']['call_b']['state']);
        $this->assertNotEmpty($checkpointJson['calls']['call_a']['label'], 'labels are resolved');
        $this->assertSame('storeNote', $checkpointJson['calls']['call_a']['label'], 'label is the operation id');
        $this->assertSame('list_applications', $checkpointJson['calls']['call_b']['label'], 'label falls back to the tool name');

        $row = $this->runRow('run-1');
        $this->assertNotNull($row->last_progress_at, 'last_progress_at is set');
        $this->assertGreaterThan($before, $row->lease_expires_at, 'the lease is renewed');
        $this->assertSame(0, (int) $row->provider_attempts, 'provider_attempts is reset to 0');
    }

    // ---------------------------------------------------------------
    // C3 — markStarted
    // ---------------------------------------------------------------

    #[Test]
    public function markStarted_records_effect_action_and_extends_lease(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $checkpoint = $this->checkpoint();
        $checkpoint->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $checkpoint->openStep('run-1', 'step-1', 1);
        $checkpoint->recordModelResponse('step-1', '', $this->twoCalls());

        $checkpoint->markStarted('step-1', 'call_a', ToolEffect::Effectful, 'action-1', 120);

        $checkpointJson = json_decode((string) $this->step('step-1')->checkpoint, true);
        $call = $checkpointJson['calls']['call_a'];
        $this->assertSame('started', $call['state']);
        $this->assertNotEmpty($call['started_at']);
        $this->assertSame('effectful', $call['effect']);
        $this->assertSame('action-1', $call['action_id']);

        $this->assertWithin(
            Carbon::now()->addSeconds(120),
            $this->runRow('run-1')->lease_expires_at,
            30,
            'the lease is extended to now + leaseSeconds',
        );
    }

    // ---------------------------------------------------------------
    // C4 — markCompleted
    // ---------------------------------------------------------------

    #[Test]
    public function markCompleted_records_result(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $checkpoint = $this->checkpoint();
        $checkpoint->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $checkpoint->openStep('run-1', 'step-1', 1);
        $checkpoint->recordModelResponse('step-1', '', $this->twoCalls());
        $checkpoint->markStarted('step-1', 'call_a', ToolEffect::Effectful, 'action-1', 120);

        $result = ['tool_call_id' => 'call_a', 'content' => 'stored', 'method' => 'passthrough'];
        $checkpoint->markCompleted('step-1', 'call_a', $result);

        $checkpointJson = json_decode((string) $this->step('step-1')->checkpoint, true);
        $this->assertSame('completed', $checkpointJson['calls']['call_a']['state']);
        $this->assertSame($result, $checkpointJson['calls']['call_a']['result']);
        $this->assertNotNull($this->runRow('run-1')->last_progress_at, 'last_progress_at is set');
    }

    // ---------------------------------------------------------------
    // C6 — markPaused
    // ---------------------------------------------------------------

    #[Test]
    public function markPaused_releases_lease(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $checkpoint = $this->checkpoint();
        $checkpoint->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $checkpoint->openStep('run-1', 'step-1', 1);
        $checkpoint->recordModelResponse('step-1', '', $this->twoCalls());
        $checkpoint->markStarted('step-1', 'call_a', ToolEffect::Durable, 'action-1', 120);

        $checkpoint->markPaused('run-1', 'step-1', 'call_a');

        $checkpointJson = json_decode((string) $this->step('step-1')->checkpoint, true);
        $this->assertSame('paused', $this->step('step-1')->checkpoint_state);
        $this->assertSame('paused', $checkpointJson['calls']['call_a']['state']);
        $row = $this->runRow('run-1');
        $this->assertSame('paused', $row->resume_state);
        $this->assertNull($row->lease_holder, 'the lease is released on a pause');
    }

    // ---------------------------------------------------------------
    // C5 — commitStep
    // ---------------------------------------------------------------

    #[Test]
    public function commitStep_strips_results_and_resets_interruptions(): void
    {
        $this->makeRun('run-1', ['consecutive_interruptions' => 2]);
        $this->makeStep('step-1', 'run-1', 1);
        $checkpoint = $this->checkpoint();
        $checkpoint->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $checkpoint->openStep('run-1', 'step-1', 1);
        $checkpoint->recordModelResponse('step-1', '', $this->twoCalls());
        $checkpoint->markStarted('step-1', 'call_a', ToolEffect::Effectful, 'action-1', 120);
        $checkpoint->markCompleted('step-1', 'call_a', ['tool_call_id' => 'call_a', 'content' => 'stored']);

        $checkpoint->commitStep('run-1', 'step-1', 'message-1');

        $step = $this->step('step-1');
        $this->assertSame('message-1', $step->message_id);
        $this->assertSame('committed', $step->checkpoint_state);
        $checkpointJson = json_decode((string) $step->checkpoint, true);
        foreach ($checkpointJson['calls'] as $call) {
            $this->assertArrayNotHasKey('result', $call, 'results are stripped on commit');
        }
        $row = $this->runRow('run-1');
        $this->assertSame(0, (int) $row->consecutive_interruptions, 'consecutive_interruptions is reset (quickstart M8)');
        $this->assertNotNull($row->last_progress_at);
    }

    // ---------------------------------------------------------------
    // markOutcomeUnknown
    // ---------------------------------------------------------------

    #[Test]
    public function markOutcomeUnknown(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $checkpoint = $this->checkpoint();
        $checkpoint->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $checkpoint->openStep('run-1', 'step-1', 1);
        $checkpoint->recordModelResponse('step-1', '', $this->twoCalls());
        $checkpoint->markStarted('step-1', 'call_a', ToolEffect::Effectful, 'action-1', 120);

        $checkpoint->markOutcomeUnknown('step-1', 'call_a');

        $checkpointJson = json_decode((string) $this->step('step-1')->checkpoint, true);
        $this->assertSame('outcome_unknown', $checkpointJson['calls']['call_a']['state']);
    }

    // ---------------------------------------------------------------
    // renewLease — a superseded process
    // ---------------------------------------------------------------

    #[Test]
    public function renewLease_throws_LeaseLost_for_foreign_nonce(): void
    {
        $this->makeRun('run-1');
        $checkpoint = $this->checkpoint();
        $checkpoint->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        // Another process now holds the lease.
        DB::table('agent_runs')->where('id', 'run-1')->update(['lease_holder' => '99999999-9999-9999-9999-999999999999']);

        $this->expectException(LeaseLost::class);
        $this->checkpoint()->renewLease('run-1', 300);
    }

    // ---------------------------------------------------------------
    // Strictness — refuses inside a transaction (quickstart M26)
    // ---------------------------------------------------------------

    #[Test]
    public function refuses_inside_transaction(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $checkpoint = $this->checkpoint();

        $calls = [
            fn () => $checkpoint->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []),
            fn () => $checkpoint->openStep('run-1', 'step-1', 1),
            fn () => $checkpoint->recordModelResponse('step-1', '', $this->twoCalls()),
            fn () => $checkpoint->markStarted('step-1', 'call_a', ToolEffect::Effectful, 'action-1', 120),
            fn () => $checkpoint->markCompleted('step-1', 'call_a', ['tool_call_id' => 'call_a', 'content' => 'x']),
            fn () => $checkpoint->markPaused('run-1', 'step-1', 'call_a'),
            fn () => $checkpoint->commitStep('run-1', 'step-1', 'message-1'),
            fn () => $checkpoint->markOutcomeUnknown('step-1', 'call_a'),
            fn () => $checkpoint->renewLease('run-1', 300),
        ];

        foreach ($calls as $i => $call) {
            $thrown = false;
            try {
                DB::transaction(function () use ($call) {
                    $call();
                });
            } catch (CheckpointWriteFailed $e) {
                $thrown = true;
                $this->assertSame('inside_transaction', $e->reason, "method {$i} refuses inside a transaction with the right reason");
            }
            $this->assertTrue($thrown, "method {$i} must throw CheckpointWriteFailed inside a transaction");
        }
    }

    // ---------------------------------------------------------------
    // Strictness — throws instead of swallowing
    // ---------------------------------------------------------------

    #[Test]
    public function throws_instead_of_swallowing(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        // The step table is gone: the write cannot succeed and must not be swallowed.
        Schema::dropIfExists('agent_run_steps');

        $thrown = false;
        try {
            $this->checkpoint()->openStep('run-1', 'step-1', 1);
        } catch (\Throwable) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'a DB error on a checkpoint write is thrown, not swallowed');
    }

    // ---------------------------------------------------------------
    // No-ops
    // ---------------------------------------------------------------

    #[Test]
    public function noop_when_run_id_null(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);

        // No run id: the writes are no-ops, and nothing is written.
        $this->checkpoint()->openStep(null, 'step-1', 1);
        $this->checkpoint()->commitStep(null, 'step-1', 'message-1');
        $this->checkpoint()->renewLease(null, 300);

        $step = $this->step('step-1');
        $this->assertNull($step->checkpoint_state, 'no checkpoint_state is written');
        $this->assertNull($step->checkpoint, 'no checkpoint is written');
        $this->assertNull($step->iteration, 'no iteration is written');
    }

    #[Test]
    public function noop_when_disabled(): void
    {
        config(['llm-client.resume.enabled' => false]);
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);

        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 1);

        $row = $this->runRow('run-1');
        $this->assertNull($row->checkpoint_version, 'resume disabled: no checkpoint_version');
        $this->assertNull($row->resume_state, 'resume disabled: no resume_state');
        $this->assertNull($this->step('step-1')->checkpoint_state, 'resume disabled: no step checkpoint');
    }

    // ---------------------------------------------------------------
    // announceRunChanged
    // ---------------------------------------------------------------

    #[Test]
    public function announces_run_changed(): void
    {
        $announced = [];
        $spy = Mockery::mock(RunTraceRecorder::class);
        $spy->shouldReceive('announceRunChanged')->andReturnUsing(function (?string $id) use (&$announced) {
            $announced[] = $id;
        });
        $this->app->instance(RunTraceRecorder::class, $spy);

        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $checkpoint = $this->checkpoint();
        $checkpoint->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $checkpoint->openStep('run-1', 'step-1', 1);
        $checkpoint->recordModelResponse('step-1', '', $this->twoCalls());
        $checkpoint->markStarted('step-1', 'call_a', ToolEffect::Effectful, 'action-1', 120);
        $checkpoint->markCompleted('step-1', 'call_a', ['tool_call_id' => 'call_a', 'content' => 'x']);

        $checkpoint->commitStep('run-1', 'step-1', 'message-1');

        $this->assertContains('run-1', $announced, 'a visible change announces the run as changed');
    }

    // ---------------------------------------------------------------
    // first write after a resume lands `working`
    // ---------------------------------------------------------------

    /**
     * Set up a run that has just been resumed: it is `resuming` and this
     * process holds the lease. The first state-changing write must land it
     * back in `working` (data-model §1 state diagram).
     */
    private function resumedRun(string $runId, string $stepId, bool $withModel = false): void
    {
        $this->makeRun($runId);
        $this->makeStep($stepId, $runId, 1);
        $checkpoint = $this->checkpoint();
        $checkpoint->openRun($runId, ResumeMode::Sync, ResumeDriver::Root, []);
        $checkpoint->openStep($runId, $stepId, 1);
        if ($withModel) {
            $checkpoint->recordModelResponse($stepId, '', $this->twoCalls());
        }
        // Simulate the state right after a resume claim: resuming, lease held.
        DB::table('agent_runs')->where('id', $runId)->update(['resume_state' => 'resuming']);
    }

    #[Test]
    public function first_write_after_resume_sets_working(): void
    {
        // C1
        $this->resumedRun('run-1', 'step-1');
        $this->checkpoint()->openStep('run-1', 'step-1', 1);
        $this->assertSame('working', $this->runRow('run-1')->resume_state, 'C1 lands the run back in working');

        // C2
        $this->resumedRun('run-2', 'step-2', true);
        $this->checkpoint()->recordModelResponse('step-2', '', $this->twoCalls());
        $this->assertSame('working', $this->runRow('run-2')->resume_state, 'C2 lands the run back in working');

        // C3
        $this->resumedRun('run-3', 'step-3', true);
        $this->checkpoint()->markStarted('step-3', 'call_a', ToolEffect::Effectful, 'action-1', 120);
        $this->assertSame('working', $this->runRow('run-3')->resume_state, 'C3 lands the run back in working');

        // C4
        $this->resumedRun('run-4', 'step-4', true);
        $this->checkpoint()->markStarted('step-4', 'call_a', ToolEffect::Effectful, 'action-1', 120);
        $this->checkpoint()->markCompleted('step-4', 'call_a', ['tool_call_id' => 'call_a', 'content' => 'x']);
        $this->assertSame('working', $this->runRow('run-4')->resume_state, 'C4 lands the run back in working');

        // C5
        $this->resumedRun('run-5', 'step-5', true);
        $this->checkpoint()->markStarted('step-5', 'call_a', ToolEffect::Effectful, 'action-1', 120);
        $this->checkpoint()->markCompleted('step-5', 'call_a', ['tool_call_id' => 'call_a', 'content' => 'x']);
        $this->checkpoint()->commitStep('run-5', 'step-5', 'message-1');
        $this->assertSame('working', $this->runRow('run-5')->resume_state, 'C5 lands the run back in working');
    }

    // ---------------------------------------------------------------
    // a superseded process gets LeaseLost at its next write
    // ---------------------------------------------------------------

    #[Test]
    public function writes_throw_LeaseLost_for_superseded_process(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $checkpoint = $this->checkpoint();
        $checkpoint->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $checkpoint->openStep('run-1', 'step-1', 1);
        // Another process took the run over: it now holds the lease.
        DB::table('agent_runs')->where('id', 'run-1')->update(['lease_holder' => '99999999-9999-9999-9999-999999999999']);

        $thrown = false;
        try {
            $checkpoint->recordModelResponse('step-1', 'late write', $this->twoCalls());
        } catch (LeaseLost) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'a superseded process gets LeaseLost at its next write');
        $this->assertSame('open', $this->step('step-1')->checkpoint_state, 'and nothing was written');
    }

    /**
     * The detection sweep declared the run interrupted: it released the
     * lease and minted a new dispatch id for the resume job. The slow
     * original process, coming back to its next write, must not take the
     * released lease back — the resume job's claim is the only way in.
     */
    #[Test]
    public function a_run_the_sweep_marked_interrupted_is_not_reclaimed_by_the_original_process(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $checkpoint = $this->checkpoint();
        $checkpoint->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $checkpoint->openStep('run-1', 'step-1', 1);
        $checkpoint->recordModelResponse('step-1', 'one write', [$this->twoCalls()[0]]);
        $checkpoint->markStarted('step-1', 'call_a', ToolEffect::Effectful, '', 60);

        // The sweep's compare-and-set, exactly as it leaves the row.
        DB::table('agent_runs')->where('id', 'run-1')->update([
            'resume_state' => 'interrupted',
            'interrupted_at' => now()->format('Y-m-d H:i:s.u'),
            'dispatch_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
            'lease_holder' => null,
            'consecutive_interruptions' => 1,
        ]);

        foreach ([
            'C4' => fn () => $checkpoint->markCompleted('step-1', 'call_a', ['tool_call_id' => 'call_a', 'content' => 'late']),
            'C5' => fn () => $checkpoint->commitStep('run-1', 'step-1', 'msg-1'),
            'C1' => fn () => $checkpoint->openStep('run-1', 'step-1', 2),
        ] as $point => $write) {
            $thrown = false;
            try {
                $write();
            } catch (LeaseLost) {
                $thrown = true;
            }
            $this->assertTrue($thrown, "{$point}: the original process gets LeaseLost on an interrupted run");
        }

        $row = $this->runRow('run-1');
        $this->assertNull($row->lease_holder, 'the released lease stays released for the resume job');
        $this->assertSame('interrupted', $row->resume_state);
        $this->assertSame('started', json_decode((string) $this->step('step-1')->checkpoint, true)['calls']['call_a']['state'], 'the call is not recorded completed by the superseded process');
    }

    // ---------------------------------------------------------------
    // A tolerant timestamp comparison.
    // ---------------------------------------------------------------

    private function assertWithin(Carbon $expected, ?string $actual, int $toleranceSeconds, string $message = ''): void
    {
        $this->assertNotNull($actual, $message ?: 'the timestamp is set');
        $delta = abs(Carbon::parse($actual)->diffInSeconds($expected));
        $this->assertLessThanOrEqual($toleranceSeconds, $delta, $message." (expected {$expected}, got {$actual})");
    }
}
