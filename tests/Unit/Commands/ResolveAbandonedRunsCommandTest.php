<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\TraceExportConfig;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for ResolveAbandonedRunsCommand.
 *
 * Tests abandonment threshold filtering, step resolution,
 * confirmation-pending exemption, dry-run mode, and edge cases.
 */
class ResolveAbandonedRunsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Enable run tracing for tests.
        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.run_trace.abandonment_minutes', 60);
        $this->app['config']->set('llm-client.agent_loop.confirmation_timeout', 300);
    }

    protected function tearDown(): void
    {
        TraceExportConfig::reset();

        // Clean up tables after each test.
        foreach (['agent_run_export_queue', 'agent_run_messages', 'agent_run_steps', 'agent_runs'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    // Helper: insert a run row directly (bypasses recorder enabled checks).
    private function insertRun(
        string $id,
        string $userId,
        string $endState,
        ?string $startedAt,
        ?string $endedAt = null,
        ?int $durationMs = null,
        int $stepCount = 0,
        ?string $conversationId = null,
        string $kind = 'interactive',
        ?string $source = null,
        ?string $endReason = null,
    ): void {
        DB::table('agent_runs')->insert([
            'id' => $id,
            'kind' => $kind,
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'source' => $source,
            'end_state' => $endState,
            'end_reason' => $endReason,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_ms' => $durationMs,
            'step_count' => $stepCount,
            'created_at' => $startedAt,
        ]);
    }

    // Helper: insert a step row directly.
    private function insertStep(
        string $id,
        string $runId,
        int $position,
        string $endState,
        ?string $startedAt,
        ?string $endedAt = null,
        ?int $durationMs = null,
        int $attemptCount = 1,
        ?string $attemptGroupId = null,
        ?string $endReason = null,
        ?int $waitMs = null,
    ): void {
        DB::table('agent_run_steps')->insert([
            'id' => $id,
            'run_id' => $runId,
            'position' => $position,
            'attempt_group_id' => $attemptGroupId,
            'end_state' => $endState,
            'end_reason' => $endReason,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_ms' => $durationMs,
            'wait_ms' => $waitMs,
            'attempt_count' => $attemptCount,
        ]);
    }

    // ========== T063: Core abandonment logic ==========

    #[Test]
    public function stale_run_and_open_step_both_become_abandoned()
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));
        $this->insertStep($stepId, $runId, 1, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs');

        $this->assertSame(0, $exitCode);

        // Run should be abandoned with full terminal fields.
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals(RunEndState::Abandoned->value, $run->end_state);
        $this->assertNotNull($run->end_reason);
        $this->assertNotNull($run->ended_at);
        $this->assertNotNull($run->duration_ms);
        $this->assertGreaterThan(0, $run->duration_ms);
        $this->assertGreaterThanOrEqual(1, $run->step_count);

        // Open step should also be abandoned.
        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertNotNull($step);
        $this->assertEquals(RunEndState::Abandoned->value, $step->end_state);
        $this->assertNotNull($step->end_reason);
        $this->assertNotNull($step->ended_at);
    }

    #[Test]
    public function completed_steps_before_abandonment_keep_their_end_state()
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $completedStepId = (string) Str::uuid();
        $openStepId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);
        $stepCompletedTime = CarbonImmutable::now()->subMinutes(110);

        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        // A step that completed before abandonment.
        $this->insertStep($completedStepId, $runId, 1, RunEndState::Completed->value,
            $staleTime->format('Y-m-d H:i:s.u'),
            $stepCompletedTime->format('Y-m-d H:i:s.u'),
            600000);

        // A step still in_progress.
        $this->insertStep($openStepId, $runId, 2, RunEndState::InProgress->value,
            $stepCompletedTime->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs');

        $this->assertSame(0, $exitCode);

        // Completed step stays completed.
        $completedStep = DB::table('agent_run_steps')->where('id', $completedStepId)->first();
        $this->assertNotNull($completedStep);
        $this->assertEquals(RunEndState::Completed->value, $completedStep->end_state);

        // Open step becomes abandoned.
        $openStep = DB::table('agent_run_steps')->where('id', $openStepId)->first();
        $this->assertNotNull($openStep);
        $this->assertEquals(RunEndState::Abandoned->value, $openStep->end_state);
    }

    // ========== T064: Threshold, confirmation exemption, dry-run, --minutes ==========

    #[Test]
    public function run_inside_threshold_is_untouched()
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $recentTime = CarbonImmutable::now()->subMinutes(30);

        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $recentTime->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs');

        $this->assertSame(0, $exitCode);

        // Run should still be in_progress.
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals(RunEndState::InProgress->value, $run->end_state);
        $this->assertNull($run->ended_at);
    }

    #[Test]
    public function step_waiting_on_confirmation_inside_timeout_is_untouched()
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));
        $this->insertStep($stepId, $runId, 1, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        // The pause payload is written verbatim in the shape the agent loop
        // produces (contracts §3.2) — `pending_confirmation` nested, carrying
        // `expires_at`, with `run_id` beside it. Inventing a flatter shape here
        // would let the sweep's reader and this test agree with each other while
        // both disagreeing with production.
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('messages'));

        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'role' => 'assistant',
            'content' => '',
            'tool_data' => json_encode([
                'tool_calls' => [['id' => 'call_1']],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'tool_name' => 'contacts.destroy',
                    'operationId' => 'destroyContact',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/{id}',
                    'arguments' => [],
                    // Inside the 300s confirmation timeout.
                    'expires_at' => CarbonImmutable::now()->addSeconds(280)->toIso8601String(),
                ],
                'run_id' => $runId,
                'step_id' => $stepId,
                'paused_at' => CarbonImmutable::now()->subSeconds(20)->toIso8601String(),
            ]),
            'conversation_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // --minutes=1 puts the run well past the abandonment threshold, so the
        // exemption is the only thing that can save it. Under the 60-minute
        // default the run would survive regardless and this would prove nothing.
        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs', ['--minutes' => 1]);

        $this->assertSame(0, $exitCode);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals(
            RunEndState::InProgress->value,
            $run->end_state,
            'A run waiting on a human inside the confirmation timeout must not be swept (SC-008)',
        );

        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertEquals(RunEndState::InProgress->value, $step->end_state);
    }

    #[Test]
    public function run_whose_confirmation_has_expired_is_swept()
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));
        $this->insertStep($stepId, $runId, 1, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        // Same shape, but the human never answered and the window has closed.
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'role' => 'assistant',
            'content' => '',
            'tool_data' => json_encode([
                'tool_calls' => [['id' => 'call_1']],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'tool_name' => 'contacts.destroy',
                    'expires_at' => $staleTime->addSeconds(300)->toIso8601String(),
                ],
                'run_id' => $runId,
                'step_id' => $stepId,
                'paused_at' => $staleTime->toIso8601String(),
            ]),
            'conversation_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs');

        $this->assertSame(0, $exitCode);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertEquals(
            RunEndState::Abandoned->value,
            $run->end_state,
            'An expired confirmation is not a reason to stay open forever (FR-017)',
        );
    }

    /**
     * data-model.md §4: an open step ends at its last observed activity, not at
     * the sweep's now(), so its duration does not absorb detection lag.
     */
    #[Test]
    public function swept_step_duration_excludes_the_sweeps_detection_lag()
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        // A step that opened and never closed: as far as anything observed, it
        // stopped where it started.
        $this->insertStep($stepId, $runId, 1, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        Artisan::call('llm-client:resolve-abandoned-runs');

        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertEquals(RunEndState::Abandoned->value, $step->end_state);
        $this->assertEquals(
            0,
            (int) $step->duration_ms,
            'The two hours before the sweep noticed are not step working time',
        );

        // The run, by contrast, legitimately spans the whole abandoned window —
        // how long it was outstanding before anyone noticed.
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertGreaterThan(7_000_000, (int) $run->duration_ms);

        // duration_ms is a whole number of milliseconds, not a float: the column
        // is an unsignedBigInteger and MySQL would truncate silently.
        $this->assertSame(
            0.0,
            fmod((float) $run->duration_ms, 1.0),
            'duration_ms must be written as an integer',
        );
    }

    #[Test]
    public function dry_run_reports_what_it_would_resolve()
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));
        $this->insertStep((string) Str::uuid(), $runId, 1, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        Artisan::call('llm-client:resolve-abandoned-runs', ['--dry-run' => true]);
        $output = Artisan::output();

        // A dry run reporting zero is indistinguishable from one that found nothing.
        $this->assertStringContainsString('Runs would be resolved: 1', $output);
        $this->assertStringContainsString('Open steps that would be closed: 1', $output);
    }

    #[Test]
    public function run_with_zero_steps_ages_out_on_started_at()
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        // Run with no steps — ages out based on its own started_at.
        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs');

        $this->assertSame(0, $exitCode);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals(RunEndState::Abandoned->value, $run->end_state);
        $this->assertNotNull($run->end_reason);
        $this->assertNotNull($run->ended_at);
        $this->assertNotNull($run->duration_ms);
        $this->assertEquals(0, $run->step_count);
    }

    #[Test]
    public function dry_run_changes_nothing()
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));
        $this->insertStep($stepId, $runId, 1, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);

        // Nothing should change.
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals(RunEndState::InProgress->value, $run->end_state);
        $this->assertNull($run->ended_at);

        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertNotNull($step);
        $this->assertEquals(RunEndState::InProgress->value, $step->end_state);
    }

    #[Test]
    public function minutes_option_overrides_config_default()
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        // 90 minutes ago — outside 60 min default but inside 120 min.
        $staleTime = CarbonImmutable::now()->subMinutes(90);

        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        // With --minutes=60, the run should be resolved.
        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs', ['--minutes' => 60]);

        $this->assertSame(0, $exitCode);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals(RunEndState::Abandoned->value, $run->end_state);
    }

    // ========== T064a: Edge cases ==========

    #[Test]
    public function run_interrupted_between_steps_resolves_with_no_open_step()
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);
        $stepEndedTime = CarbonImmutable::now()->subMinutes(110);

        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        // A step that completed — the run was interrupted after this step closed
        // but before the run itself was closed.
        $this->insertStep($stepId, $runId, 1, RunEndState::Completed->value,
            $staleTime->format('Y-m-d H:i:s.u'),
            $stepEndedTime->format('Y-m-d H:i:s.u'),
            600000);

        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs');

        $this->assertSame(0, $exitCode);

        // Run becomes abandoned.
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals(RunEndState::Abandoned->value, $run->end_state);
        $this->assertNotNull($run->end_reason);
        $this->assertNotNull($run->ended_at);
        $this->assertNotNull($run->duration_ms);
        $this->assertGreaterThanOrEqual(1, $run->step_count);

        // No step left open — the completed step stays completed.
        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertNotNull($step);
        $this->assertEquals(RunEndState::Completed->value, $step->end_state);

        // Verify no in_progress steps remain for this run.
        $openSteps = DB::table('agent_run_steps')
            ->where('run_id', $runId)
            ->where('end_state', RunEndState::InProgress->value)
            ->count();
        $this->assertEquals(0, $openSteps);
    }

    #[Test]
    public function run_with_failed_completion_write_resolves_via_sweep()
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        // Simulate a run where the closeRun update failed — the run is still
        // in_progress with all steps completed, but the run never got its
        // terminal state written.
        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        $stepEndedTime = CarbonImmutable::now()->subMinutes(110);
        $this->insertStep($stepId, $runId, 1, RunEndState::Completed->value,
            $staleTime->format('Y-m-d H:i:s.u'),
            $stepEndedTime->format('Y-m-d H:i:s.u'),
            600000);

        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs');

        $this->assertSame(0, $exitCode);

        // The sweep resolves the run to abandoned (not completed — it doesn't
        // know the close was intentional, just that it never arrived).
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals(RunEndState::Abandoned->value, $run->end_state);
        $this->assertNotNull($run->end_reason);
        $this->assertNotNull($run->ended_at);
        $this->assertNotNull($run->duration_ms);
    }

    // ========================================================================
    // Reconciliation fix (Issue 2, spec.md US2 Acceptance Scenario 2): the
    // sweep closed abandoned runs via raw DB::table('agent_runs')->update(...)
    // calls, entirely bypassing RunTraceRecorder::closeRun() -- so
    // enqueueForwarding() never ran and an abandoned run's forwarding row
    // was never written to agent_run_export_queue, even with 'external'
    // selected. "a run fails or is abandoned... the forwarded record
    // reflects that outcome rather than being omitted" therefore did not
    // hold for the abandoned case.
    // ========================================================================

    #[Test]
    public function an_abandoned_run_is_enqueued_for_external_forwarding(): void
    {
        $this->app['config']->set('llm-client.run_trace.export.destinations', ['internal', 'external']);
        $this->app['config']->set('llm-client.run_trace.export.otlp_endpoint', 'https://tempo.example.com:4318/v1/traces');
        TraceExportConfig::reset();

        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));
        $this->insertStep($stepId, $runId, 1, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs');

        $this->assertSame(0, $exitCode);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertEquals(RunEndState::Abandoned->value, $run->end_state);

        $this->assertSame(
            1,
            DB::table('agent_run_export_queue')->where('run_id', $runId)->count(),
            'an abandoned run must be enqueued for forwarding exactly like any other terminal state, when external is selected (spec.md US2 Acceptance Scenario 2)',
        );
    }

    #[Test]
    public function an_abandoned_run_is_not_enqueued_when_external_is_not_selected(): void
    {
        // Default TraceExportConfig (internal only) -- no otlp_endpoint
        // configured. The sweep must not enqueue anything in this case,
        // mirroring closeRun()'s own enqueueForwarding() gate.
        TraceExportConfig::reset();

        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        $this->insertRun($runId, $userId, RunEndState::InProgress->value,
            $staleTime->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs');

        $this->assertSame(0, $exitCode);

        $this->assertSame(
            0,
            DB::table('agent_run_export_queue')->where('run_id', $runId)->count(),
            'internal-only (the default) must not enqueue anything for forwarding',
        );
    }
}
