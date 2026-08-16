<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\LlmClient\Models\Delegation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 101-parallel-subagent-execution, Phase 3 (US3), tasks.md T014.
 *
 * Batch-member coverage for `llm-client:resolve-stalled-delegations
 * {--dry-run}` (contracts §6, research.md D4 layer 3), mirroring
 * `src/Commands/ResolveStalledEvalRunsCommand.php`'s exact shape --
 * Grounding note item 9 -- and `tests/Unit/Commands/ResolveAbandonedRunsCommandTest.php`'s
 * own `Artisan::call('llm-client:...', ['--dry-run' => true])` idiom.
 *
 * Every fixture row is a real `agent_delegations` row inserted directly
 * (this command reads/writes nothing but that one table, data-model.md
 * §1) -- no DelegationService/AgentLoopService scaffolding needed.
 *
 * 110-delegation-deadlock-timeout (Phase 4, tasks.md T026): the command
 * and its class were renamed from `ResolveStalledDelegationBatchesCommand`
 * / `llm-client:resolve-stalled-delegation-batches` when the solo-delegation
 * sweep branch was added alongside this file's own, unchanged batch-member
 * branch -- this file (renamed to match) covers ONLY that batch-member
 * branch; solo-delegation coverage lives in
 * `tests/Feature/ResolveStalledDelegationsCommandTest.php`.
 */
class ResolveStalledDelegationsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->defineAgentDelegationSchema();

        config(['llm-client.delegation.concurrency.stale_after_minutes' => 10]);
    }

    protected function tearDown(): void
    {
        DB::table('agent_delegations')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function makeDelegation(string $status, ?string $batchId, \DateTimeInterface $startedAt): Delegation
    {
        return Delegation::create([
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => (string) Str::uuid(),
            'task' => 'A batch member possibly abandoned by its own parent process.',
            'depth' => 1,
            'status' => $status,
            'batch_id' => $batchId,
            'started_at' => $startedAt,
        ]);
    }

    private function fresh(Delegation $delegation): Delegation
    {
        return Delegation::find($delegation->id);
    }

    private function staleTimestamp(): \Illuminate\Support\Carbon
    {
        // Well past the configured 10-minute stale_after_minutes.
        return now()->subMinutes(30);
    }

    private function freshTimestamp(): \Illuminate\Support\Carbon
    {
        // Well inside the configured 10-minute stale_after_minutes.
        return now()->subMinutes(2);
    }

    // -----------------------------------------------------------------
    // Both eligible statuses are swept alike (mutation-checklist row 6)
    // -----------------------------------------------------------------

    #[Test]
    public function a_stale_queued_row_never_admitted_at_all_is_force_finalized_exhausted_batch_join_timeout(): void
    {
        $batchId = (string) Str::uuid();
        $delegation = $this->makeDelegation('queued', $batchId, $this->staleTimestamp());

        $exitCode = Artisan::call('llm-client:resolve-stalled-delegations');

        $this->assertSame(0, $exitCode);

        $row = $this->fresh($delegation);
        $this->assertSame('exhausted', $row->status, 'a stale QUEUED row -- one that never won an admission slot at all -- must still be swept, not just in_progress rows');
        $this->assertSame('batch_join_timeout', $row->result_reason);
        $this->assertSame('failure', $row->result_status);
        $this->assertNotNull($row->completed_at);
    }

    #[Test]
    public function a_stale_in_progress_row_is_force_finalized_exhausted_batch_join_timeout(): void
    {
        $batchId = (string) Str::uuid();
        $delegation = $this->makeDelegation('in_progress', $batchId, $this->staleTimestamp());

        $exitCode = Artisan::call('llm-client:resolve-stalled-delegations');

        $this->assertSame(0, $exitCode);

        $row = $this->fresh($delegation);
        $this->assertSame('exhausted', $row->status);
        $this->assertSame('batch_join_timeout', $row->result_reason);
        $this->assertNotNull($row->completed_at);
    }

    // -----------------------------------------------------------------
    // A fresh (not-yet-stale) row of either status is left untouched
    // -----------------------------------------------------------------

    #[Test]
    public function a_fresh_queued_row_is_left_untouched(): void
    {
        $batchId = (string) Str::uuid();
        $delegation = $this->makeDelegation('queued', $batchId, $this->freshTimestamp());

        Artisan::call('llm-client:resolve-stalled-delegations');

        $row = $this->fresh($delegation);
        $this->assertSame('queued', $row->status, 'a row well inside the configured stale window must never be swept');
        $this->assertNull($row->completed_at);
    }

    #[Test]
    public function a_fresh_in_progress_row_is_left_untouched(): void
    {
        $batchId = (string) Str::uuid();
        $delegation = $this->makeDelegation('in_progress', $batchId, $this->freshTimestamp());

        Artisan::call('llm-client:resolve-stalled-delegations');

        $row = $this->fresh($delegation);
        $this->assertSame('in_progress', $row->status);
        $this->assertNull($row->completed_at);
    }

    // -----------------------------------------------------------------
    // 110-delegation-deadlock-timeout (Phase 4, research.md D3): a solo
    // (non-batch) delegation is NO LONGER out of scope for this command --
    // this file's own prior assumption ("this command is scoped to
    // batch_id IS NOT NULL rows only") is exactly what that feature's
    // generalized sweep overturns by design (contracts/
    // delegation-chain-bounds.md §2). Solo-delegation eligibility
    // (stale AND idle, via DelegationService::isIdle()) and its
    // stale-but-active/idle-but-young survival cases are covered by
    // tests/Feature/ResolveStalledDelegationsCommandTest.php (T020-T022),
    // not duplicated here -- this file stays scoped to the batch-member
    // branch alone, per its own header doc.
    // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // --dry-run reports without writing
    // -----------------------------------------------------------------

    #[Test]
    public function dry_run_reports_what_would_happen_without_writing_anything(): void
    {
        $batchId = (string) Str::uuid();
        $delegation = $this->makeDelegation('in_progress', $batchId, $this->staleTimestamp());

        $exitCode = Artisan::call('llm-client:resolve-stalled-delegations', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);

        $row = $this->fresh($delegation);
        $this->assertSame('in_progress', $row->status, '--dry-run must never write anything');
        $this->assertNull($row->completed_at);

        $output = Artisan::output();
        $this->assertNotSame('', trim($output), '--dry-run must still report what it found/would do');
    }

    // -----------------------------------------------------------------
    // Idempotency: a second run against an already-terminal row is a
    // no-op
    // -----------------------------------------------------------------

    #[Test]
    public function a_second_run_against_an_already_terminal_row_is_a_no_op(): void
    {
        $batchId = (string) Str::uuid();
        $delegation = $this->makeDelegation('in_progress', $batchId, $this->staleTimestamp());

        Artisan::call('llm-client:resolve-stalled-delegations');
        $firstPassRow = $this->fresh($delegation);
        $this->assertSame('exhausted', $firstPassRow->status, 'fixture sanity: the first sweep must have force-finalized the row');
        $completedAtAfterFirstPass = $firstPassRow->completed_at;

        $exitCode = Artisan::call('llm-client:resolve-stalled-delegations');

        $this->assertSame(0, $exitCode, 'a second run must exit cleanly, never erroring against an already-terminal row');

        $secondPassRow = $this->fresh($delegation);
        $this->assertSame('exhausted', $secondPassRow->status, 'an already-terminal row must never be re-processed or re-written');
        $this->assertEquals(
            $completedAtAfterFirstPass,
            $secondPassRow->completed_at,
            'a no-op second pass must never touch completed_at again',
        );
    }
}
