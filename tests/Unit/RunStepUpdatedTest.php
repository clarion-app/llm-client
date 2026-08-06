<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\RunStepUpdated;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 6 (T055), User Story 3 — data-model.md §4.2 /
 * contracts/run-realtime-events.md's RunStepUpdated event: same three
 * properties as RunUpdatedTest (T054), but owner resolution goes through
 * step -> run -> user_id (mirroring actionsForStep()'s existing lookup
 * shape, research.md D1/D3), and the payload matches the StepSummary shape
 * GET /agent-runs/{runId}/steps would return for the same step id.
 *
 * Written before ClarionApp\LlmClient\Events\RunStepUpdated exists — every
 * test here is expected to fail with a class-not-found error until the
 * class is created (Phase 6 implementation, T060). That failure is the
 * correct, expected state for this phase.
 */
class RunStepUpdatedTest extends TestCase
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
    public function broadcast_on_resolves_to_the_owners_private_channel_via_step_run_user_id(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $event = new RunStepUpdated($stepId);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-User.' . $this->user->id, (string) $channels[0]);
    }

    #[Test]
    public function broadcast_on_resolves_to_empty_array_when_step_has_since_been_purged(): void
    {
        $event = new RunStepUpdated((string) Str::uuid());

        $this->assertSame([], $event->broadcastOn());
    }

    #[Test]
    public function broadcast_with_payload_matches_rest_step_summary_shape_and_values(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $restResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps");
        $restResponse->assertStatus(200);

        $restStep = collect($restResponse->json('data'))
            ->firstWhere('id', $stepId);
        $this->assertNotNull($restStep, 'Expected the just-closed step to appear in the REST steps listing.');

        $event = new RunStepUpdated($stepId);
        $payload = $event->broadcastWith();

        $this->assertSame($restStep, $payload);
    }

    #[Test]
    public function broadcast_with_reflects_the_current_state_at_broadcast_time_while_still_in_progress(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);

        $event = new RunStepUpdated($stepId);
        $payload = $event->broadcastWith();

        $this->assertSame('in_progress', $payload['end_state']);
        $this->assertNull($payload['ended_at']);
        $this->assertNull($payload['duration_ms']);
    }
}
