<?php

namespace ClarionApp\LlmClient\Tests\Unit;

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
 * 106-multi-agent-run-view, Phase 3 (US1), tasks.md T008.
 *
 * `GET /agent-runs/{runId}/arrangement` (contracts/arrangement-api.md §1):
 * an owned run with delegations returns 200 with the documented shape; an
 * owned run with none returns 200 has_delegations:false; a
 * nonexistent/foreign run returns the same uniform 404
 * RunController::notFoundResponse() already returns for every other
 * endpoint on this controller (FR-014).
 *
 * Mirrors RunControllerTest.php's own established RefreshDatabase +
 * RunTraceRecorder pattern -- Delegation rows are created directly, the
 * same "pure read-path controller, real row to read back" precedent
 * DelegationQueryControllerTest.php's makeDelegationRow() already
 * established, kept minimal here since ownership/ shape are this file's
 * only concern (no agent-name resolution assertion -- that belongs to
 * DelegationQueryArrangementTest.php, which already covers it directly
 * against the service).
 */
class RunControllerArrangementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private RunTraceRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
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

    private function actingAsUser(User $user)
    {
        return $this->actingAs($user, 'api');
    }

    private function makeDelegationRow(User $owner, string $parentRunId, ?string $helperRunId, array $overrides = []): Delegation
    {
        return Delegation::create(array_merge([
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $owner->id,
            'task' => 'Extract line items from the attached invoice text.',
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
    public function owned_run_with_delegations_returns_200_with_the_documented_shape(): void
    {
        $rootRunId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $this->recorder->closeRun($rootRunId, RunEndState::Completed);

        $helperRunId = $this->recorder->openRun(RunKind::SystemInitiated, $this->user->id);
        $this->recorder->closeRun($helperRunId, RunEndState::Completed);

        $delegation = $this->makeDelegationRow($this->user, $rootRunId, $helperRunId);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$rootRunId}/arrangement");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'root_run_id', 'has_delegations', 'truncated',
                'runs', 'delegations' => [
                    '*' => [
                        'id', 'parent_run_id', 'parent_action_id', 'helper_run_id',
                        'helper_agent_id', 'helper_agent_name', 'depth', 'status',
                        'batch_id', 'started_at', 'completed_at',
                    ],
                ],
            ])
            ->assertJson([
                'root_run_id' => $rootRunId,
                'has_delegations' => true,
                'truncated' => false,
            ]);

        $body = $response->json();
        $this->assertArrayHasKey($rootRunId, $body['runs']);
        $this->assertArrayHasKey($helperRunId, $body['runs']);
        $this->assertCount(1, $body['delegations']);
        $this->assertSame($delegation->id, $body['delegations'][0]['id']);
    }

    #[Test]
    public function owned_run_with_no_delegations_returns_200_has_delegations_false(): void
    {
        $rootRunId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $this->recorder->closeRun($rootRunId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$rootRunId}/arrangement");

        $response->assertStatus(200)
            ->assertJson([
                'root_run_id' => $rootRunId,
                'has_delegations' => false,
                'truncated' => false,
                'delegations' => [],
            ]);
    }

    #[Test]
    public function nonexistent_run_returns_404(): void
    {
        $response = $this->actingAsUser($this->user)
            ->getJson('/api/clarion-app/llm-client/agent-runs/' . (string) Str::uuid() . '/arrangement');

        $response->assertStatus(404)
            ->assertExactJson(['error' => 'Run not found', 'code' => 'run_not_found']);
    }

    #[Test]
    public function foreign_owned_run_returns_404(): void
    {
        $rootRunId = $this->recorder->openRun(RunKind::Interactive, $this->otherUser->id);
        $this->recorder->closeRun($rootRunId, RunEndState::Completed);
        $this->makeDelegationRow($this->otherUser, $rootRunId, null);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$rootRunId}/arrangement");

        $response->assertStatus(404)
            ->assertExactJson(['error' => 'Run not found', 'code' => 'run_not_found']);
    }

    #[Test]
    public function returns_identical_shape_for_absent_and_foreign_run(): void
    {
        $rootRunId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $this->recorder->closeRun($rootRunId, RunEndState::Completed);
        $this->makeDelegationRow($this->user, $rootRunId, null);

        $requester = $this->actingAsUser($this->otherUser);
        $randomUuid = (string) Str::uuid();

        $absentResponse = $requester->getJson(
            "/api/clarion-app/llm-client/agent-runs/{$randomUuid}/arrangement",
        );
        $foreignResponse = $requester->getJson(
            "/api/clarion-app/llm-client/agent-runs/{$rootRunId}/arrangement",
        );

        $this->assertSame($absentResponse->getStatusCode(), $foreignResponse->getStatusCode());
        $this->assertSame($absentResponse->json(), $foreignResponse->json());
    }
}
