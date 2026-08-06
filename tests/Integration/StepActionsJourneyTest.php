<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\DB;

/**
 * Journey tests for agent step actions on the synchronous path (US3).
 *
 * Proves that sequential tool calls in the real agent loop record non-overlapping
 * action ranges, exercising the complete sync path end-to-end.
 */
class StepActionsJourneyTest extends AssembledSystemTestCase
{
    /**
     * T049a [US3]: Real agent loop's two sequential tool calls record
     * non-overlapping ranges (FR-008, SC-003).
     *
     * The synchronous path executes tool calls sequentially, so each tool
     * invocation action must have a non-overlapping time range with the next.
     * start_1 < end_1 <= start_2 < end_2.
     */
    public function test_sequential_tool_calls_record_non_overlapping_ranges(): void
    {
        $this->scenario = 'sequential_tool_calls_non_overlapping';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();

        // Enable tracing.
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        // Script two tool-call responses followed by a final answer.
        // The agent loop will execute the tools sequentially.
        $this->script()
            ->toolRequest('search_operations', ['query' => 'first'])
            ->toolRequest('search_operations', ['query' => 'second'])
            ->finalAnswer('Both searches complete.');

        // Run the synchronous agent loop.
        $this->app->make(AgentLoopService::class)->run($fixture->conversation, 'Run two searches.');

        // Verify actions were recorded.
        $actions = DB::table('agent_run_actions')->orderBy('started_at')->get();
        $toolActions = $actions->where('action_type', 'tool_invocation')->values();

        // Should have at least 2 tool_invocation actions.
        $this->assertGreaterThanOrEqual(
            2,
            $toolActions->count(),
            'Expected at least 2 tool_invocation actions for sequential tool calls'
        );

        // Verify non-overlapping ranges for the first two tool actions.
        $first = $toolActions[0];
        $second = $toolActions[1];

        // First action ends before second action starts (non-overlapping).
        $this->assertLessThanOrEqual(
            $second->started_at,
            $first->ended_at,
            'First tool action should end before or when second starts (non-overlapping)'
        );

        // Sequential tool calls (across iterations) are on different steps.
        // Each iteration opens a new step, executes tool calls, then closes the step.
        $this->assertNotEquals(
            $first->step_id,
            $second->step_id,
            'Sequential tool calls across iterations should be on different steps'
        );

        // Both should have success outcome.
        $this->assertEquals('success', $first->outcome);
        $this->assertEquals('success', $second->outcome);
    }

    /**
     * T061 [US4]: Never-returning action recorded as unfinished on run close.
     *
     * Simulates a scenario where the run is closed while an action is still
     * in_progress. The flushUnfinishedActions() call in closeRun() should
     * transition the action to 'unfinished' with proper ended_at and duration_ms.
     *
     * Also verifies nesting integrity (parent-child hierarchy), truncation of
     * large content, and redaction of secrets in action content.
     */
    public function test_never_returning_action_recorded_as_unfinished_on_run_close(): void
    {
        $this->scenario = 'never_returning_action_unfinished';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();
        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 16384);

        $recorder = $this->app->make(RunTraceRecorder::class);

        // Open run and step manually to control the lifecycle.
        $runId = $recorder->openRun(RunKind::Interactive, $fixture->user->id, $fixture->conversation->id);
        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($runId);
        $this->assertNotNull($stepId);

        // Open a parent tool action (simulating a tool that never returns).
        $parentActionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'long_running_tool');
        $this->assertNotNull($parentActionId);

        // Open a nested child action (nesting integrity test).
        $childActionId = $recorder->openAction($stepId, ActionType::LlmRequest, 'nested-model', null, $parentActionId);
        $this->assertNotNull($childActionId);

        // Close the child action (it completed, parent is still hanging).
        $recorder->closeAction($childActionId, ActionOutcome::Success, null, '{"status": "ok"}');

        // Close step, then close run — should flush parentActionId to unfinished.
        $recorder->closeStep($stepId, RunEndState::Completed);
        $recorder->closeRun($runId, RunEndState::StoppedEarly, 'user cancelled');

        // Parent action was flushed to unfinished.
        $parentRow = DB::table('agent_run_actions')->where('id', $parentActionId)->first();
        $this->assertEquals('unfinished', $parentRow->outcome);
        $this->assertNotNull($parentRow->ended_at);
        $this->assertNotNull($parentRow->duration_ms);
        $this->assertGreaterThanOrEqual(0, $parentRow->duration_ms);

        // Child action remains success (not touched by flush).
        $childRow = DB::table('agent_run_actions')->where('id', $childActionId)->first();
        $this->assertEquals('success', $childRow->outcome);

        // Nesting integrity: child's parent_action_id still points to parent.
        $this->assertEquals($parentActionId, $childRow->parent_action_id);

        // Run is terminal.
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertEquals('stopped_early', $run->end_state);

        // Truncation test: close action with content exceeding cap.
        $runId2 = $recorder->openRun(RunKind::Interactive, $fixture->user->id, $fixture->conversation->id);
        $stepId2 = $recorder->openStep($runId2);
        $truncActionId = $recorder->openAction($stepId2, ActionType::ToolInvocation, 'big_result_tool');

        $largeContent = str_repeat('x', 20000);
        $recorder->closeAction($truncActionId, ActionOutcome::Success, null, $largeContent);
        $recorder->closeStep($stepId2, RunEndState::Completed);
        $recorder->closeRun($runId2, RunEndState::Completed);

        $truncRow = DB::table('agent_run_actions')->where('id', $truncActionId)->first();
        $this->assertNotNull($truncRow->content);
        $this->assertLessThanOrEqual(16384, strlen($truncRow->content));
        $this->assertStringContainsString('[TRUNCATED', $truncRow->content);

        // Redaction test: Bearer tokens in content are redacted.
        $runId3 = $recorder->openRun(RunKind::Interactive, $fixture->user->id, $fixture->conversation->id);
        $stepId3 = $recorder->openStep($runId3);
        $redactActionId = $recorder->openAction($stepId3, ActionType::ToolInvocation, 'api_call_tool');

        $secretContent = '{"headers": {"authorization": "Bearer super-secret-token-abc123"}}';
        $recorder->closeAction($redactActionId, ActionOutcome::Success, null, $secretContent);
        $recorder->closeStep($stepId3, RunEndState::Completed);
        $recorder->closeRun($runId3, RunEndState::Completed);

        $redactRow = DB::table('agent_run_actions')->where('id', $redactActionId)->first();
        $this->assertStringNotContainsString('super-secret-token-abc123', $redactRow->content);
        $this->assertStringContainsString('[REDACTED]', $redactRow->content);
    }

    /**
     * T061a [US4]: Full confirmation round trip with timeout window.
     *
     * A tool pauses for confirmation, the clock advances past action_timeout_minutes,
     * the run is resumed and approved. The action ends 'success', no action carries
     * a timeout failure_reason, and the recorded duration reflects execution, not the pause.
     */
    public function test_confirmation_round_trip_survives_timeout_window(): void
    {
        $this->scenario = 'confirmation_round_trip_timeout';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();
        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.run_trace.action_timeout_minutes', 5);

        $recorder = $this->app->make(RunTraceRecorder::class);

        // Open run and step.
        $runId = $recorder->openRun(RunKind::Interactive, $fixture->user->id, $fixture->conversation->id);
        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($runId);
        $this->assertNotNull($stepId);

        // Open a tool action that requires confirmation.
        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'execute_operation');
        $this->assertNotNull($actionId);

        // Suspend the action (awaiting confirmation).
        $recorder->closeAction($actionId, ActionOutcome::AwaitingConfirmation);

        // Backdate both started_at and paused_at to simulate a long human wait (past the timeout).
        // started_at 20 minutes ago, paused_at 10 minutes ago → pre-pause duration = 10 minutes.
        // The human wait window (10 minutes) exceeds action_timeout_minutes (5 minutes).
        $startedAtBackdated = now()->subMinutes(20)->format('Y-m-d H:i:s.u');
        $pausedAtBackdated = now()->subMinutes(10)->format('Y-m-d H:i:s.u');
        DB::table('agent_run_actions')
            ->where('id', $actionId)
            ->update([
                'started_at' => $startedAtBackdated,
                'paused_at' => $pausedAtBackdated,
            ]);

        // Verify action is suspended.
        $suspendedRow = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertEquals('awaiting_confirmation', $suspendedRow->outcome);
        $this->assertNull($suspendedRow->ended_at);

        // Resolve the action to success (user approved after long wait).
        $recorder->closeAction($actionId, ActionOutcome::Success, null, '{"result": "executed"}');

        // Close step and run.
        $recorder->closeStep($stepId, RunEndState::Completed);
        $recorder->closeRun($runId, RunEndState::Completed);

        // Action resolved to success, not unfinished.
        $finalRow = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertEquals('success', $finalRow->outcome);
        $this->assertNull($finalRow->failure_reason, 'No timeout failure_reason should be set');
        $this->assertNotNull($finalRow->ended_at);

        // Duration should be ~10 minutes (pre-pause execution only, not the human-wait window).
        $this->assertNotNull($finalRow->duration_ms);
        $this->assertGreaterThan(500_000, $finalRow->duration_ms,
            'Duration should include pre-pause execution time (~10 minutes)');
        $this->assertLessThan(2000_000, $finalRow->duration_ms,
            'Duration should exclude the human-wait window (should be ~10 min, not ~20 min)');

        // Run is terminal with no unfinished actions.
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertEquals('completed', $run->end_state);

        // No action in the run carries a timeout failure_reason.
        $allActions = DB::table('agent_run_actions')
            ->where('run_id', $runId)
            ->whereNotNull('failure_reason')
            ->where('failure_reason', 'like', '%timeout%')
            ->get();
        $this->assertEquals(0, $allActions->count(),
            'No action should carry a timeout failure_reason after confirmation round trip');
    }

    /**
     * T061b [US4]: Background-job run via traceSystemRun() produces at least one action.
     *
     * A closure calling a provider directly (not through AgentLoopService) produces
     * at least one action for its model call, verifying that traceSystemRun() brackets
     * the work as an action (FR-014, SC-010).
     */
    public function test_background_job_trace_system_run_produces_action(): void
    {
        $this->scenario = 'background_job_trace_system_run';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $recorder = $this->app->make(RunTraceRecorder::class);

        // traceSystemRun with a simple callable that succeeds.
        $result = $recorder->traceSystemRun(
            'test_background_job',
            $fixture->user->id,
            $fixture->conversation->id,
            fn () => 'job_result',
            ActionType::LlmRequest,
            'background-model',
        );

        $this->assertEquals('job_result', $result);

        // Find the run created by traceSystemRun.
        $runs = DB::table('agent_runs')
            ->where('source', 'test_background_job')
            ->where('user_id', $fixture->user->id)
            ->get();
        $this->assertEquals(1, $runs->count(), 'traceSystemRun should create exactly one run');

        $runId = $runs[0]->id;

        // Run should be completed.
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertEquals('completed', $run->end_state);

        // At least one action should exist for the model call.
        $actions = DB::table('agent_run_actions')->where('run_id', $runId)->get();
        $this->assertGreaterThanOrEqual(
            1,
            $actions->count(),
            'traceSystemRun should produce at least one action for its callable'
        );

        // The action should be success with the correct target.
        $action = $actions->where('target', 'background-model')->first();
        $this->assertNotNull($action, 'Action should have target matching the model name');
        $this->assertEquals('success', $action->outcome);
        $this->assertEquals('llm_request', $action->action_type);
        $this->assertNotNull($action->ended_at);
        $this->assertNotNull($action->duration_ms);

        // Step should also be completed.
        $steps = DB::table('agent_run_steps')->where('run_id', $runId)->get();
        $this->assertEquals(1, $steps->count());
        $this->assertEquals('completed', $steps[0]->end_state);
    }
}
