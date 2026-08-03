<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Harness\ResponseScript;

/**
 * Continuity journey tests for agent run tracing (US6).
 *
 * Proves that a run spanning multiple processes remains exactly one record
 * with contiguous step positions, and that run_id survives both the
 * http-queue payload hop and confirmation pauses through messages.tool_data.
 */
class RunTraceContinuityJourneyTest extends AssembledSystemTestCase
{
    /**
     * T053 [US6]: A run spanning ≥ 2 processes yields exactly one record with
     * contiguous position values and a single end state set once on the original
     * record.
     *
     * FR-016, SC-009, US6 scenarios 1 and 3.
     *
     * The streaming path dispatches each iteration to a separate queue job
     * (process), so a multi-round streaming conversation spans multiple processes.
     * The test drives a conversation with ≥ 2 tool-call rounds followed by
     * a final answer, all over the streaming path.
     */
    public function test_multi_process_run_yields_single_record(): void
    {
        $this->scenario = 'multi_process_run_yields_single_record';
        $this->entryPath = 'stream';

        $fixture = $this->fixture()->build();

        // Enable tracing.
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        // Script a multi-round conversation: two tool-call rounds then a final answer.
        // Each round dispatches a separate queue job (process hop).
        $this->script()
            ->toolRequest('search_operations', ['query' => 'first search'])
            ->toolRequest('search_operations', ['query' => 'second search'])
            ->finalAnswer('Here are the results from both searches.');

        // Start the streaming agent loop.
        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        // Extract dispatched jobs — jobs are dispatched incrementally as the
        // handler processes each iteration. We extract after each emit/finish.
        $stream = $this->stream();
        $stream->extractDispatchedJobs();

        // Drive each job slot through the handler.
        // The script has 3 steps: 2 tool requests + 1 final answer.
        // Each tool request dispatches a new job for the next iteration.
        $scriptStepCount = 3;
        for ($slot = 0; $slot < $scriptStepCount; $slot++) {
            $response = $this->script()->serve();

            if (isset($response['choices'][0]['message']['tool_calls'])) {
                // Tool-call response: build SSE chunks with tool calls.
                $sseChunks = $this->buildToolCallSseChunks($response);
            } else {
                // Final answer: build SSE chunks with text content.
                $sseChunks = $this->buildSseChunks($response);
            }

            $stream->emit($sseChunks);
            $stream->finish();

            // After emit/finish, the handler may have dispatched a new job.
            // Extract it for the next iteration.
            if ($slot < $scriptStepCount - 1) {
                $stream->nextSlot();
                $stream->extractDispatchedJobs();
            }
        }

        // Assert that multiple jobs were dispatched (≥ 2 processes).
        // The first start() dispatches 1 job, and each tool-call iteration
        // dispatches another. With 2 tool requests, we expect 3 jobs total.
        $jobCount = $stream->turnCount();
        $this->assertGreaterThanOrEqual(
            2,
            $jobCount,
            "Expected at least 2 dispatched jobs for a multi-round conversation, got {$jobCount}"
        );

        // ---- Assert exactly one run record ----
        $runCount = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->count();
        $this->assertSame(
            1,
            $runCount,
            "Expected exactly one run record, found {$runCount}"
        );

        // ---- Assert the run is terminal (completed) ----
        $run = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->first();
        $this->assertNotEquals(
            RunEndState::InProgress->value,
            $run->end_state,
            "Run should be terminal (not in_progress), got {$run->end_state}"
        );

        // ---- Assert contiguous positions ----
        $steps = DB::table('agent_run_steps')
            ->where('run_id', $run->id)
            ->orderBy('position')
            ->get();

        $this->assertGreaterThan(
            1,
            $steps->count(),
            "Expected multiple steps for a multi-round conversation"
        );

        $positions = $steps->pluck('position')->toArray();
        $expectedPositions = range(1, count($positions));
        $this->assertSame(
            $expectedPositions,
            $positions,
            "Step positions should be contiguous starting from 1"
        );

        // ---- Assert each step has a real start time ----
        foreach ($steps as $step) {
            $this->assertNotNull($step->started_at, "Step {$step->position} should have a started_at");
            $this->assertNotNull($step->ended_at, "Step {$step->position} should have an ended_at");
        }
    }

    /**
     * T054 [US6]: run_id survives the http-queue payload hop (contracts §3.1)
     * and survives a confirmation pause through messages.tool_data (contracts §3.2).
     *
     * For the http-queue hop: verify that the run_id in the job payload matches
     * the run record's id across multiple dispatched jobs.
     *
     * For confirmation pauses: verify that resume() recovers run_id from tool_data
     * and continues the existing run rather than minting a new one.
     */
    public function test_run_id_survives_queue_hop_and_confirmation_pause(): void
    {
        $this->scenario = 'run_id_survives_queue_hop_and_confirmation';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();

        // Enable tracing.
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $agentLoopService = $this->app->make(AgentLoopService::class);

        // ---- Part 1: run_id survives the http-queue payload hop ----
        // Start a streaming conversation and verify run_id is in the job payload.
        $this->script()
            ->toolRequest('search_operations', ['query' => 'test'])
            ->finalAnswer('Search results.');

        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        $stream = $this->stream();
        $stream->extractDispatchedJobs();

        // The first job should have run_id in its data payload.
        $firstJobData = $stream->capturedData()[0] ?? [];
        $this->assertArrayHasKey(
            'run_id',
            $firstJobData,
            'Job payload should contain run_id'
        );
        $this->assertNotNull(
            $firstJobData['run_id'],
            'run_id in job payload should not be null'
        );

        // Verify the run_id matches the run record.
        $runCount = DB::table('agent_runs')
            ->where('id', $firstJobData['run_id'])
            ->count();
        $this->assertSame(
            1,
            $runCount,
            "run_id from job payload should match an existing run record"
        );

        // Drive the stream to completion.
        // The script has 2 steps: 1 tool request + 1 final answer.
        // start() dispatches 1 job, and the handler dispatches another after
        // processing the tool call. We iterate based on script steps.
        $scriptStepCount = 2;
        for ($slot = 0; $slot < $scriptStepCount; $slot++) {
            $response = $this->script()->serve();

            if (isset($response['choices'][0]['message']['tool_calls'])) {
                $sseChunks = $this->buildToolCallSseChunks($response);
            } else {
                $sseChunks = $this->buildSseChunks($response);
            }

            $stream->emit($sseChunks);
            $stream->finish();

            // After emit/finish, the handler may have dispatched a new job.
            // Extract it for the next iteration.
            if ($slot < $scriptStepCount - 1) {
                $stream->nextSlot();
                $stream->extractDispatchedJobs();
            }
        }

        // ---- Part 2: run_id survives a confirmation pause ----
        // Test the confirmation path directly by creating a message with
        // pending_confirmation and run_id in tool_data, then calling resumeSync().
        // resumeSync() calls callLlmSync() which uses the scripted transport.

        // Get the run_id from Part 1.
        $runRecords = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->orderByDesc('started_at')
            ->get();

        if ($runRecords->count() > 0) {
            $runId = $runRecords[0]->id;

            // Clean up messages for a fresh confirmation test.
            // Clean steps and message trace tables, but preserve the run record
            // so resumeSync() can recover it. Reset end_state to in_progress
            // so resumeSync() can close it properly.
            Message::where('conversation_id', $fixture->conversation->id)->delete();
            DB::table('agent_run_steps')->delete();
            DB::table('agent_run_messages')->delete();
            DB::table('agent_runs')
                ->where('id', $runId)
                ->update([
                    'end_state' => RunEndState::InProgress->value,
                    'ended_at' => null,
                    'duration_ms' => null,
                    'step_count' => 0,
                ]);
            $this->rebindScript();
            $stream->reset();

            // Create a message with pending confirmation and run_id in tool_data.
            $confirmMessage = Message::create([
                'conversation_id' => $fixture->conversation->id,
                'content' => 'Please confirm this operation.',
                'role' => 'assistant',
                'user' => $fixture->conversation->character,
                'responseTime' => 0,
                'tool_data' => [
                    'tool_calls' => [
                        [
                            'id' => 'call_confirm_001',
                            'type' => 'function',
                            'function' => [
                                'name' => 'execute_operation',
                                'arguments' => json_encode(['key' => 'value']),
                            ],
                        ],
                    ],
                    'tool_results' => null,
                    'iteration' => 1,
                    'run_id' => $runId,
                    'pending_confirmation' => [
                        'tool_name' => 'execute_operation',
                        'operationId' => 'test_operation',
                        'method' => 'POST',
                        'path' => '/test',
                        'arguments' => ['key' => 'value'],
                        'expires_at' => now()->addMinutes(5)->toIso8601String(),
                    ],
                ],
            ]);

            // Script the final answer for resumeSync().
            // resumeSync() executes the confirmed API call (or sets cancellation),
            // then calls the LLM in a loop. We script a final answer so it exits
            // after 1 iteration.
            // Use approved=false to skip the HTTP call (backend not running in tests).
            $this->script()
                ->finalAnswer('Operation completed after confirmation.');

            // Resume the confirmation — this should continue using the recovered run_id.
            // approved=false skips executeApiCall but still runs the LLM loop.
            $resumeResult = $agentLoopService->resumeSync(
                $fixture->conversation,
                $confirmMessage,
                false
            );

            // Assert that exactly one run exists for this conversation (continuity invariant).
            $runCount = DB::table('agent_runs')
                ->where('conversation_id', $fixture->conversation->id)
                ->count();
            $this->assertSame(
                1,
                $runCount,
                "Expected exactly one run record after resume. Found {$runCount}."
            );

            // Assert that a run exists with the recovered run_id.
            // resumeSync() opens a step on the recovered run_id, so a step record
            // should exist for that run.
            $stepCount = DB::table('agent_run_steps')
                ->where('run_id', $runId)
                ->count();
            $this->assertGreaterThanOrEqual(
                1,
                $stepCount,
                "Resume should have opened a step on the recovered run_id. Found {$stepCount} steps."
            );

            // Assert the step has contiguous position (position 1 for this fresh run).
            $steps = DB::table('agent_run_steps')
                ->where('run_id', $runId)
                ->orderBy('position')
                ->get();
            $positions = $steps->pluck('position')->toArray();
            $this->assertContains(
                1,
                $positions,
                "Step positions should start from 1"
            );
        }
    }

    /**
     * T055 [US6]: A pre-feature tool_data with no run_id mints a fresh run for
     * the resumed portion without crashing, and the record is honest about
     * covering only part of the work.
     *
     * Research §D6 — a confirmation message written before this feature has no
     * run_id in its tool_data. resume() should mint a fresh run.
     */
    public function test_pre_feature_tool_data_mints_fresh_run(): void
    {
        $this->scenario = 'pre_feature_tool_data_mints_fresh_run';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();

        // Enable tracing.
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        // Create a message with tool_data that has NO run_id (pre-feature shape).
        $confirmMessage = Message::create([
            'conversation_id' => $fixture->conversation->id,
            'content' => 'Please confirm this operation.',
            'role' => 'assistant',
            'user' => $fixture->conversation->character,
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_pre_feature_001',
                        'type' => 'function',
                        'function' => [
                            'name' => 'execute_operation',
                            'arguments' => json_encode(['key' => 'value']),
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                // NOTE: no 'run_id' key — simulating pre-feature tool_data.
                'pending_confirmation' => [
                    'tool_name' => 'execute_operation',
                    'operationId' => 'test_operation',
                    'method' => 'POST',
                    'path' => '/test',
                    'arguments' => ['key' => 'value'],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        // Script the final answer for resumeSync().
        // Use approved=false to skip the HTTP call (backend not running in tests).
        $this->script()
            ->finalAnswer('Operation completed.');

        // Resume should NOT crash — it mints a fresh run for the resumed portion.
        // approved=false skips executeApiCall but still runs the LLM loop.
        $agentLoopService = $this->app->make(AgentLoopService::class);
        $resumeResult = $agentLoopService->resumeSync(
            $fixture->conversation,
            $confirmMessage,
            false
        );

        // Assert that at least one run was created.
        $runCount = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->count();
        $this->assertGreaterThanOrEqual(
            1,
            $runCount,
            "At least one run should exist after resume with pre-feature tool_data"
        );

        // Assert the run record is honest — it covers only the resumed portion
        // (the run was minted at resume time, so it only has steps from after resume).
        $run = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->orderByDesc('started_at')
            ->first();

        // The run should have a started_at that is recent (within the last minute).
        $startedAt = \Carbon\Carbon::parse($run->started_at);
        $this->assertGreaterThan(
            now()->subMinute(),
            $startedAt,
            "Run started_at should be recent (minted at resume time)"
        );
    }

    /**
     * T056 [US6]: A run's total duration spans the first step's start to the last
     * step's end even across a long delay, and each step reports its own start time.
     *
     * US6 scenario 2, SC-012.
     *
     * The run's duration_ms should be >= the sum of its steps' duration_ms values
     * (contract C6), and the run's duration should span the full time from the
     * first step's started_at to the last step's ended_at.
     */
    public function test_run_duration_spans_full_time(): void
    {
        $this->scenario = 'run_duration_spans_full_time';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();

        // Enable tracing.
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        // Script a multi-step conversation.
        $this->script()
            ->toolRequest('search_operations', ['query' => 'step 1'])
            ->toolRequest('search_operations', ['query' => 'step 2'])
            ->finalAnswer('All steps complete.');

        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Multi-step request.'
        );

        // Assert the run completed.
        $this->assertSame('completed', $result['status']);

        // Get the run record.
        $run = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->first();
        $this->assertNotNull($run, 'Run should exist');

        // Get steps ordered by position.
        $steps = DB::table('agent_run_steps')
            ->where('run_id', $run->id)
            ->orderBy('position')
            ->get();
        $this->assertGreaterThan(0, $steps->count(), 'Run should have steps');

        // ---- Assert each step has its own start time ----
        foreach ($steps as $step) {
            $this->assertNotNull($step->started_at, "Step {$step->position} should have started_at");
            $this->assertNotNull($step->ended_at, "Step {$step->position} should have ended_at");
            $this->assertNotNull($step->duration_ms, "Step {$step->position} should have duration_ms");
        }

        // ---- Assert run duration >= sum of step durations (contract C6) ----
        $stepDurationSum = 0;
        foreach ($steps as $step) {
            $stepDurationSum += (int) ($step->duration_ms ?? 0);
        }
        $this->assertGreaterThanOrEqual(
            $stepDurationSum,
            (int) ($run->duration_ms ?? 0),
            "Run duration_ms ({$run->duration_ms}) should be >= sum of step durations ({$stepDurationSum})"
        );

        // ---- Assert run's duration spans first step start to last step end ----
        $firstStep = $steps->first();
        $lastStep = $steps->last();

        $firstStepStart = \Carbon\Carbon::parse($firstStep->started_at);
        $lastStepEnd = \Carbon\Carbon::parse($lastStep->ended_at);
        $runStarted = \Carbon\Carbon::parse($run->started_at);
        $runEnded = \Carbon\Carbon::parse($run->ended_at);

        // Run started_at should be <= first step's started_at.
        $this->assertLessThanOrEqual(
            $firstStepStart,
            $runStarted,
            "Run started_at should be <= first step's started_at"
        );

        // Run ended_at should be >= last step's ended_at.
        $this->assertGreaterThanOrEqual(
            $lastStepEnd,
            $runEnded,
            "Run ended_at should be >= last step's ended_at"
        );
    }

    /**
     * T065 [US5]: A dropped http-queue job leaves an open step that the sweep
     * then resolves to `abandoned`, which is the deliberate design of the
     * streaming path (research §D5, FR-017).
     *
     * The streaming path opens a step in dispatchStreamRequest() BEFORE the
     * job is dispatched. If the job is dropped (never processed), the step
     * stays open and the run stays in_progress. The sweep command resolves
     * both to `abandoned`.
     */
    public function test_dropped_job_resolves_to_abandoned(): void
    {
        $this->scenario = 'dropped_job_resolves_to_abandoned';
        $this->entryPath = 'stream';

        $fixture = $this->fixture()->build();

        // Enable tracing.
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        // Script a response — it won't be consumed since the job is dropped,
        // but the script must have at least one step to avoid the teardown
        // "unconsumed script steps" failure. We'll consume it manually.
        $this->script()
            ->finalAnswer('This response will not be delivered.');

        // Start the streaming agent loop. This calls dispatchStreamRequest()
        // which opens a run and a step, then dispatches the job.
        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        // Extract the dispatched job — we'll NOT process it (simulating a drop).
        $this->stream()->extractDispatchedJobs();

        // ---- Assert the run and step are in_progress ----
        $run = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->first();
        $this->assertNotNull($run, 'Run should exist');
        $this->assertSame(
            'in_progress',
            $run->end_state,
            "Run should be in_progress before the job is processed"
        );

        $step = DB::table('agent_run_steps')
            ->where('run_id', $run->id)
            ->first();
        $this->assertNotNull($step, 'Step should exist (opened in dispatchStreamRequest)');
        $this->assertSame(
            'in_progress',
            $step->end_state,
            "Step should be in_progress before the job is processed"
        );

        // ---- Age the step's started_at to be older than the threshold ----
        // The sweep uses MAX(COALESCE(steps.ended_at, steps.started_at)) as
        // the latest activity. We age started_at to simulate time passing.
        $agedStartedAt = now()->subMinutes(5);
        DB::table('agent_run_steps')
            ->where('id', $step->id)
            ->update(['started_at' => $agedStartedAt]);

        // Also age the run's started_at so the eligibility query finds it
        // (the query filters on end_state = 'in_progress' AND started_at < cutoff).
        DB::table('agent_runs')
            ->where('id', $run->id)
            ->update(['started_at' => $agedStartedAt]);

        // ---- Run the sweep command with a 2-minute threshold ----
        $exitCode = \Illuminate\Support\Facades\Artisan::call(
            'llm-client:resolve-abandoned-runs',
            ['--minutes' => 2]
        );

        // Consume the scripted step so teardown doesn't fail.
        $this->script()->serve();

        // Assert the command succeeded.
        $this->assertSame(
            0,
            $exitCode,
            "Sweep command should succeed"
        );

        // ---- Assert the run resolved to abandoned ----
        $run = DB::table('agent_runs')
            ->where('id', $run->id)
            ->first();
        $this->assertSame(
            'abandoned',
            $run->end_state,
            "Run should resolve to abandoned after sweep"
        );
        $this->assertNotNull(
            $run->end_reason,
            'Run should have an end_reason after sweep'
        );
        $this->assertNotNull(
            $run->ended_at,
            'Run should have an ended_at after sweep'
        );
        $this->assertGreaterThan(
            0,
            (int) ($run->duration_ms ?? 0),
            'Run should have a positive duration_ms after sweep'
        );

        // ---- Assert the step resolved to abandoned ----
        $step = DB::table('agent_run_steps')
            ->where('id', $step->id)
            ->first();
        $this->assertSame(
            'abandoned',
            $step->end_state,
            "Step should resolve to abandoned after sweep"
        );
        $this->assertNotNull(
            $step->ended_at,
            'Step should have an ended_at after sweep'
        );
    }

    /**
     * Build SSE chunks for a tool-call response.
     */
    protected function buildToolCallSseChunks(array $response): array
    {
        $toolCalls = $response['choices'][0]['message']['tool_calls'] ?? [];
        $chunks = [];

        foreach ($toolCalls as $tc) {
            // Tool call ID chunk
            $data = json_encode([
                'choices' => [
                    [
                        'delta' => [
                            'tool_calls' => [
                                [
                                    'index' => 0,
                                    'id' => $tc['id'],
                                    'type' => $tc['type'],
                                ],
                            ],
                        ],
                        'finish_reason' => null,
                    ],
                ],
            ]);
            $chunks[] = "data: {$data}\n\n";

            // Function name chunk
            $data = json_encode([
                'choices' => [
                    [
                        'delta' => [
                            'tool_calls' => [
                                [
                                    'index' => 0,
                                    'function' => [
                                        'name' => $tc['function']['name'],
                                    ],
                                ],
                            ],
                        ],
                        'finish_reason' => null,
                    ],
                ],
            ]);
            $chunks[] = "data: {$data}\n\n";

            // Function arguments chunk
            $data = json_encode([
                'choices' => [
                    [
                        'delta' => [
                            'tool_calls' => [
                                [
                                    'index' => 0,
                                    'function' => [
                                        'arguments' => $tc['function']['arguments'],
                                    ],
                                ],
                            ],
                        ],
                        'finish_reason' => null,
                    ],
                ],
            ]);
            $chunks[] = "data: {$data}\n\n";
        }

        // Final chunk with finish_reason
        $finishReason = $response['choices'][0]['finish_reason'] ?? 'tool_calls';
        $finalData = json_encode([
            'choices' => [
                [
                    'delta' => [],
                    'finish_reason' => $finishReason,
                ],
            ],
        ]);
        $chunks[] = "data: {$finalData}\n\n";

        return $chunks;
    }

    /**
     * Build SSE chunks from a scripted response (text content).
     */
    protected function buildSseChunks(array $response): array
    {
        $content = $response['choices'][0]['message']['content'] ?? '';
        $finishReason = $response['choices'][0]['finish_reason'] ?? 'stop';

        $chunks = [];
        $chunkSize = 10;
        for ($i = 0; $i < strlen($content); $i += $chunkSize) {
            $piece = substr($content, $i, $chunkSize);
            $data = json_encode([
                'choices' => [
                    [
                        'delta' => ['content' => $piece],
                        'finish_reason' => null,
                    ],
                ],
            ]);
            $chunks[] = "data: {$data}\n\n";
        }

        $finalData = json_encode([
            'choices' => [
                [
                    'delta' => [],
                    'finish_reason' => $finishReason,
                ],
            ],
        ]);
        $chunks[] = "data: {$finalData}\n\n";

        return $chunks;
    }

    /**
     * Clean trace tables (truncate) for a fresh test run.
     */
    protected function cleanTraceTables(): void
    {
        foreach (['agent_run_messages', 'agent_run_steps', 'agent_runs'] as $table) {
            if (DB::connection()->getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    /**
     * Rebind a fresh ResponseScript instance (ResponseScript has no reset()).
     */
    protected function rebindScript(): void
    {
        $this->script = new ResponseScript();
        $this->transport = new \Tests\Integration\Harness\ScriptedTransport(
            $this->script,
            new \Tests\Integration\Harness\DeterministicEmbedder(
                (int) config('llm-client.memory.embedding.dimension', 1536)
            )
        );
        // Rebind the handler in the container.
        $this->app->bind('llm-client.http_handler', fn () => $this->transport->handlerStack());
    }
}
