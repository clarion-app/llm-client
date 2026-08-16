<?php

namespace ClarionApp\LlmClient\Tests\Integration;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 110-delegation-deadlock-timeout, Phase 5 (US3, tasks.md T030/T031/T033,
 * research.md D3/D4, contracts/delegation-chain-bounds.md §2 "Whole-subtree
 * finalization (new)").
 *
 * `ResolveStalledDelegationsCommand`'s solo-delegation branch
 * (`resolveSoloDelegations()`) currently selects and finalizes each stale +
 * idle row it queries directly (`DelegationService::isIdle()` applied
 * per-row), with NO code that walks a found row's own
 * `parent_conversation_id` ancestry to also finalize still-`in_progress`
 * ancestors in the same dead-process chain -- Phase 5's implementation half
 * (T034) adds that. This file proves the gap: a multi-hop chain where only
 * the deepest row independently satisfies the flat stale+idle query is left
 * with its ancestor stranded `in_progress` forever -- exactly the "leaving a
 * user waiting forever" scenario spec.md User Story 3 exists to close.
 *
 * It also proves `forceFinalizeStalledDelegation()` currently writes a
 * fixed placeholder `result_summary` (T007's own deliberately generic "The
 * delegation chain was force-finalized as stalled.") rather than composing
 * from the helper conversation's own last persisted assistant message
 * (T035's job, research.md D4) -- FR-006's "partial work preserved" is not
 * yet true of the sweep path.
 *
 * Fixture conventions mirror ResolveStalledDelegationsCommandTest's own
 * light Delegation-row fixture helpers and its direct-insert
 * agent_runs/agent_run_steps/agent_run_actions style for producing
 * precisely-timestamped activity (or its absence).
 */
class DelegationChainUnwindJourneyTest extends TestCase
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
        DB::table('messages')->delete();
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

    private function conversation(): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Already titled',
        ]);
    }

    private function makeDelegation(string $parentConversationId, string $helperConversationId, \DateTimeInterface $startedAt): Delegation
    {
        return Delegation::create([
            'parent_conversation_id' => $parentConversationId,
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => $helperConversationId,
            'owner_user_id' => $this->user->id,
            'task' => 'A chain-unwind fixture delegation.',
            'depth' => 1,
            'status' => 'in_progress',
            'batch_id' => null,
            'started_at' => $startedAt,
        ]);
    }

    private function fresh(Delegation $delegation): Delegation
    {
        return Delegation::find($delegation->id);
    }

    private function staleTimestamp(int $minutesAgo = 30): \Illuminate\Support\Carbon
    {
        return now()->subMinutes($minutesAgo);
    }

    /**
     * Directly inserts a real agent_runs/agent_run_steps/agent_run_actions
     * chain for $conversationId, with the action's own started_at/ended_at
     * set explicitly -- mirrors ResolveStalledDelegationsCommandTest's own
     * seedHelperRunActivity(), parameterized on $outcome/$endedAt so T033
     * can produce a still-open 'awaiting_confirmation' action (null
     * ended_at) instead of the ordinary closed 'success' one.
     */
    private function seedHelperRunActivity(
        string $conversationId,
        \DateTimeInterface $actionAt,
        string $outcome = 'success',
        \DateTimeInterface|false|null $endedAt = false,
    ): void {
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

        // $endedAt === false means "default to $actionAt" (an ordinary
        // closed action); an explicit null leaves the row open/suspended,
        // matching how a genuinely still-awaiting-confirmation action is
        // actually recorded (ActionOutcome::isSuspended()).
        DB::table('agent_run_actions')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => 'tool_invocation',
            'outcome' => $outcome,
            'started_at' => $actionAt,
            'ended_at' => $endedAt === false ? $actionAt : $endedAt,
            'created_at' => now(),
        ]);
    }

    // =================================================================
    // T030 -- whole-subtree finalization: every row in a dead-process
    // chain must reach 'exhausted', not only the one the flat query
    // directly matches.
    // =================================================================

    #[Test]
    public function every_row_in_a_stale_idle_solo_delegation_chain_is_finalized_not_only_the_leaf(): void
    {
        // A -> B -> C: Delegation1 (A -> B) is the ANCESTOR, Delegation2
        // (B -> C) is the LEAF. A is never itself a helper conversation
        // anywhere, so a bare fabricated id stands in for it exactly as
        // ResolveStalledDelegationsCommandTest's own makeSoloDelegation()
        // already does for a chain's outermost parent.
        $convA = (string) Str::uuid();
        $convB = $this->conversation();
        $convC = $this->conversation();

        // Delegation2 (leaf, B -> C): stale by age (well past
        // stale_after_minutes) AND idle -- convC has no agent_runs/
        // agent_run_actions activity at all, so isIdle() falls back to
        // comparing started_at directly against idle_after_minutes, which
        // this timestamp is also well past. This is the row the CURRENT
        // flat query independently matches and finalizes on its own.
        $delegation2 = $this->makeDelegation($convB->id, $convC->id, $this->staleTimestamp(20));

        // Delegation1 (ancestor, A -> B): started strictly before the leaf
        // (a parent always exists before the child hop it creates), so it
        // is UNAVOIDABLY at least as stale by age as the leaf. The only
        // way the CURRENT, per-row isIdle() check can fail to also select
        // it is via activity: convB's own run has an action timestamped
        // recently (well inside idle_after_minutes), so isIdle(Delegation1)
        // reads false today, even though convB is, in fact, permanently
        // blocked inside the same dead process that also killed convC --
        // exactly the case the whole-subtree walk (T034) exists to catch,
        // since nothing about B's OWN row proves it is stuck without also
        // looking at what it is waiting on.
        $delegation1 = $this->makeDelegation($convA, $convB->id, $this->staleTimestamp(30));
        $this->seedHelperRunActivity($convB->id, now()->subMinutes(2));

        $exitCode = Artisan::call('llm-client:resolve-stalled-delegations');
        $this->assertSame(0, $exitCode, 'the sweep must exit cleanly');

        $leafRow = $this->fresh($delegation2);
        $this->assertSame(
            'exhausted',
            $leafRow->status,
            'the leaf row is directly matched by the flat stale+idle query and must be finalized',
        );

        $ancestorRow = $this->fresh($delegation1);
        $this->assertSame(
            'exhausted',
            $ancestorRow->status,
            'FR-007: every participant in a stopped chain must reach a resolved terminal state -- once the leaf proves the owning process is dead, the still-in_progress ancestor above it (found by walking parent_conversation_id) must be finalized too in the SAME sweep pass, not left stranded in_progress because its own row looked recently active in isolation',
        );
        $this->assertNotNull($ancestorRow->completed_at);
    }

    // =================================================================
    // T031 -- a finalized row's result_summary preserves the helper
    // conversation's own last persisted assistant message (FR-006).
    // =================================================================

    #[Test]
    public function a_finalized_rows_result_summary_contains_its_helper_conversations_last_assistant_message(): void
    {
        $helperConversation = $this->conversation();

        $partialOutput = 'Partial output the helper actually produced before its process died.';
        Message::factory()->create([
            'conversation_id' => $helperConversation->id,
            'role' => 'assistant',
            'content' => $partialOutput,
        ]);

        $delegation = $this->makeDelegation((string) Str::uuid(), $helperConversation->id, $this->staleTimestamp());

        Artisan::call('llm-client:resolve-stalled-delegations');

        $row = $this->fresh($delegation);
        $this->assertSame('exhausted', $row->status);
        $this->assertStringContainsString(
            $partialOutput,
            (string) $row->result_summary,
            "FR-006: whatever partial, useful work the helper had already produced must be preserved in what is reported back -- forceFinalizeStalledDelegation() currently writes a fixed generic placeholder instead of composing from the helper conversation's own last assistant message",
        );
    }

    // =================================================================
    // T033 -- a delegation stuck on a confirmation-pending outcome is,
    // once stale+idle, swept exactly like any other stalled participant
    // (spec.md Edge Cases). The general mechanism does not special-case
    // this -- expected to already pass today.
    // =================================================================

    #[Test]
    public function a_delegation_blocked_on_an_unresolved_confirmation_is_swept_like_any_other_stalled_participant(): void
    {
        $helperConversation = $this->conversation();

        // The helper's own run has an agent_run_actions row still sitting
        // in 'awaiting_confirmation' -- a genuinely stuck confirmation
        // prompt that will never be answered because the owning process is
        // dead (mirrors AgentLoopService's/AgentLoopStreamHandler's own
        // ActionOutcome::AwaitingConfirmation suspend). Left open (null
        // ended_at), matching how a genuinely suspended action is actually
        // recorded (ActionOutcome::isSuspended()), and timestamped old
        // enough to count as idle.
        $this->seedHelperRunActivity(
            $helperConversation->id,
            $this->staleTimestamp(20),
            'awaiting_confirmation',
            null,
        );

        $delegation = $this->makeDelegation((string) Str::uuid(), $helperConversation->id, $this->staleTimestamp());

        Artisan::call('llm-client:resolve-stalled-delegations');

        $row = $this->fresh($delegation);
        $this->assertSame(
            'exhausted',
            $row->status,
            'spec.md Edge Cases: a participant waiting on a human confirmation that will never come must still reach its own resolved terminal state once stale+idle, exactly like any other stalled participant -- the sweep must not special-case (or be blocked by) a confirmation-pending outcome',
        );
        $this->assertSame('chain_stalled', $row->result_reason);
        $this->assertNotNull($row->completed_at);
    }
}
