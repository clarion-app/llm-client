<?php

namespace Tests\Feature\Resume;

use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * llm-client:resume-interrupted-runs — the interruption detection sweep
 * (lease contract §2).
 *
 * Runs whose lease has expired (or whose delayed job was lost) are
 * detected by a compare-and-set, recorded, and re-driven on the run's
 * own queue. Paused, cancelled, slow-but-alive and exempt runs are
 * never touched; helper drivers are skipped (their parent drives them);
 * a second sweep never dispatches twice.
 */
class ResumeInterruptedRunsCommandTest extends TestCase
{
    private string $userId = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['llm-client.resume.enabled' => true]);
        Queue::fake();
    }

    /** The detection window the sweep reasons about. */
    private function window(): int
    {
        return (int) config('llm-client.resume.detection_window_seconds', 300);
    }

    /** Insert a resumable in-progress run row. */
    private function makeRun(string $id, array $overrides = []): void
    {
        DB::table('agent_runs')->insert(array_merge([
            'id' => $id,
            'kind' => 'interactive',
            'user_id' => $this->userId,
            'conversation_id' => 'conv-1',
            'started_at' => now()->subMinutes(10)->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
            'checkpoint_version' => 1,
            'resume_mode' => 'sync',
            'resume_driver' => 'root',
            'resume_state' => 'working',
            'dispatch_id' => '10000000-0000-0000-0000-000000000001',
            'lease_holder' => '99999999-9999-9999-9999-999999999999',
            'lease_expires_at' => now()->subSeconds(1)->format('Y-m-d H:i:s.u'),
            'consecutive_interruptions' => 0,
        ], $overrides));
    }

    /** @return object the run row */
    private function runRow(string $id): object
    {
        $row = DB::table('agent_runs')->where('id', $id)->first();
        $this->assertNotNull($row, "run {$id} must exist");

        return $row;
    }

    private function eventCount(string $runId, string $kind): int
    {
        return DB::table('agent_run_resume_events')->where('run_id', $runId)->where('kind', $kind)->count();
    }

    /** The one pushed job, asserted to carry the run and dispatch ids. */
    private function assertJobPushedWith(string $runId, ?string $dispatchId): void
    {
        Queue::assertPushed(ResumeInterruptedRunJob::class, function ($job) use ($runId, $dispatchId) {
            $ref = new \ReflectionObject($job);
            $props = [];
            foreach ($ref->getProperties() as $property) {
                $property->setAccessible(true);
                $props[] = $property->getValue($job);
            }

            return in_array($runId, $props, true)
                && ($dispatchId === null || in_array($dispatchId, $props, true));
        });
    }

    // ---------------------------------------------------------------
    // Eligibility
    // ---------------------------------------------------------------

    #[Test]
    public function eligible_after_lease_expiry(): void
    {
        $this->makeRun('run-1');

        $exitCode = Artisan::call('llm-client:resume-interrupted-runs');
        $this->assertSame(0, $exitCode);

        $row = $this->runRow('run-1');
        $this->assertSame('interrupted', $row->resume_state, 'the CAS lands the run in interrupted');
        $this->assertNotNull($row->interrupted_at, 'interrupted_at is set');
        $this->assertNotSame('10000000-0000-0000-0000-000000000001', $row->dispatch_id, 'a new dispatch id is minted');
        $this->assertNull($row->lease_holder, 'the lease is released');
        $this->assertSame(1, (int) $row->consecutive_interruptions, 'the interruption is counted');

        $this->assertSame(1, $this->eventCount('run-1', 'interruption_detected'), 'one interruption_detected event');

        Queue::assertPushedOn((string) config('llm-client.resume.queue', 'default'), ResumeInterruptedRunJob::class);
        $this->assertJobPushedWith('run-1', $row->dispatch_id);
    }

    #[Test]
    public function paused_run_never_detected(): void
    {
        $this->makeRun('run-1', ['resume_state' => 'paused', 'lease_expires_at' => now()->subSeconds(1)->format('Y-m-d H:i:s.u')]);

        $exitCode = Artisan::call('llm-client:resume-interrupted-runs');
        $this->assertSame(0, $exitCode);

        $this->assertSame('paused', $this->runRow('run-1')->resume_state, 'a paused run is untouched, even with an expired lease');
        $this->assertSame(0, $this->eventCount('run-1', 'interruption_detected'));
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);
    }

    #[Test]
    public function slow_but_alive_not_detected(): void
    {
        $this->makeRun('run-1', ['lease_expires_at' => now()->addMinutes(5)->format('Y-m-d H:i:s.u')]);

        $exitCode = Artisan::call('llm-client:resume-interrupted-runs');
        $this->assertSame(0, $exitCode);

        $this->assertSame('working', $this->runRow('run-1')->resume_state, 'a live lease is not an interruption');
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);
    }

    #[Test]
    public function cancelled_not_detected(): void
    {
        $this->makeRun('run-1', ['cancelled_at' => now()->format('Y-m-d H:i:s.u')]);

        $exitCode = Artisan::call('llm-client:resume-interrupted-runs');
        $this->assertSame(0, $exitCode);

        $this->assertSame('working', $this->runRow('run-1')->resume_state, 'a cancelled run is untouched');
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);
    }

    // ---------------------------------------------------------------
    // Lost delayed jobs
    // ---------------------------------------------------------------

    #[Test]
    public function retry_wait_not_detected_before_due(): void
    {
        $this->makeRun('run-1', [
            'resume_state' => 'waiting_provider_retry',
            'next_attempt_at' => now()->addSeconds(60)->format('Y-m-d H:i:s.u'),
        ]);

        $exitCode = Artisan::call('llm-client:resume-interrupted-runs');
        $this->assertSame(0, $exitCode);

        $this->assertSame('waiting_provider_retry', $this->runRow('run-1')->resume_state, 'the delayed job is still due: untouched');
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);
    }

    #[Test]
    public function lost_retry_job_redriven(): void
    {
        $this->makeRun('run-1', [
            'resume_state' => 'waiting_provider_retry',
            'next_attempt_at' => now()->subSeconds($this->window() + 1)->format('Y-m-d H:i:s.u'),
        ]);

        $exitCode = Artisan::call('llm-client:resume-interrupted-runs');
        $this->assertSame(0, $exitCode);

        $row = $this->runRow('run-1');
        $this->assertNotSame('10000000-0000-0000-0000-000000000001', $row->dispatch_id, 'a lost delayed job is re-dispatched with a fresh dispatch id');
        $this->assertSame(1, $this->eventCount('run-1', 'interruption_detected'), 'a lost unit of work counts as an interruption');
        $this->assertJobPushedWith('run-1', $row->dispatch_id);
    }

    #[Test]
    public function lost_resume_job_redriven(): void
    {
        $this->makeRun('run-1', [
            'resume_state' => 'interrupted',
            'interrupted_at' => now()->subSeconds($this->window() + 1)->format('Y-m-d H:i:s.u'),
            'lease_holder' => null,
        ]);

        $exitCode = Artisan::call('llm-client:resume-interrupted-runs');
        $this->assertSame(0, $exitCode);

        $row = $this->runRow('run-1');
        $this->assertNotSame('10000000-0000-0000-0000-000000000001', $row->dispatch_id, 'a lost resume job is re-driven with a fresh dispatch id');
        $this->assertNotNull($row->interrupted_at, 'the detection is refreshed so the next sweep does not re-drive again');
        $this->assertJobPushedWith('run-1', $row->dispatch_id);
    }

    // ---------------------------------------------------------------
    // No double dispatch
    // ---------------------------------------------------------------

    #[Test]
    public function two_sweeps_one_dispatch(): void
    {
        $this->makeRun('run-1');

        $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs'));
        $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs'), 'a second sweep finds nothing new');

        Queue::assertPushed(ResumeInterruptedRunJob::class, null, 1, 'the run is dispatched exactly once');
    }

    // ---------------------------------------------------------------
    // Helper drivers
    // ---------------------------------------------------------------

    /** Insert a delegation row between a parent run and a helper run. */
    private function makeDelegation(string $id, string $parentRunId, string $helperRunId, string $status = 'in_progress'): void
    {
        DB::table('agent_delegations')->insert([
            'id' => $id,
            'parent_conversation_id' => 'conv-parent',
            'helper_agent_id' => 'agent-1',
            'helper_conversation_id' => 'conv-helper',
            'owner_user_id' => $this->userId,
            'task' => 'helper task',
            'depth' => 1,
            'status' => $status,
            'parent_run_id' => $parentRunId,
            'helper_run_id' => $helperRunId,
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }

    #[Test]
    public function parent_driver_skipped(): void
    {
        // The parent is live (its lease is in the future), so it is not
        // itself eligible; the sweep only sees the interrupted helper, which
        // it skips because the live parent's own resume re-joins it.
        $this->makeRun('run-parent', [
            'end_state' => 'in_progress',
            'resume_driver' => 'root',
            'lease_expires_at' => now()->addSeconds(100)->format('Y-m-d H:i:s.u'),
        ]);
        $this->makeRun('run-helper', ['resume_driver' => 'parent']);
        $this->makeDelegation('del-1', 'run-parent', 'run-helper');

        $exitCode = Artisan::call('llm-client:resume-interrupted-runs');
        $this->assertSame(0, $exitCode);

        $this->assertSame('working', $this->runRow('run-helper')->resume_state, 'a parent-driven helper is skipped (its parent drives it)');
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);
    }

    #[Test]
    public function parent_driven_helper_whose_parent_is_terminal_is_closed_stopped_early(): void
    {
        $this->makeRun('run-parent', ['end_state' => 'completed', 'resume_driver' => 'root']);
        $this->makeRun('run-helper', ['resume_driver' => 'parent']);
        $this->makeDelegation('del-1', 'run-parent', 'run-helper');

        $exitCode = Artisan::call('llm-client:resume-interrupted-runs');
        $this->assertSame(0, $exitCode);

        $this->assertSame(
            'stopped_early',
            $this->runRow('run-helper')->end_state,
            'a helper whose parent ended is closed stopped_early — never left running',
        );
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);
    }

    #[Test]
    public function batch_member_helper_whose_parent_is_terminal_is_closed_stopped_early(): void
    {
        $this->makeRun('run-parent', ['end_state' => 'completed', 'resume_driver' => 'root']);
        $this->makeRun('run-member', ['resume_driver' => 'batch_member']);
        $this->makeDelegation('del-1', 'run-parent', 'run-member');

        $exitCode = Artisan::call('llm-client:resume-interrupted-runs');
        $this->assertSame(0, $exitCode);

        $this->assertSame(
            'stopped_early',
            $this->runRow('run-member')->end_state,
            'a batch member whose parent ended is closed stopped_early, exactly as for parent',
        );
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);
    }

    #[Test]
    public function none_driver_skipped(): void
    {
        $this->makeRun('run-1', ['resume_driver' => 'none']);

        $exitCode = Artisan::call('llm-client:resume-interrupted-runs');
        $this->assertSame(0, $exitCode);

        $this->assertSame('working', $this->runRow('run-1')->resume_state, 'a none-driver run is left to the abandonment sweeps');
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);
    }

    // ---------------------------------------------------------------
    // Exemptions and dry run
    // ---------------------------------------------------------------

    #[Test]
    public function pending_question_exempt_via_PausedRunQuery(): void
    {
        // Defence in depth: even a working run with an expired lease is
        // exempt while its question is still answerable.
        $this->makeRun('run-1');
        DB::table('agent_questions')->insert([
            'id' => 'q-1',
            'conversation_id' => 'conv-1',
            'user_id' => $this->userId,
            'paused_message_id' => 'msg-1',
            'tool_call_id' => 'call_q',
            'channel' => 'web',
            'resume_mode' => 'sync',
            'question' => 'Which option?',
            'choices' => '[]',
            'state' => 'pending',
            'run_id' => 'run-1',
            'asked_at' => now()->subMinutes(5)->format('Y-m-d H:i:s.u'),
            'expires_at' => now()->addHours(1)->format('Y-m-d H:i:s.u'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('llm-client:resume-interrupted-runs');
        $this->assertSame(0, $exitCode);

        $this->assertSame('working', $this->runRow('run-1')->resume_state, 'a run waiting on an answerable question is exempt');
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);
    }

    #[Test]
    public function dry_run_writes_nothing_and_lists_what_it_would_do(): void
    {
        $this->makeRun('run-1');

        $exitCode = Artisan::call('llm-client:resume-interrupted-runs', ['--dry-run' => true]);
        $this->assertSame(0, $exitCode);

        $row = $this->runRow('run-1');
        $this->assertSame('working', $row->resume_state, 'a dry run changes nothing');
        $this->assertNull($row->interrupted_at, 'a dry run writes no interrupted_at');
        $this->assertSame('10000000-0000-0000-0000-000000000001', $row->dispatch_id, 'a dry run mints no dispatch id');
        $this->assertSame(0, $this->eventCount('run-1', 'interruption_detected'), 'a dry run writes no events');
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);

        $this->assertStringContainsString('run-1', Artisan::output(), 'a dry run lists what it would do');
    }

    // ---------------------------------------------------------------
    // Scheduling
    // ---------------------------------------------------------------

    #[Test]
    public function scheduled_every_minute(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $event = null;
        foreach ($schedule->events() as $scheduled) {
            if (str_contains($scheduled->command ?? '', 'resume-interrupted-runs')) {
                $event = $scheduled;
                break;
            }
        }

        $this->assertNotNull($event, 'Schedule should contain the resume-interrupted-runs command');
        $this->assertSame(
            '* * * * *',
            (string) $event->expression,
            'Schedule should run every minute',
        );
        $this->assertTrue(
            $event->withoutOverlapping,
            'Schedule should use withoutOverlapping',
        );
    }
}
