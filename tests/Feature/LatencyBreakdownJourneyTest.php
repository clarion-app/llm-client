<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Journey tests for User Story 3 (spec.md, 074-latency-metrics): for an
 * individual response, an operator can see how its total time breaks down
 * into model wait, tool execution, and product processing -- surfaced
 * through the existing GET /agent-runs/{id} read (contracts/latency-api.md
 * §2, data-model.md §5), no new endpoint.
 */
class LatencyBreakdownJourneyTest extends TestCase
{
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
        foreach (['agent_run_actions', 'agent_run_steps', 'agent_runs', 'users'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    private function endpoint(string $runId): string
    {
        return "/api/clarion-app/llm-client/agent-runs/{$runId}";
    }

    /**
     * Acceptance Scenario 1 + 3: a completed response with at least one tool
     * call shows three separate non-negative durations (model_wait_ms,
     * tool_exec_ms, product_ms) which, together with confirm_wait_ms (kept
     * at 0 by this fixture so the three-term and four-term sums coincide,
     * FR-010/US4), reconcile with duration_ms (FR-007, contracts/latency-api.md
     * §2's reconciliation invariant).
     */
    #[Test]
    public function completed_response_breakdown_reconciles_with_total_duration(): void
    {
        $runId = $this->recorder->openRun(
            RunKind::Interactive,
            $this->user->id,
            streamed: false,
            model: 'claude-sonnet-5',
            agentId: 'research-assistant',
        );
        $this->assertNotNull($runId);

        $stepId = $this->recorder->openStep($runId);

        $llmActionId = $this->recorder->openAction($stepId, ActionType::LlmRequest, 'claude-sonnet-5');
        usleep(15_000);
        $this->recorder->closeAction($llmActionId, ActionOutcome::Success, null, 'model reply');

        $toolActionId = $this->recorder->openAction($stepId, ActionType::ToolInvocation, 'search_operations');
        usleep(10_000);
        $this->recorder->closeAction($toolActionId, ActionOutcome::Success, null, 'tool result');

        // No wait_ms passed -- confirm_wait_ms stays 0 for this fixture, per
        // T027's instruction to keep the three-term and four-term sums
        // unambiguous in this scenario.
        $this->recorder->closeStep($stepId, RunEndState::Completed);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        $response = $this->actingAs($this->user)->getJson($this->endpoint($runId));

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertSame(0, $body['confirm_wait_ms'], 'fixture has no confirmation pause');
        $this->assertGreaterThanOrEqual(0, $body['model_wait_ms']);
        $this->assertGreaterThanOrEqual(0, $body['tool_exec_ms']);
        $this->assertGreaterThanOrEqual(0, $body['product_ms']);
        $this->assertGreaterThan(0, $body['model_wait_ms'], 'the run made an llm_request action, so model wait must be > 0');
        $this->assertGreaterThan(0, $body['tool_exec_ms'], 'the run made a tool_invocation action, so tool exec must be > 0');

        $this->assertSame(
            $body['duration_ms'],
            $body['model_wait_ms'] + $body['tool_exec_ms'] + $body['confirm_wait_ms'] + $body['product_ms'],
            'FR-007: model_wait_ms + tool_exec_ms + confirm_wait_ms + product_ms must reconcile with duration_ms',
        );
    }

    /**
     * Acceptance Scenario 2: a completed response with no tool calls shows
     * tool_exec_ms: 0, not absent/null.
     */
    #[Test]
    public function completed_response_with_no_tool_calls_shows_zero_not_null(): void
    {
        $runId = $this->recorder->openRun(
            RunKind::Interactive,
            $this->user->id,
            streamed: false,
            model: 'claude-sonnet-5',
            agentId: 'research-assistant',
        );
        $this->assertNotNull($runId);

        $stepId = $this->recorder->openStep($runId);
        $llmActionId = $this->recorder->openAction($stepId, ActionType::LlmRequest, 'claude-sonnet-5');
        usleep(10_000);
        $this->recorder->closeAction($llmActionId, ActionOutcome::Success, null, 'model reply');
        // No tool_invocation action opened at all.
        $this->recorder->closeStep($stepId, RunEndState::Completed);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        $response = $this->actingAs($this->user)->getJson($this->endpoint($runId));

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertArrayHasKey('tool_exec_ms', $body);
        $this->assertNotNull($body['tool_exec_ms'], 'tool-execution time must be shown as zero, not absent');
        $this->assertSame(0, $body['tool_exec_ms']);
    }

    /**
     * Acceptance Scenario 4 (data-model.md §3's worked example): two tool
     * calls with overlapping execution windows -- Tool A [T0, T0+5000ms],
     * Tool B [T0+2000ms, T0+7000ms] -- contribute their merged union
     * (7000ms) once to tool_exec_ms, not their summed individual durations
     * (10000ms, mutation-checklist row 3).
     *
     * Timestamps are written directly so the two intervals' overlap is
     * exact and deterministic, rather than relying on real wall-clock
     * timing around two closeAction() calls.
     */
    #[Test]
    public function overlapping_tool_windows_are_merged_once_not_summed(): void
    {
        $runId = $this->recorder->openRun(
            RunKind::Interactive,
            $this->user->id,
            streamed: false,
            model: 'claude-sonnet-5',
            agentId: 'research-assistant',
        );
        $this->assertNotNull($runId);
        $stepId = $this->recorder->openStep($runId);
        $this->assertNotNull($stepId);

        // Backdate the run and step so a real (positive) duration_ms results
        // once closeRun() computes elapsed time against "now".
        $base = Carbon::now()->subSeconds(20);
        DB::table('agent_runs')->where('id', $runId)
            ->update(['started_at' => $base->format('Y-m-d H:i:s.u')]);
        DB::table('agent_run_steps')->where('id', $stepId)
            ->update(['started_at' => $base->format('Y-m-d H:i:s.u')]);

        $toolAStart = $base->copy();
        $toolAEnd = $base->copy()->addMilliseconds(5000);
        $toolBStart = $base->copy()->addMilliseconds(2000);
        $toolBEnd = $base->copy()->addMilliseconds(7000);

        DB::table('agent_run_actions')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => ActionType::ToolInvocation->value,
            'target' => 'tool_a',
            'attempt_group_id' => null,
            'parent_action_id' => null,
            'outcome' => ActionOutcome::Success->value,
            'failure_reason' => null,
            'paused_at' => null,
            'started_at' => $toolAStart->format('Y-m-d H:i:s.u'),
            'ended_at' => $toolAEnd->format('Y-m-d H:i:s.u'),
            'duration_ms' => 5000,
            'content' => null,
            'created_at' => $toolAStart->format('Y-m-d H:i:s.u'),
        ]);

        DB::table('agent_run_actions')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => ActionType::ToolInvocation->value,
            'target' => 'tool_b',
            'attempt_group_id' => null,
            'parent_action_id' => null,
            'outcome' => ActionOutcome::Success->value,
            'failure_reason' => null,
            'paused_at' => null,
            'started_at' => $toolBStart->format('Y-m-d H:i:s.u'),
            'ended_at' => $toolBEnd->format('Y-m-d H:i:s.u'),
            'duration_ms' => 5000,
            'content' => null,
            'created_at' => $toolBStart->format('Y-m-d H:i:s.u'),
        ]);

        $this->recorder->closeStep($stepId, RunEndState::Completed);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        $response = $this->actingAs($this->user)->getJson($this->endpoint($runId));

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertSame(
            7000,
            $body['tool_exec_ms'],
            'overlapping [0,5000] and [2000,7000] must merge to a 7000ms union, not sum to 10000ms',
        );
        $this->assertNotSame(10000, $body['tool_exec_ms']);
    }

    /**
     * A still-in_progress run shows all four breakdown columns as null (no
     * breakdown has been computed yet -- it is computed once, at close),
     * while first_output_ms may already be non-null for a streamed run that
     * has produced visible output but not yet concluded.
     */
    #[Test]
    public function in_progress_run_shows_null_breakdown_with_possible_first_output(): void
    {
        $runId = $this->recorder->openRun(
            RunKind::Interactive,
            $this->user->id,
            streamed: true,
            model: 'claude-sonnet-5',
            agentId: 'research-assistant',
        );
        $this->assertNotNull($runId);

        $stepId = $this->recorder->openStep($runId);
        $this->recorder->openAction($stepId, ActionType::LlmRequest, 'claude-sonnet-5');

        usleep(10_000);
        // The run has produced its first visible output (e.g. still mid a
        // later tool-use round) but has not yet reached a terminal state --
        // closeRun() is deliberately never called in this test.
        $this->recorder->recordFirstOutput($runId);

        $response = $this->actingAs($this->user)->getJson($this->endpoint($runId));

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertSame('in_progress', $body['end_state']);
        $this->assertNull($body['model_wait_ms']);
        $this->assertNull($body['tool_exec_ms']);
        $this->assertNull($body['confirm_wait_ms']);
        $this->assertNull($body['product_ms']);
        $this->assertNotNull(
            $body['first_output_ms'],
            'a streamed run that has produced visible output may report first_output_ms before it concludes',
        );
    }
}
