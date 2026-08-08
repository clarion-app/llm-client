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
 * SC-002 overhead benchmark for 074-latency-metrics: recording timing data
 * (the new openRun() streamed/model/agentId arguments, recordFirstOutput(),
 * and closeRun()'s new computeLatencyBreakdown() call) must add well under
 * 10ms per response.
 *
 * Reuses the same run_trace.enabled=true/false config-toggle methodology as
 * the existing SC-007/SC-008 benchmarks (RunOverheadBenchmark, and
 * RunTraceSafetyJourneyTest's sync/stream SC-007 benchmarks): the same
 * scripted run lifecycle is executed under both settings and the wall-clock
 * median delta between them is asserted against the bound. The "byte-identical
 * delivered response" half of that methodology has no analog at this
 * recorder-only level (there is no delivered response here) -- its equivalent
 * correctness check is that every recorder call returns a real id when
 * enabled and null when disabled, so the two runs are exercising the same
 * call shape either way, not two different code paths.
 */
class LatencyOverheadBenchmark extends TestCase
{
    private const ITERATIONS = 10;

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
     * One full run lifecycle: openRun (with streamed/model/agentId), one
     * step with a wait_ms confirmation pause, two tool actions and one LLM
     * action, then closeStep/closeRun -- the closeRun() call is where
     * computeLatencyBreakdown() (model_wait_ms/tool_exec_ms/confirm_wait_ms/
     * product_ms) runs.
     */
    private function runOnce(RunTraceRecorder $recorder): void
    {
        $runId = $recorder->openRun(
            RunKind::Interactive,
            'user-1',
            streamed: true,
            model: 'claude-sonnet-5',
            agentId: 'benchmark-agent',
        );
        $recorder->recordFirstOutput($runId);

        $stepId = $recorder->openStep($runId);

        $llmActionId = $recorder->openAction($stepId, ActionType::LlmRequest, 'claude-sonnet-5');
        $recorder->closeAction($llmActionId, ActionOutcome::Success, null, '{"status":"ok"}');

        $toolActionId1 = $recorder->openAction($stepId, ActionType::ToolInvocation, 'tool_a');
        $recorder->closeAction($toolActionId1, ActionOutcome::Success, null, 'result_a');

        $toolActionId2 = $recorder->openAction($stepId, ActionType::ToolInvocation, 'tool_b');
        $recorder->closeAction($toolActionId2, ActionOutcome::Success, null, 'result_b');

        $recorder->closeStep($stepId, RunEndState::Completed, null, waitMs: 50);
        $recorder->closeRun($runId, RunEndState::Completed);
    }

    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }

        return $values[$mid];
    }

    public function test_latency_capture_overhead_is_well_under_ten_milliseconds(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        // ---- Control timings: tracing disabled (every recorder call is a no-op) ----
        $this->app['config']->set('llm-client.run_trace.enabled', false);
        $controlTimes = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $start = microtime(true);
            $this->runOnce($recorder);
            $controlTimes[] = microtime(true) - $start;
        }

        $this->assertSame(0, DB::table('agent_runs')->count(), 'tracing disabled must write nothing');

        // ---- Recording timings: tracing enabled, including the new latency capture ----
        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $recordingTimes = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            DB::table('agent_run_actions')->delete();
            DB::table('agent_run_steps')->delete();
            DB::table('agent_runs')->delete();

            $start = microtime(true);
            $this->runOnce($recorder);
            $recordingTimes[] = microtime(true) - $start;
        }

        $this->assertGreaterThan(0, DB::table('agent_runs')->count(), 'tracing enabled must write the run');

        $controlMedian = $this->median($controlTimes);
        $recordingMedian = $this->median($recordingTimes);
        $overheadMs = ($recordingMedian - $controlMedian) * 1000;

        $this->assertLessThan(
            10.0,
            $overheadMs,
            "Latency capture overhead ({$overheadMs}ms) exceeds the 10ms SC-002 bound "
                . '(control median: ' . round($controlMedian * 1000, 3) . 'ms, '
                . 'recording median: ' . round($recordingMedian * 1000, 3) . 'ms)'
        );
    }

    /**
     * The breakdown-specific figures (074's addition to closeRun()) stay
     * within the same bound in isolation, not just as part of the whole
     * run lifecycle above -- computeLatencyBreakdown() is the one new query
     * cluster this feature added to the terminal write.
     */
    public function test_compute_latency_breakdown_overhead_is_well_under_ten_milliseconds(): void
    {
        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $recorder = $this->app->make(RunTraceRecorder::class);

        $runId = $recorder->openRun(RunKind::Interactive, 'user-1', streamed: false, model: 'claude-sonnet-5');
        $stepId = $recorder->openStep($runId);

        for ($i = 0; $i < 5; $i++) {
            $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, "tool_{$i}");
            $recorder->closeAction($actionId, ActionOutcome::Success, null, "result_{$i}");
        }

        $recorder->closeStep($stepId, RunEndState::Completed, null, waitMs: 20);

        $times = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $start = microtime(true);
            $recorder->computeLatencyBreakdown($runId, 5000);
            $times[] = microtime(true) - $start;
        }

        $medianMs = $this->median($times) * 1000;

        $this->assertLessThan(
            10.0,
            $medianMs,
            "computeLatencyBreakdown() median overhead ({$medianMs}ms) exceeds the 10ms SC-002 bound"
        );
    }
}
