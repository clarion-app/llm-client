<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 110-delegation-deadlock-timeout, Phase 4 (US2, tasks.md T020-T022,
 * research.md D2/D3, contracts/delegation-chain-bounds.md §2).
 *
 * Solo-delegation (`batch_id IS NULL`) coverage for
 * `llm-client:resolve-stalled-delegations` -- the command renamed (T026)
 * from `ResolveStalledDelegationBatchesCommand` /
 * `llm-client:resolve-stalled-delegation-batches` when this new eligibility
 * branch was added alongside its unchanged batch-member branch. Batch-member
 * coverage stays in `tests/Unit/Commands/ResolveStalledDelegationsCommandTest.php`
 * (renamed to match, its own solo-delegation "never in scope" assumption
 * removed as exactly the thing this feature overturns by design).
 *
 * Per contracts/delegation-chain-bounds.md §2's solo-delegation eligibility
 * rule: `batch_id IS NULL`, `status = 'in_progress'`, `started_at` older
 * than `delegation.stale_after_minutes`, AND idle -- no `agent_run_actions`
 * row opened or closed for the run reachable via `helper_conversation_id`
 * within `delegation.idle_after_minutes` (DelegationService::isIdle(),
 * T025).
 *
 * Fixture conventions mirror DelegationServiceForceFinalizeStalledTest's
 * own light Delegation-row fixture helper and its
 * openRun()/openStep()/openAction()-adjacent direct-insert style for
 * producing a real `agent_run_actions` row with an explicit, controlled
 * timestamp (needed here to distinguish "recent activity" from "old
 * activity" precisely, which the recorder's own always-now() timestamping
 * cannot do without faking the clock).
 */
class ResolveStalledDelegationsCommandTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config(['llm-client.delegation.stale_after_minutes' => 10]);
        config(['llm-client.delegation.idle_after_minutes' => 15]);
    }

    protected function tearDown(): void
    {
        DB::table('agent_delegations')->delete();
        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();
        DB::table('conversations')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function helperConversation(): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Already titled',
        ]);
    }

    private function makeSoloDelegation(string $status, \DateTimeInterface $startedAt, string $helperConversationId): Delegation
    {
        return Delegation::create([
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => $helperConversationId,
            'owner_user_id' => $this->user->id,
            'task' => 'A solo delegation possibly abandoned by its own dead owning process.',
            'depth' => 1,
            'status' => $status,
            'batch_id' => null,
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

    private function youngTimestamp(): \Illuminate\Support\Carbon
    {
        // Well inside the configured 10-minute stale_after_minutes.
        return now()->subMinutes(2);
    }

    /**
     * Directly inserts a real agent_runs/agent_run_steps/agent_run_actions
     * chain for $conversationId, with the action's own started_at/ended_at
     * set explicitly -- used to give a delegation's helper run either
     * recent (well inside idle_after_minutes) or old (well past it)
     * activity, precisely, without relying on the recorder's own
     * always-now() timestamping.
     */
    private function seedHelperRunActivity(string $conversationId, \DateTimeInterface $actionAt): void
    {
        $runId = (string) Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => RunKind::Interactive->value,
            'user_id' => $this->user->id,
            'conversation_id' => $conversationId,
            'end_state' => 'in_progress',
            'started_at' => $actionAt,
            'created_at' => now(),
        ]);

        $stepId = (string) Str::uuid();
        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => $runId,
            'position' => 1,
            'end_state' => 'in_progress',
            'started_at' => $actionAt,
        ]);

        DB::table('agent_run_actions')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => 'tool_invocation',
            'outcome' => 'success',
            'started_at' => $actionAt,
            'ended_at' => $actionAt,
            'created_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // T020 -- a stale AND idle solo delegation is force-finalized
    // exhausted/chain_stalled by the generalized sweep.
    // -----------------------------------------------------------------

    #[Test]
    public function a_stale_and_idle_solo_delegation_is_force_finalized_exhausted(): void
    {
        $helperConversation = $this->helperConversation();
        // No agent_run_actions activity at all for this helper conversation
        // -- the "dead owning process, nothing ever picked this run back
        // up" case (research.md D3).
        $delegation = $this->makeSoloDelegation('in_progress', $this->staleTimestamp(), $helperConversation->id);

        $exitCode = Artisan::call('llm-client:resolve-stalled-delegations');

        $this->assertSame(0, $exitCode, 'the sweep must exit cleanly');

        $row = $this->fresh($delegation);
        $this->assertSame(
            'exhausted',
            $row->status,
            'a stale (started_at older than stale_after_minutes) and idle (no recent agent_run_actions activity) solo delegation must be force-finalized by the generalized sweep -- the exact "leaving a user waiting forever" scenario this feature closes',
        );
        $this->assertSame('chain_stalled', $row->result_reason);
        $this->assertSame('failure', $row->result_status);
        $this->assertNotNull($row->completed_at);
    }

    // -----------------------------------------------------------------
    // T021 -- a delegation that is stale by age but has RECENT
    // agent_run_actions activity (genuine progress) is left alone -- the
    // long-but-healthy survival case (SC-004/FR-005).
    // -----------------------------------------------------------------

    #[Test]
    public function a_stale_but_actively_progressing_solo_delegation_is_left_untouched(): void
    {
        $helperConversation = $this->helperConversation();
        $delegation = $this->makeSoloDelegation('in_progress', $this->staleTimestamp(), $helperConversation->id);

        // Recent activity -- well inside the configured 15-minute
        // idle_after_minutes -- even though the delegation's own
        // started_at is old.
        $this->seedHelperRunActivity($helperConversation->id, now()->subMinutes(1));

        Artisan::call('llm-client:resolve-stalled-delegations');

        $row = $this->fresh($delegation);
        $this->assertSame(
            'in_progress',
            $row->status,
            'a delegation that is old but still producing run-trace actions must never be swept solely for elapsed time -- the time bound alone must not stop a genuinely healthy, long-running chain (FR-005/SC-004)',
        );
        $this->assertNull($row->completed_at);
    }

    // -----------------------------------------------------------------
    // T022 -- a delegation that is idle (no activity at all) but YOUNG
    // (well within stale_after_minutes) is left alone -- idleness alone
    // is insufficient.
    // -----------------------------------------------------------------

    #[Test]
    public function an_idle_but_young_solo_delegation_is_left_untouched(): void
    {
        $helperConversation = $this->helperConversation();
        // No agent_run_actions activity at all (idle), but started only 2
        // minutes ago -- well inside the configured 10-minute
        // stale_after_minutes.
        $delegation = $this->makeSoloDelegation('in_progress', $this->youngTimestamp(), $helperConversation->id);

        Artisan::call('llm-client:resolve-stalled-delegations');

        $row = $this->fresh($delegation);
        $this->assertSame(
            'in_progress',
            $row->status,
            'idleness alone must never be sufficient to sweep a delegation -- it must also be stale BY AGE (started_at older than stale_after_minutes); a brand-new delegation with no activity yet is not evidence of a dead owning process',
        );
        $this->assertNull($row->completed_at);
    }
}
