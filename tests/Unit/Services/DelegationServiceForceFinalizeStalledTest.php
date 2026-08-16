<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\DelegationUpdated;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 110-delegation-deadlock-timeout, Phase 2 (Foundational), tasks.md T006
 * (research.md D3, contracts/delegation-chain-bounds.md §2).
 *
 * DelegationService::forceFinalizeStalledDelegation() does not exist yet --
 * it is added by T007, mirroring forceFinalizeBatchJoinTimeout()'s exact
 * shape (terminal-status guard, status = 'exhausted', result_reason, a
 * DelegationUpdated broadcast, and a RunTraceRecorder::closeAction() call
 * with ActionOutcome::Unfinished) but writing result_reason: 'chain_stalled'
 * by default and reused by the generalized stalled-delegation sweep rather
 * than the batch join-wait deadline. Every test below is expected to fail
 * right now with a "method does not exist" error -- that failure is the
 * correct, expected state for this phase, until T007 lands.
 *
 * Fixture conventions mirror DelegationServiceBroadcastTest's own
 * light-fixture helper (a bare Delegation::create() -- no Agent/
 * Conversation/AgentLoopService stack needed to exercise a direct terminal
 * write method) and RunTraceRecorderBroadcastTest's own
 * openRun()/openStep()/openAction() setup for producing a real
 * agent_run_actions row to close against.
 */
class DelegationServiceForceFinalizeStalledTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_runs')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function delegationService(): DelegationService
    {
        return app(DelegationService::class);
    }

    private function recorder(): RunTraceRecorder
    {
        return app(RunTraceRecorder::class);
    }

    /**
     * A bare Delegation row, filling every NOT NULL column the migration
     * requires -- mirrors DelegationServiceBroadcastTest's own
     * makeLightDelegation().
     */
    private function makeDelegation(string $status, array $overrides = []): Delegation
    {
        return Delegation::create(array_merge([
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'A stalled-finalize-test fixture delegation.',
            'depth' => 1,
            'status' => $status,
            'started_at' => now(),
        ], $overrides));
    }

    /**
     * Opens a real run/step/action, mirroring
     * RunTraceRecorderBroadcastTest's own established setup -- used to give
     * a Delegation a real parent_action_id to close against.
     */
    private function openAction(): string
    {
        $runId = $this->recorder()->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder()->openStep($runId);
        $actionId = $this->recorder()->openAction($stepId, ActionType::Delegation, 'stalled-helper');

        $this->assertNotNull($actionId, 'fixture sanity: run tracing must be enabled and produce a real action id');

        return $actionId;
    }

    // =================================================================
    // Non-terminal row: writes the expected terminal columns
    // =================================================================

    #[Test]
    public function a_non_terminal_row_is_written_to_exhausted_with_chain_stalled_as_the_default_reason(): void
    {
        $delegation = $this->makeDelegation('in_progress');

        app(DelegationService::class)->forceFinalizeStalledDelegation($delegation);

        $delegation->refresh();

        $this->assertSame('exhausted', $delegation->status);
        $this->assertSame('failure', $delegation->result_status);
        $this->assertSame('chain_stalled', $delegation->result_reason);
        $this->assertNotNull($delegation->completed_at, 'a finalized row must reach a resolved, non-null completed_at');
        $this->assertNull($delegation->result_output, 'a stalled finalize never carries content that could be mistaken for genuine output');
    }

    #[Test]
    public function a_custom_reason_argument_overrides_the_chain_stalled_default(): void
    {
        $delegation = $this->makeDelegation('in_progress');

        app(DelegationService::class)->forceFinalizeStalledDelegation($delegation, 'cycle_stopped');

        $delegation->refresh();

        $this->assertSame('exhausted', $delegation->status);
        $this->assertSame('cycle_stopped', $delegation->result_reason);
    }

    // =================================================================
    // DelegationUpdated broadcast
    // =================================================================

    #[Test]
    public function force_finalize_stalled_delegation_fires_delegation_updated_exactly_once(): void
    {
        $delegation = $this->makeDelegation('in_progress');

        Event::fake([DelegationUpdated::class]);

        app(DelegationService::class)->forceFinalizeStalledDelegation($delegation);

        Event::assertDispatchedTimes(DelegationUpdated::class, 1);
        Event::assertDispatched(DelegationUpdated::class, fn (DelegationUpdated $e) => $e->delegationId === $delegation->id);
    }

    // =================================================================
    // Run-trace action closing (parent_action_id set)
    // =================================================================

    #[Test]
    public function when_parent_action_id_is_set_the_run_trace_action_closes_as_unfinished(): void
    {
        $actionId = $this->openAction();
        $delegation = $this->makeDelegation('in_progress', ['parent_action_id' => $actionId]);

        app(DelegationService::class)->forceFinalizeStalledDelegation($delegation);

        $this->assertSame(
            \ClarionApp\LlmClient\ValueObjects\ActionOutcome::Unfinished->value,
            DB::table('agent_run_actions')->where('id', $actionId)->value('outcome'),
            "a stalled delegation's own parent action must close as Unfinished, mirroring forceFinalizeBatchJoinTimeout()'s own run-trace-closing behavior",
        );

        $endedAt = DB::table('agent_run_actions')->where('id', $actionId)->value('ended_at');
        $this->assertNotNull($endedAt, 'a closed action must have a non-null ended_at');
    }

    #[Test]
    public function when_parent_action_id_is_null_no_run_trace_action_is_touched(): void
    {
        $delegation = $this->makeDelegation('in_progress', ['parent_action_id' => null]);

        // Must not throw despite there being no action to close.
        app(DelegationService::class)->forceFinalizeStalledDelegation($delegation);

        $delegation->refresh();
        $this->assertSame('exhausted', $delegation->status);
    }

    // =================================================================
    // Already-terminal row: no-op
    // =================================================================

    public static function terminalStatusProvider(): array
    {
        return [
            'completed' => ['completed'],
            'exhausted' => ['exhausted'],
            'failed' => ['failed'],
        ];
    }

    // =================================================================
    // 110-delegation-deadlock-timeout, Phase 5 (US3, tasks.md T032,
    // FR-013): a late "in-process completion" write arriving after the
    // sweep already force-finalized the row must be discarded, not applied
    // on top of the already-resolved row. forceFinalizeStalledDelegation()
    // already carries the terminal-status guard added in Phase 2 (T007),
    // so this specific assertion is expected to ALREADY PASS today --
    // written anyway since it is explicitly required coverage per
    // tasks.md (mirrors Phase 3's own precedent of a test that confirms an
    // existing guard rather than proving a new red-phase gap).
    // =================================================================

    #[Test]
    public function a_late_completion_attempt_arriving_after_the_sweep_already_finalized_the_row_is_discarded(): void
    {
        $delegation = $this->makeDelegation('in_progress');

        // The sweep finalizes it first -- the ordinary chain_stalled path.
        app(DelegationService::class)->forceFinalizeStalledDelegation($delegation);

        $delegation->refresh();
        $firstCompletedAt = $delegation->completed_at;
        $firstResultReason = $delegation->result_reason;
        $firstResultSummary = $delegation->result_summary;

        Event::fake([DelegationUpdated::class]);

        // Simulate the "actually still alive" process finally returning and
        // attempting to write its own, different outcome onto the same row
        // -- the same terminal-write path a genuine late in-process
        // completion would go through.
        app(DelegationService::class)->forceFinalizeStalledDelegation($delegation, 'late_in_process_completion');

        $delegation->refresh();

        $this->assertSame('exhausted', $delegation->status);
        $this->assertSame(
            $firstResultReason,
            $delegation->result_reason,
            'FR-013: a late write arriving after the chain is already stopped must be discarded, not applied on top of the already-resolved row',
        );
        $this->assertSame($firstResultSummary, $delegation->result_summary);
        $this->assertTrue($firstCompletedAt->equalTo($delegation->completed_at));

        // No second broadcast for the discarded late write.
        Event::assertNotDispatched(DelegationUpdated::class);
    }

    // =================================================================
    // Reconciliation (FR-013, spec.md Edge Cases, quickstart.md Scenario
    // 7): the test above proves forceFinalizeStalledDelegation()'s OWN
    // guard is idempotent -- it re-enters the very method that already
    // carried a terminal-status check, so it can only ever re-confirm
    // that check. A genuinely late result does not arrive through that
    // method at all: it arrives through runDelegatedTask(), the terminal
    // write a still-alive process performs when its nested run() finally
    // returns, and that path had NO terminal-status guard of its own --
    // so the swept row was silently flipped back to 'completed', a second
    // DelegationUpdated fired, and the already-closed run-trace action was
    // closed again.
    //
    // This is reachable in ordinary operation, not contrived: stale +
    // idle is evidence a process is gone, never proof of it, and
    // finalizeStalledChain() deliberately finalizes a trigger row's
    // ancestors without checking their own idleness at all. The chain is
    // stopped here through finalizeStalledChain() -- the exact method the
    // sweep's solo branch calls (that the sweep selects such a row is
    // proven separately by tests/Feature/ResolveStalledDelegationsCommandTest
    // and tests/Integration/DelegationChainUnwindJourneyTest).
    // =================================================================

    #[Test]
    public function a_late_in_process_completion_arriving_through_the_run_path_is_discarded(): void
    {
        $helperConversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Already titled',
        ]);

        $delegation = $this->makeDelegation('in_progress', [
            'helper_conversation_id' => $helperConversation->id,
            'started_at' => now()->subMinutes(30),
        ]);

        // The sweep stops the chain while this delegation's owning process
        // is, in fact, still running.
        app(DelegationService::class)->finalizeStalledChain($delegation);

        $swept = Delegation::find($delegation->id);
        $this->assertSame('exhausted', $swept->status, 'fixture sanity: the chain must be stopped first');
        $this->assertSame('chain_stalled', $swept->result_reason);
        $sweptSummary = $swept->result_summary;
        $sweptCompletedAt = $swept->completed_at;

        // That still-alive process's nested run() now returns, completed,
        // and goes to write its own outcome. Its in-memory $delegation
        // model still reads 'in_progress', exactly as a live process's own
        // copy would.
        $agentLoopService = Mockery::mock(AgentLoopService::class);
        $agentLoopService->shouldReceive('run')->andReturn([
            'status' => 'completed',
            'content' => 'A late answer nobody is waiting for any more.',
            'validated' => [
                'status' => 'success',
                'summary' => 'Late summary that must never land.',
                'output' => ['late' => true],
                'undone' => '',
            ],
        ]);
        $this->app->instance(AgentLoopService::class, $agentLoopService);

        Event::fake([DelegationUpdated::class]);

        $method = new \ReflectionMethod(DelegationService::class, 'runDelegatedTask');
        $method->setAccessible(true);
        $result = $method->invoke(
            app(DelegationService::class),
            $delegation,
            new Agent(['name' => 'late-helper']),
            $helperConversation,
            null,
        );

        $row = Delegation::find($delegation->id);

        $this->assertSame(
            'exhausted',
            $row->status,
            'FR-013: a late result arriving after the chain has already been stopped must be discarded, never applied on top of the already-resolved row',
        );
        $this->assertSame('chain_stalled', $row->result_reason);
        $this->assertSame($sweptSummary, $row->result_summary);
        $this->assertNull($row->result_output, 'the late run\'s own output must never be written onto a stopped chain\'s row');
        $this->assertTrue(
            $sweptCompletedAt->equalTo($row->completed_at),
            'an already-resolved row\'s completed_at must never be rewritten by a late result',
        );

        // The late caller is handed back what the chain actually resolved
        // to -- never a second, contradictory account of the same row.
        $this->assertSame('failure', $result['status'] ?? null);
        $this->assertSame('chain_stalled', $result['reason'] ?? null);

        Event::assertNotDispatched(DelegationUpdated::class);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('terminalStatusProvider')]
    public function an_already_terminal_row_is_left_untouched(string $terminalStatus): void
    {
        $completedAt = now()->subMinutes(5);

        $delegation = $this->makeDelegation($terminalStatus, [
            'completed_at' => $completedAt,
            'result_status' => 'failure',
            'result_reason' => 'some_other_reason',
            'result_summary' => 'Pre-existing summary, must survive unchanged.',
        ]);

        Event::fake([DelegationUpdated::class]);

        app(DelegationService::class)->forceFinalizeStalledDelegation($delegation);

        $delegation->refresh();

        $this->assertSame($terminalStatus, $delegation->status, 'an already-terminal row\'s status must never change');
        $this->assertSame('some_other_reason', $delegation->result_reason, 'an already-terminal row\'s result_reason must never be overwritten with chain_stalled');
        $this->assertSame('Pre-existing summary, must survive unchanged.', $delegation->result_summary);
        $this->assertSame(
            $completedAt->format('Y-m-d H:i:s'),
            $delegation->completed_at->format('Y-m-d H:i:s'),
            'an already-terminal row\'s completed_at must never be rewritten',
        );

        // An already-terminal row must not fire a new DelegationUpdated broadcast.
        Event::assertNotDispatched(DelegationUpdated::class);
    }
}
