<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\RunRelation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for PurgeExpiredRunTracesCommand.
 *
 * Tests retention cutoff, deletion ordering (steps and associations before runs),
 * and dry-run mode. FR-026.
 */
class PurgeExpiredRunTracesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Enable run tracing and set retention for tests.
        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.run_trace.retention_days', 90);
    }

    protected function tearDown(): void
    {
        // Clean up tables after each test (child tables first).
        foreach (['agent_run_messages', 'agent_run_steps', 'agent_runs'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    // Helper: insert a run row directly.
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

    // Helper: insert an association row directly.
    private function insertAssociation(
        string $runId,
        string $messageId,
        string $relation,
    ): void {
        DB::table('agent_run_messages')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'message_id' => $messageId,
            'relation' => $relation,
            'created_at' => now(),
        ]);
    }

    // ========== T080: Core purge logic ==========

    #[Test]
    public function expired_runs_and_their_children_are_deleted()
    {
        $userId = (string) Str::uuid();
        $expiredRunId = (string) Str::uuid();
        $expiredStepId = (string) Str::uuid();
        $expiredTime = CarbonImmutable::now()->subDays(100);

        // Expired run with step and associations.
        $this->insertRun($expiredRunId, $userId, RunEndState::Completed->value,
            $expiredTime->format('Y-m-d H:i:s.u'),
            $expiredTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000,
            1);
        $this->insertStep($expiredStepId, $expiredRunId, 1, RunEndState::Completed->value,
            $expiredTime->format('Y-m-d H:i:s.u'),
            $expiredTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000);
        $this->insertAssociation($expiredRunId, (string) Str::uuid(), RunRelation::Trigger->value);
        $this->insertAssociation($expiredRunId, (string) Str::uuid(), RunRelation::Reply->value);

        $exitCode = Artisan::call('llm-client:purge-run-traces');

        $this->assertSame(0, $exitCode);

        // All rows for the expired run should be gone.
        $this->assertEquals(0, DB::table('agent_runs')->where('id', $expiredRunId)->count());
        $this->assertEquals(0, DB::table('agent_run_steps')->where('run_id', $expiredRunId)->count());
        $this->assertEquals(0, DB::table('agent_run_messages')->where('run_id', $expiredRunId)->count());
    }

    #[Test]
    public function runs_inside_retention_are_kept()
    {
        $userId = (string) Str::uuid();
        $recentRunId = (string) Str::uuid();
        $recentStepId = (string) Str::uuid();
        $recentTime = CarbonImmutable::now()->subDays(10);

        // Recent run with step and associations.
        $this->insertRun($recentRunId, $userId, RunEndState::Completed->value,
            $recentTime->format('Y-m-d H:i:s.u'),
            $recentTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000,
            1);
        $this->insertStep($recentStepId, $recentRunId, 1, RunEndState::Completed->value,
            $recentTime->format('Y-m-d H:i:s.u'),
            $recentTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000);
        $this->insertAssociation($recentRunId, (string) Str::uuid(), RunRelation::Trigger->value);

        $exitCode = Artisan::call('llm-client:purge-run-traces');

        $this->assertSame(0, $exitCode);

        // All rows for the recent run should still exist.
        $this->assertEquals(1, DB::table('agent_runs')->where('id', $recentRunId)->count());
        $this->assertEquals(1, DB::table('agent_run_steps')->where('id', $recentStepId)->count());
        $this->assertEquals(1, DB::table('agent_run_messages')->where('run_id', $recentRunId)->count());
    }

    #[Test]
    public function mixed_expired_and_recent_runs_purge_only_expired()
    {
        $userId = (string) Str::uuid();

        // Expired run.
        $expiredRunId = (string) Str::uuid();
        $expiredTime = CarbonImmutable::now()->subDays(100);
        $this->insertRun($expiredRunId, $userId, RunEndState::Completed->value,
            $expiredTime->format('Y-m-d H:i:s.u'),
            $expiredTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000,
            1);
        $this->insertStep((string) Str::uuid(), $expiredRunId, 1, RunEndState::Completed->value,
            $expiredTime->format('Y-m-d H:i:s.u'),
            $expiredTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000);

        // Recent run.
        $recentRunId = (string) Str::uuid();
        $recentTime = CarbonImmutable::now()->subDays(10);
        $this->insertRun($recentRunId, $userId, RunEndState::Completed->value,
            $recentTime->format('Y-m-d H:i:s.u'),
            $recentTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000,
            1);
        $this->insertStep((string) Str::uuid(), $recentRunId, 1, RunEndState::Completed->value,
            $recentTime->format('Y-m-d H:i:s.u'),
            $recentTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000);

        $exitCode = Artisan::call('llm-client:purge-run-traces');

        $this->assertSame(0, $exitCode);

        // Expired run and its children are gone.
        $this->assertEquals(0, DB::table('agent_runs')->where('id', $expiredRunId)->count());
        $this->assertEquals(0, DB::table('agent_run_steps')->where('run_id', $expiredRunId)->count());
        $this->assertEquals(0, DB::table('agent_run_messages')->where('run_id', $expiredRunId)->count());

        // Recent run and its children are kept.
        $this->assertEquals(1, DB::table('agent_runs')->where('id', $recentRunId)->count());
        $this->assertEquals(1, DB::table('agent_run_steps')->where('run_id', $recentRunId)->count());
    }

    #[Test]
    public function dry_run_deletes_nothing()
    {
        $userId = (string) Str::uuid();
        $expiredRunId = (string) Str::uuid();
        $expiredStepId = (string) Str::uuid();
        $expiredTime = CarbonImmutable::now()->subDays(100);

        $this->insertRun($expiredRunId, $userId, RunEndState::Completed->value,
            $expiredTime->format('Y-m-d H:i:s.u'),
            $expiredTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000,
            1);
        $this->insertStep($expiredStepId, $expiredRunId, 1, RunEndState::Completed->value,
            $expiredTime->format('Y-m-d H:i:s.u'),
            $expiredTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000);
        $this->insertAssociation($expiredRunId, (string) Str::uuid(), RunRelation::Trigger->value);

        $exitCode = Artisan::call('llm-client:purge-run-traces', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);

        // Nothing should be deleted in dry-run mode.
        $this->assertEquals(1, DB::table('agent_runs')->where('id', $expiredRunId)->count());
        $this->assertEquals(1, DB::table('agent_run_steps')->where('id', $expiredStepId)->count());
        $this->assertEquals(1, DB::table('agent_run_messages')->where('run_id', $expiredRunId)->count());
    }

    #[Test]
    public function days_option_overrides_config_default()
    {
        $userId = (string) Str::uuid();

        // Run at 50 days — expired by 30-day override, but inside 90-day default.
        $runId = (string) Str::uuid();
        $runTime = CarbonImmutable::now()->subDays(50);
        $this->insertRun($runId, $userId, RunEndState::Completed->value,
            $runTime->format('Y-m-d H:i:s.u'),
            $runTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000,
            0);

        // With --days=30, the run should be purged.
        $exitCode = Artisan::call('llm-client:purge-run-traces', ['--days' => 30]);

        $this->assertSame(0, $exitCode);

        $this->assertEquals(0, DB::table('agent_runs')->where('id', $runId)->count());
    }

    #[Test]
    public function steps_and_associations_are_deleted_before_runs()
    {
        $userId = (string) Str::uuid();
        $expiredRunId = (string) Str::uuid();
        $expiredStepId = (string) Str::uuid();
        $expiredTime = CarbonImmutable::now()->subDays(100);

        $this->insertRun($expiredRunId, $userId, RunEndState::Completed->value,
            $expiredTime->format('Y-m-d H:i:s.u'),
            $expiredTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000,
            1);
        $this->insertStep($expiredStepId, $expiredRunId, 1, RunEndState::Completed->value,
            $expiredTime->format('Y-m-d H:i:s.u'),
            $expiredTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000);
        $this->insertAssociation($expiredRunId, (string) Str::uuid(), RunRelation::Trigger->value);

        $exitCode = Artisan::call('llm-client:purge-run-traces');

        $this->assertSame(0, $exitCode);

        // All rows should be gone (no foreign key violations).
        $this->assertEquals(0, DB::table('agent_runs')->where('id', $expiredRunId)->count());
        $this->assertEquals(0, DB::table('agent_run_steps')->where('run_id', $expiredRunId)->count());
        $this->assertEquals(0, DB::table('agent_run_messages')->where('run_id', $expiredRunId)->count());
    }

    #[Test]
    public function purge_with_no_expired_data_is_a_no_op()
    {
        $userId = (string) Str::uuid();
        $recentRunId = (string) Str::uuid();
        $recentTime = CarbonImmutable::now()->subDays(5);

        $this->insertRun($recentRunId, $userId, RunEndState::Completed->value,
            $recentTime->format('Y-m-d H:i:s.u'),
            $recentTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000,
            0);

        $exitCode = Artisan::call('llm-client:purge-run-traces');

        $this->assertSame(0, $exitCode);

        // Run should still exist.
        $this->assertEquals(1, DB::table('agent_runs')->where('id', $recentRunId)->count());

        // Output should mention no expired records.
        $output = Artisan::output();
        $this->assertStringContainsString('No expired', $output);
    }

    #[Test]
    public function systemInitiated_runs_are_also_purged()
    {
        $userId = (string) Str::uuid();
        $expiredRunId = (string) Str::uuid();
        $expiredTime = CarbonImmutable::now()->subDays(100);

        // System-initiated run (no conversation).
        $this->insertRun($expiredRunId, $userId, RunEndState::Completed->value,
            $expiredTime->format('Y-m-d H:i:s.u'),
            $expiredTime->addHour()->format('Y-m-d H:i:s.u'),
            3600000,
            1,
            null,
            RunKind::SystemInitiated->value,
            'title_generation');

        $exitCode = Artisan::call('llm-client:purge-run-traces');

        $this->assertSame(0, $exitCode);

        $this->assertEquals(0, DB::table('agent_runs')->where('id', $expiredRunId)->count());
    }
}
