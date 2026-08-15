<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Jobs\RunDelegationBatchMemberJob;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\DelegationConcurrencyGate;
use ClarionApp\LlmClient\Services\DelegationQuery;
use ClarionApp\LlmClient\Services\DelegationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 101-parallel-subagent-execution, Phase 5 (US4), tasks.md T028.
 *
 * The story-specific proof FR-006/FR-007/SC-004 need over
 * `DelegationConcurrencyGate` (already built and unit-boundary-tested in
 * Phase 3, T010): not "the gate's own boundary cases are individually
 * correct" but "a real batch larger than the ceiling still eventually
 * completes with every member included" and "the installation-wide axis is
 * real and separate", exercised through the real
 * `RunDelegationBatchMemberJob::handle()` (idempotency guard,
 * `tryAdmit()`/`release()` branch) and `DelegationQuery::membersForBatch()`
 * (FR-012 recoverability), not by poking `DelegationConcurrencyGate`
 * directly the way `DelegationConcurrencyGateTest` does.
 *
 * Genuine multi-process wall-clock concurrency cannot be proven in this
 * fast suite -- a single PHPUnit process has no such capability without
 * real background workers, exactly the limitation
 * `ParallelDelegationJourneyTest`'s own docblock already documents for
 * scenarios 1/4/5/9. `RunDelegationBatchMemberJob::handle()` is instead
 * driven directly (not through the queue) with `DelegationService` replaced
 * by a double whose `runBatchMember()` is a no-op -- decoupling "admitted"
 * from "finished" so this test can choose, deterministically, exactly when
 * each member's own slot frees, and poll the real `agent_delegations` table
 * in between every step to prove the ceiling invariant holds at each of
 * those points, not merely at the end. `tests/RealDatabase/
 * DelegationConcurrencyTest.php` (T029, research.md D6) is the only place
 * this same guarantee is proven against real concurrent operating-system
 * processes racing real MariaDB.
 */
class DelegationConcurrencyCeilingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->defineAgentDelegationSchema();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        DB::table('agent_delegations')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function gate(): DelegationConcurrencyGate
    {
        return app(DelegationConcurrencyGate::class);
    }

    private function query(): DelegationQuery
    {
        return app(DelegationQuery::class);
    }

    /** A DelegationService double whose runBatchMember() is a no-op -- this
     *  test decides when each member "finishes" by writing its terminal
     *  status directly, so several rows can be genuinely in_progress at
     *  once. */
    private function noOpService(): DelegationService
    {
        $service = Mockery::mock(DelegationService::class);
        // 106-multi-agent-run-view (US2, research.md D4a): the job calls
        // this on every successful admission, immediately before
        // runBatchMember() -- a no-op double here too, this test's own
        // concern is the concurrency ceiling's admission decisions.
        $service->shouldReceive('broadcastDelegationAdmitted')->andReturnNull();
        $service->shouldReceive('runBatchMember')->andReturnNull();

        return $service;
    }

    private function makeQueuedDelegation(string $batchId, string $ownerUserId, string $task): Delegation
    {
        return Delegation::create([
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $ownerUserId,
            'task' => $task,
            'depth' => 1,
            'status' => 'queued',
            'batch_id' => $batchId,
            'started_at' => now(),
        ]);
    }

    private function inProgressCount(string ...$batchIds): int
    {
        return DB::table('agent_delegations')
            ->whereIn('batch_id', $batchIds)
            ->where('status', 'in_progress')
            ->count();
    }

    private function finalize(Delegation $delegation, string $status): void
    {
        Delegation::find($delegation->id)->update([
            'status' => $status,
            'completed_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // Scenario 6 (FR-006, SC-004): a batch larger than the ceiling still
    // completes and includes every member, identical in membership to
    // what an unconstrained ceiling would have produced
    // -----------------------------------------------------------------

    #[Test]
    public function scenario_6_a_batch_larger_than_the_ceiling_still_completes_with_every_member_individually_attributed(): void
    {
        config(['llm-client.delegation.concurrency.max_concurrent_per_batch' => 2]);

        $ownerUserId = (string) Str::uuid();
        $batchId = (string) Str::uuid();
        $tasks = ['Member 0 task.', 'Member 1 task.', 'Member 2 task.', 'Member 3 task.', 'Member 4 task.'];
        $members = array_map(fn (string $task) => $this->makeQueuedDelegation($batchId, $ownerUserId, $task), $tasks);
        $jobs = array_map(fn (Delegation $d) => new RunDelegationBatchMemberJob($d->id), $members);

        $gate = $this->gate();
        $service = $this->noOpService();

        $peak = 0;
        $poll = function () use ($batchId, &$peak) {
            $count = $this->inProgressCount($batchId);
            $peak = max($peak, $count);
            $this->assertLessThanOrEqual(2, $count, 'at no polled instant may more than the per-batch ceiling of 2 be in_progress at once');
        };

        // Five workers racing admission, driven deterministically by this
        // test (a real multi-process race is RealDatabase's own job,
        // T029): the first two admit, the rest are refused while the
        // ceiling is full, and each subsequent admission only becomes
        // possible once an earlier member's own slot frees.
        $jobs[0]->handle($gate, $service);
        $poll();
        $jobs[1]->handle($gate, $service);
        $poll();
        $jobs[2]->handle($gate, $service);
        $poll();
        $jobs[3]->handle($gate, $service);
        $poll();
        $jobs[4]->handle($gate, $service);
        $poll();

        $this->assertSame('queued', Delegation::find($members[2]->id)->status, 'refused while the ceiling of 2 is full -- must be left exactly as it was');
        $this->assertSame('queued', Delegation::find($members[3]->id)->status);
        $this->assertSame('queued', Delegation::find($members[4]->id)->status);

        $this->finalize($members[0], 'completed');
        $poll();
        $jobs[2]->handle($gate, $service);
        $poll();
        $this->finalize($members[1], 'failed');
        $poll();
        $jobs[3]->handle($gate, $service);
        $poll();
        $this->finalize($members[2], 'completed');
        $poll();
        $jobs[4]->handle($gate, $service);
        $poll();
        $this->finalize($members[3], 'completed');
        $poll();
        $this->finalize($members[4], 'exhausted');
        $poll();

        $this->assertSame(2, $peak, 'the ceiling of 2 must genuinely have been reached at some point, not merely never exceeded because it was never approached');

        // All five eventually reach a terminal status.
        $finalStatuses = DB::table('agent_delegations')->where('batch_id', $batchId)->pluck('status', 'id');
        foreach ($members as $member) {
            $this->assertContains(
                $finalStatuses[$member->id],
                ['completed', 'exhausted', 'failed'],
                "member {$member->id} must have reached a terminal status"
            );
        }

        // The final combined view includes all five, individually
        // attributed, identical in membership to what an unconstrained
        // ceiling would have produced -- only timing differs, never
        // content (US4 AC3's literal text).
        config(['llm-client.delegation.concurrency.max_concurrent_per_batch' => 5]);
        $unconstrainedBatchId = (string) Str::uuid();
        $unconstrainedMembers = array_map(
            fn (string $task) => $this->makeQueuedDelegation($unconstrainedBatchId, $ownerUserId, $task),
            $tasks
        );
        foreach ($unconstrainedMembers as $i => $member) {
            (new RunDelegationBatchMemberJob($member->id))->handle($gate, $service);
            $this->finalize($member, 'completed');
        }

        $constrainedMembership = $this->query()->membersForBatch($ownerUserId, $batchId);
        $unconstrainedMembership = $this->query()->membersForBatch($ownerUserId, $unconstrainedBatchId);

        $this->assertCount(5, $constrainedMembership);
        $this->assertCount(5, $unconstrainedMembership);

        $constrainedTasks = collect($constrainedMembership)->pluck('task')->sort()->values()->all();
        $unconstrainedTasks = collect($unconstrainedMembership)->pluck('task')->sort()->values()->all();
        $this->assertSame(
            $unconstrainedTasks,
            $constrainedTasks,
            'the constrained batch\'s final membership must be identical in content to what an unconstrained ceiling would have produced'
        );

        $constrainedIds = collect($constrainedMembership)->pluck('id')->sort()->values()->all();
        $expectedIds = collect($members)->pluck('id')->sort()->values()->all();
        $this->assertSame($expectedIds, $constrainedIds, 'every one of the five original rows must be individually present, none dropped and none duplicated');
    }

    // -----------------------------------------------------------------
    // Scenario 7 (US4 AC2): a ceiling of 1 forces strictly one-at-a-time
    // execution, and a waiting member is admitted only once a slot frees
    // -----------------------------------------------------------------

    #[Test]
    public function scenario_7_ceiling_of_one_forces_strictly_sequential_execution_and_admission_waits_for_a_freed_slot(): void
    {
        config(['llm-client.delegation.concurrency.max_concurrent_per_batch' => 1]);

        $ownerUserId = (string) Str::uuid();
        $batchId = (string) Str::uuid();
        $members = [
            $this->makeQueuedDelegation($batchId, $ownerUserId, 'Member 0 task.'),
            $this->makeQueuedDelegation($batchId, $ownerUserId, 'Member 1 task.'),
            $this->makeQueuedDelegation($batchId, $ownerUserId, 'Member 2 task.'),
        ];
        $jobs = array_map(fn (Delegation $d) => new RunDelegationBatchMemberJob($d->id), $members);

        $gate = $this->gate();
        $service = $this->noOpService();

        $peak = 0;
        $poll = function () use ($batchId, &$peak) {
            $count = $this->inProgressCount($batchId);
            $peak = max($peak, $count);
            $this->assertLessThanOrEqual(1, $count, 'ceiling of 1: never 2 concurrently in_progress');
        };

        $jobs[0]->handle($gate, $service);
        $poll();
        $this->assertSame('in_progress', Delegation::find($members[0]->id)->status);

        // A first attempt for member 2 WHILE member 1 still occupies the
        // one and only slot -- this must be refused, not merely never
        // attempted this early.
        $jobs[1]->handle($gate, $service);
        $poll();
        $this->assertSame('queued', Delegation::find($members[1]->id)->status, 'member 2 must be refused while member 1 still holds the ceiling\'s one slot');

        $jobs[2]->handle($gate, $service);
        $poll();
        $this->assertSame('queued', Delegation::find($members[2]->id)->status);

        $this->finalize($members[0], 'completed');
        $member1CompletedAt = Delegation::find($members[0]->id)->completed_at;
        $poll();
        $this->assertSame(0, $this->inProgressCount($batchId), 'member 1\'s own slot must be fully free once it is terminal');

        // The retry -- only now must it succeed.
        $jobs[1]->handle($gate, $service);
        $transitionObservedAt = now();
        $poll();

        $this->assertSame(
            'in_progress',
            Delegation::find($members[1]->id)->status,
            'member 2 must be admitted only once member 1\'s own slot has genuinely freed'
        );
        $this->assertTrue(
            $transitionObservedAt->gte($member1CompletedAt),
            'member 2\'s own queued -> in_progress transition must not be observable before member 1\'s completed_at -- admission genuinely waits for a freed slot, not merely "eventually gets there"'
        );

        // Member 3 must still be refused -- the ceiling of 1 is already
        // occupied by member 2 now.
        $jobs[2]->handle($gate, $service);
        $poll();
        $this->assertSame('queued', Delegation::find($members[2]->id)->status);

        $this->finalize($members[1], 'failed');
        $poll();

        $jobs[2]->handle($gate, $service);
        $poll();
        $this->assertSame('in_progress', Delegation::find($members[2]->id)->status);

        $this->finalize($members[2], 'completed');
        $poll();

        $this->assertSame(1, $peak, 'the ceiling of 1 must genuinely have been reached');

        $membership = $this->query()->membersForBatch($ownerUserId, $batchId);
        $this->assertCount(3, $membership);
    }

    // -----------------------------------------------------------------
    // Scenario 8 (FR-007): the installation-wide ceiling caps concurrency
    // across two different users' batches at once -- a real, separate axis
    // -----------------------------------------------------------------

    #[Test]
    public function scenario_8_the_installation_wide_ceiling_caps_concurrency_across_two_different_users_batches(): void
    {
        // Effectively unconstrain the per-batch axis so only the
        // installation-wide ceiling of 3 can be what refuses anyone below.
        config(['llm-client.delegation.concurrency.max_concurrent_per_batch' => 10]);
        config(['llm-client.delegation.concurrency.max_concurrent_per_installation' => 3]);

        $userX = (string) Str::uuid();
        $userY = (string) Str::uuid();
        $batchX = (string) Str::uuid();
        $batchY = (string) Str::uuid();

        $membersX = array_map(
            fn (int $i) => $this->makeQueuedDelegation($batchX, $userX, "X{$i} task."),
            range(0, 4)
        );
        $membersY = array_map(
            fn (int $i) => $this->makeQueuedDelegation($batchY, $userY, "Y{$i} task."),
            range(0, 4)
        );
        $jobsX = array_map(fn (Delegation $d) => new RunDelegationBatchMemberJob($d->id), $membersX);
        $jobsY = array_map(fn (Delegation $d) => new RunDelegationBatchMemberJob($d->id), $membersY);

        $gate = $this->gate();
        $service = $this->noOpService();

        $peak = 0;
        $poll = function () use ($batchX, $batchY, &$peak) {
            $count = $this->inProgressCount($batchX, $batchY);
            $peak = max($peak, $count);
            $this->assertLessThanOrEqual(3, $count, 'the COMBINED count of in_progress rows across both batches must never exceed the installation-wide ceiling of 3');
        };

        // Interleave admission attempts across both users' batches --
        // neither batch's own per-batch ceiling (10) is anywhere near
        // reached by any single one of these.
        $jobsX[0]->handle($gate, $service);
        $poll();
        $jobsY[0]->handle($gate, $service);
        $poll();
        $jobsX[1]->handle($gate, $service);
        $poll(); // installation ceiling of 3 now reached (X0, Y0, X1)

        $jobsY[1]->handle($gate, $service);
        $poll();
        $this->assertSame('queued', Delegation::find($membersY[1]->id)->status, 'refused: the installation-wide ceiling is already occupied, even though batch Y\'s own per-batch ceiling is nowhere near reached');

        $jobsX[2]->handle($gate, $service);
        $poll();
        $this->assertSame('queued', Delegation::find($membersX[2]->id)->status);

        $this->finalize($membersX[0], 'completed');
        $poll();
        $jobsY[1]->handle($gate, $service);
        $poll();
        $this->assertSame('in_progress', Delegation::find($membersY[1]->id)->status);

        $this->finalize($membersY[0], 'completed');
        $poll();
        $jobsX[2]->handle($gate, $service);
        $poll();
        $this->assertSame('in_progress', Delegation::find($membersX[2]->id)->status);

        $this->finalize($membersX[1], 'failed');
        $poll();
        $jobsX[3]->handle($gate, $service);
        $poll();

        $this->finalize($membersY[1], 'completed');
        $poll();
        $jobsY[2]->handle($gate, $service);
        $poll();

        $this->finalize($membersX[2], 'completed');
        $poll();
        $jobsX[4]->handle($gate, $service);
        $poll();

        $this->finalize($membersY[2], 'completed');
        $poll();
        $jobsY[3]->handle($gate, $service);
        $poll();

        $this->finalize($membersX[3], 'completed');
        $poll();
        $jobsY[4]->handle($gate, $service);
        $poll();

        $this->finalize($membersX[4], 'completed');
        $poll();
        $this->finalize($membersY[3], 'completed');
        $poll();
        $this->finalize($membersY[4], 'completed');
        $poll();

        $this->assertSame(3, $peak, 'the installation-wide ceiling of 3 must genuinely have been reached');

        $finalStatuses = DB::table('agent_delegations')->whereIn('batch_id', [$batchX, $batchY])->pluck('status', 'id');
        foreach ([...$membersX, ...$membersY] as $member) {
            $this->assertContains($finalStatuses[$member->id], ['completed', 'exhausted', 'failed']);
        }

        $this->assertCount(5, $this->query()->membersForBatch($userX, $batchX));
        $this->assertCount(5, $this->query()->membersForBatch($userY, $batchY));
    }
}
