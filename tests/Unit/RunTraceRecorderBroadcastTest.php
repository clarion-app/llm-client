<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\RunActionUpdated;
use ClarionApp\LlmClient\Events\RunStepUpdated;
use ClarionApp\LlmClient\Events\RunUpdated;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 6 (T053), User Story 3 — proves RunTraceRecorder's five new event()
 * call sites (research.md D3, data-model.md §4): openStep()/closeStep() fire
 * RunStepUpdated, openAction()/closeAction() fire RunActionUpdated (on every
 * outcome-changing branch), and closeRun() fires RunUpdated — each exactly
 * once per call. Also proves standing rule 7 (never-throw discipline): a
 * listener that throws while handling one of these events must not prevent
 * the underlying DB write from succeeding, and must not propagate out of the
 * recorder method that fired it. Finally proves research.md D3's documented
 * exclusions: recordStepAttempt(), recordCompletedAction(), and
 * flushUnfinishedActions() fire no event of their own.
 *
 * Written before ClarionApp\LlmClient\Events\{RunUpdated,RunStepUpdated,
 * RunActionUpdated} exist and before RunTraceRecorder has any event() call
 * sites — every Event::assertDispatched(...)/assertNotDispatched(...) assertion here
 * is expected to fail (no event of the named class is ever actually fired
 * yet), and every Event::listen()-based never-throw test is expected to
 * fail once these classes exist and RunTraceRecorder is instrumented, not
 * before. That failure is the correct, expected state for this phase.
 */
class RunTraceRecorderBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private RunTraceRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $this->user = User::factory()->create();
        $this->recorder = $this->app->make(RunTraceRecorder::class);
    }

    protected function tearDown(): void
    {
        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();

        parent::tearDown();
    }

    // ========================================================================
    // openStep() / closeStep() -> RunStepUpdated, exactly once per call
    // ========================================================================

    #[Test]
    public function open_step_fires_run_step_updated_exactly_once(): void
    {
        Event::fake([RunStepUpdated::class]);

        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);

        Event::assertDispatchedTimes(RunStepUpdated::class, 1);
        Event::assertDispatched(RunStepUpdated::class, function ($event) use ($stepId) {
            return $event->stepId === $stepId;
        });
    }

    #[Test]
    public function close_step_fires_run_step_updated_exactly_once(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);

        Event::fake([RunStepUpdated::class]);

        $this->recorder->closeStep($stepId, RunEndState::Completed);

        Event::assertDispatchedTimes(RunStepUpdated::class, 1);
        Event::assertDispatched(RunStepUpdated::class, function ($event) use ($stepId) {
            return $event->stepId === $stepId;
        });
    }

    // ========================================================================
    // openAction() / closeAction() -> RunActionUpdated, exactly once per call,
    // for every outcome-changing branch of closeAction() (research.md D3).
    // ========================================================================

    #[Test]
    public function open_action_fires_run_action_updated_exactly_once(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);

        Event::fake([RunActionUpdated::class]);

        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search');

        Event::assertDispatchedTimes(RunActionUpdated::class, 1);
        Event::assertDispatched(RunActionUpdated::class, function ($event) use ($actionId) {
            return $event->actionId === $actionId;
        });
    }

    #[Test]
    public function close_action_terminal_success_branch_fires_run_action_updated_exactly_once(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search');

        Event::fake([RunActionUpdated::class]);

        $this->recorder->closeAction($actionId, ActionOutcome::Success, null, 'result');

        Event::assertDispatchedTimes(RunActionUpdated::class, 1);
    }

    #[Test]
    public function close_action_terminal_failure_branch_fires_run_action_updated_exactly_once(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search');

        Event::fake([RunActionUpdated::class]);

        $this->recorder->closeAction($actionId, ActionOutcome::Failure, 'boom');

        Event::assertDispatchedTimes(RunActionUpdated::class, 1);
    }

    #[Test]
    public function close_action_awaiting_confirmation_suspend_branch_fires_run_action_updated_exactly_once(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search');

        Event::fake([RunActionUpdated::class]);

        $this->recorder->closeAction($actionId, ActionOutcome::AwaitingConfirmation);

        Event::assertDispatchedTimes(RunActionUpdated::class, 1);
    }

    #[Test]
    public function close_action_resolve_from_paused_branch_fires_run_action_updated_exactly_once(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search');
        $this->recorder->closeAction($actionId, ActionOutcome::AwaitingConfirmation);

        Event::fake([RunActionUpdated::class]);

        $this->recorder->closeAction($actionId, ActionOutcome::Success, null, 'resolved result');

        Event::assertDispatchedTimes(RunActionUpdated::class, 1);
    }

    // ========================================================================
    // closeRun() -> RunUpdated, exactly once per call
    // ========================================================================

    #[Test]
    public function close_run_fires_run_updated_exactly_once(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);

        Event::fake([RunUpdated::class]);

        $this->recorder->closeRun($runId, RunEndState::Completed);

        Event::assertDispatchedTimes(RunUpdated::class, 1);
        Event::assertDispatched(RunUpdated::class, function ($event) use ($runId) {
            return $event->runId === $runId;
        });
    }

    // ========================================================================
    // Standing rule 7 (never-throw discipline): a broadcast failure never
    // blocks or fails the recording write it's reporting on, and never
    // propagates to the caller.
    // ========================================================================

    #[Test]
    public function broadcast_failure_on_open_step_does_not_prevent_db_write_or_propagate(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);

        Event::listen(RunStepUpdated::class, function (): void {
            throw new \RuntimeException('Pusher unreachable');
        });

        // Must not throw despite the listener above.
        $stepId = $this->recorder->openStep($runId);

        $this->assertNotNull($stepId);
        $this->assertSame(1, DB::table('agent_run_steps')->where('id', $stepId)->count());
    }

    #[Test]
    public function broadcast_failure_on_close_step_does_not_prevent_db_write_or_propagate(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);

        Event::listen(RunStepUpdated::class, function (): void {
            throw new \RuntimeException('Pusher unreachable');
        });

        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $this->assertSame(
            RunEndState::Completed->value,
            DB::table('agent_run_steps')->where('id', $stepId)->value('end_state'),
        );
    }

    #[Test]
    public function broadcast_failure_on_open_action_does_not_prevent_db_write_or_propagate(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);

        Event::listen(RunActionUpdated::class, function (): void {
            throw new \RuntimeException('Pusher unreachable');
        });

        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search');

        $this->assertNotNull($actionId);
        $this->assertSame(1, DB::table('agent_run_actions')->where('id', $actionId)->count());
    }

    #[Test]
    public function broadcast_failure_on_close_action_does_not_prevent_db_write_or_propagate(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search');

        Event::listen(RunActionUpdated::class, function (): void {
            throw new \RuntimeException('Pusher unreachable');
        });

        $this->recorder->closeAction($actionId, ActionOutcome::Success, null, 'result');

        $this->assertSame(
            ActionOutcome::Success->value,
            DB::table('agent_run_actions')->where('id', $actionId)->value('outcome'),
        );
    }

    #[Test]
    public function broadcast_failure_on_close_run_does_not_prevent_db_write_or_propagate(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);

        Event::listen(RunUpdated::class, function (): void {
            throw new \RuntimeException('Pusher unreachable');
        });

        $this->recorder->closeRun($runId, RunEndState::Completed);

        $this->assertSame(
            RunEndState::Completed->value,
            DB::table('agent_runs')->where('id', $runId)->value('end_state'),
        );
    }

    // ========================================================================
    // research.md D3's documented exclusions — no event fired directly.
    // ========================================================================

    #[Test]
    public function record_step_attempt_fires_no_event(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);

        Event::fake([RunStepUpdated::class, RunActionUpdated::class, RunUpdated::class]);

        $this->recorder->recordStepAttempt($stepId);

        Event::assertNotDispatched(RunStepUpdated::class);
        Event::assertNotDispatched(RunActionUpdated::class);
        Event::assertNotDispatched(RunUpdated::class);
    }

    #[Test]
    public function record_completed_action_fires_no_event(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);

        Event::fake([RunStepUpdated::class, RunActionUpdated::class, RunUpdated::class]);

        $this->recorder->recordCompletedAction(
            $stepId,
            ActionType::ContextReshape,
            ActionOutcome::Success,
            new \DateTimeImmutable('-5 seconds'),
            new \DateTimeImmutable(),
            'reshape-target',
        );

        Event::assertNotDispatched(RunStepUpdated::class);
        Event::assertNotDispatched(RunActionUpdated::class);
        Event::assertNotDispatched(RunUpdated::class);
    }

    #[Test]
    public function flush_unfinished_actions_fires_no_event_of_its_own(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        // Left open on purpose — closeRun() below sweeps it via
        // flushUnfinishedActions(); D3's exclusion says that sweep itself
        // fires no RunActionUpdated, independent of whatever closeRun()
        // itself fires for the run level.
        $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'left-open');

        Event::fake([RunActionUpdated::class]);

        $this->recorder->closeRun($runId, RunEndState::Completed);

        Event::assertNotDispatched(RunActionUpdated::class);
    }
}
