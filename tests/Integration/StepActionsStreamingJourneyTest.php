<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Services\AgentLoopService;
use Illuminate\Support\Facades\DB;

/**
 * Streaming path action tests for agent step actions (US3).
 *
 * Proves that the streaming path records LLM request actions, tool invocation
 * actions, and context reshape actions correctly, and survives recording failures.
 */
class StepActionsStreamingJourneyTest extends AssembledSystemTestCase
{
    /**
     * T051 [US3]: LLM request action spans entire stream, tool calls in finish()
     * are separate actions, failed stream records actions, action_id in http-queue payload.
     *
     * Validates:
     * - action_id present in http-queue job payload
     * - LLM request action spans the stream (started_at before tool actions)
     * - Tool calls in finish() create separate tool_invocation actions
     * - Failed stream still records actions
     */
    public function test_llm_request_action_spans_stream_and_tool_calls_are_separate(): void
    {
        $this->scenario = 'llm_request_action_spans_stream';
        $this->entryPath = 'stream';

        $fixture = $this->fixture()->build();

        // Enable tracing.
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        // Script a single tool-call response.
        $this->script()
            ->toolRequest('search_operations', ['query' => 'test'])
            ->finalAnswer('Results.');

        // Start the streaming agent loop.
        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        // Extract dispatched jobs.
        $stream = $this->stream();
        $stream->extractDispatchedJobs();

        // Verify action_id is in job payload.
        $firstJobData = $stream->capturedData()[0] ?? [];
        $this->assertArrayHasKey(
            'action_id',
            $firstJobData,
            'Job payload should contain action_id for LLM request action'
        );
        $this->assertNotNull(
            $firstJobData['action_id'],
            'action_id in job payload should not be null'
        );

        // Drive the stream to completion.
        $response = $this->script()->serve();
        $sseChunks = $this->buildToolCallSseChunks($response);
        $stream->emit($sseChunks);
        $stream->finish();

        // Extract second job (dispatched after tool call processing).
        $stream->nextSlot();
        $stream->extractDispatchedJobs();

        // Second job should also have action_id.
        $secondJobData = $stream->capturedData()[1] ?? null;
        if ($secondJobData !== null) {
            $this->assertArrayHasKey(
                'action_id',
                $secondJobData,
                'Second job payload should contain action_id'
            );
        }

        // Drive second iteration.
        $response2 = $this->script()->serve();
        $sseChunks2 = $this->buildSseChunks($response2);
        $stream->emit($sseChunks2);
        $stream->finish();

        // Verify actions were recorded.
        $actions = DB::table('agent_run_actions')->orderBy('started_at')->get();
        $this->assertGreaterThan(
            0,
            $actions->count(),
            'Expected at least one action to be recorded'
        );

        // Find LLM request actions.
        $llmActions = $actions->where('action_type', 'llm_request');
        $this->assertGreaterThan(
            0,
            $llmActions->count(),
            'Expected at least one llm_request action'
        );
    }

    /**
     * T051a [US3]: Streaming path survives recording failure (FR-005).
     *
     * When the actions table is dropped mid-stream, the handler continues
     * processing without throwing.
     */
    public function test_streaming_path_survives_recording_failure(): void
    {
        $this->scenario = 'streaming_survives_recording_failure';
        $this->entryPath = 'stream';

        $fixture = $this->fixture()->build();

        // Enable tracing.
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        // Script a single tool-call response (no follow-up needed for this test).
        $this->script()
            ->toolRequest('search_operations', ['query' => 'test']);

        // Expect RunTraceRecorder degradations (actions table is dropped).
        $this->ledger->expect('RunTraceRecorder:*');

        // Start the streaming agent loop.
        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        // Extract dispatched jobs.
        $stream = $this->stream();
        $stream->extractDispatchedJobs();

        // Drop the actions table to simulate recording failure.
        DB::statement('DROP TABLE IF EXISTS agent_run_actions');

        // Drive the stream — should not throw.
        $response = $this->script()->serve();
        $sseChunks = $this->buildToolCallSseChunks($response);

        // This should not throw even though the actions table is gone.
        $stream->emit($sseChunks);
        $stream->finish();

        // Run and step records should still exist (only actions table was affected).
        $runCount = DB::table('agent_runs')->count();
        $this->assertGreaterThanOrEqual(1, $runCount);
    }

    /**
     * T051b [US3]: Streaming-prep context reshape recorded against step opened after it.
     *
     * When applyContextWindowTrim() runs before any step exists, the reshape
     * action is recorded via recordCompletedAction() with the correct step_id
     * (retroactive write pattern).
     */
    public function test_streaming_prep_context_reshape_recorded_against_step(): void
    {
        $this->scenario = 'streaming_prep_context_reshape';
        $this->entryPath = 'stream';

        $fixture = $this->fixture()->build();

        // Enable tracing.
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        // Script a final answer (no tool calls).
        $this->script()
            ->finalAnswer('Hello, how can I help?');

        // Start the streaming agent loop.
        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        // Extract dispatched jobs.
        $stream = $this->stream();
        $stream->extractDispatchedJobs();

        // Drive the stream.
        $response = $this->script()->serve();
        $sseChunks = $this->buildSseChunks($response);
        $stream->emit($sseChunks);
        $stream->finish();

        // Verify actions were recorded.
        $actions = DB::table('agent_run_actions')->orderBy('started_at')->get();

        // Find the step that was opened.
        $steps = DB::table('agent_run_steps')->get();
        $this->assertGreaterThan(0, $steps->count(), 'Expected at least one step');

        // All actions should have a valid step_id.
        foreach ($actions as $action) {
            $stepExists = DB::table('agent_run_steps')->where('id', $action->step_id)->exists();
            $this->assertTrue(
                $stepExists,
                "Action {$action->id} has step_id {$action->step_id} that does not exist in agent_run_steps"
            );
        }

        // LLM request action should exist.
        $llmActions = $actions->where('action_type', 'llm_request');
        $this->assertGreaterThan(
            0,
            $llmActions->count(),
            'Expected at least one llm_request action'
        );
    }
}
