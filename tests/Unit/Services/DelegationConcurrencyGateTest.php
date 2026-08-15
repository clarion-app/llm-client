<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\DelegationConcurrencyGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 101-parallel-subagent-execution, Phase 3 (US1), tasks.md T010.
 *
 * Unit tests for the not-yet-built `DelegationConcurrencyGate::tryAdmit(
 * string $batchId, string $delegationId): bool` (contracts §4, research.md
 * D2). Mirrors `tests/Unit/Services/RateLimitGateTest.php`'s own "construct
 * two instances, prove the second sees the first's effect" technique
 * (research.md D6): admission state must live in the shared
 * `agent_delegations` table, never on an instance/static property of the
 * gate itself, since two separate queue workers each construct their own
 * `DelegationConcurrencyGate` instance.
 *
 * Every fixture row is a real `Delegation` row created directly (no
 * DelegationService/AgentLoopService scaffolding needed — the gate reads
 * and writes nothing but `agent_delegations.status`, data-model.md §2), so
 * this file needs only the schema `TestCase::defineAgentDelegationSchema()`
 * already provides.
 *
 * Written before `DelegationConcurrencyGate` exists — every test below is
 * expected to FAIL red (class not found) until T016 creates it.
 */
class DelegationConcurrencyGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->defineAgentDelegationSchema();
    }

    protected function tearDown(): void
    {
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

    /**
     * A real `agent_delegations` row in `queued` status, ready for
     * `tryAdmit()` to act on. `helper_conversation_id` is unique per row
     * (a real schema constraint), so every fixture row needs its own.
     */
    private function makeQueuedDelegation(?string $batchId, array $overrides = []): Delegation
    {
        return Delegation::create(array_merge([
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => (string) Str::uuid(),
            'task' => 'A concurrently-dispatched batch member.',
            'depth' => 1,
            'status' => 'queued',
            'batch_id' => $batchId,
            'started_at' => now(),
        ], $overrides));
    }

    private function statusOf(Delegation $delegation): string
    {
        return Delegation::find($delegation->id)->status;
    }

    // -----------------------------------------------------------------
    // The per-batch ceiling (FR-006)
    // -----------------------------------------------------------------

    #[Test]
    public function tryadmit_admits_up_to_the_per_batch_ceiling_and_refuses_the_next_one_for_the_same_batch(): void
    {
        config(['llm-client.delegation.concurrency.max_concurrent_per_batch' => 2]);

        $batchId = (string) Str::uuid();
        $first = $this->makeQueuedDelegation($batchId);
        $second = $this->makeQueuedDelegation($batchId);
        $third = $this->makeQueuedDelegation($batchId);

        $gate = $this->gate();

        $this->assertTrue($gate->tryAdmit($batchId, $first->id), 'the 1st of 2 must be admitted');
        $this->assertTrue($gate->tryAdmit($batchId, $second->id), 'the 2nd of 2 must be admitted, reaching the ceiling');
        $this->assertFalse($gate->tryAdmit($batchId, $third->id), 'the 3rd must be refused once the per-batch ceiling of 2 is already reached');

        $this->assertSame('in_progress', $this->statusOf($first));
        $this->assertSame('in_progress', $this->statusOf($second));
        $this->assertSame('queued', $this->statusOf($third), 'a refused row must be left exactly as it was -- still queued');
    }

    #[Test]
    public function tryadmit_evaluates_the_per_batch_ceiling_independently_per_batch(): void
    {
        config(['llm-client.delegation.concurrency.max_concurrent_per_batch' => 1]);

        $batchA = (string) Str::uuid();
        $batchB = (string) Str::uuid();
        $memberA = $this->makeQueuedDelegation($batchA);
        $memberB = $this->makeQueuedDelegation($batchB);

        $gate = $this->gate();

        // Batch A is already at its own ceiling of 1 -- this must have no
        // bearing at all on batch B's own, separate ceiling.
        $this->assertTrue($gate->tryAdmit($batchA, $memberA->id));
        $this->assertTrue(
            $gate->tryAdmit($batchB, $memberB->id),
            'a different batch\'s own ceiling must be evaluated independently -- one batch being full must never block another',
        );
    }

    // -----------------------------------------------------------------
    // The installation-wide ceiling (FR-007) -- a real, separate axis
    // -----------------------------------------------------------------

    #[Test]
    public function tryadmit_refuses_beyond_the_installation_wide_ceiling_even_when_no_single_batchs_own_ceiling_is_reached(): void
    {
        // Each batch's own per-batch ceiling is generous (10) -- nowhere
        // near reached by any one batch below -- so only the
        // installation-wide ceiling of 3 can be what refuses the 4th
        // admission (FR-007 is a real, separate axis, mutation-checklist
        // row 3).
        config(['llm-client.delegation.concurrency.max_concurrent_per_batch' => 10]);
        config(['llm-client.delegation.concurrency.max_concurrent_per_installation' => 3]);

        $batchA = (string) Str::uuid();
        $batchB = (string) Str::uuid();
        $batchC = (string) Str::uuid();
        $batchD = (string) Str::uuid();

        $memberA = $this->makeQueuedDelegation($batchA);
        $memberB = $this->makeQueuedDelegation($batchB);
        $memberC = $this->makeQueuedDelegation($batchC);
        $memberD = $this->makeQueuedDelegation($batchD);

        $gate = $this->gate();

        $this->assertTrue($gate->tryAdmit($batchA, $memberA->id));
        $this->assertTrue($gate->tryAdmit($batchB, $memberB->id));
        $this->assertTrue($gate->tryAdmit($batchC, $memberC->id), 'the 3rd admission installation-wide must still succeed, reaching the installation ceiling of 3');

        $this->assertFalse(
            $gate->tryAdmit($batchD, $memberD->id),
            'a 4th admission, from a FOURTH distinct batch whose own per-batch ceiling is nowhere near reached, must still be refused by the installation-wide ceiling',
        );
        $this->assertSame('queued', $this->statusOf($memberD));
    }

    // -----------------------------------------------------------------
    // A freed slot makes room for the next admission
    // -----------------------------------------------------------------

    #[Test]
    public function a_freed_slot_makes_room_for_the_next_tryadmit_call_to_succeed(): void
    {
        config(['llm-client.delegation.concurrency.max_concurrent_per_batch' => 1]);

        $batchId = (string) Str::uuid();
        $first = $this->makeQueuedDelegation($batchId);
        $second = $this->makeQueuedDelegation($batchId);

        $gate = $this->gate();

        $this->assertTrue($gate->tryAdmit($batchId, $first->id));
        $this->assertFalse($gate->tryAdmit($batchId, $second->id), 'fixture sanity: the ceiling of 1 must already be occupied by the first admission');

        // The first member's own slot frees -- it reaches a terminal
        // status, exactly as a real completed/failed/exhausted member
        // would (data-model.md §2: a slot is nothing but "this row's own
        // status column reads in_progress").
        Delegation::find($first->id)->update(['status' => 'completed', 'completed_at' => now()]);

        $this->assertTrue(
            $gate->tryAdmit($batchId, $second->id),
            'once the first member\'s own slot frees, the next tryAdmit() call for the same batch must succeed',
        );
        $this->assertSame('in_progress', $this->statusOf($second));
    }

    // -----------------------------------------------------------------
    // Admission state lives in the shared table, not on the gate
    // instance (RateLimitGateTest's own "construct two instances"
    // technique, research.md D6)
    // -----------------------------------------------------------------

    #[Test]
    public function two_separately_constructed_gate_instances_against_the_same_db_state_see_each_others_admitted_rows(): void
    {
        config(['llm-client.delegation.concurrency.max_concurrent_per_batch' => 2]);

        $batchId = (string) Str::uuid();
        $first = $this->makeQueuedDelegation($batchId);
        $second = $this->makeQueuedDelegation($batchId);
        $third = $this->makeQueuedDelegation($batchId);

        // Two independently-constructed instances -- exactly what two
        // separate queue workers, each resolving DelegationConcurrencyGate
        // fresh out of their own container, would have.
        $gateOne = new DelegationConcurrencyGate();
        $gateTwo = new DelegationConcurrencyGate();

        $this->assertTrue($gateOne->tryAdmit($batchId, $first->id), 'the first instance must admit the 1st member');
        $this->assertTrue(
            $gateTwo->tryAdmit($batchId, $second->id),
            'a SECOND, separately-constructed instance must see the first instance\'s own admission and admit only up to the SAME shared ceiling -- proving admission state lives in the agent_delegations table, not on either instance',
        );
        $this->assertFalse(
            $gateOne->tryAdmit($batchId, $third->id),
            'the first instance, asked again, must also see the second instance\'s own admission and refuse the 3rd -- the ceiling is shared, not per-instance',
        );
    }

    // -----------------------------------------------------------------
    // Postconditions (contracts §4)
    // -----------------------------------------------------------------

    #[Test]
    public function on_true_the_rows_status_reads_in_progress_in_the_same_call(): void
    {
        $batchId = (string) Str::uuid();
        $delegation = $this->makeQueuedDelegation($batchId);

        $admitted = $this->gate()->tryAdmit($batchId, $delegation->id);

        $this->assertTrue($admitted);
        $this->assertSame(
            'in_progress',
            $this->statusOf($delegation),
            'the row\'s own status must already read in_progress by the time tryAdmit() returns true -- not merely eventually',
        );
    }

    #[Test]
    public function on_false_no_row_is_written_at_all(): void
    {
        config(['llm-client.delegation.concurrency.max_concurrent_per_batch' => 0]);

        $batchId = (string) Str::uuid();
        $delegation = $this->makeQueuedDelegation($batchId);

        $admitted = $this->gate()->tryAdmit($batchId, $delegation->id);

        $this->assertFalse($admitted);
        $this->assertSame('queued', $this->statusOf($delegation), 'a refused admission must leave the row completely untouched -- still queued');
        $this->assertNull(Delegation::find($delegation->id)->completed_at, 'a refused admission must never write any terminal field either');
    }
}
