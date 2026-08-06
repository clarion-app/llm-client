<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Services\AgentLoopService;
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
}
