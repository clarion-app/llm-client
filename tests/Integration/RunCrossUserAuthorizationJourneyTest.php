<?php

namespace ClarionApp\LlmClient\Tests\Integration;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 5 (T045), User Story 5 — the REST-only portion of US5's cross-user
 * denial guarantee (Acceptance Scenarios 1, 4; FR-014; research.md D2).
 *
 * User A creates a run with steps and nested actions (including one with
 * recorded content). User B — an authenticated but entirely unrelated user
 * — then issues an HTTP request against every one of the five endpoint
 * routes Phases 3-4 added, using User A's real, previously-seen ids. The
 * action-detail route is exercised twice: once for a top-level action and
 * once for a nested child action, for six requests total. Every one of the
 * six must come back as the identical uniform 404
 * `{"error": "Run not found", "code": "run_not_found"}` — no step, action,
 * or content from User A's run is ever exposed to User B, and the outcome
 * is indistinguishable from requesting a run id that does not exist at all
 * (spec.md US5 Independent Test, REST-reachable subset).
 *
 * Structurally mirrors life-log-backend's
 * tests/Integration/UserChannelAuthorizationTest.php (T045's own reference
 * point): two real users, one real owned resource, and an assertion that
 * the *other* user is refused identically regardless of which sub-resource
 * they ask for.
 *
 * Per this phase's own header, Phases 3-4 already built every one of these
 * endpoints on top of RunTraceQuery::findRun()'s (or the equivalent
 * step/action -> run -> user_id lookup's) existing ownership check, so this
 * test is expected to PASS immediately — it is proving, not creating,
 * the authorization boundary.
 */
class RunCrossUserAuthorizationJourneyTest extends TestCase
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
    public function user_b_is_refused_identically_across_every_endpoint_for_user_as_run(): void
    {
        // --- User A's run: steps, a nested action, and one action with
        // recorded content, so a leak of any kind (step listing, top-level
        // action listing, nested-child listing, or content) would be
        // detectable by this test's own assertions.
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->userA->id);

        $stepId = $this->recorder->openStep($runId, 1);

        $parentAction = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search_operations');
        $childAction = $this->recorder->openAction(
            $stepId,
            ActionType::LlmRequest,
            'nested-model',
            null,
            $parentAction,
        );
        $this->recorder->closeAction($childAction, ActionOutcome::Success, null, 'nested child content');
        $this->recorder->closeAction($parentAction, ActionOutcome::Success, null, 'top-level parent content');

        $this->recorder->closeStep($stepId, RunEndState::Completed);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        $uniform404 = ['error' => 'Run not found', 'code' => 'run_not_found'];

        $userB = $this->actingAs($this->userB, 'api');

        // 1. GET /agent-runs/{runId}
        $userB->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}")
            ->assertStatus(404)
            ->assertExactJson($uniform404);

        // 2. GET /agent-runs/{runId}/steps
        $userB->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps")
            ->assertStatus(404)
            ->assertExactJson($uniform404);

        // 3. GET /agent-runs/{runId}/steps/{stepId}/actions
        $userB->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps/{$stepId}/actions")
            ->assertStatus(404)
            ->assertExactJson($uniform404);

        // 4. GET /agent-runs/{runId}/actions/{parentAction}/children
        $userB->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/actions/{$parentAction}/children")
            ->assertStatus(404)
            ->assertExactJson($uniform404);

        // 5. GET /agent-runs/{runId}/actions/{parentAction} — top-level action detail
        $userB->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/actions/{$parentAction}")
            ->assertStatus(404)
            ->assertExactJson($uniform404);

        // 6. GET /agent-runs/{runId}/actions/{childAction} — nested child action detail
        $userB->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/actions/{$childAction}")
            ->assertStatus(404)
            ->assertExactJson($uniform404);
    }
}
