<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Journey tests for User Story 1 (spec.md, 074-latency-metrics): for every
 * agent response -- streamed or whole, successful, failed, or
 * cancelled/abandoned -- the system records how long it took, and how long
 * until the first visible output for a streamed one, without ever failing
 * the response itself over it.
 *
 * Uses RunTraceRecorder (and the resolve-abandoned-runs sweep) directly to
 * simulate what the agent loop and streaming handler do at each call site,
 * matching the style already established for the run-trace feature this one
 * extends.
 */
class ResponseLatencyCaptureJourneyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
    }

    protected function tearDown(): void
    {
        foreach (['agent_run_export_queue', 'agent_run_messages', 'agent_run_actions', 'agent_run_steps', 'agent_runs'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    /**
     * Acceptance Scenario 1: a streamed response run to successful completion
     * shows is_streamed: true, a non-null first_output_ms strictly less than
     * duration_ms, and a full (reconciling) breakdown.
     */
    /** @test */
    public function streamed_response_records_first_output_and_full_breakdown(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $userId = (string) Str::uuid();
        $convId = (string) Str::uuid();

        $runId = $recorder->openRun(
            RunKind::Interactive,
            $userId,
            $convId,
            null,
            streamed: true,
            model: 'claude-sonnet-5',
            agentId: 'research-assistant',
        );
        $this->assertNotNull($runId);

        $stepId = $recorder->openStep($runId);
        $actionId = $recorder->openAction($stepId, ActionType::LlmRequest, 'claude-sonnet-5');

        usleep(30_000);
        // The moment the first content delta reaches the user.
        $recorder->recordFirstOutput($runId);

        usleep(20_000);
        $recorder->closeAction($actionId, ActionOutcome::Success);
        $recorder->closeStep($stepId, RunEndState::Completed);
        $recorder->closeRun($runId, RunEndState::Completed);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals(RunEndState::Completed->value, $run->end_state);
        $this->assertEquals(1, (int) $run->is_streamed);
        $this->assertEquals('claude-sonnet-5', $run->model);
        $this->assertEquals('research-assistant', $run->agent_id);

        $this->assertNotNull($run->first_output_ms);
        $this->assertLessThan(
            (int) $run->duration_ms,
            (int) $run->first_output_ms,
            'first_output_ms must be strictly less than the total duration',
        );

        $this->assertNotNull($run->model_wait_ms);
        $this->assertNotNull($run->tool_exec_ms);
        $this->assertNotNull($run->confirm_wait_ms);
        $this->assertNotNull($run->product_ms);
        $this->assertSame(
            (int) $run->duration_ms,
            (int) $run->model_wait_ms + (int) $run->tool_exec_ms + (int) $run->confirm_wait_ms + (int) $run->product_ms,
            'FR-007: the breakdown must reconcile exactly with the total',
        );
    }

    /**
     * Acceptance Scenario 2: a whole (non-streamed) response run to successful
     * completion shows is_streamed: false, first_output_ms: null (SC-005 --
     * never a fabricated figure distinct from the total), and a full breakdown.
     */
    /** @test */
    public function whole_response_records_no_first_output_and_full_breakdown(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $userId = (string) Str::uuid();
        $convId = (string) Str::uuid();

        $runId = $recorder->openRun(
            RunKind::Interactive,
            $userId,
            $convId,
            null,
            streamed: false,
            model: 'gpt-4',
            agentId: 'research-assistant',
        );
        $this->assertNotNull($runId);

        $stepId = $recorder->openStep($runId);
        $actionId = $recorder->openAction($stepId, ActionType::LlmRequest, 'gpt-4');
        usleep(20_000);
        $recorder->closeAction($actionId, ActionOutcome::Success);
        $recorder->closeStep($stepId, RunEndState::Completed);
        $recorder->closeRun($runId, RunEndState::Completed);

        // The whole (synchronous) path never calls recordFirstOutput().
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertEquals(RunEndState::Completed->value, $run->end_state);
        $this->assertEquals(0, (int) $run->is_streamed);
        $this->assertNull(
            $run->first_output_ms,
            'SC-005: a whole response must never report a fabricated first-output figure',
        );

        $this->assertNotNull($run->model_wait_ms);
        $this->assertNotNull($run->tool_exec_ms);
        $this->assertNotNull($run->confirm_wait_ms);
        $this->assertNotNull($run->product_ms);
        $this->assertSame(
            (int) $run->duration_ms,
            (int) $run->model_wait_ms + (int) $run->tool_exec_ms + (int) $run->confirm_wait_ms + (int) $run->product_ms,
        );
    }

    /**
     * Acceptance Scenario 3: a response whose provider call is forced to throw
     * produces a run with end_state: 'failed', a non-null duration_ms covering
     * the elapsed time up to the failure, and a full (non-null) breakdown --
     * not a partially-populated row.
     */
    /** @test */
    public function failed_response_produces_failed_run_with_full_breakdown(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $userId = (string) Str::uuid();
        $convId = (string) Str::uuid();

        $runId = $recorder->openRun(
            RunKind::Interactive,
            $userId,
            $convId,
            null,
            streamed: false,
            model: 'gpt-4',
            agentId: 'research-assistant',
        );
        $this->assertNotNull($runId);

        $stepId = $recorder->openStep($runId);
        $actionId = $recorder->openAction($stepId, ActionType::LlmRequest, 'gpt-4');
        usleep(15_000);
        $recorder->closeAction($actionId, ActionOutcome::Failure, 'connection timeout');
        $recorder->closeStep($stepId, RunEndState::Failed);
        $recorder->closeRun($runId, RunEndState::Failed, 'connection timeout');

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals(RunEndState::Failed->value, $run->end_state);
        $this->assertEquals('connection timeout', $run->end_reason);
        $this->assertNotNull($run->duration_ms);
        $this->assertGreaterThan(0, $run->duration_ms);

        $this->assertNotNull($run->model_wait_ms);
        $this->assertNotNull($run->tool_exec_ms);
        $this->assertNotNull($run->confirm_wait_ms);
        $this->assertNotNull(
            $run->product_ms,
            'a failed response must still get a full breakdown, not a partially-populated row',
        );
    }

    /**
     * Acceptance Scenario 4: a response abandoned mid-flight and swept via
     * `php artisan llm-client:resolve-abandoned-runs` produces a run with
     * end_state: 'abandoned' and the same full breakdown shape.
     */
    /** @test */
    public function abandoned_response_is_swept_with_full_breakdown(): void
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => RunKind::Interactive->value,
            'user_id' => $userId,
            'conversation_id' => null,
            'source' => null,
            'end_state' => RunEndState::InProgress->value,
            'end_reason' => null,
            'started_at' => $staleTime->format('Y-m-d H:i:s.u'),
            'ended_at' => null,
            'duration_ms' => null,
            'step_count' => 0,
            'created_at' => $staleTime->format('Y-m-d H:i:s.u'),
            'is_streamed' => true,
            'model' => 'claude-sonnet-5',
            'agent_id' => 'research-assistant',
        ]);

        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => $runId,
            'position' => 1,
            'attempt_group_id' => null,
            'end_state' => RunEndState::InProgress->value,
            'end_reason' => null,
            'started_at' => $staleTime->format('Y-m-d H:i:s.u'),
            'ended_at' => null,
            'duration_ms' => null,
            'wait_ms' => null,
            'attempt_count' => 1,
        ]);

        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs');
        $this->assertSame(0, $exitCode);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals(RunEndState::Abandoned->value, $run->end_state);

        $this->assertNotNull($run->model_wait_ms);
        $this->assertNotNull($run->tool_exec_ms);
        $this->assertNotNull($run->confirm_wait_ms);
        $this->assertNotNull($run->product_ms);
    }

    /**
     * Edge case (spec.md): a streamed response forced to fail before its first
     * content delta shows first_output_ms: null -- never reached, never
     * fabricated.
     */
    /** @test */
    public function streamed_response_failing_before_first_output_reports_null_first_output(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $userId = (string) Str::uuid();
        $convId = (string) Str::uuid();

        $runId = $recorder->openRun(
            RunKind::Interactive,
            $userId,
            $convId,
            null,
            streamed: true,
            model: 'claude-sonnet-5',
            agentId: 'research-assistant',
        );
        $this->assertNotNull($runId);

        $stepId = $recorder->openStep($runId);
        $actionId = $recorder->openAction($stepId, ActionType::LlmRequest, 'claude-sonnet-5');

        // The stream fails before any content delta arrives -- recordFirstOutput()
        // is never called.
        $recorder->closeAction($actionId, ActionOutcome::Failure, 'stream reset');
        $recorder->closeStep($stepId, RunEndState::Failed);
        $recorder->closeRun($runId, RunEndState::Failed, 'stream reset');

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals(1, (int) $run->is_streamed);
        $this->assertEquals(RunEndState::Failed->value, $run->end_state);
        $this->assertNull(
            $run->first_output_ms,
            'a streamed response that never reaches output must never fabricate a figure',
        );
    }
}
