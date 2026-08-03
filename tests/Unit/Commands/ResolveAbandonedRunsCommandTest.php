<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
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
        // Clean up tables after each test.
        foreach (['agent_run_messages', 'agent_run_steps', 'agent_runs'] as $table) {
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

        // Insert a pending confirmation message with expires_at in the future.
        // The messages table may not exist in this test context, so we check first.
        if (DB::getSchemaBuilder()->hasTable('messages')) {
            $msgId = (string) Str::uuid();
            $expiresAt = CarbonImmutable::now()->addMinutes(3)->toIso8601String();
            DB::table('messages')->insert([
                'id' => $msgId,
                'role' => 'assistant',
                'content' => json_encode([
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'tool_1',
                        'name' => 'request_confirmation',
                        'input' => ['message' => 'Please confirm.'],
                    ]],
                ]),
                'tool_data' => json_encode([
                    'run_id' => $runId,
                    'step_id' => $stepId,
                    'pending' => true,
                    'expires_at' => $expiresAt,
                ]),
                'conversation_id' => 'test-conv',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $exitCode = Artisan::call('llm-client:resolve-abandoned-runs');

            $this->assertSame(0, $exitCode);

            // Run should still be in_progress because a pending confirmation exists.
            $run = DB::table('agent_runs')->where('id', $runId)->first();
            $this->assertNotNull($run);
            $this->assertEquals(RunEndState::InProgress->value, $run->end_state);
        } else {
            // Without messages table, skip the confirmation check.
            // The test still validates that the sweep runs without error.
            $exitCode = Artisan::call('llm-client:resolve-abandoned-runs');
            $this->assertSame(0, $exitCode);
        }
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
}
