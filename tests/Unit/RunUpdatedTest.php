<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\RunUpdated;
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
 * Phase 6 (T054), User Story 3 — data-model.md §4.1 /
 * contracts/run-realtime-events.md's RunUpdated event: broadcastOn()
 * resolves to the run owner's already-hardened PrivateChannel('User.{id}')
 * (research.md D1) for an existing run, and to [] when the run has since
 * been purged; broadcastWith() matches exactly what GET /agent-runs/{runId}
 * would return for the same id at the same instant (research.md D3's
 * "payload freshness" — resolved from the database at broadcast time, not
 * from constructor-captured values).
 *
 * Written before ClarionApp\LlmClient\Events\RunUpdated exists — every test
 * here is expected to fail with a class-not-found error until the class is
 * created (Phase 6 implementation, T059). That failure is the correct,
 * expected state for this phase.
 */
class RunUpdatedTest extends TestCase
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
    public function broadcast_on_resolves_to_the_owners_private_channel_for_an_existing_run(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        $event = new RunUpdated($runId);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-User.' . $this->user->id, (string) $channels[0]);
    }

    #[Test]
    public function broadcast_on_resolves_to_empty_array_when_run_has_since_been_purged(): void
    {
        $event = new RunUpdated((string) Str::uuid());

        $this->assertSame([], $event->broadcastOn());
    }

    #[Test]
    public function broadcast_with_payload_matches_rest_run_summary_shape_and_values(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search_operations');
        $this->recorder->closeAction($actionId, ActionOutcome::Success, null, 'result');
        $this->recorder->closeStep($stepId, RunEndState::Completed);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        $restResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}");
        $restResponse->assertStatus(200);

        $event = new RunUpdated($runId);
        $payload = $event->broadcastWith();

        $this->assertSame($restResponse->json(), $payload);
    }

    #[Test]
    public function broadcast_with_reflects_the_current_terminal_state_at_broadcast_time(): void
    {
        // Payload freshness (research.md D3): resolved from the database at
        // broadcast time, not captured at construction — so a payload built
        // right after the terminal write reflects that write, never a
        // stale in-progress snapshot.
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $event = new RunUpdated($runId);

        $this->recorder->closeRun($runId, RunEndState::Failed, 'boom');

        $payload = $event->broadcastWith();

        $this->assertSame('failed', $payload['end_state']);
        $this->assertSame('boom', $payload['end_reason']);
    }
}
