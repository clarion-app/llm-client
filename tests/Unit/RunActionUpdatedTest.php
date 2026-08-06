<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\RunActionUpdated;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 6 (T056), User Story 3 — data-model.md §4.3 /
 * contracts/run-realtime-events.md's RunActionUpdated event: same three
 * properties as RunUpdatedTest (T054)/RunStepUpdatedTest (T055), with owner
 * resolution via action -> run -> user_id, payload matching the
 * ActionSummary shape GET .../steps/{stepId}/actions (or .../children)
 * would return for the same action id — and, critically, the payload must
 * never contain a `content` key (FR-011 on the push channel too,
 * research.md D3; quickstart.md mutation-testing row 8).
 *
 * Written before ClarionApp\LlmClient\Events\RunActionUpdated exists —
 * every test here is expected to fail with a class-not-found error until
 * the class is created (Phase 6 implementation, T061). That failure is the
 * correct, expected state for this phase.
 */
class RunActionUpdatedTest extends TestCase
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

    #[Test]
    public function broadcast_on_resolves_to_the_owners_private_channel_via_action_run_user_id(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search_operations');
        $this->recorder->closeAction($actionId, ActionOutcome::Success, null, 'result');

        $event = new RunActionUpdated($actionId);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-User.' . $this->user->id, (string) $channels[0]);
    }

    #[Test]
    public function broadcast_on_resolves_to_empty_array_when_action_has_since_been_purged(): void
    {
        $event = new RunActionUpdated((string) Str::uuid());

        $this->assertSame([], $event->broadcastOn());
    }

    #[Test]
    public function broadcast_with_payload_matches_rest_action_summary_shape_and_values(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search_operations');
        $this->recorder->closeAction($actionId, ActionOutcome::Success, null, 'sensitive result content');

        $restResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps/{$stepId}/actions");
        $restResponse->assertStatus(200);

        $restAction = collect($restResponse->json('data'))
            ->firstWhere('id', $actionId);
        $this->assertNotNull($restAction, 'Expected the just-closed action to appear in the REST step-actions listing.');

        $event = new RunActionUpdated($actionId);
        $payload = $event->broadcastWith();

        $this->assertSame($restAction, $payload);
    }

    #[Test]
    public function broadcast_with_payload_matches_rest_shape_for_a_nested_child_action(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $parentAction = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'parent_op');
        $childAction = $this->recorder->openAction($stepId, ActionType::LlmRequest, 'child_op', null, $parentAction);
        $this->recorder->closeAction($childAction, ActionOutcome::Success, null, 'child content');

        $restResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/actions/{$parentAction}/children");
        $restResponse->assertStatus(200);

        $restChild = collect($restResponse->json('data'))->firstWhere('id', $childAction);
        $this->assertNotNull($restChild);

        $event = new RunActionUpdated($childAction);
        $payload = $event->broadcastWith();

        $this->assertSame($restChild, $payload);
    }

    #[Test]
    public function payload_never_contains_content(): void
    {
        // quickstart.md mutation-testing row 8: widening broadcastWith() to
        // include `content` must be caught here.
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search_operations');
        $this->recorder->closeAction($actionId, ActionOutcome::Success, null, 'sensitive result content that must never leak');

        $event = new RunActionUpdated($actionId);
        $payload = $event->broadcastWith();

        $this->assertArrayNotHasKey('content', $payload);
    }

    #[Test]
    public function payload_never_contains_content_on_the_awaiting_confirmation_suspend_branch(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search_operations');
        $this->recorder->closeAction($actionId, ActionOutcome::AwaitingConfirmation);

        $event = new RunActionUpdated($actionId);
        $payload = $event->broadcastWith();

        $this->assertArrayNotHasKey('content', $payload);
        $this->assertSame('awaiting_confirmation', $payload['outcome']);
    }
}
