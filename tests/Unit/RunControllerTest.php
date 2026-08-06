<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 3 (T012-T015), User Story 1 — the four read endpoints RunDiagram.tsx
 * builds itself from: GET /agent-runs/{runId}, .../steps,
 * .../steps/{stepId}/actions, and .../actions/{actionId}/children
 * (contracts/run-read-api.md).
 *
 * Written before RunController has any route-handling methods (Phase 2 only
 * added the constructor + notFoundResponse() skeleton, and no route exists
 * in Routes.php yet), so every request in this file is expected to fail —
 * either against Laravel's own "route not found" 404 or, once routes exist
 * but a handler is unimplemented, differently. That failure is the correct,
 * expected state for this phase; Phase 3's implementation tasks (T018-T029)
 * are what turn these tests green.
 */
class RunControllerTest extends TestCase
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
        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();

        parent::tearDown();
    }

    private function actingAsUser(User $user)
    {
        return $this->actingAs($user, 'api');
    }

    // ========================================================================
    // T012 — GET /agent-runs/{runId}
    // ========================================================================

    #[Test]
    public function own_run_returns_200_with_run_summary_shape(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);

        $step1 = $this->recorder->openStep($runId);
        $action1 = $this->recorder->openAction($step1, ActionType::ToolInvocation, 'search_operations');
        $this->recorder->closeAction($action1, ActionOutcome::Success, null, 'result-1');
        $this->recorder->closeStep($step1, RunEndState::Completed);

        $step2 = $this->recorder->openStep($runId);
        $action2 = $this->recorder->openAction($step2, ActionType::LlmRequest, 'test-model');
        $this->recorder->closeAction($action2, ActionOutcome::Success, null, 'result-2');
        $action3 = $this->recorder->openAction($step2, ActionType::ToolInvocation, 'search_operations');
        $this->recorder->closeAction($action3, ActionOutcome::Success, null, 'result-3');
        $this->recorder->closeStep($step2, RunEndState::Completed);

        $this->recorder->closeRun($runId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id', 'kind', 'end_state', 'end_reason', 'started_at', 'ended_at',
                'duration_ms', 'step_count', 'action_count', 'conversation_id',
            ])
            ->assertJson([
                'id' => $runId,
                'kind' => 'interactive',
                'end_state' => 'completed',
                'end_reason' => null,
                'conversation_id' => null,
                'step_count' => 2,
                'action_count' => 3,
            ]);
    }

    #[Test]
    public function nonexistent_run_id_returns_404(): void
    {
        $response = $this->actingAsUser($this->user)
            ->getJson('/api/clarion-app/llm-client/agent-runs/' . (string) Str::uuid());

        $response->assertStatus(404)
            ->assertExactJson(['error' => 'Run not found', 'code' => 'run_not_found']);
    }

    #[Test]
    public function other_users_run_id_returns_identical_404_body(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        $response = $this->actingAsUser($this->otherUser)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}");

        $response->assertStatus(404)
            ->assertExactJson(['error' => 'Run not found', 'code' => 'run_not_found']);
    }

    #[Test]
    public function returns_identical_shape_for_absent_and_foreign_run(): void
    {
        // US5/FR-014 — the nonexistent-id and foreign-owned-id failure bodies
        // must be byte-identical, or the status/body split itself is a leak
        // (research.md D2).
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        $absentResponse = $this->actingAsUser($this->otherUser)
            ->getJson('/api/clarion-app/llm-client/agent-runs/' . (string) Str::uuid());

        $foreignResponse = $this->actingAsUser($this->otherUser)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}");

        $this->assertSame($absentResponse->getStatusCode(), $foreignResponse->getStatusCode());
        $this->assertSame($absentResponse->json(), $foreignResponse->json());
    }

    // ========================================================================
    // T013 — GET /agent-runs/{runId}/steps
    // ========================================================================

    #[Test]
    public function steps_endpoint_returns_ordered_by_position(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);

        // Opened out of position order, on purpose — the response must sort
        // by `position`, not by insertion/opened order.
        $step3 = $this->recorder->openStep($runId, 3);
        $step1 = $this->recorder->openStep($runId, 1);
        $step2 = $this->recorder->openStep($runId, 2);
        $this->recorder->closeStep($step3, RunEndState::Completed);
        $this->recorder->closeStep($step1, RunEndState::Completed);
        $this->recorder->closeStep($step2, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps");

        $response->assertStatus(200);
        $positions = array_column($response->json('data'), 'position');
        $this->assertSame([1, 2, 3], $positions);
    }

    #[Test]
    public function steps_endpoint_default_pagination(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        for ($i = 1; $i <= 3; $i++) {
            $step = $this->recorder->openStep($runId, $i);
            $this->recorder->closeStep($step, RunEndState::Completed);
        }

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps");

        $response->assertStatus(200)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 1);
    }

    #[Test]
    public function steps_endpoint_caps_per_page_at_200(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $step = $this->recorder->openStep($runId, 1);
        $this->recorder->closeStep($step, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps?per_page=9999");

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 200);
    }

    #[Test]
    public function zero_step_run_returns_empty_data_not_error(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        // No steps opened at all — an immediate-failure run per spec.md's edge case.

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps");

        $response->assertStatus(200)
            ->assertExactJson([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 100,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ]);
    }

    #[Test]
    public function steps_endpoint_returns_404_when_run_not_accessible(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $step = $this->recorder->openStep($runId, 1);
        $this->recorder->closeStep($step, RunEndState::Completed);

        $response = $this->actingAsUser($this->otherUser)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps");

        $response->assertStatus(404)
            ->assertExactJson(['error' => 'Run not found', 'code' => 'run_not_found']);
    }

    // ========================================================================
    // T014 — GET /agent-runs/{runId}/steps/{stepId}/actions
    // ========================================================================

    #[Test]
    public function step_actions_returns_only_top_level_actions_ordered_by_started_at(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);

        $actionA = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search_a');
        usleep(2000);
        $actionB = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search_b');
        usleep(2000);
        // Nested under A — must never appear in the top-level listing.
        $childOfA = $this->recorder->openAction($stepId, ActionType::LlmRequest, 'nested', null, $actionA);

        $this->recorder->closeAction($childOfA, ActionOutcome::Success, null, 'child-result');
        $this->recorder->closeAction($actionA, ActionOutcome::Success, null, 'a-result');
        $this->recorder->closeAction($actionB, ActionOutcome::Success, null, 'b-result');
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps/{$stepId}/actions");

        $response->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$actionA, $actionB], $ids);
    }

    #[Test]
    public function step_actions_has_children_flag_correct(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);

        $actionA = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search_a');
        $actionB = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search_b');
        $childOfA = $this->recorder->openAction($stepId, ActionType::LlmRequest, 'nested', null, $actionA);

        $this->recorder->closeAction($childOfA, ActionOutcome::Success, null, 'child-result');
        $this->recorder->closeAction($actionA, ActionOutcome::Success, null, 'a-result');
        $this->recorder->closeAction($actionB, ActionOutcome::Success, null, 'b-result');
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps/{$stepId}/actions");

        $response->assertStatus(200);
        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($byId[$actionA]['has_children']);
        $this->assertFalse($byId[$actionB]['has_children']);
    }

    #[Test]
    public function step_actions_default_pagination(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);

        for ($i = 0; $i < 3; $i++) {
            $action = $this->recorder->openAction($stepId, ActionType::ToolInvocation, "search_$i");
            $this->recorder->closeAction($action, ActionOutcome::Success, null, "result-$i");
        }
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps/{$stepId}/actions");

        $response->assertStatus(200)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 3);
    }

    #[Test]
    public function step_actions_caps_per_page_at_100(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $action = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search');
        $this->recorder->closeAction($action, ActionOutcome::Success, null, 'result');
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps/{$stepId}/actions?per_page=9999");

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    #[Test]
    public function step_actions_never_includes_content_key(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $actionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search');
        $this->recorder->closeAction($actionId, ActionOutcome::Success, null, 'sensitive result content');
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps/{$stepId}/actions");

        $response->assertStatus(200);
        foreach ($response->json('data') as $action) {
            $this->assertArrayNotHasKey('content', $action);
        }
    }

    #[Test]
    public function step_actions_returns_404_when_step_not_accessible(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $response = $this->actingAsUser($this->otherUser)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps/{$stepId}/actions");

        $response->assertStatus(404)
            ->assertExactJson(['error' => 'Run not found', 'code' => 'run_not_found']);
    }

    #[Test]
    public function step_actions_returns_404_for_nonexistent_step(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps/" . (string) Str::uuid() . '/actions');

        $response->assertStatus(404)
            ->assertExactJson(['error' => 'Run not found', 'code' => 'run_not_found']);
    }

    // ========================================================================
    // T015 — GET /agent-runs/{runId}/actions/{actionId}/children
    // ========================================================================

    #[Test]
    public function action_children_returns_only_direct_children_ordered_by_started_at(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);

        $parentAction = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'parent_op');
        $childA = $this->recorder->openAction($stepId, ActionType::LlmRequest, 'child_a', null, $parentAction);
        usleep(2000);
        $childB = $this->recorder->openAction($stepId, ActionType::LlmRequest, 'child_b', null, $parentAction);
        usleep(2000);
        // Grandchild — nested under childA, must never appear when listing parentAction's children.
        $grandchild = $this->recorder->openAction($stepId, ActionType::LlmRequest, 'grandchild', null, $childA);

        $this->recorder->closeAction($grandchild, ActionOutcome::Success, null, 'grandchild-result');
        $this->recorder->closeAction($childA, ActionOutcome::Success, null, 'child-a-result');
        $this->recorder->closeAction($childB, ActionOutcome::Success, null, 'child-b-result');
        $this->recorder->closeAction($parentAction, ActionOutcome::Success, null, 'parent-result');
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/actions/{$parentAction}/children");

        $response->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$childA, $childB], $ids);
    }

    #[Test]
    public function action_children_has_children_flag_correct(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);

        $parentAction = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'parent_op');
        $childA = $this->recorder->openAction($stepId, ActionType::LlmRequest, 'child_a', null, $parentAction);
        $childB = $this->recorder->openAction($stepId, ActionType::LlmRequest, 'child_b', null, $parentAction);
        $grandchild = $this->recorder->openAction($stepId, ActionType::LlmRequest, 'grandchild', null, $childA);

        $this->recorder->closeAction($grandchild, ActionOutcome::Success, null, 'grandchild-result');
        $this->recorder->closeAction($childA, ActionOutcome::Success, null, 'child-a-result');
        $this->recorder->closeAction($childB, ActionOutcome::Success, null, 'child-b-result');
        $this->recorder->closeAction($parentAction, ActionOutcome::Success, null, 'parent-result');
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/actions/{$parentAction}/children");

        $response->assertStatus(200);
        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($byId[$childA]['has_children']);
        $this->assertFalse($byId[$childB]['has_children']);
    }

    #[Test]
    public function action_children_default_pagination(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $parentAction = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'parent_op');

        for ($i = 0; $i < 3; $i++) {
            $child = $this->recorder->openAction($stepId, ActionType::LlmRequest, "child_$i", null, $parentAction);
            $this->recorder->closeAction($child, ActionOutcome::Success, null, "child-result-$i");
        }
        $this->recorder->closeAction($parentAction, ActionOutcome::Success, null, 'parent-result');
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/actions/{$parentAction}/children");

        $response->assertStatus(200)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 3);
    }

    #[Test]
    public function action_children_caps_per_page_at_100(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $parentAction = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'parent_op');
        $child = $this->recorder->openAction($stepId, ActionType::LlmRequest, 'child', null, $parentAction);
        $this->recorder->closeAction($child, ActionOutcome::Success, null, 'child-result');
        $this->recorder->closeAction($parentAction, ActionOutcome::Success, null, 'parent-result');
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/actions/{$parentAction}/children?per_page=9999");

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    #[Test]
    public function action_children_never_includes_content_key(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $parentAction = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'parent_op');
        $child = $this->recorder->openAction($stepId, ActionType::LlmRequest, 'child', null, $parentAction);
        $this->recorder->closeAction($child, ActionOutcome::Success, null, 'sensitive child content');
        $this->recorder->closeAction($parentAction, ActionOutcome::Success, null, 'sensitive parent content');
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/actions/{$parentAction}/children");

        $response->assertStatus(200);
        foreach ($response->json('data') as $action) {
            $this->assertArrayNotHasKey('content', $action);
        }
    }

    #[Test]
    public function action_children_returns_404_when_action_not_accessible(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder->openStep($runId);
        $parentAction = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'parent_op');
        $this->recorder->closeAction($parentAction, ActionOutcome::Success, null, 'parent-result');
        $this->recorder->closeStep($stepId, RunEndState::Completed);

        $response = $this->actingAsUser($this->otherUser)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/actions/{$parentAction}/children");

        $response->assertStatus(404)
            ->assertExactJson(['error' => 'Run not found', 'code' => 'run_not_found']);
    }

    #[Test]
    public function action_children_returns_404_for_nonexistent_action(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/actions/" . (string) Str::uuid() . '/children');

        $response->assertStatus(404)
            ->assertExactJson(['error' => 'Run not found', 'code' => 'run_not_found']);
    }
}
