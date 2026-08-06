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
 * Benchmarks the overhead of individual action recording operations.
 *
 * Measures the delta between action start and recording completion for each
 * action type, asserting the median stays within SC-007 bounds (≤ 5ms).
 */
class ActionOverheadBenchmark extends TestCase
{
    private const ITERATIONS = 50;

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
     * Measure open + close overhead for an LLM request action.
     * Median should be ≤ 5ms (SC-007).
     */
    public function test_llm_request_action_overhead_median_within_bound(): void
    {
        $durations = $this->measureActionOverhead(ActionType::LlmRequest, 'gpt-4');
        $median = $this->median($durations);

        $this->assertLessThanOrEqual(
            5,
            $median,
            "LLM request action overhead median ({$median}ms) exceeds 5ms bound (SC-007)"
        );
    }

    /**
     * Measure open + close overhead for a tool invocation action.
     * Median should be ≤ 5ms (SC-007).
     */
    public function test_tool_invocation_action_overhead_median_within_bound(): void
    {
        $durations = $this->measureActionOverhead(
            ActionType::ToolInvocation,
            'search_operations',
            '{"results": 3}'
        );
        $median = $this->median($durations);

        $this->assertLessThanOrEqual(
            5,
            $median,
            "Tool invocation action overhead median ({$median}ms) exceeds 5ms bound (SC-007)"
        );
    }

    /**
     * Measure open + close overhead for a context reshape action.
     * Median should be ≤ 5ms (SC-007).
     */
    public function test_context_reshape_action_overhead_median_within_bound(): void
    {
        $durations = $this->measureActionOverhead(
            ActionType::ContextReshape,
            'window_trim',
            'trimmed 5 messages'
        );
        $median = $this->median($durations);

        $this->assertLessThanOrEqual(
            5,
            $median,
            "Context reshape action overhead median ({$median}ms) exceeds 5ms bound (SC-007)"
        );
    }

    /**
     * Measure recordCompletedAction overhead (single INSERT path).
     * Median should be ≤ 5ms (SC-007).
     */
    public function test_completed_action_overhead_median_within_bound(): void
    {
        $durations = $this->measureCompletedActionOverhead();
        $median = $this->median($durations);

        $this->assertLessThanOrEqual(
            5,
            $median,
            "Completed action overhead median ({$median}ms) exceeds 5ms bound (SC-007)"
        );
    }

    private function measureActionOverhead(
        ActionType $actionType,
        string $target,
        ?string $content = null
    ): array {
        $durations = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            [$recorder, $runId, $stepId] = $this->setupRunAndStep();

            $startMicro = microtime(true);
            $actionId = $recorder->openAction($stepId, $actionType, $target);
            $recorder->closeAction($actionId, ActionOutcome::Success, null, $content);
            $endMicro = microtime(true);

            $durations[] = ($endMicro - $startMicro) * 1000;

            // Clean up for next iteration.
            DB::table('agent_run_actions')->delete();
            DB::table('agent_run_steps')->delete();
            DB::table('agent_runs')->delete();
        }

        return $durations;
    }

    private function measureCompletedActionOverhead(): array
    {
        $durations = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            [$recorder, $runId, $stepId] = $this->setupRunAndStep();

            $startedAt = new \DateTimeImmutable();
            $endedAt = (clone $startedAt)->modify('+1 second');

            $startMicro = microtime(true);
            $recorder->recordCompletedAction(
                $stepId,
                ActionType::ContextReshape,
                ActionOutcome::Success,
                $startedAt,
                $endedAt,
                'window_trim',
                null,
                null,
                null,
                'trimmed'
            );
            $endMicro = microtime(true);

            $durations[] = ($endMicro - $startMicro) * 1000;

            // Clean up for next iteration.
            DB::table('agent_run_actions')->delete();
            DB::table('agent_run_steps')->delete();
            DB::table('agent_runs')->delete();
        }

        return $durations;
    }

    private function setupRunAndStep(): array
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $stepId = $recorder->openStep($runId);
        return [$recorder, $runId, $stepId];
    }

    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2.0;
        }

        return (float) $values[$mid];
    }
}
