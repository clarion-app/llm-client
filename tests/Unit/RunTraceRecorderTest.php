<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\RunRelation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RunTraceRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Enable run tracing for tests.
        $this->app['config']->set('llm-client.run_trace.enabled', true);
    }

    protected function tearDown(): void
    {
        // Clean up tables after each test (check existence first — never-throw tests may drop them).
        foreach (['agent_run_messages', 'agent_run_actions', 'agent_run_steps', 'agent_runs'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    /**
     * Insert an agent_run_actions row directly, with explicit timestamps, so the
     * latency-breakdown tests can control interval shapes precisely rather than
     * relying on usleep()-based timing.
     */
    private function insertAction(
        string $runId,
        string $stepId,
        string $actionType,
        string $startedAt,
        ?string $endedAt = null,
        ?int $durationMs = null,
        ?string $pausedAt = null,
        string $outcome = 'success',
    ): string {
        $id = (string) \Illuminate\Support\Str::uuid();

        DB::table('agent_run_actions')->insert([
            'id' => $id,
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => $actionType,
            'target' => null,
            'attempt_group_id' => null,
            'parent_action_id' => null,
            'outcome' => $outcome,
            'failure_reason' => null,
            'paused_at' => $pausedAt,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_ms' => $durationMs,
            'content' => null,
            'created_at' => $startedAt,
        ]);

        return $id;
    }

    /**
     * Insert an agent_run_steps row directly, with an explicit wait_ms, for the
     * confirm_wait_ms aggregation tests.
     */
    private function insertStepWithWait(string $runId, int $position, ?int $waitMs): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $now = now()->format('Y-m-d H:i:s.u');

        DB::table('agent_run_steps')->insert([
            'id' => $id,
            'run_id' => $runId,
            'position' => $position,
            'attempt_group_id' => null,
            'end_state' => 'completed',
            'end_reason' => null,
            'started_at' => $now,
            'ended_at' => $now,
            'duration_ms' => 1000,
            'wait_ms' => $waitMs,
            'attempt_count' => 1,
        ]);

        return $id;
    }

    // ========== openRun ==========

    /** @test */
    public function open_run_creates_in_progress_run(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $runId = $recorder->openRun(RunKind::Interactive, $userId, $convId);

        $this->assertNotNull($runId);
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals($userId, $run->user_id);
        $this->assertEquals($convId, $run->conversation_id);
        $this->assertEquals(RunKind::Interactive->value, $run->kind);
        $this->assertEquals(RunEndState::InProgress->value, $run->end_state);
        $this->assertNull($run->ended_at);
        $this->assertNull($run->duration_ms);
        $this->assertEquals(0, $run->step_count);
    }

    /** @test */
    public function open_run_returns_null_when_disabled(): void
    {
        $this->app['config']->set('llm-client.run_trace.enabled', false);
        $recorder = $this->app->make(RunTraceRecorder::class);

        $result = $recorder->openRun(RunKind::Interactive, 'user-1');
        $this->assertNull($result);
    }

    /** @test */
    public function open_run_handles_null_conversation_id(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        $runId = $recorder->openRun(RunKind::SystemInitiated, 'user-1', null, 'scheduled');

        $this->assertNotNull($runId);
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNull($run->conversation_id);
        $this->assertEquals('scheduled', $run->source);
        $this->assertEquals(RunKind::SystemInitiated->value, $run->kind);
    }

    // ========== openStep ==========

    /** @test */
    public function open_step_creates_step_with_auto_position(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');

        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($stepId);

        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertNotNull($step);
        $this->assertEquals($runId, $step->run_id);
        $this->assertEquals(1, $step->position);
        $this->assertEquals(RunEndState::InProgress->value, $step->end_state);
    }

    /** @test */
    public function open_step_derives_position_as_one_plus_count(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');

        $step1 = $recorder->openStep($runId);
        $step2 = $recorder->openStep($runId);
        $step3 = $recorder->openStep($runId);

        $s1 = DB::table('agent_run_steps')->where('id', $step1)->first();
        $s2 = DB::table('agent_run_steps')->where('id', $step2)->first();
        $s3 = DB::table('agent_run_steps')->where('id', $step3)->first();

        $this->assertEquals(1, $s1->position);
        $this->assertEquals(2, $s2->position);
        $this->assertEquals(3, $s3->position);
    }

    /** @test */
    public function open_step_returns_null_for_null_run_id(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $result = $recorder->openStep(null);
        $this->assertNull($result);
    }

    // ========== recordStepAttempt ==========

    /** @test */
    public function record_step_attempt_increments_attempt_count(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $stepId = $recorder->openStep($runId);

        // Initial attempt_count is 1.
        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertEquals(1, $step->attempt_count);

        // Record a retry - increments to 2.
        $recorder->recordStepAttempt($stepId);

        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertEquals(2, $step->attempt_count);
        // Step should still be in_progress.
        $this->assertEquals(RunEndState::InProgress->value, $step->end_state);
    }

    /** @test */
    public function record_step_attempt_is_noop_for_terminal_step(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $stepId = $recorder->openStep($runId);
        $recorder->closeStep($stepId, RunEndState::Completed);

        // Attempt to record on a closed step - should be a no-op.
        $recorder->recordStepAttempt($stepId);

        // Step should remain completed with attempt_count unchanged.
        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertEquals(RunEndState::Completed->value, $step->end_state);
        $this->assertEquals(1, $step->attempt_count);
    }

    /** @test */
    public function record_step_attempt_is_noop_for_null_step_id(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $recorder->recordStepAttempt(null);
        // No exception, no rows written.
        $this->assertEquals(0, DB::table('agent_run_steps')->count());
    }

    // ========== closeStep ==========

    /** @test */
    public function close_step_sets_end_state_and_duration(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $stepId = $recorder->openStep($runId);

        usleep(100_000); // 100ms to ensure non-zero duration.
        $recorder->closeStep($stepId, RunEndState::Completed);

        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertEquals(RunEndState::Completed->value, $step->end_state);
        $this->assertNotNull($step->ended_at);

        // The elapsed time is measured, not merely non-negative: a duration that
        // collapses to 0 for a step that demonstrably took 100ms is the failure
        // mode this asserts against.
        $this->assertGreaterThanOrEqual(100, $step->duration_ms);
        $this->assertLessThan(5_000, $step->duration_ms);
    }

    /** @test */
    public function close_step_clamps_duration_to_zero_minimum(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $stepId = $recorder->openStep($runId);

        // Close immediately - duration should be >= 0.
        $recorder->closeStep($stepId, RunEndState::Completed);

        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertGreaterThanOrEqual(0, $step->duration_ms);
    }

    /** @test */
    public function close_step_is_idempotent_on_terminal_state(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $stepId = $recorder->openStep($runId);
        $recorder->closeStep($stepId, RunEndState::Completed);

        $firstClosedAt = DB::table('agent_run_steps')->where('id', $stepId)->value('ended_at');

        // Close again - should not re-transition.
        $recorder->closeStep($stepId, RunEndState::Failed);

        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertEquals(RunEndState::Completed->value, $step->end_state);
        $this->assertEquals($firstClosedAt, $step->ended_at);
    }

    /** @test */
    public function close_step_is_noop_for_null_step_id(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $recorder->closeStep(null, RunEndState::Completed);
        // No exception.
        $this->assertEquals(0, DB::table('agent_run_steps')->count());
    }

    // ========== closeRun ==========

    /** @test */
    public function close_run_sets_end_state_and_duration(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');

        usleep(100_000);
        $recorder->closeRun($runId, RunEndState::Completed);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertEquals(RunEndState::Completed->value, $run->end_state);
        $this->assertNotNull($run->ended_at);
        $this->assertGreaterThanOrEqual(100, $run->duration_ms);
        $this->assertLessThan(5_000, $run->duration_ms);
    }

    /**
     * SC-012: a run's duration reconciles against its steps, and subtracting the
     * recorded human-wait yields system working time. Both figures must be real
     * measurements for the subtraction to mean anything.
     *
     * @test
     */
    public function run_duration_reconciles_against_its_steps(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');

        $stepOne = $recorder->openStep($runId, 1);
        usleep(60_000);
        $recorder->closeStep($stepOne, RunEndState::Completed);

        $stepTwo = $recorder->openStep($runId, 2);
        usleep(60_000);
        $recorder->closeStep($stepTwo, RunEndState::Completed, null, 40); // 40ms of it human wait

        $recorder->closeRun($runId, RunEndState::Completed);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $steps = DB::table('agent_run_steps')->where('run_id', $runId)->orderBy('position')->get();

        $stepSum = $steps->sum('duration_ms');
        $this->assertGreaterThanOrEqual(120, $stepSum, 'Both steps ran for a measurable time');
        $this->assertGreaterThanOrEqual($stepSum, $run->duration_ms, 'SC-012: run spans at least its steps');

        // System working time is derivable without further instrumentation.
        $this->assertSame(
            $steps[1]->duration_ms - 40,
            $steps[1]->duration_ms - $steps[1]->wait_ms,
        );
    }

    /** @test */
    public function close_run_sets_end_reason(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');

        $recorder->closeRun($runId, RunEndState::StoppedEarly, 'Maximum iterations reached');

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertEquals('Maximum iterations reached', $run->end_reason);
    }

    /** @test */
    public function close_run_links_reply_message(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $replyMsgId = (string) \Illuminate\Support\Str::uuid();

        $recorder->closeRun($runId, RunEndState::Completed, null, $replyMsgId);

        // Reply message is linked via agent_run_messages, not agent_runs.reply_message_id.
        $link = DB::table('agent_run_messages')
            ->where('run_id', $runId)
            ->where('message_id', $replyMsgId)
            ->first();
        $this->assertNotNull($link);
        $this->assertEquals(RunRelation::Reply->value, $link->relation);
    }

    /** @test */
    public function close_run_clamps_duration_to_zero_minimum(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');

        $recorder->closeRun($runId, RunEndState::Completed);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertGreaterThanOrEqual(0, $run->duration_ms);
    }

    /** @test */
    public function close_run_is_idempotent_on_terminal_state(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $recorder->closeRun($runId, RunEndState::Completed);

        $firstEndedAt = DB::table('agent_runs')->where('id', $runId)->value('ended_at');

        $recorder->closeRun($runId, RunEndState::Failed, 'Some error');

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertEquals(RunEndState::Completed->value, $run->end_state);
        $this->assertEquals($firstEndedAt, $run->ended_at);
    }

    /** @test */
    public function close_run_is_noop_for_null_run_id(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $recorder->closeRun(null, RunEndState::Completed);
        $this->assertEquals(0, DB::table('agent_runs')->count());
    }

    // ========== linkMessage ==========

    /** @test */
    public function link_message_creates_link(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $msgId = (string) \Illuminate\Support\Str::uuid();

        $recorder->linkMessage($runId, $msgId, RunRelation::Trigger);

        $link = DB::table('agent_run_messages')
            ->where('run_id', $runId)
            ->where('message_id', $msgId)
            ->first();
        $this->assertNotNull($link);
        $this->assertEquals(RunRelation::Trigger->value, $link->relation);
    }

    /** @test */
    public function link_message_is_noop_for_null_run_id(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $recorder->linkMessage(null, 'msg-1', RunRelation::Trigger);
        $this->assertEquals(0, DB::table('agent_run_messages')->count());
    }

    // ========== traceSystemRun ==========

    /** @test */
    public function trace_system_run_creates_complete_run(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        $result = $recorder->traceSystemRun(
            'scheduled_cleanup',
            'user-1',
            null,
            fn () => 'done',
        );

        $this->assertEquals('done', $result);
        $runs = DB::table('agent_runs')->get();
        $this->assertCount(1, $runs);
        $run = $runs[0];
        $this->assertEquals(RunKind::SystemInitiated->value, $run->kind);
        $this->assertEquals('scheduled_cleanup', $run->source);
        $this->assertEquals(RunEndState::Completed->value, $run->end_state);
        $this->assertNotNull($run->ended_at);
    }

    /** @test */
    public function trace_system_run_records_failure_and_rethrows(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        $testException = new \RuntimeException('test error');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test error');

        try {
            $recorder->traceSystemRun(
                'failing_task',
                'user-1',
                null,
                fn () => throw $testException,
            );
        } finally {
            // Verify the run was recorded as failed.
            $runs = DB::table('agent_runs')->get();
            $this->assertCount(1, $runs);
            $run = $runs[0];
            $this->assertEquals(RunEndState::Failed->value, $run->end_state);
        }
    }

    // ========== Never-throw discipline ==========

    /** @test */
    public function open_run_catches_db_errors(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        // Drop the table to force an error.
        DB::statement('DROP TABLE IF EXISTS agent_runs');

        $warned = false;
        Log::listen(function ($entry) use (&$warned) {
            if ($entry->level === 'warning') {
                $warned = true;
            }
        });

        $result = $recorder->openRun(RunKind::Interactive, 'user-1');
        $this->assertNull($result);
        $this->assertTrue($warned, 'Expected a warning log entry');
    }

    /** @test */
    public function close_step_catches_db_errors(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        DB::statement('DROP TABLE IF EXISTS agent_run_steps');

        $warned = false;
        Log::listen(function ($entry) use (&$warned) {
            if ($entry->level === 'warning') {
                $warned = true;
            }
        });

        $recorder->closeStep('non-existent-step', RunEndState::Completed);
        // No exception thrown.
        $this->assertTrue($warned, 'Expected a warning log entry');
    }

    /** @test */
    public function close_run_catches_db_errors(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        DB::statement('DROP TABLE IF EXISTS agent_runs');

        $warned = false;
        Log::listen(function ($entry) use (&$warned) {
            if ($entry->level === 'warning') {
                $warned = true;
            }
        });

        $recorder->closeRun('non-existent-run', RunEndState::Completed);
        // No exception thrown.
        $this->assertTrue($warned, 'Expected a warning log entry');
    }

    // ========== openRun: streamed/model/agentId (074-latency-metrics) ==========

    /** @test */
    public function open_run_writes_streamed_model_and_agent_id(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $userId = (string) \Illuminate\Support\Str::uuid();

        $runId = $recorder->openRun(
            RunKind::Interactive,
            $userId,
            null,
            null,
            streamed: true,
            model: 'claude-sonnet-5',
            agentId: 'research-assistant',
        );

        $this->assertNotNull($runId);
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertEquals(1, (int) $run->is_streamed);
        $this->assertEquals('claude-sonnet-5', $run->model);
        $this->assertEquals('research-assistant', $run->agent_id);
    }

    /** @test */
    public function open_run_defaults_streamed_model_and_agent_id(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');

        $this->assertNotNull($runId);
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertEquals(0, (int) $run->is_streamed, 'streamed defaults to false');
        $this->assertNull($run->model);
        $this->assertNull($run->agent_id);
    }

    // ========== recordFirstOutput (074-latency-metrics) ==========

    /** @test */
    public function record_first_output_is_noop_for_null_run_id(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        // Must not throw.
        $recorder->recordFirstOutput(null);
        $this->assertTrue(true);
    }

    /** @test */
    public function record_first_output_is_noop_when_tracing_disabled(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');

        $this->app['config']->set('llm-client.run_trace.enabled', false);
        $recorder->recordFirstOutput($runId);
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNull($run->first_output_ms);
    }

    /** @test */
    public function record_first_output_sets_elapsed_time_on_first_call(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');

        usleep(50_000);
        $recorder->recordFirstOutput($runId);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run->first_output_ms);
        $this->assertGreaterThanOrEqual(50, $run->first_output_ms);
        $this->assertLessThan(5_000, $run->first_output_ms);
    }

    /** @test */
    public function record_first_output_second_call_is_noop(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');

        $recorder->recordFirstOutput($runId);
        $first = DB::table('agent_runs')->where('id', $runId)->value('first_output_ms');

        usleep(50_000);
        $recorder->recordFirstOutput($runId);
        $second = DB::table('agent_runs')->where('id', $runId)->value('first_output_ms');

        $this->assertSame(
            $first,
            $second,
            'A later call must not overwrite the first-recorded value (the WHERE first_output_ms IS NULL guard)',
        );
    }

    /** @test */
    public function record_first_output_catches_db_errors(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');

        DB::statement('DROP TABLE IF EXISTS agent_runs');

        $warned = false;
        Log::listen(function ($entry) use (&$warned) {
            if ($entry->level === 'warning') {
                $warned = true;
            }
        });

        // Must not throw — mirrors open_run_catches_db_errors/close_run_catches_db_errors above.
        $recorder->recordFirstOutput($runId);
        $this->assertTrue($warned, 'Expected a warning log entry');
    }

    // ========== computeLatencyBreakdown (074-latency-metrics) ==========

    /** @test */
    public function compute_latency_breakdown_sums_model_wait_ms_from_llm_request_actions(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $stepId = $recorder->openStep($runId);

        $now = now()->format('Y-m-d H:i:s.u');
        $this->insertAction($runId, $stepId, 'llm_request', $now, $now, 1000);
        $this->insertAction($runId, $stepId, 'llm_request', $now, $now, 1500);
        // Noise: a tool_invocation action must not be counted into model_wait_ms.
        $this->insertAction($runId, $stepId, 'tool_invocation', $now, $now, 999999);

        $breakdown = $recorder->computeLatencyBreakdown($runId, 100000);

        $this->assertSame(2500, $breakdown['model_wait_ms']);
    }

    /** @test */
    public function compute_latency_breakdown_merges_overlapping_tool_invocation_intervals(): void
    {
        // data-model.md §3 worked example: [10:00:00,10:00:05] (5000ms) +
        // [10:00:02,10:00:07] (5000ms) merge to 7000ms, not the naive 10000ms sum.
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $stepId = $recorder->openStep($runId);

        $this->insertAction($runId, $stepId, 'tool_invocation', '2026-08-07 10:00:00.000000', '2026-08-07 10:00:05.000000');
        $this->insertAction($runId, $stepId, 'tool_invocation', '2026-08-07 10:00:02.000000', '2026-08-07 10:00:07.000000');

        $breakdown = $recorder->computeLatencyBreakdown($runId, 100000);

        $this->assertSame(7000, $breakdown['tool_exec_ms']);
    }

    /** @test */
    public function compute_latency_breakdown_uses_pre_pause_interval_for_paused_then_resumed_action(): void
    {
        // A tool_invocation that passed through awaiting_confirmation contributes
        // only its pre-pause portion — the long confirmation wait afterward must
        // not inflate tool_exec_ms (data-model.md §3).
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $stepId = $recorder->openStep($runId);

        $this->insertAction(
            $runId,
            $stepId,
            'tool_invocation',
            startedAt: '2026-08-07 10:00:00.000000',
            endedAt: '2026-08-07 10:00:52.000000', // resolved 52s later
            durationMs: null,
            pausedAt: '2026-08-07 10:00:02.000000', // paused after 2s
            outcome: 'success',
        );

        $breakdown = $recorder->computeLatencyBreakdown($runId, 100000);

        $this->assertSame(2000, $breakdown['tool_exec_ms']);
    }

    /** @test */
    public function compute_latency_breakdown_sums_confirm_wait_ms_from_step_wait_ms(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');

        $this->insertStepWithWait($runId, 1, 300);
        $this->insertStepWithWait($runId, 2, 700);
        $this->insertStepWithWait($runId, 3, null); // no confirmation pause

        $breakdown = $recorder->computeLatencyBreakdown($runId, 100000);

        $this->assertSame(1000, $breakdown['confirm_wait_ms']);
    }

    /** @test */
    public function compute_latency_breakdown_clamps_product_ms_to_zero_with_warning(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $stepId = $recorder->openStep($runId);

        $now = now()->format('Y-m-d H:i:s.u');
        // model_wait_ms alone (5000ms) already exceeds the total duration (1000ms).
        $this->insertAction($runId, $stepId, 'llm_request', $now, $now, 5000);

        $warned = false;
        Log::listen(function ($entry) use (&$warned) {
            if ($entry->level === 'warning') {
                $warned = true;
            }
        });

        $breakdown = $recorder->computeLatencyBreakdown($runId, 1000);

        $this->assertSame(0, $breakdown['product_ms'], 'product_ms must clamp to 0, never go negative');
        $this->assertTrue($warned, 'Expected a warning log entry when the clamp fires');
    }
}
