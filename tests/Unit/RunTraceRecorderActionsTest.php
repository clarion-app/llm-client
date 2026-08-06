<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Services\ContentSanitizer;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RunTraceRecorderActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.run_trace.action_row_cap', 500);
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 16384);
    }

    protected function tearDown(): void
    {
        foreach (['agent_run_actions', 'agent_run_steps', 'agent_run_messages', 'agent_runs'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        parent::tearDown();
    }

    private function setupRunAndStep(): array
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $stepId = $recorder->openStep($runId);
        return [$recorder, $runId, $stepId];
    }

    // ========== openAction() ==========

    /** @test */
    public function open_action_creates_row_with_correct_columns(): void
    {
        [$recorder, $runId, $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::LlmRequest, 'gpt-4');

        $this->assertNotNull($actionId);
        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertNotNull($action);
        $this->assertEquals($runId, $action->run_id);
        $this->assertEquals($stepId, $action->step_id);
        $this->assertEquals('llm_request', $action->action_type);
        $this->assertEquals('gpt-4', $action->target);
        $this->assertEquals('in_progress', $action->outcome);
        $this->assertNull($action->ended_at);
        $this->assertNull($action->duration_ms);
        $this->assertNull($action->failure_reason);
        $this->assertNull($action->content);
        $this->assertNull($action->parent_action_id);
        $this->assertNull($action->paused_at);
        $this->assertNotNull($action->started_at);
    }

    /** @test */
    public function open_action_returns_null_for_null_step_id(): void
    {
        [$recorder] = $this->setupRunAndStep();

        $result = $recorder->openAction(null, ActionType::LlmRequest);
        $this->assertNull($result);
    }

    /** @test */
    public function open_action_is_noop_when_tracing_disabled(): void
    {
        $this->app['config']->set('llm-client.run_trace.enabled', false);
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $result = $recorder->openAction($stepId, ActionType::LlmRequest);
        $this->assertNull($result);
    }

    /** @test */
    public function open_action_catches_db_exception(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        // Drop the actions table to force an error.
        DB::statement('DROP TABLE IF EXISTS agent_run_actions');

        $warned = false;
        Log::listen(function ($entry) use (&$warned) {
            if ($entry->level === 'warning') {
                $warned = true;
            }
        });

        $result = $recorder->openAction($stepId, ActionType::LlmRequest);
        $this->assertNull($result);
        $this->assertTrue($warned, 'Expected a warning log entry');
    }

    /** @test */
    public function open_action_accepts_optional_parameters(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $attemptGroupId = (string) \Illuminate\Support\Str::uuid();
        $parentActionId = (string) \Illuminate\Support\Str::uuid();

        $actionId = $recorder->openAction(
            $stepId,
            ActionType::LlmRequest,
            'nested-model',
            $attemptGroupId,
            $parentActionId,
        );

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertEquals($attemptGroupId, $action->attempt_group_id);
        $this->assertEquals($parentActionId, $action->parent_action_id);
    }

    // ========== closeAction() ==========

    /** @test */
    public function close_action_updates_outcome_and_duration(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'search_operations');

        usleep(100_000); // 100ms to ensure measurable duration.
        $recorder->closeAction($actionId, ActionOutcome::Success, null, '{"results": 5}');

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertEquals('success', $action->outcome);
        $this->assertNotNull($action->ended_at);
        $this->assertGreaterThanOrEqual(100, $action->duration_ms);
        $this->assertLessThan(5_000, $action->duration_ms);
        $this->assertNotNull($action->content);
    }

    /** @test */
    public function close_action_with_failure_sets_failure_reason(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::LlmRequest, 'gpt-4');

        $recorder->closeAction($actionId, ActionOutcome::Failure, 'connection timeout');

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertEquals('failure', $action->outcome);
        $this->assertEquals('connection timeout', $action->failure_reason);
    }

    /** @test */
    public function close_action_with_success_leaves_failure_reason_null(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::LlmRequest, 'gpt-4');
        $recorder->closeAction($actionId, ActionOutcome::Success);

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertNull($action->failure_reason);
    }

    /** @test */
    public function close_action_is_noop_for_null_action_id(): void
    {
        [$recorder] = $this->setupRunAndStep();

        $recorder->closeAction(null, ActionOutcome::Success);
        // No exception.
        $this->assertEquals(0, DB::table('agent_run_actions')->count());
    }

    /** @test */
    public function close_action_is_noop_when_tracing_disabled(): void
    {
        // Open the action while tracing is enabled (default in setUp).
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::LlmRequest);
        $this->assertNotNull($actionId);

        // Now disable tracing.
        $this->app['config']->set('llm-client.run_trace.enabled', false);
        $recorder = $this->app->make(RunTraceRecorder::class);
        $recorder->closeAction($actionId, ActionOutcome::Success);

        // Outcome should remain in_progress because close was a no-op.
        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertNotNull($action);
        $this->assertEquals('in_progress', $action->outcome);
    }

    /** @test */
    public function close_action_catches_db_exception(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::LlmRequest);
        DB::statement('DROP TABLE IF EXISTS agent_run_actions');

        $warned = false;
        Log::listen(function ($entry) use (&$warned) {
            if ($entry->level === 'warning') {
                $warned = true;
            }
        });

        $recorder->closeAction($actionId, ActionOutcome::Success);
        // No exception thrown.
        $this->assertTrue($warned, 'Expected a warning log entry');
    }

    /** @test */
    public function close_action_clamps_duration_to_zero_minimum(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::ContextReshape, 'window_trim');
        // Close immediately - duration should be >= 0.
        $recorder->closeAction($actionId, ActionOutcome::Success);

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertGreaterThanOrEqual(0, $action->duration_ms);
    }

    // ========== AwaitingConfirmation (T023e supporting tests) ==========

    /** @test */
    public function close_action_with_awaiting_confirmation_stamps_paused_at(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'execute_operation');

        $recorder->closeAction($actionId, ActionOutcome::AwaitingConfirmation);

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertEquals('awaiting_confirmation', $action->outcome);
        $this->assertNotNull($action->paused_at);
        $this->assertNull($action->ended_at);
        $this->assertNull($action->duration_ms);
    }

    /** @test */
    public function close_action_resolves_awaiting_confirmation_to_success(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'execute_operation');
        $recorder->closeAction($actionId, ActionOutcome::AwaitingConfirmation);

        usleep(100_000);
        $recorder->closeAction($actionId, ActionOutcome::Success);

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertEquals('success', $action->outcome);
        $this->assertNotNull($action->ended_at);
        // Duration should be small (pre-pause execution only, not the pause itself).
        $this->assertGreaterThanOrEqual(0, $action->duration_ms);
    }

    /** @test */
    public function close_action_resolves_awaiting_confirmation_to_failure(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'execute_operation');
        $recorder->closeAction($actionId, ActionOutcome::AwaitingConfirmation);

        $recorder->closeAction($actionId, ActionOutcome::Failure, 'user declined');

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertEquals('failure', $action->outcome);
        $this->assertEquals('user declined', $action->failure_reason);
        $this->assertNotNull($action->ended_at);
    }

    // ========== recordCompletedAction() ==========

    /** @test */
    public function record_completed_action_inserts_single_row(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $startedAt = new \DateTimeImmutable('2026-01-01 10:00:00.000000');
        $endedAt = new \DateTimeImmutable('2026-01-01 10:00:05.000000');

        $actionId = $recorder->recordCompletedAction(
            $stepId,
            ActionType::ContextReshape,
            ActionOutcome::Success,
            $startedAt,
            $endedAt,
            'window_trim',
        );

        $this->assertNotNull($actionId);
        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertEquals('context_reshape', $action->action_type);
        $this->assertEquals('window_trim', $action->target);
        $this->assertEquals('success', $action->outcome);
    }

    /** @test */
    public function record_completed_action_returns_null_for_null_step_id(): void
    {
        [$recorder] = $this->setupRunAndStep();

        $result = $recorder->recordCompletedAction(
            null,
            ActionType::LlmRequest,
            ActionOutcome::Success,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
        );
        $this->assertNull($result);
    }

    /** @test */
    public function record_completed_action_is_noop_when_tracing_disabled(): void
    {
        $this->app['config']->set('llm-client.run_trace.enabled', false);
        $recorder = $this->app->make(RunTraceRecorder::class);
        [$_, , $stepId] = $this->setupRunAndStep();

        $result = $recorder->recordCompletedAction(
            $stepId,
            ActionType::LlmRequest,
            ActionOutcome::Success,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
        );
        $this->assertNull($result);
    }

    /** @test */
    public function record_completed_action_catches_db_exception(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        DB::statement('DROP TABLE IF EXISTS agent_run_actions');

        $warned = false;
        Log::listen(function ($entry) use (&$warned) {
            if ($entry->level === 'warning') {
                $warned = true;
            }
        });

        $result = $recorder->recordCompletedAction(
            $stepId,
            ActionType::LlmRequest,
            ActionOutcome::Success,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
        );
        $this->assertNull($result);
        $this->assertTrue($warned, 'Expected a warning log entry');
    }

    // ========== T023a: target persistence, sanitizer separation, truncation survival ==========

    /** @test */
    public function target_persisted_verbatim_at_open_and_survives_content_truncation(): void
    {
        // Use a very small cap so content is definitely truncated.
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 64);
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $toolName = 'contacts.destroy_with_a_very_long_tool_name_for_testing';
        $actionId = $recorder->openAction(
            $stepId,
            ActionType::ToolInvocation,
            $toolName,
        );

        // Content is larger than the cap and contains a secret.
        $largeContent = str_repeat('x', 200) . '"authorization": "Bearer secret-token-123"';

        $recorder->closeAction($actionId, ActionOutcome::Success, null, $largeContent);

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();

        // Target is preserved verbatim — never sanitized, never truncated.
        $this->assertEquals($toolName, $action->target);

        // Content was truncated to the cap.
        $this->assertNotNull($action->content);
        $this->assertLessThanOrEqual(64, strlen($action->content));

        // Content was sanitized (the Bearer token should be redacted).
        $this->assertStringNotContainsString('secret-token-123', $action->content);
    }

    // ========== T023b: recordCompletedAction uses caller timestamps, not write time ==========

    /** @test */
    public function record_completed_action_computes_duration_from_caller_timestamps_not_write_time(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        // Action ran for ~5 seconds, 10 minutes ago.
        $startedAt = (new \DateTimeImmutable())->modify('-10 minutes')->modify('-5 seconds');
        $endedAt = (new \DateTimeImmutable())->modify('-10 minutes');

        $actionId = $recorder->recordCompletedAction(
            $stepId,
            ActionType::LlmRequest,
            ActionOutcome::Success,
            $startedAt,
            $endedAt,
            'gpt-4',
        );

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();

        // Duration should be ~5000ms, not ~605000ms (10 min 5 sec from write time).
        $this->assertGreaterThanOrEqual(4_500, $action->duration_ms,
            'Duration should reflect the 5-second action, not the 10-minute write lag');
        $this->assertLessThanOrEqual(5_500, $action->duration_ms,
            'Duration should reflect the 5-second action, not the 10-minute write lag');

        // started_at and ended_at should match the caller's timestamps.
        $recordedStarted = \Carbon\Carbon::parse($action->started_at);
        $recordedEnded = \Carbon\Carbon::parse($action->ended_at);
        $this->assertLessThanOrEqual(1, abs($recordedStarted->diffInSeconds($startedAt)));
        $this->assertLessThanOrEqual(1, abs($recordedEnded->diffInSeconds($endedAt)));
    }

    // ========== T023c: sub-millisecond action records duration_ms = 0 ==========

    /** @test */
    public function action_faster_than_timestamp_resolution_records_zero_duration(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::ContextReshape, 'window_trim');
        // Close immediately — no sleep, should be within timestamp resolution.
        $recorder->closeAction($actionId, ActionOutcome::Success);

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();

        // Duration is exactly 0, never null, never negative.
        $this->assertEquals(0, $action->duration_ms);
    }

    // ========== flushUnfinishedActions() ==========

    /** @test */
    public function flush_unfinished_actions_marks_in_progress_as_unfinished(): void
    {
        [$recorder, $runId, $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'slow_tool');
        // Don't close the action — leave it in_progress.

        $recorder->flushUnfinishedActions($runId);

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertEquals('unfinished', $action->outcome);
        $this->assertNotNull($action->ended_at);
    }

    /** @test */
    public function flush_unfinished_actions_does_not_touch_awaiting_confirmation(): void
    {
        [$recorder, $runId, $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'execute_operation');
        $recorder->closeAction($actionId, ActionOutcome::AwaitingConfirmation);

        $recorder->flushUnfinishedActions($runId);

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        // Should remain awaiting_confirmation, not swept to unfinished.
        $this->assertEquals('awaiting_confirmation', $action->outcome);
        $this->assertNull($action->ended_at);
    }

    /** @test */
    public function flush_unfinished_actions_is_noop_for_null_run_id(): void
    {
        [$recorder] = $this->setupRunAndStep();

        $recorder->flushUnfinishedActions(null);

        // Verify no actions were flushed (no-op on null run_id).
        $count = DB::table('agent_run_actions')->count();
        $this->assertEquals(0, $count);
    }

    /** @test */
    public function flush_unfinished_actions_catches_db_exception(): void
    {
        [$recorder, $runId] = $this->setupRunAndStep();

        DB::statement('DROP TABLE IF EXISTS agent_run_actions');

        $warned = false;
        Log::listen(function ($entry) use (&$warned) {
            if ($entry->level === 'warning') {
                $warned = true;
            }
        });

        $recorder->flushUnfinishedActions($runId);
        $this->assertTrue($warned, 'Expected a warning log entry');
    }

    // ========== Per-run cap ==========

    /** @test */
    public function open_action_returns_null_when_per_run_cap_exceeded(): void
    {
        $this->app['config']->set('llm-client.run_trace.action_row_cap', 2);
        [$recorder, $runId, $stepId] = $this->setupRunAndStep();

        $action1 = $recorder->openAction($stepId, ActionType::LlmRequest, 'model-1');
        $action2 = $recorder->openAction($stepId, ActionType::LlmRequest, 'model-2');
        $action3 = $recorder->openAction($stepId, ActionType::LlmRequest, 'model-3');

        $this->assertNotNull($action1);
        $this->assertNotNull($action2);
        $this->assertNull($action3, 'Third action should be null when cap of 2 is exceeded');
    }

    /** @test */
    public function cap_exceeded_logs_warning(): void
    {
        $this->app['config']->set('llm-client.run_trace.action_row_cap', 1);
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $recorder->openAction($stepId, ActionType::LlmRequest, 'model-1');

        $warned = false;
        Log::listen(function ($entry) use (&$warned) {
            if ($entry->level === 'warning') {
                $warned = true;
            }
        });

        $recorder->openAction($stepId, ActionType::LlmRequest, 'model-2');
        $this->assertTrue($warned, 'Expected a warning when cap is exceeded');
    }

    // ========== T033: closeAction() on terminal action is no-op (C16) ==========

    /** @test */
    public function close_action_on_already_terminal_action_is_no_op(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::LlmRequest, 'gpt-4');
        $recorder->closeAction($actionId, ActionOutcome::Success);

        // Attempt to close again with a different outcome — should be a no-op.
        $recorder->closeAction($actionId, ActionOutcome::Failure, 'should not overwrite');

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        // Outcome should remain 'success', not overwritten to 'failure'.
        $this->assertEquals('success', $action->outcome);
        $this->assertNull($action->failure_reason);
    }

    /** @test */
    public function close_action_on_unfinished_action_is_no_op(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'slow_tool');

        // Manually set to unfinished (simulating flushUnfinishedActions).
        DB::table('agent_run_actions')
            ->where('id', $actionId)
            ->update([
                'outcome' => 'unfinished',
                'ended_at' => now()->format('Y-m-d H:i:s.u'),
                'duration_ms' => 0,
            ]);

        // Attempt to close again — should be a no-op.
        $recorder->closeAction($actionId, ActionOutcome::Success);

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertEquals('unfinished', $action->outcome);
    }

    // ========== T041: closeAction passes content through ContentSanitizer::prepare() ==========

    /** @test */
    public function close_action_passes_content_through_sanitizer(): void
    {
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'execute_operation');

        // Content contains a Bearer token that should be redacted.
        $content = '{"headers": {"authorization": "Bearer secret-token-xyz123"}}';
        $recorder->closeAction($actionId, ActionOutcome::Success, null, $content);

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();

        // Bearer token should be redacted.
        $this->assertStringNotContainsString('secret-token-xyz123', $action->content);
        $this->assertStringContainsString('[REDACTED]', $action->content);
    }

    /** @test */
    public function close_action_truncates_content_over_cap(): void
    {
        // Set a small cap so truncation is obvious.
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 64);
        [$recorder, , $stepId] = $this->setupRunAndStep();

        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'big_tool');

        // Content exceeds the 64-byte cap.
        $content = str_repeat('x', 200);
        $recorder->closeAction($actionId, ActionOutcome::Success, null, $content);

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();

        // Content should be truncated to within the cap.
        $this->assertLessThanOrEqual(64, strlen($action->content));
        $this->assertStringContainsString('[TRUNCATED', $action->content);
    }
}
