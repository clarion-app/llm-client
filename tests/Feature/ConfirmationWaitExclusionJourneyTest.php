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
 * Proves that time a user spends responding to a confirmation prompt is
 * tracked as its own quantity and is never mistaken for the system doing
 * work: it must not be folded into model-wait, tool-execution, or
 * product-processing time, and a response that happened to need
 * confirmation must not look artificially slower on a "system working"
 * basis than an otherwise-identical response that did not.
 */
class ConfirmationWaitExclusionJourneyTest extends TestCase
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
     * Inserts a single llm_request action row directly, with an explicit,
     * deterministic duration, so model-wait time is fixed and independent
     * of real wall-clock timing in the test.
     */
    private function insertLlmAction(string $runId, string $stepId, Carbon $startedAt, int $durationMs): void
    {
        DB::table('agent_run_actions')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => ActionType::LlmRequest->value,
            'target' => 'claude-sonnet-5',
            'attempt_group_id' => null,
            'parent_action_id' => null,
            'outcome' => ActionOutcome::Success->value,
            'failure_reason' => null,
            'paused_at' => null,
            'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
            'ended_at' => $startedAt->copy()->addMilliseconds($durationMs)->format('Y-m-d H:i:s.u'),
            'duration_ms' => $durationMs,
            'content' => null,
            'created_at' => $startedAt->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * Builds one response run: one llm_request action of a fixed duration,
     * no tool calls, and a step closed with an explicit confirmation-wait
     * duration (the same value the real confirmation-resume code path would
     * have computed and passed in). The run's own started_at (and its
     * step's) are backdated so that closing it "now" produces a real,
     * non-zero elapsed duration covering both the model call and the wait.
     */
    private function buildRun(int $modelWaitMs, int $confirmWaitMs, int $backdateMs): string
    {
        $runId = $this->recorder->openRun(
            RunKind::Interactive,
            $this->user->id,
            streamed: false,
            model: 'claude-sonnet-5',
            agentId: 'research-assistant',
        );
        $this->assertNotNull($runId);

        $base = Carbon::now()->subMilliseconds($backdateMs);
        DB::table('agent_runs')->where('id', $runId)
            ->update(['started_at' => $base->format('Y-m-d H:i:s.u')]);

        $stepId = $this->recorder->openStep($runId);
        $this->assertNotNull($stepId);
        DB::table('agent_run_steps')->where('id', $stepId)
            ->update(['started_at' => $base->format('Y-m-d H:i:s.u')]);

        $this->insertLlmAction($runId, $stepId, $base, $modelWaitMs);

        // A confirm_wait_ms of 0 is passed as null, matching how a step
        // that never paused for confirmation is closed elsewhere in this
        // suite (no wait argument at all).
        $this->recorder->closeStep(
            $stepId,
            RunEndState::Completed,
            null,
            $confirmWaitMs > 0 ? $confirmWaitMs : null,
        );
        $this->recorder->closeRun($runId, RunEndState::Completed);

        return $runId;
    }

    /**
     * A response that paused for a confirmation prompt and waited several
     * seconds before the user confirmed shows that wait as its own
     * quantity, and it is subtracted out of product-processing time rather
     * than being folded into it. Because confirm_wait_ms is written
     * verbatim from the wait duration passed to the step (never re-derived
     * from the step's own total elapsed duration, which would include the
     * wait and misattribute it), the figure below is exact, not
     * approximate, even though the run's total duration is real elapsed
     * wall-clock time and therefore carries a small amount of test-runtime
     * jitter.
     */
    #[Test]
    public function confirmation_wait_is_recorded_separately_and_excluded_from_the_other_categories(): void
    {
        $modelWaitMs = 500;
        $confirmWaitMs = 3000; // "several seconds" of human decision time
        $runId = $this->buildRun($modelWaitMs, $confirmWaitMs, backdateMs: 4000);

        $response = $this->actingAs($this->user)->getJson($this->endpoint($runId));
        $response->assertStatus(200);
        $body = $response->json();

        $this->assertSame(
            $confirmWaitMs,
            $body['confirm_wait_ms'],
            'confirm_wait_ms must reflect the confirmation wait exactly, not a value derived from step duration',
        );
        $this->assertSame($modelWaitMs, $body['model_wait_ms'], 'the confirmation wait must not leak into model_wait_ms');
        $this->assertSame(0, $body['tool_exec_ms'], 'the confirmation wait must not leak into tool_exec_ms');

        // If confirm_wait_ms were folded into product_ms instead of being
        // subtracted out as its own category, product_ms would equal
        // (duration_ms - model_wait_ms - tool_exec_ms). Confirm that the
        // actual figure is smaller than that by exactly the confirmation
        // wait -- proving the wait was excluded, not absorbed.
        $productIfNotExcluded = $body['duration_ms'] - $body['model_wait_ms'] - $body['tool_exec_ms'];
        $this->assertSame(
            $confirmWaitMs,
            $productIfNotExcluded - $body['product_ms'],
            'confirm_wait_ms must be subtracted out of product_ms rather than counted as product-processing time',
        );

        // The reconciliation invariant still holds with confirm_wait_ms as
        // its own additive term.
        $this->assertSame(
            $body['duration_ms'],
            $body['model_wait_ms'] + $body['tool_exec_ms'] + $body['confirm_wait_ms'] + $body['product_ms'],
        );
    }

    /**
     * Two responses did comparable work (the same single model call, no
     * tool calls) but one of them also paused for a confirmation prompt.
     * Their total elapsed durations differ enormously because of the wait,
     * but their product-processing durations -- the figure that represents
     * how hard the system itself worked -- must stay comparable, so the
     * response that happened to need confirmation is not misread as a
     * slower system.
     */
    #[Test]
    public function two_similar_responses_show_comparable_product_ms_despite_very_different_total_duration(): void
    {
        $modelWaitMs = 500;

        $withoutConfirmationRunId = $this->buildRun($modelWaitMs, confirmWaitMs: 0, backdateMs: 1000);
        $withConfirmationRunId = $this->buildRun($modelWaitMs, confirmWaitMs: 3000, backdateMs: 4000);

        $withoutConfirmation = $this->actingAs($this->user)
            ->getJson($this->endpoint($withoutConfirmationRunId))
            ->assertStatus(200)
            ->json();
        $withConfirmation = $this->actingAs($this->user)
            ->getJson($this->endpoint($withConfirmationRunId))
            ->assertStatus(200)
            ->json();

        $this->assertGreaterThan(
            $withoutConfirmation['duration_ms'] + 2000,
            $withConfirmation['duration_ms'],
            'the confirmation pause must make the two total durations very different',
        );

        $this->assertEqualsWithDelta(
            $withoutConfirmation['product_ms'],
            $withConfirmation['product_ms'],
            500,
            'despite very different total durations, product_ms (system-working time) must stay comparable '
                . 'once the confirmation wait is excluded',
        );
    }
}
