<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\RunRelation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Journey tests for agent run tracing.
 *
 * These tests verify the complete lifecycle of a traced run from open to close,
 * including step tracking, attempt counts, and message links.
 */
class RunTraceJourneyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
    }

    protected function tearDown(): void
    {
        DB::table('agent_run_messages')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();

        parent::tearDown();
    }

    /** @test */
    public function complete_interactive_run_journey(): void
    {
        // Simulates an interactive agent run: user sends a message, the agent
        // processes it in a single step, and returns a reply.
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);

        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();
        $userMsgId = (string) \Illuminate\Support\Str::uuid();
        $assistantMsgId = (string) \Illuminate\Support\Str::uuid();

        // Open the run.
        $runId = $recorder->openRun(RunKind::Interactive, $userId, $convId);
        $this->assertNotNull($runId);

        // Link the trigger message.
        $recorder->linkMessage($runId, $userMsgId, RunRelation::Trigger);

        // Open and complete a step.
        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($stepId);

        // Record an attempt (increments attempt_count from 1 to 2).
        $recorder->recordStepAttempt($stepId);

        usleep(50_000);
        $recorder->closeStep($stepId, RunEndState::Completed);

        // Close the run with the reply message.
        $recorder->closeRun($runId, RunEndState::Completed, null, $assistantMsgId);

        // Verify via query service.
        $run = $query->findRun($userId, $runId);
        $this->assertNotNull($run);
        // AgentRun model uses enum casts — end_state is a RunEndState instance.
        $this->assertEquals(RunEndState::Completed, $run->end_state);
        $this->assertNotNull($run->ended_at);
        $this->assertGreaterThanOrEqual(0, $run->duration_ms);

        // Verify steps.
        $steps = $query->stepsForRun($userId, $runId);
        $this->assertIsArray($steps);
        $this->assertCount(1, $steps);
        $this->assertEquals(1, $steps[0]->position);
        $this->assertEquals(2, $steps[0]->attempt_count);
        $this->assertEquals(RunEndState::Completed, $steps[0]->end_state);

        // Verify trigger link.
        $link = DB::table('agent_run_messages')
            ->where('run_id', $runId)
            ->where('message_id', $userMsgId)
            ->first();
        $this->assertNotNull($link);
        $this->assertEquals(RunRelation::Trigger->value, $link->relation);

        // Verify reply message link.
        $replyLink = DB::table('agent_run_messages')
            ->where('run_id', $runId)
            ->where('message_id', $assistantMsgId)
            ->first();
        $this->assertNotNull($replyLink);
        $this->assertEquals(RunRelation::Reply->value, $replyLink->relation);
    }

    /** @test */
    public function multi_step_run_with_retries_journey(): void
    {
        // Simulates a run with multiple steps where the first step has retries.
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);

        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $runId = $recorder->openRun(RunKind::Interactive, $userId, $convId);

        // Step 1: Opens, retries once (attempt_count goes to 2), then completes.
        $step1Id = $recorder->openStep($runId);
        $recorder->recordStepAttempt($step1Id); // 1 -> 2
        usleep(10_000);
        $recorder->recordStepAttempt($step1Id); // 2 -> 3
        $recorder->closeStep($step1Id, RunEndState::Completed);

        // Step 2: Single attempt (attempt_count stays 1), completes.
        $step2Id = $recorder->openStep($runId);
        $recorder->closeStep($step2Id, RunEndState::Completed);

        $recorder->closeRun($runId, RunEndState::Completed);

        // Verify steps.
        $steps = $query->stepsForRun($userId, $runId);
        $this->assertCount(2, $steps);

        // Step 1 has position 1 and attempt_count of 3.
        $this->assertEquals(1, $steps[0]->position);
        $this->assertEquals(3, $steps[0]->attempt_count);

        // Step 2 has position 2 and attempt_count of 1.
        $this->assertEquals(2, $steps[1]->position);
        $this->assertEquals(1, $steps[1]->attempt_count);
    }

    /** @test */
    public function stopped_early_run_journey(): void
    {
        // Simulates a run that hits max iterations.
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);

        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $runId = $recorder->openRun(RunKind::Interactive, $userId, $convId);
        $stepId = $recorder->openStep($runId);

        $recorder->closeStep($stepId, RunEndState::StoppedEarly);
        $recorder->closeRun($runId, RunEndState::StoppedEarly, 'Maximum iterations reached');

        $run = $query->findRun($userId, $runId);
        $this->assertEquals(RunEndState::StoppedEarly, $run->end_state);
        $this->assertEquals('Maximum iterations reached', $run->end_reason);

        $steps = $query->stepsForRun($userId, $runId);
        $this->assertEquals(RunEndState::StoppedEarly, $steps[0]->end_state);
    }

    /** @test */
    public function failed_run_journey(): void
    {
        // Simulates a run that fails with an exception.
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);

        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $runId = $recorder->openRun(RunKind::Interactive, $userId, $convId);
        $stepId = $recorder->openStep($runId);

        $recorder->closeStep($stepId, RunEndState::Failed);
        $recorder->closeRun($runId, RunEndState::Failed, 'Connection timeout');

        $run = $query->findRun($userId, $runId);
        $this->assertEquals(RunEndState::Failed, $run->end_state);
        $this->assertEquals('Connection timeout', $run->end_reason);
    }

    /** @test */
    public function system_initiated_run_journey(): void
    {
        // Simulates a system-initiated run (e.g., scheduled task).
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);

        $userId = (string) \Illuminate\Support\Str::uuid();

        $result = $recorder->traceSystemRun(
            'scheduled_cleanup',
            $userId,
            null,
            fn () => 'cleanup_done',
        );

        $this->assertEquals('cleanup_done', $result);
        $runs = DB::table('agent_runs')->where('user_id', $userId)->get();
        $this->assertCount(1, $runs);
        $runId = $runs[0]->id;

        $run = $query->findRun($userId, $runId);
        $this->assertNotNull($run);
        $this->assertEquals(RunKind::SystemInitiated, $run->kind);
        $this->assertEquals('scheduled_cleanup', $run->source);
        $this->assertEquals(RunEndState::Completed, $run->end_state);
    }

    /** @test */
    public function runs_for_conversation_journey(): void
    {
        // Multiple runs for the same conversation.
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);

        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        // Create three runs.
        $run1Id = $recorder->openRun(RunKind::Interactive, $userId, $convId);
        $recorder->closeRun($run1Id, RunEndState::Completed);

        usleep(10_000);
        $run2Id = $recorder->openRun(RunKind::Interactive, $userId, $convId);
        $recorder->closeRun($run2Id, RunEndState::Completed);

        usleep(10_000);
        $run3Id = $recorder->openRun(RunKind::Interactive, $userId, $convId);
        $recorder->closeRun($run3Id, RunEndState::Failed, 'Error');

        $runs = $query->runsForConversation($userId, $convId);

        $this->assertCount(3, $runs);
        // Ordered by started_at ascending.
        $this->assertEquals($run1Id, $runs[0]->id);
        $this->assertEquals($run2Id, $runs[1]->id);
        $this->assertEquals($run3Id, $runs[2]->id);

        // findRunForMessage should find the run for a trigger message.
        $userMsgId = (string) \Illuminate\Support\Str::uuid();
        $recorder->linkMessage($run1Id, $userMsgId, RunRelation::Trigger);

        $foundRun = $query->findRunForMessage($userId, $userMsgId);
        $this->assertNotNull($foundRun);
        $this->assertEquals($run1Id, $foundRun->id);
    }

    /** @test */
    public function disabled_tracing_skips_all_writes(): void
    {
        // When tracing is disabled, no writes occur.
        $this->app['config']->set('llm-client.run_trace.enabled', false);
        $recorder = $this->app->make(RunTraceRecorder::class);

        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $this->assertNull($runId);

        $this->assertEquals(0, DB::table('agent_runs')->count());
        $this->assertEquals(0, DB::table('agent_run_steps')->count());
        $this->assertEquals(0, DB::table('agent_run_messages')->count());
    }

    /** @test */
    public function duration_includes_step_time(): void
    {
        // Verify that run duration is >= sum of step durations.
        $recorder = $this->app->make(RunTraceRecorder::class);

        $userId = (string) \Illuminate\Support\Str::uuid();
        $runId = $recorder->openRun(RunKind::Interactive, $userId);

        $stepId = $recorder->openStep($runId);
        usleep(100_000); // 100ms
        $recorder->closeStep($stepId, RunEndState::Completed);

        usleep(50_000); // Extra 50ms
        $recorder->closeRun($runId, RunEndState::Completed);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $step = DB::table('agent_run_steps')->where('run_id', $runId)->first();

        // Run duration should be >= step duration.
        $this->assertGreaterThanOrEqual($step->duration_ms, $run->duration_ms);
    }

    // === Phase 4: US4 Streaming Tests ===

    /** @test */
    public function streaming_path_produces_identical_record_shape(): void
    {
        // T038: The streaming path produces a record of identical shape, step count,
        // and end-state vocabulary to the synchronous one. This simulates what the
        // streaming handler does: open run → open step → close step → close run.
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);

        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();
        $userMsgId = (string) \Illuminate\Support\Str::uuid();
        $assistantMsgId = (string) \Illuminate\Support\Str::uuid();

        // Simulate streaming path: open run, link trigger, open/close steps, close run.
        // The streaming handler mints one attemptGroupId per instance and the step
        // lifecycle mirrors the sync path — open at dispatch, close at finish.
        $runId = $recorder->openRun(RunKind::Interactive, $userId, $convId);
        $this->assertNotNull($runId);

        $recorder->linkMessage($runId, $userMsgId, RunRelation::Trigger);

        // First step: model call delivered incrementally.
        $step1Id = $recorder->openStep($runId, 1, (string) \Illuminate\Support\Str::uuid());
        $this->assertNotNull($step1Id);
        usleep(50_000);
        $recorder->closeStep($step1Id, RunEndState::Completed);

        // Second step: another round (tool call then model response).
        $step2Id = $recorder->openStep($runId, 2, (string) \Illuminate\Support\Str::uuid());
        $this->assertNotNull($step2Id);
        $recorder->closeStep($step2Id, RunEndState::Completed);

        $recorder->closeRun($runId, RunEndState::Completed, null, $assistantMsgId);

        // Verify identical shape to sync path.
        $run = $query->findRun($userId, $runId);
        $this->assertNotNull($run);
        $this->assertEquals(RunEndState::Completed, $run->end_state);
        $this->assertNotNull($run->ended_at);
        $this->assertGreaterThanOrEqual(0, $run->duration_ms);

        $steps = $query->stepsForRun($userId, $runId);
        $this->assertIsArray($steps);
        $this->assertCount(2, $steps);
        $this->assertEquals(1, $steps[0]->position);
        $this->assertEquals(2, $steps[1]->position);
        $this->assertEquals(RunEndState::Completed, $steps[0]->end_state);
        $this->assertEquals(RunEndState::Completed, $steps[1]->end_state);

        // Each step has a real start time and duration.
        $this->assertNotNull($steps[0]->started_at);
        $this->assertNotNull($steps[0]->ended_at);
        $this->assertGreaterThanOrEqual(0, $steps[0]->duration_ms);

        // Verify message links.
        $triggerLink = DB::table('agent_run_messages')
            ->where('run_id', $runId)
            ->where('message_id', $userMsgId)
            ->first();
        $this->assertNotNull($triggerLink);
        $this->assertEquals(RunRelation::Trigger->value, $triggerLink->relation);

        $replyLink = DB::table('agent_run_messages')
            ->where('run_id', $runId)
            ->where('message_id', $assistantMsgId)
            ->first();
        $this->assertNotNull($replyLink);
        $this->assertEquals(RunRelation::Reply->value, $replyLink->relation);
    }

    /** @test */
    public function streaming_path_failure_marks_run_and_step_failed(): void
    {
        // T038: A stream failure partway through marks both the run and the
        // in-flight step as `failed`.
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);

        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $runId = $recorder->openRun(RunKind::Interactive, $userId, $convId);
        $this->assertNotNull($runId);

        // First step completes.
        $step1Id = $recorder->openStep($runId, 1, (string) \Illuminate\Support\Str::uuid());
        $recorder->closeStep($step1Id, RunEndState::Completed);

        // Second step fails (stream error).
        $step2Id = $recorder->openStep($runId, 2, (string) \Illuminate\Support\Str::uuid());
        $recorder->closeStep($step2Id, RunEndState::Failed, 'Connection reset by peer');

        $recorder->closeRun($runId, RunEndState::Failed, 'Connection reset by peer');

        $run = $query->findRun($userId, $runId);
        $this->assertEquals(RunEndState::Failed, $run->end_state);
        $this->assertEquals('Connection reset by peer', $run->end_reason);

        $steps = $query->stepsForRun($userId, $runId);
        $this->assertCount(2, $steps);
        $this->assertEquals(RunEndState::Completed, $steps[0]->end_state);
        $this->assertEquals(RunEndState::Failed, $steps[1]->end_state);
        $this->assertEquals('Connection reset by peer', $steps[1]->end_reason);
    }

    /** @test */
    public function streaming_response_resolves_from_trigger_and_reply(): void
    {
        // T038a: A streamed response resolves to its run from both its trigger
        // user message and its assistant reply, exactly as the synchronous one does.
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);

        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();
        $userMsgId = (string) \Illuminate\Support\Str::uuid();
        $assistantMsgId = (string) \Illuminate\Support\Str::uuid();

        $runId = $recorder->openRun(RunKind::Interactive, $userId, $convId);
        $recorder->linkMessage($runId, $userMsgId, RunRelation::Trigger);

        $stepId = $recorder->openStep($runId);
        $recorder->closeStep($stepId, RunEndState::Completed);

        $recorder->closeRun($runId, RunEndState::Completed, null, $assistantMsgId);

        // Resolve from trigger message.
        $runFromTrigger = $query->findRunForMessage($userId, $userMsgId);
        $this->assertNotNull($runFromTrigger);
        $this->assertEquals($runId, $runFromTrigger->id);

        // Resolve from reply message.
        $runFromReply = $query->findRunForMessage($userId, $assistantMsgId);
        $this->assertNotNull($runFromReply);
        $this->assertEquals($runId, $runFromReply->id);

        // Both resolve to the same run.
        $this->assertEquals($runFromTrigger->id, $runFromReply->id);

        // Resolving by relation also works.
        $runTriggerOnly = $query->findRunForMessage($userId, $userMsgId, RunRelation::Trigger);
        $this->assertNotNull($runTriggerOnly);
        $this->assertEquals($runId, $runTriggerOnly->id);

        $runReplyOnly = $query->findRunForMessage($userId, $assistantMsgId, RunRelation::Reply);
        $this->assertNotNull($runReplyOnly);
        $this->assertEquals($runId, $runReplyOnly->id);
    }

    // === Phase 8: US7 Background Model Work Tests ===

    /** @test */
    public function background_jobs_produce_system_initiated_runs(): void
    {
        // T071/T077: Background jobs with actual LLM calls produce system_initiated runs.
        // Audit found two instrumented sources: episodic_memory and chunk_summary_prewarm.
        // HandleOpenAIGenerateConversationTitleResponse is a response handler (no LLM call).
        // ExtractFeedbackPreferencesJob is purely heuristic (no LLM call).
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);

        $userId = (string) \Illuminate\Support\Str::uuid();

        // Simulate episodic memory generation.
        $convId = (string) \Illuminate\Support\Str::uuid();
        $memoryResult = $recorder->traceSystemRun(
            'episodic_memory',
            $userId,
            $convId,
            fn () => ['summary' => 'Conversation summary', 'topics' => ['topic1']],
        );
        $this->assertEquals('Conversation summary', $memoryResult['summary']);

        // Simulate chunk summary pre-warm.
        $chunkResult = $recorder->traceSystemRun(
            'chunk_summary_prewarm',
            $userId,
            $convId,
            fn () => ['decisions' => ['keep' => ['item1']]],
        );
        $this->assertArrayHasKey('decisions', $chunkResult);

        // Verify two system-initiated runs exist, each with exactly one step.
        $runs = DB::table('agent_runs')
            ->where('user_id', $userId)
            ->where('kind', RunKind::SystemInitiated->value)
            ->get();
        $this->assertCount(2, $runs);

        foreach ($runs as $run) {
            $runId = $run->id;

            // Each run is system-initiated with a source.
            $this->assertEquals(RunKind::SystemInitiated->value, $run->kind);
            $this->assertNotNull($run->source);
            $this->assertContains($run->source, ['episodic_memory', 'chunk_summary_prewarm']);

            // Each run is completed with exactly one step.
            $this->assertEquals(RunEndState::Completed->value, $run->end_state);
            $this->assertEquals(1, $run->step_count);

            $steps = $query->stepsForRun($userId, $runId);
            $this->assertCount(1, $steps);
            $this->assertEquals(1, $steps[0]->position);
            $this->assertEquals(RunEndState::Completed, $steps[0]->end_state);
        }

        // Verify system-initiated runs are distinguishable from interactive runs.
        $interactiveRuns = DB::table('agent_runs')
            ->where('user_id', $userId)
            ->where('kind', RunKind::Interactive->value)
            ->count();
        $this->assertEquals(0, $interactiveRuns);
    }

    /** @test */
    public function background_run_with_no_conversation_records_null_conversation_id(): void
    {
        // T072: A background run with no conversation records conversation_id = null
        // and is attributed to the owning user (FR-007).
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);

        $userId = (string) \Illuminate\Support\Str::uuid();

        $recorder->traceSystemRun(
            'title_generation',
            $userId,
            null, // No conversation.
            fn () => 'Title',
        );

        $runs = DB::table('agent_runs')
            ->where('user_id', $userId)
            ->get();
        $this->assertCount(1, $runs);
        $run = $runs[0];

        // conversation_id is null for system-initiated runs without a conversation.
        $this->assertNull($run->conversation_id);
        $this->assertEquals($userId, $run->user_id);
        $this->assertEquals(RunKind::SystemInitiated->value, $run->kind);

        // The run is still queryable through runsForUser.
        $allRuns = $query->runsForUser($userId);
        $this->assertCount(1, $allRuns);
        $this->assertNull($allRuns[0]->conversation_id);
    }

    /** @test */
    public function failed_background_job_yields_failed_run_with_reason(): void
    {
        // T072: A failed background job yields run `failed` with a reason (US7 scenario 3).
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);

        $userId = (string) \Illuminate\Support\Str::uuid();

        $testException = new \RuntimeException('Model API timeout');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Model API timeout');

        try {
            $recorder->traceSystemRun(
                'episodic_memory',
                $userId,
                (string) \Illuminate\Support\Str::uuid(),
                fn () => throw $testException,
            );
        } finally {
            // Verify the run was recorded as failed with the exception message as reason.
            $runs = DB::table('agent_runs')
                ->where('user_id', $userId)
                ->get();
            $this->assertCount(1, $runs);
            $run = $runs[0];
            $this->assertEquals(RunEndState::Failed->value, $run->end_state);
            $this->assertEquals('Model API timeout', $run->end_reason);
            $this->assertNotNull($run->ended_at);
            $this->assertGreaterThanOrEqual(0, $run->duration_ms);

            // The step is also failed.
            $runId = $run->id;
            $steps = $query->stepsForRun($userId, $runId);
            $this->assertCount(1, $steps);
            $this->assertEquals(RunEndState::Failed, $steps[0]->end_state);
            $this->assertEquals('Model API timeout', $steps[0]->end_reason);
        }
    }
}
