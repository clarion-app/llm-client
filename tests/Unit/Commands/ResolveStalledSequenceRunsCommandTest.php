<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\LlmClient\Jobs\RunSequenceStageJob;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Models\StageSequenceDefinition;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 105-stage-pipeline, Phase 6 (US4), tasks.md T055/T056 (quickstart scenario
 * 8, research.md D6, Grounding note item 6).
 *
 * Unit tests for the not-yet-built
 * `llm-client:resolve-stalled-sequence-runs {--dry-run}`, mirroring
 * ResolveStalledManagedTasksCommandTest.php's exact
 * `Artisan::call('llm-client:...', ['--dry-run' => true])` idiom. Every
 * fixture row is a real stage_sequence_definitions/stages/sequence_runs/
 * stage_results row inserted directly (none of these tables carry a
 * DB-level FK) -- no SequenceService/AgentLoopService scaffolding needed,
 * since this command only ever inspects StageResult.status/Stage.is_idempotent
 * and either re-dispatches a job or force-fails the run; it never itself
 * calls delegate().
 *
 * Written before ResolveStalledSequenceRunsCommand exists -- every test
 * below is expected to FAIL red (command not found) until T060 creates it.
 */
class ResolveStalledSequenceRunsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.pipeline.stale_after_minutes' => 10]);
    }

    protected function tearDown(): void
    {
        DB::table('stage_results')->delete();
        DB::table('sequence_runs')->delete();
        DB::table('stages')->delete();
        DB::table('stage_sequence_definitions')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: SequenceRun, 1: array<int, Stage>, 2: StageResult} the run, its stages, and the blocking (running) StageResult
     */
    private function makeStalledRunFixture(string $runStatus, bool $blockingStageIdempotent, \DateTimeInterface $lastProgressAt): array
    {
        $definition = StageSequenceDefinition::create([
            'owner_user_id' => (string) Str::uuid(),
            'coordinator_agent_id' => (string) Str::uuid(),
            'name' => 'Stalled sweep fixture',
            'description' => null,
        ]);

        $stageDefs = [
            ['name' => 'Draft', 'is_idempotent' => false],
            ['name' => 'Send notification', 'is_idempotent' => $blockingStageIdempotent],
            ['name' => 'Finish', 'is_idempotent' => false],
        ];

        $stages = [];
        foreach ($stageDefs as $index => $def) {
            $stages[] = Stage::create([
                'sequence_definition_id' => $definition->id,
                'position' => $index + 1,
                'name' => $def['name'],
                'helper_agent_id' => (string) Str::uuid(),
                'is_idempotent' => $def['is_idempotent'],
            ]);
        }

        $run = SequenceRun::create([
            'sequence_definition_id' => $definition->id,
            'owner_user_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'status' => $runStatus,
            'starting_input' => json_encode(['topic' => 'stalled sweep test']),
            'current_stage_position' => 2,
            'last_progress_at' => $lastProgressAt,
            'resume_count' => 0,
            'started_at' => now()->subHour(),
        ]);

        StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[0]->id,
            'status' => 'completed',
            'output' => json_encode(['drafted' => true]),
            'started_at' => now()->subMinutes(30),
            'completed_at' => now()->subMinutes(25),
        ]);

        $blocking = StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[1]->id,
            'status' => 'running',
            'started_at' => now()->subMinutes(20),
        ]);

        StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[2]->id,
            'status' => 'pending',
        ]);

        return [$run, $stages, $blocking];
    }

    private function fresh(SequenceRun $run): SequenceRun
    {
        return SequenceRun::find($run->id);
    }

    // -----------------------------------------------------------------
    // T055: idempotent crash path -- re-dispatch, never fail the run.
    // -----------------------------------------------------------------

    #[Test]
    public function a_stale_run_blocked_on_an_idempotent_stage_gets_a_fresh_stage_job_re_dispatched(): void
    {
        [$run] = $this->makeStalledRunFixture(
            runStatus: 'in_progress',
            blockingStageIdempotent: true,
            lastProgressAt: now()->subMinutes(15), // stale: older than the 10-minute threshold
        );

        Queue::fake();

        $exitCode = Artisan::call('llm-client:resolve-stalled-sequence-runs');

        $this->assertSame(0, $exitCode);
        Queue::assertPushed(RunSequenceStageJob::class, fn (RunSequenceStageJob $job) => $job->sequenceRunId === $run->id);

        $row = $this->fresh($run);
        $this->assertNotSame('failed', $row->status, 're-dispatching a fresh stage job must never itself fail the run');
        $this->assertNull($row->completed_at);
    }

    // -----------------------------------------------------------------
    // T056: non-idempotent crash path -- force-fail, dispatch nothing.
    // -----------------------------------------------------------------

    #[Test]
    public function a_stale_run_blocked_on_a_non_idempotent_stage_is_marked_failed_and_no_job_is_dispatched(): void
    {
        [$run, $stages, $blocking] = $this->makeStalledRunFixture(
            runStatus: 'in_progress',
            blockingStageIdempotent: false,
            lastProgressAt: now()->subMinutes(15),
        );

        Queue::fake();

        $exitCode = Artisan::call('llm-client:resolve-stalled-sequence-runs');

        $this->assertSame(0, $exitCode);
        Queue::assertNotPushed(RunSequenceStageJob::class, null, 'a run blocked on a non-idempotent crashed stage must never be silently re-dispatched');

        $row = $this->fresh($run);
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->failure_reason);
        $this->assertStringContainsString('Send notification', $row->failure_reason, 'the failure reason must name the specific blocking stage');
        $this->assertNotNull($row->completed_at);

        // The blocking StageResult itself is left as-is by the sweep --
        // only the run's own status is forced terminal.
        $blockingRow = StageResult::find($blocking->id);
        $this->assertSame('running', $blockingRow->status);
    }

    // -----------------------------------------------------------------
    // A 'resumed' status run is swept identically to 'in_progress'
    // (Grounding note item 6 -- do not scope the eligibility query to
    // in_progress alone).
    // -----------------------------------------------------------------

    #[Test]
    public function a_stale_resumed_run_is_also_swept(): void
    {
        [$run] = $this->makeStalledRunFixture(
            runStatus: 'resumed',
            blockingStageIdempotent: true,
            lastProgressAt: now()->subMinutes(15),
        );

        Queue::fake();

        Artisan::call('llm-client:resolve-stalled-sequence-runs');

        Queue::assertPushed(RunSequenceStageJob::class, fn (RunSequenceStageJob $job) => $job->sequenceRunId === $run->id);
    }

    // -----------------------------------------------------------------
    // A fresh (not-yet-stale) row is left completely untouched.
    // -----------------------------------------------------------------

    #[Test]
    public function a_fresh_row_is_left_untouched(): void
    {
        [$run] = $this->makeStalledRunFixture(
            runStatus: 'in_progress',
            blockingStageIdempotent: false,
            lastProgressAt: now()->subMinutes(2), // well inside the 10-minute stale threshold
        );

        Queue::fake();

        Artisan::call('llm-client:resolve-stalled-sequence-runs');

        Queue::assertNotPushed(RunSequenceStageJob::class);
        $row = $this->fresh($run);
        $this->assertSame('in_progress', $row->status);
        $this->assertNull($row->completed_at);
    }

    // -----------------------------------------------------------------
    // An already-terminal run is never swept, however stale.
    // -----------------------------------------------------------------

    #[Test]
    public function an_already_terminal_run_is_never_swept_however_stale(): void
    {
        [$run] = $this->makeStalledRunFixture(
            runStatus: 'completed',
            blockingStageIdempotent: false,
            lastProgressAt: now()->subHours(2),
        );

        Queue::fake();

        Artisan::call('llm-client:resolve-stalled-sequence-runs');

        Queue::assertNotPushed(RunSequenceStageJob::class);
        $row = $this->fresh($run);
        $this->assertSame('completed', $row->status);
    }

    // -----------------------------------------------------------------
    // --dry-run reports without writing or dispatching, for either branch.
    // -----------------------------------------------------------------

    #[Test]
    public function dry_run_reports_without_dispatching_for_the_re_dispatch_case(): void
    {
        [$run] = $this->makeStalledRunFixture(
            runStatus: 'in_progress',
            blockingStageIdempotent: true,
            lastProgressAt: now()->subMinutes(15),
        );

        Queue::fake();

        $exitCode = Artisan::call('llm-client:resolve-stalled-sequence-runs', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        Queue::assertNotPushed(RunSequenceStageJob::class, null, '--dry-run must never actually dispatch a job');

        $row = $this->fresh($run);
        $this->assertSame('in_progress', $row->status);

        $output = Artisan::output();
        $this->assertNotSame('', trim($output), '--dry-run must still report what it found/would do');
    }

    #[Test]
    public function dry_run_reports_without_writing_for_the_force_fail_case(): void
    {
        [$run, , $blocking] = $this->makeStalledRunFixture(
            runStatus: 'in_progress',
            blockingStageIdempotent: false,
            lastProgressAt: now()->subMinutes(15),
        );

        Queue::fake();

        $exitCode = Artisan::call('llm-client:resolve-stalled-sequence-runs', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        Queue::assertNotPushed(RunSequenceStageJob::class);

        $row = $this->fresh($run);
        $this->assertSame('in_progress', $row->status, '--dry-run must never finalize anything');
        $this->assertNull($row->completed_at);

        $blockingRow = StageResult::find($blocking->id);
        $this->assertSame('running', $blockingRow->status);

        $output = Artisan::output();
        $this->assertNotSame('', trim($output), '--dry-run must still report what it found/would do');
    }
}
