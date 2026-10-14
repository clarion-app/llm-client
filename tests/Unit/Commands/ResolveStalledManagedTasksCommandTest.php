<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 6 (US4), tasks.md T047.
 *
 * Unit tests for the not-yet-built `llm-client:resolve-stalled-managed-tasks
 * {--dry-run}` (research.md D7, contracts/manager-agent-meta-tools.md §6),
 * mirroring `ResolveStalledDelegationBatchesCommandTest.php`'s exact
 * `Artisan::call('llm-client:...', ['--dry-run' => true])` idiom. Every
 * fixture row is a real `managed_tasks`/`managed_task_parts` row inserted
 * directly (both tables carry no DB-level FK, data-model.md §1/§2) -- no
 * ManagerService/AgentLoopService scaffolding needed except where a test
 * asserts finalizeWithShortfall()'s own effect on a part.
 *
 * Written before `ResolveStalledManagedTasksCommand` exists -- every test
 * below is expected to FAIL red (command not found) until T051 creates it.
 */
class ResolveStalledManagedTasksCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.manager.stale_after_minutes' => 10]);
    }

    protected function tearDown(): void
    {
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
        DB::table('agent_runs')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function makeTask(string $status, \DateTimeInterface $startedAt, \DateTimeInterface $lastProgressAt, int $maxSeconds = 1800, int $roundCeiling = 30, int $roundsUsed = 5): ManagedTask
    {
        return ManagedTask::create([
            'conversation_id' => (string) Str::uuid(),
            'owner_user_id' => (string) Str::uuid(),
            'manager_agent_id' => (string) Str::uuid(),
            'original_request' => 'A task possibly abandoned by its own worker.',
            'status' => $status,
            'round_ceiling' => $roundCeiling,
            'rounds_used' => $roundsUsed,
            'max_seconds' => $maxSeconds,
            'last_progress_at' => $lastProgressAt,
            'started_at' => $startedAt,
        ]);
    }

    private function makePart(ManagedTask $task, string $state): ManagedTaskPart
    {
        return ManagedTaskPart::create([
            'managed_task_id' => $task->id,
            'sequence' => 1,
            'description' => 'The only part.',
            'state' => $state,
            'assignment_count' => 1,
        ]);
    }

    private function fresh(ManagedTask $task): ManagedTask
    {
        return ManagedTask::find($task->id);
    }

    // -----------------------------------------------------------------
    // Stale, but not yet past max_seconds: re-dispatch (crash recovery).
    // -----------------------------------------------------------------

    #[Test]
    public function a_stale_row_not_yet_past_max_seconds_gets_a_fresh_step_job_re_dispatched(): void
    {
        $task = $this->makeTask(
            status: 'in_progress',
            startedAt: now()->subMinutes(20),
            lastProgressAt: now()->subMinutes(15), // stale: older than the 10-minute threshold
            maxSeconds: 1800, // 30 minutes -- 20 elapsed minutes has NOT reached this bound
        );

        Queue::fake();

        $exitCode = Artisan::call('llm-client:resolve-stalled-managed-tasks');

        $this->assertSame(0, $exitCode);
        Queue::assertPushed(RunManagedTaskStepJob::class, function (RunManagedTaskStepJob $job) use ($task) {
            return $job->managedTaskId === $task->id;
        });

        $row = $this->fresh($task);
        $this->assertSame('in_progress', $row->status, 're-dispatching a fresh step job must never itself change the task\'s status');
        $this->assertNull($row->completed_at);
    }

    // -----------------------------------------------------------------
    // Stale AND past max_seconds: force-finalize directly, no job.
    // -----------------------------------------------------------------

    #[Test]
    public function a_stale_row_past_max_seconds_is_finalized_with_shortfall_directly_and_no_job_is_dispatched(): void
    {
        $task = $this->makeTask(
            status: 'in_progress',
            startedAt: now()->subMinutes(40),
            lastProgressAt: now()->subMinutes(15), // stale
            maxSeconds: 1800, // 30 minutes -- 40 elapsed minutes HAS exceeded this bound
        );
        $part = $this->makePart($task, 'out_for_assignment');

        Queue::fake();

        $exitCode = Artisan::call('llm-client:resolve-stalled-managed-tasks');

        $this->assertSame(0, $exitCode);
        Queue::assertNotPushed(RunManagedTaskStepJob::class, null, 'a task past its own wall-clock bound must never be resumed, only force-finalized');

        $row = $this->fresh($task);
        $this->assertSame('completed_with_shortfalls', $row->status);
        $this->assertNotNull($row->final_response, 'FR-011: a final response must always be delivered');
        $this->assertNotNull($row->completed_at);

        $part->refresh();
        $this->assertSame('reported_as_shortfall', $part->state);
        $this->assertNotNull($part->shortfall_reason);
    }

    // -----------------------------------------------------------------
    // A fresh (not-yet-stale) row is left completely untouched.
    // -----------------------------------------------------------------

    #[Test]
    public function a_fresh_row_is_left_untouched(): void
    {
        $task = $this->makeTask(
            status: 'in_progress',
            startedAt: now()->subMinutes(5),
            lastProgressAt: now()->subMinutes(2), // well inside the 10-minute stale threshold
            maxSeconds: 1800,
        );

        Queue::fake();

        Artisan::call('llm-client:resolve-stalled-managed-tasks');

        Queue::assertNotPushed(RunManagedTaskStepJob::class);
        $row = $this->fresh($task);
        $this->assertSame('in_progress', $row->status);
        $this->assertNull($row->completed_at);
    }

    // -----------------------------------------------------------------
    // A task not in_progress (already terminal) is never in scope.
    // -----------------------------------------------------------------

    #[Test]
    public function an_already_terminal_row_is_never_swept_however_stale(): void
    {
        $task = $this->makeTask(
            status: 'completed',
            startedAt: now()->subHours(2),
            lastProgressAt: now()->subHours(2),
            maxSeconds: 1800,
        );

        Queue::fake();

        Artisan::call('llm-client:resolve-stalled-managed-tasks');

        Queue::assertNotPushed(RunManagedTaskStepJob::class);
        $row = $this->fresh($task);
        $this->assertSame('completed', $row->status, 'a terminal row must never be re-processed by this sweep');
    }

    // -----------------------------------------------------------------
    // 134-resumable-long-tasks (lease contract section 8a): a round whose
    // run is being resumed, or is waiting to retry the provider, belongs
    // to that recovery. A fresh round queued beside it would race it for
    // the same manager conversation.
    // -----------------------------------------------------------------

    private function makeRoundRun(ManagedTask $task, string $endState, ?int $checkpointVersion, ?string $resumeState): void
    {
        DB::table('agent_runs')->insert([
            'id' => (string) Str::uuid(),
            'kind' => 'system_initiated',
            'user_id' => $task->owner_user_id,
            'conversation_id' => $task->conversation_id,
            'end_state' => $endState,
            'started_at' => now()->subMinutes(20),
            'checkpoint_version' => $checkpointVersion,
            'resume_driver' => 'root',
            'resume_state' => $resumeState,
            'lease_expires_at' => now()->subMinutes(12),
            'next_attempt_at' => $resumeState === 'waiting_provider_retry' ? now()->addMinutes(3) : null,
        ]);
    }

    private function makeStaleTask(): ManagedTask
    {
        return $this->makeTask(
            status: 'in_progress',
            startedAt: now()->subMinutes(20),
            lastProgressAt: now()->subMinutes(15),
            maxSeconds: 1800,
        );
    }

    /** @return array<string, array{0: string}> */
    public static function ownedResumeStates(): array
    {
        return [
            'interrupted' => ['interrupted'],
            'resuming' => ['resuming'],
            'waiting for the provider' => ['waiting_provider_retry'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('ownedResumeStates')]
    public function skips_task_whose_round_is_being_resumed(string $resumeState): void
    {
        $task = $this->makeStaleTask();
        $this->makeRoundRun($task, 'in_progress', 1, $resumeState);

        Queue::fake();
        Artisan::call('llm-client:resolve-stalled-managed-tasks');

        Queue::assertNotPushed(RunManagedTaskStepJob::class, null, 'the resume owns this round: no second round beside it');
        $this->assertSame('in_progress', $this->fresh($task)->status);
        $this->assertNull($this->fresh($task)->completed_at);
    }

    #[Test]
    public function a_stale_task_whose_round_run_has_ended_is_re_dispatched_as_today(): void
    {
        $task = $this->makeStaleTask();
        $this->makeRoundRun($task, 'completed', 1, null);

        Queue::fake();
        Artisan::call('llm-client:resolve-stalled-managed-tasks');

        Queue::assertPushed(RunManagedTaskStepJob::class, fn (RunManagedTaskStepJob $job) => $job->managedTaskId === $task->id);
    }

    #[Test]
    public function a_stale_task_whose_round_run_predates_checkpoints_is_re_dispatched_as_today(): void
    {
        $task = $this->makeStaleTask();
        $this->makeRoundRun($task, 'in_progress', null, null);

        Queue::fake();
        Artisan::call('llm-client:resolve-stalled-managed-tasks');

        Queue::assertPushed(RunManagedTaskStepJob::class, fn (RunManagedTaskStepJob $job) => $job->managedTaskId === $task->id);
    }

    #[Test]
    public function only_the_newest_run_decides_whether_a_round_is_being_resumed(): void
    {
        $task = $this->makeStaleTask();
        // An older run in the same conversation is still marked resumable,
        // but a newer one has ended: the round is not being resumed.
        $this->makeRoundRun($task, 'in_progress', 1, 'interrupted');
        DB::table('agent_runs')->where('conversation_id', $task->conversation_id)->update(['started_at' => now()->subHours(2)]);
        $this->makeRoundRun($task, 'completed', 1, null);

        Queue::fake();
        Artisan::call('llm-client:resolve-stalled-managed-tasks');

        Queue::assertPushed(RunManagedTaskStepJob::class, fn (RunManagedTaskStepJob $job) => $job->managedTaskId === $task->id);
    }

    // -----------------------------------------------------------------
    // --dry-run reports without writing or dispatching, for either
    // eligible case.
    // -----------------------------------------------------------------

    #[Test]
    public function dry_run_reports_without_writing_or_dispatching_for_the_re_dispatch_case(): void
    {
        $task = $this->makeTask(
            status: 'in_progress',
            startedAt: now()->subMinutes(20),
            lastProgressAt: now()->subMinutes(15),
            maxSeconds: 1800,
        );

        Queue::fake();

        $exitCode = Artisan::call('llm-client:resolve-stalled-managed-tasks', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        Queue::assertNotPushed(RunManagedTaskStepJob::class, null, '--dry-run must never actually dispatch a job');

        $row = $this->fresh($task);
        $this->assertSame('in_progress', $row->status);

        $output = Artisan::output();
        $this->assertNotSame('', trim($output), '--dry-run must still report what it found/would do');
    }

    #[Test]
    public function dry_run_reports_without_writing_for_the_force_finalize_case(): void
    {
        $task = $this->makeTask(
            status: 'in_progress',
            startedAt: now()->subMinutes(40),
            lastProgressAt: now()->subMinutes(15),
            maxSeconds: 1800,
        );
        $part = $this->makePart($task, 'out_for_assignment');

        Queue::fake();

        $exitCode = Artisan::call('llm-client:resolve-stalled-managed-tasks', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        Queue::assertNotPushed(RunManagedTaskStepJob::class);

        $row = $this->fresh($task);
        $this->assertSame('in_progress', $row->status, '--dry-run must never finalize anything');
        $this->assertNull($row->completed_at);

        $part->refresh();
        $this->assertSame('out_for_assignment', $part->state, '--dry-run must never touch a part\'s state');

        $output = Artisan::output();
        $this->assertNotSame('', trim($output), '--dry-run must still report what it found/would do');
    }
}
