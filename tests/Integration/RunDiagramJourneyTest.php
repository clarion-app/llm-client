<?php

namespace ClarionApp\LlmClient\Tests\Integration;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 3 (T016), User Story 1 — end-to-end proof that the four paginated
 * read endpoints (GET /agent-runs/{runId}, .../steps,
 * .../steps/{stepId}/actions, .../actions/{actionId}/children) reconstruct
 * the same run shape a direct RunTraceQuery read would produce, for a run
 * exercising every US1 acceptance scenario at once:
 *
 *   - nested actions (an action under another action),
 *   - one failed step,
 *   - one failed action,
 *   - two actions under the same step with overlapping [started_at, ended_at]
 *     ranges (FR-019).
 *
 * Written before RunController has any route-handling methods and before
 * RunTraceQuery::actionSummariesForStep()/actionSummaryChildren() exist
 * (both are Phase 3 implementation tasks, T018/T019/T020-T024) — every
 * assertion here is expected to fail, either against a missing route or an
 * undefined RunTraceQuery method. That is the correct state for this phase.
 */
class RunDiagramJourneyTest extends TestCase
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
    public function run_with_nested_failed_and_overlapping_elements_reconstructs_via_the_four_endpoints(): void
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);

        // --- Step 1: fails outright, carries one failed action (US1 scenarios 3-4).
        $step1 = $this->recorder->openStep($runId, 1);
        $failedAction = $this->recorder->openAction($step1, ActionType::ToolInvocation, 'flaky_operation');
        $this->recorder->closeAction($failedAction, ActionOutcome::Failure, 'Operation timed out after 30s');
        $this->recorder->closeStep($step1, RunEndState::Failed, 'downstream tool failed');

        // --- Step 2: nested action (action under action) + two overlapping
        // top-level actions (US1 scenarios 1-2, FR-019).
        $step2 = $this->recorder->openStep($runId, 2);

        $parentAction = $this->recorder->openAction($step2, ActionType::ToolInvocation, 'parent_op');
        $childAction = $this->recorder->openAction($step2, ActionType::LlmRequest, 'nested_model', null, $parentAction);
        $this->recorder->closeAction($childAction, ActionOutcome::Success, null, 'child content');
        $this->recorder->closeAction($parentAction, ActionOutcome::Success, null, 'parent content');

        // Overlap by construction: open Q, open R before closing Q, close Q,
        // then close R — Q.[started,ended] and R.[started,ended] overlap.
        $overlapQ = $this->recorder->openAction($step2, ActionType::ToolInvocation, 'overlap_q');
        $overlapR = $this->recorder->openAction($step2, ActionType::ToolInvocation, 'overlap_r');
        $this->recorder->closeAction($overlapQ, ActionOutcome::Success, null, 'q content');
        $this->recorder->closeAction($overlapR, ActionOutcome::Success, null, 'r content');

        $this->recorder->closeStep($step2, RunEndState::Completed);

        $this->recorder->closeRun($runId, RunEndState::Completed);

        // Precondition: the run really has the shape this scenario claims.
        $this->assertSame(5, DB::table('agent_run_actions')->where('run_id', $runId)->count());

        $client = $this->actingAs($this->user, 'api');

        // === 1. GET /agent-runs/{runId} ===
        $runResponse = $client->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}");
        $runResponse->assertStatus(200)
            ->assertJson([
                'id' => $runId,
                'end_state' => 'completed',
                'step_count' => 2,
                'action_count' => 5,
            ]);

        // === 2. GET /agent-runs/{runId}/steps ===
        $stepsResponse = $client->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps");
        $stepsResponse->assertStatus(200);
        $stepsById = collect($stepsResponse->json('data'))->keyBy('id');

        $this->assertSame('failed', $stepsById[$step1]['end_state']);
        $this->assertSame(1, $stepsById[$step1]['action_count']);
        $this->assertSame('completed', $stepsById[$step2]['end_state']);
        $this->assertSame(4, $stepsById[$step2]['action_count']);

        // === 3. GET /agent-runs/{runId}/steps/{step1}/actions — the failed action ===
        $step1ActionsResponse = $client->getJson(
            "/api/clarion-app/llm-client/agent-runs/{$runId}/steps/{$step1}/actions"
        );
        $step1ActionsResponse->assertStatus(200);
        $step1Actions = $step1ActionsResponse->json('data');
        $this->assertCount(1, $step1Actions);
        $this->assertSame($failedAction, $step1Actions[0]['id']);
        $this->assertSame('failure', $step1Actions[0]['outcome']);
        $this->assertSame('Operation timed out after 30s', $step1Actions[0]['failure_reason']);
        $this->assertFalse($step1Actions[0]['has_children']);
        $this->assertArrayNotHasKey('content', $step1Actions[0]);

        // === 4. GET /agent-runs/{runId}/steps/{step2}/actions — top-level only ===
        $step2ActionsResponse = $client->getJson(
            "/api/clarion-app/llm-client/agent-runs/{$runId}/steps/{$step2}/actions"
        );
        $step2ActionsResponse->assertStatus(200);
        $step2Actions = collect($step2ActionsResponse->json('data'))->keyBy('id');

        // Exactly the three top-level actions — never the nested child.
        $this->assertCount(3, $step2Actions);
        $this->assertSame(
            [$parentAction, $overlapQ, $overlapR],
            $step2Actions->keys()->all(),
            'Top-level actions under step 2, ordered by started_at (construction order)'
        );
        $this->assertArrayNotHasKey($childAction, $step2Actions->toArray());
        $this->assertTrue($step2Actions[$parentAction]['has_children']);
        $this->assertFalse($step2Actions[$overlapQ]['has_children']);
        $this->assertFalse($step2Actions[$overlapR]['has_children']);

        // FR-019: Q and R's [started_at, ended_at] ranges overlap by
        // construction (Q opened, then R opened before Q closed, then Q
        // closed, then R closed) — R starts strictly before Q ends.
        $qStart = $step2Actions[$overlapQ]['started_at'];
        $qEnd = $step2Actions[$overlapQ]['ended_at'];
        $rStart = $step2Actions[$overlapR]['started_at'];
        // assertLessThanOrEqual(expected, actual) asserts actual <= expected.
        $this->assertLessThanOrEqual($rStart, $qStart, 'Q opened before R (construction order)');
        // assertLessThan(expected, actual) asserts actual < expected.
        $this->assertLessThan($qEnd, $rStart, 'R must start before Q ends, or there is no overlap to render');

        // === 5. GET /agent-runs/{runId}/actions/{parentAction}/children — the nested action ===
        $childrenResponse = $client->getJson(
            "/api/clarion-app/llm-client/agent-runs/{$runId}/actions/{$parentAction}/children"
        );
        $childrenResponse->assertStatus(200);
        $children = $childrenResponse->json('data');
        $this->assertCount(1, $children);
        $this->assertSame($childAction, $children[0]['id']);
        $this->assertArrayNotHasKey('content', $children[0]);

        // === Reconciliation against a direct RunTraceQuery read (data-model.md §2) ===
        // These two methods are new, Phase-3 additions (T018/T019) that do not
        // exist yet — calling them here is expected to fail with "call to
        // undefined method" until Phase 3's implementation tasks land.
        $query = $this->app->make(RunTraceQuery::class);

        $directStep2Actions = collect($query->actionSummariesForStep($this->user->id, $step2, 1, 50)['data'])
            ->keyBy('id');
        $this->assertSame(
            $step2Actions->keys()->sort()->values()->all(),
            $directStep2Actions->keys()->sort()->values()->all(),
            'HTTP step-actions listing must match a direct RunTraceQuery::actionSummariesForStep() read'
        );

        $directChildren = collect($query->actionSummaryChildren($this->user->id, $parentAction, 1, 50)['data'])
            ->keyBy('id');
        $this->assertSame(
            [$childAction],
            $directChildren->keys()->all(),
            'HTTP action-children listing must match a direct RunTraceQuery::actionSummaryChildren() read'
        );
    }
}
