<?php

namespace ClarionApp\LlmClient\Tests\Unit\Jobs;

use ClarionApp\LlmClient\Jobs\RunDelegationBatchMemberJob;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\DelegationConcurrencyGate;
use ClarionApp\LlmClient\Services\DelegationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 101-parallel-subagent-execution, Phase 3 (US1), tasks.md T011.
 *
 * Unit tests for the not-yet-built `RunDelegationBatchMemberJob` (contracts
 * §5, research.md D1/D4, mirroring `src/Jobs/RunEvalCaseJob.php`'s exact
 * shape -- Grounding note item 7). `DelegationConcurrencyGate` and
 * `DelegationService` are both replaced with Mockery doubles, passed
 * directly into `handle()`'s own method-injected parameters (exactly how
 * Laravel's queue worker resolves them at dispatch time, so calling
 * `handle($mockGate, $mockService)` directly exercises the identical
 * signature a real worker would use) -- the only thing this file cares
 * about is the ADMISSION DECISION'S CONSEQUENCE (run vs release vs no-op),
 * never DelegationConcurrencyGate's or DelegationService's own internals
 * (covered by their own dedicated test files, T010/T012/T018).
 *
 * `release()`/`fail()` are asserted via Laravel's own built-in
 * `InteractsWithQueue::withFakeQueueInteractions()` +
 * `assertReleased()`/`assertNotReleased()` -- calling `release()` on a job
 * instance that was never actually pulled off a real queue would otherwise
 * silently no-op (`$this->job` stays null), which is exactly the trap
 * `withFakeQueueInteractions()` exists to avoid.
 *
 * Written before `RunDelegationBatchMemberJob` exists -- every test below
 * is expected to FAIL red (class not found) until T017 creates it.
 */
class RunDelegationBatchMemberJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->defineAgentDelegationSchema();
    }

    protected function tearDown(): void
    {
        // A raw, uncaught Error (the "class not found" this file's tests
        // are expected to hit until T017 lands) leaves PHP's own error/
        // exception handler stack disturbed -- AgentLoopServiceTest's own
        // established precedent for cleaning this up so it never bleeds
        // into a later test in the same process.
        restore_error_handler();
        restore_exception_handler();

        DB::table('agent_delegations')->delete();
        Mockery::close();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function makeDelegation(string $status, ?string $batchId = null): Delegation
    {
        return Delegation::create([
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => (string) Str::uuid(),
            'task' => 'A concurrently-dispatched batch member.',
            'depth' => 1,
            'status' => $status,
            'batch_id' => $batchId ?? (string) Str::uuid(),
            'started_at' => now(),
        ]);
    }

    private function fresh(Delegation $delegation): Delegation
    {
        return Delegation::find($delegation->id);
    }

    // -----------------------------------------------------------------
    // Admitted -> runs the member to a terminal outcome
    // -----------------------------------------------------------------

    #[Test]
    public function handle_calls_tryadmit_and_on_true_runs_the_member_via_the_extracted_execution_method(): void
    {
        $delegation = $this->makeDelegation('queued');

        $gate = Mockery::mock(DelegationConcurrencyGate::class);
        $gate->shouldReceive('tryAdmit')
            ->once()
            ->with($delegation->batch_id, $delegation->id)
            ->andReturn(true);

        $service = Mockery::mock(DelegationService::class);
        $service->shouldReceive('runBatchMember')
            ->once()
            ->with(Mockery::on(fn ($d) => $d instanceof Delegation && $d->id === $delegation->id));

        $job = new RunDelegationBatchMemberJob($delegation->id);
        $job->withFakeQueueInteractions();

        $job->handle($gate, $service);

        $job->assertNotReleased();
        $job->assertNotDeleted();
        $job->assertNotFailed();
    }

    // -----------------------------------------------------------------
    // Refused -> release(), never marked terminal
    // -----------------------------------------------------------------

    #[Test]
    public function handle_releases_the_job_on_admission_failure_and_never_marks_the_delegation_terminal(): void
    {
        config(['llm-client.delegation.concurrency.admission_retry_delay_seconds' => 7]);

        $delegation = $this->makeDelegation('queued');

        $gate = Mockery::mock(DelegationConcurrencyGate::class);
        $gate->shouldReceive('tryAdmit')
            ->once()
            ->with($delegation->batch_id, $delegation->id)
            ->andReturn(false);

        $service = Mockery::mock(DelegationService::class);
        $service->shouldNotReceive('runBatchMember');

        $job = new RunDelegationBatchMemberJob($delegation->id);
        $job->withFakeQueueInteractions();

        $job->handle($gate, $service);

        $job->assertReleased(7, 'a refused admission must release the job back onto the queue after the configured admission_retry_delay_seconds, never treat a full ceiling as a failure');
        $job->assertNotDeleted();
        $job->assertNotFailed();

        $this->assertSame(
            'queued',
            $this->fresh($delegation)->status,
            'a refused admission must never mark the delegation terminal -- it stays queued, eligible for the next retry',
        );
    }

    // -----------------------------------------------------------------
    // Redelivery against a row no longer queued -- a no-op
    // -----------------------------------------------------------------

    #[Test]
    public function handle_is_a_no_op_for_a_redelivered_job_whose_row_is_already_in_progress(): void
    {
        $delegation = $this->makeDelegation('in_progress');

        $gate = Mockery::mock(DelegationConcurrencyGate::class);
        $gate->shouldNotReceive('tryAdmit');

        $service = Mockery::mock(DelegationService::class);
        $service->shouldNotReceive('runBatchMember');

        $job = new RunDelegationBatchMemberJob($delegation->id);
        $job->withFakeQueueInteractions();

        $job->handle($gate, $service);

        $job->assertNotReleased();
        $job->assertNotDeleted();
        $job->assertNotFailed();
        $this->assertSame('in_progress', $this->fresh($delegation)->status, 'a redelivered job must never disturb a row that has already moved on');
    }

    #[Test]
    public function handle_is_a_no_op_for_a_redelivered_job_whose_row_is_already_terminal(): void
    {
        $delegation = $this->makeDelegation('completed');

        $gate = Mockery::mock(DelegationConcurrencyGate::class);
        $gate->shouldNotReceive('tryAdmit');

        $service = Mockery::mock(DelegationService::class);
        $service->shouldNotReceive('runBatchMember');

        $job = new RunDelegationBatchMemberJob($delegation->id);
        $job->withFakeQueueInteractions();

        $job->handle($gate, $service);

        $job->assertNotReleased();
        $this->assertSame('completed', $this->fresh($delegation)->status);
    }

    // -----------------------------------------------------------------
    // failed() -- the worker-level timeout/exception hook
    // -----------------------------------------------------------------

    #[Test]
    public function failed_force_finalizes_a_still_in_progress_row_without_leaving_it_non_terminal(): void
    {
        $delegation = $this->makeDelegation('in_progress');

        $job = new RunDelegationBatchMemberJob($delegation->id);
        $job->failed(new \RuntimeException('The queue worker killed this job for exceeding its timeout.'));

        $row = $this->fresh($delegation);

        $this->assertContains(
            $row->status,
            ['failed', 'exhausted'],
            'failed() must force-finalize the row to a TERMINAL status -- it must never be left queued or in_progress',
        );
        $this->assertNotNull($row->completed_at);
        $this->assertSame(
            'failure',
            $row->result_status,
            'the structured six-field result_status must be written too, mirroring every other terminal path in this feature',
        );
    }

    #[Test]
    public function failed_is_a_no_op_for_a_row_that_is_already_terminal(): void
    {
        $delegation = $this->makeDelegation('completed');
        $originalCompletedAt = now()->subMinute();
        $delegation->update(['completed_at' => $originalCompletedAt]);

        $job = new RunDelegationBatchMemberJob($delegation->id);
        $job->failed(new \RuntimeException('Arrives after the row already completed normally.'));

        $row = $this->fresh($delegation);
        $this->assertSame('completed', $row->status, 'failed() must never override an already-terminal outcome');
    }
}
