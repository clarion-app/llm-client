<?php

namespace ClarionApp\LlmClient\Tests\Integration;

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
 * Phase 6 (T057), User Story 5 — completes US5 Acceptance Scenario 3 /
 * FR-015, the one live-update-non-leak scenario Phase 5 could not close on
 * its own because no broadcast event existed yet to inspect
 * (tasks.md Phase 5/6 header notes).
 *
 * User A's in-progress run fires RunStepUpdated/RunActionUpdated/RunUpdated
 * as RunTraceRecorder records it. Event::fake() intercepts the actual
 * broadcast (no live Pusher connection needed) while still constructing
 * each real event object, so this test can call the event's own
 * broadcastOn() directly inside the assertion callback and confirm it
 * always resolves to user A's private channel — and never to user B's, a
 * real, distinct, authenticated user created in this same test — for every
 * single dispatched instance, not just the first one Event::assertDispatched
 * happens to match.
 *
 * Written before ClarionApp\LlmClient\Events\{RunUpdated,RunStepUpdated,
 * RunActionUpdated} exist and before RunTraceRecorder fires any of them —
 * every assertion here is expected to fail (either a class-not-found error
 * or an "event was not dispatched" assertion failure) until Phase 6's
 * implementation tasks land. That failure is the correct, expected state
 * for this phase.
 */
class RunLiveUpdateNonLeakTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;
    private User $userB;
    private RunTraceRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
        $this->recorder = $this->app->make(RunTraceRecorder::class);
    }

    protected function tearDown(): void
    {
        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();

        parent::tearDown();
    }

    #[Test]
    public function run_step_and_action_events_resolve_only_to_the_owners_channel_never_the_other_users(): void
    {
        Event::fake([RunUpdated::class, RunStepUpdated::class, RunActionUpdated::class]);

        $runId = $this->recorder->openRun(RunKind::Interactive, $this->userA->id);
        $stepId = $this->recorder->openStep($runId);
        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search_operations');
        $this->recorder->closeAction($actionId, ActionOutcome::Success, null, 'result');
        $this->recorder->closeStep($stepId, RunEndState::Completed);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        $ownerChannel = 'private-User.' . $this->userA->id;
        $foreignChannel = 'private-User.' . $this->userB->id;

        // At least one RunStepUpdated (open) and at least one
        // RunActionUpdated (open) and exactly one RunUpdated (close) must
        // have fired — assert each resolves to A's channel only.
        Event::assertDispatched(RunStepUpdated::class, function (RunStepUpdated $event) use ($ownerChannel, $foreignChannel) {
            $names = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            return $names === [$ownerChannel] && !in_array($foreignChannel, $names, true);
        });

        Event::assertDispatched(RunActionUpdated::class, function (RunActionUpdated $event) use ($ownerChannel, $foreignChannel) {
            $names = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            return $names === [$ownerChannel] && !in_array($foreignChannel, $names, true);
        });

        Event::assertDispatched(RunUpdated::class, function (RunUpdated $event) use ($ownerChannel, $foreignChannel) {
            $names = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            return $names === [$ownerChannel] && !in_array($foreignChannel, $names, true);
        });
    }

    #[Test]
    public function every_dispatched_run_step_updated_instance_individually_excludes_the_other_users_channel(): void
    {
        // Belt-and-suspenders over the test above: open+close two steps so
        // RunStepUpdated fires multiple times, and check every dispatched
        // instance (not merely the first Event::assertDispatched match).
        Event::fake([RunStepUpdated::class]);

        $runId = $this->recorder->openRun(RunKind::Interactive, $this->userA->id);
        $step1 = $this->recorder->openStep($runId);
        $this->recorder->closeStep($step1, RunEndState::Completed);
        $step2 = $this->recorder->openStep($runId);
        $this->recorder->closeStep($step2, RunEndState::Completed);

        $ownerChannel = 'private-User.' . $this->userA->id;
        $foreignChannel = 'private-User.' . $this->userB->id;

        Event::assertDispatchedTimes(RunStepUpdated::class, 4); // open + close, twice

        Event::assertDispatched(RunStepUpdated::class, function (RunStepUpdated $event) use ($ownerChannel, $foreignChannel) {
            $names = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            $this->assertNotContains($foreignChannel, $names);
            $this->assertSame([$ownerChannel], $names);
            return true;
        });
    }

    #[Test]
    public function user_b_is_never_named_by_broadcast_on_even_when_user_b_also_has_a_run_in_progress(): void
    {
        // The strongest version of the non-leak guarantee: user B is not
        // merely absent, they have their own concurrent, in-progress run —
        // proving isolation is structural (per-owner channel resolution),
        // not merely "there was nothing to leak."
        Event::fake([RunStepUpdated::class, RunActionUpdated::class, RunUpdated::class]);

        $runIdA = $this->recorder->openRun(RunKind::Interactive, $this->userA->id);
        $stepIdA = $this->recorder->openStep($runIdA);
        $actionIdA = $this->recorder->openAction($stepIdA, ActionType::ToolInvocation, 'a_op');
        $this->recorder->closeAction($actionIdA, ActionOutcome::Success, null, 'a-result');

        $runIdB = $this->recorder->openRun(RunKind::Interactive, $this->userB->id);
        $stepIdB = $this->recorder->openStep($runIdB);
        $actionIdB = $this->recorder->openAction($stepIdB, ActionType::ToolInvocation, 'b_op');
        $this->recorder->closeAction($actionIdB, ActionOutcome::Success, null, 'b-result');

        $ownerAChannel = 'private-User.' . $this->userA->id;
        $ownerBChannel = 'private-User.' . $this->userB->id;

        Event::assertDispatched(RunActionUpdated::class, function (RunActionUpdated $event) use ($actionIdA, $ownerAChannel, $ownerBChannel) {
            if ($event->actionId !== $actionIdA) {
                return false;
            }
            $names = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            return $names === [$ownerAChannel] && !in_array($ownerBChannel, $names, true);
        });

        Event::assertDispatched(RunActionUpdated::class, function (RunActionUpdated $event) use ($actionIdB, $ownerAChannel, $ownerBChannel) {
            if ($event->actionId !== $actionIdB) {
                return false;
            }
            $names = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            return $names === [$ownerBChannel] && !in_array($ownerAChannel, $names, true);
        });
    }
}
