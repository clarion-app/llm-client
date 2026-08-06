<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Benchmarks end-to-end action recording overhead for a simulated run.
 *
 * Measures total overhead of recording 30 actions across a run lifecycle,
 * asserting the total stays within SC-008 bounds (≤ 100ms).
 */
class RunOverheadBenchmark extends TestCase
{
    private const ACTIONS_PER_RUN = 30;

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

    /**
     * Simulate a run with 30 actions (mixed types) and measure total overhead.
     * Total should be ≤ 100ms (SC-008).
     */
    public function test_run_with_thirty_actions_total_overhead_within_bound(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        // Open run and step outside the measurement window.
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $stepId = $recorder->openStep($runId);

        // Measure the action recording overhead.
        $startMicro = microtime(true);

        $actionIds = [];
        for ($i = 0; $i < self::ACTIONS_PER_RUN; $i++) {
            // Cycle through action types: LLM, tool, reshape.
            $types = [ActionType::LlmRequest, ActionType::ToolInvocation, ActionType::ContextReshape];
            $type = $types[$i % 3];
            $target = match ($type) {
                ActionType::LlmRequest => 'gpt-4',
                ActionType::ToolInvocation => "tool_{$i}",
                ActionType::ContextReshape => 'window_trim',
            };

            $actionId = $recorder->openAction($stepId, $type, $target);
            $actionIds[] = $actionId;
        }

        // Close all actions.
        foreach ($actionIds as $i => $actionId) {
            $content = $i % 2 === 0 ? '{"status": "ok"}' : null;
            $recorder->closeAction($actionId, ActionOutcome::Success, null, $content);
        }

        $endMicro = microtime(true);
        $totalMs = ($endMicro - $startMicro) * 1000;

        // Close step and run (outside measurement).
        $recorder->closeStep($stepId, RunEndState::Completed);
        $recorder->closeRun($runId, RunEndState::Completed);

        $this->assertLessThanOrEqual(
            100,
            $totalMs,
            "Total action recording overhead ({$totalMs}ms) exceeds 100ms bound (SC-008)"
        );

        // Verify all actions were recorded.
        $actionCount = DB::table('agent_run_actions')->where('run_id', $runId)->count();
        $this->assertEquals(
            self::ACTIONS_PER_RUN,
            $actionCount,
            "Expected {$totalMs} actions to be recorded"
        );
    }

    /**
     * Simulate a run with 30 actions including some failures and unfinished flush.
     * Total should still be ≤ 100ms (SC-008).
     */
    public function test_run_with_mixed_outcomes_overhead_within_bound(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $stepId = $recorder->openStep($runId);

        $startMicro = microtime(true);

        $actionIds = [];
        for ($i = 0; $i < self::ACTIONS_PER_RUN; $i++) {
            $types = [ActionType::LlmRequest, ActionType::ToolInvocation, ActionType::ContextReshape];
            $type = $types[$i % 3];
            $target = "target_{$i}";

            $actionId = $recorder->openAction($stepId, $type, $target);
            $actionIds[] = $actionId;
        }

        // Close most actions, leave a few in_progress for flush.
        $closeCount = self::ACTIONS_PER_RUN - 3;
        for ($i = 0; $i < $closeCount; $i++) {
            $outcome = ($i % 5 === 0)
                ? ActionOutcome::Failure
                : ActionOutcome::Success;
            $reason = ($outcome === ActionOutcome::Failure) ? 'simulated error' : null;
            $recorder->closeAction($actionIds[$i], $outcome, $reason, null, "result_{$i}");
        }

        // Close step and run — flushUnfinishedActions runs inside closeRun.
        $recorder->closeStep($stepId, RunEndState::Completed);
        $recorder->closeRun($runId, RunEndState::Completed);

        $endMicro = microtime(true);
        $totalMs = ($endMicro - $startMicro) * 1000;

        $this->assertLessThanOrEqual(
            100,
            $totalMs,
            "Mixed outcomes overhead ({$totalMs}ms) exceeds 100ms bound (SC-008)"
        );

        // Verify all actions recorded, including flushed ones.
        $actions = DB::table('agent_run_actions')->where('run_id', $runId)->get();
        $this->assertEquals(self::ACTIONS_PER_RUN, $actions->count());

        // 3 should be unfinished (flushed).
        $unfinished = $actions->where('outcome', 'unfinished')->count();
        $this->assertEquals(3, $unfinished, 'Expected 3 actions to be flushed as unfinished');
    }
}
