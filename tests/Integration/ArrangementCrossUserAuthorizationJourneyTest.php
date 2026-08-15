<?php

namespace ClarionApp\LlmClient\Tests\Integration;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * 106-multi-agent-run-view, Phase 3 (US1), tasks.md T009.
 *
 * End-to-end: user A's run with a nested delegation tree (a direct
 * hand-off plus one further nested delegation from that helper); user B's
 * request to `GET /agent-runs/{runId}/arrangement` gets the uniform 404 --
 * no id, name, or timing from user A's tree leaks. Mirrors
 * RunCrossUserAuthorizationJourneyTest.php (070) directly.
 */
class ArrangementCrossUserAuthorizationJourneyTest extends TestCase
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
        DB::table('agent_delegations')->delete();
        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();

        parent::tearDown();
    }

    private function makeDelegationRow(User $owner, string $parentRunId, ?string $helperRunId, array $overrides = []): Delegation
    {
        return Delegation::create(array_merge([
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $owner->id,
            'task' => 'A task only user A should ever be able to see.',
            'depth' => 1,
            'status' => 'completed',
            'batch_id' => null,
            'parent_run_id' => $parentRunId,
            'parent_action_id' => null,
            'helper_run_id' => $helperRunId,
            'outcome_summary' => 'Completed normally.',
            'started_at' => now(),
            'completed_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function user_b_is_refused_the_uniform_404_for_user_as_nested_arrangement(): void
    {
        $rootRunId = $this->recorder->openRun(RunKind::Interactive, $this->userA->id);
        $this->recorder->closeRun($rootRunId, RunEndState::Completed);

        $midRunId = $this->recorder->openRun(RunKind::SystemInitiated, $this->userA->id);
        $this->recorder->closeRun($midRunId, RunEndState::Completed);

        $leafRunId = $this->recorder->openRun(RunKind::SystemInitiated, $this->userA->id);
        $this->recorder->closeRun($leafRunId, RunEndState::Completed);

        $this->makeDelegationRow($this->userA, $rootRunId, $midRunId, ['depth' => 1]);
        $this->makeDelegationRow($this->userA, $midRunId, $leafRunId, ['depth' => 2]);

        $response = $this->actingAs($this->userB, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$rootRunId}/arrangement");

        $response->assertStatus(404)
            ->assertExactJson(['error' => 'Run not found', 'code' => 'run_not_found']);
    }
}
