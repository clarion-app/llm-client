<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Services\RunTraceQuery;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Acceptance-level coverage for spec.md's User Story 1 ("A self-hosted
 * installation keeps its own records, bounded") — Acceptance Scenarios 1-4.
 *
 * Scenario 5 (an invalid retention value falls back to the documented
 * default) is covered separately at the command-unit level in
 * tests/Unit/Commands/PurgeExpiredRunTracesCommandTest.php, where the
 * exact Log::warning contract is asserted.
 *
 * These tests exercise the full run/step/action fixture shape (rather than
 * hand-rolled single-table rows) so a purge that silently orphaned a step
 * or action row would be caught here even if a narrower unit test missed it.
 */
class TraceRetentionJourneyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.run_trace.retention_days', 90);
    }

    protected function tearDown(): void
    {
        foreach (['agent_run_actions', 'agent_run_steps', 'agent_run_messages', 'agent_runs', 'agent_run_export_queue'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    /**
     * Inserts a fully-shaped run (one step, two actions, one nested under the
     * other) whose started_at/ended_at land `$ageDays` in the past, so tests
     * can assert on both survival/purge and the absence of orphaned children.
     */
    private function insertAgedRunWithStepAndActions(string $userId, int $ageDays): array
    {
        $runId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $parentActionId = (string) Str::uuid();
        $childActionId = (string) Str::uuid();
        $time = CarbonImmutable::now()->subDays($ageDays);
        $formatted = $time->format('Y-m-d H:i:s.u');
        $endFormatted = $time->addMinute()->format('Y-m-d H:i:s.u');

        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => RunKind::Interactive->value,
            'user_id' => $userId,
            'conversation_id' => null,
            'source' => null,
            'end_state' => RunEndState::Completed->value,
            'end_reason' => null,
            'started_at' => $formatted,
            'ended_at' => $endFormatted,
            'duration_ms' => 60000,
            'step_count' => 1,
            'created_at' => $formatted,
        ]);

        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => $runId,
            'position' => 1,
            'attempt_group_id' => null,
            'end_state' => RunEndState::Completed->value,
            'end_reason' => null,
            'started_at' => $formatted,
            'ended_at' => $endFormatted,
            'duration_ms' => 60000,
            'wait_ms' => null,
            'attempt_count' => 1,
        ]);

        DB::table('agent_run_actions')->insert([
            'id' => $parentActionId,
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => ActionType::ToolInvocation->value,
            'target' => 'some_tool',
            'attempt_group_id' => null,
            'parent_action_id' => null,
            'outcome' => ActionOutcome::Success->value,
            'failure_reason' => null,
            'paused_at' => null,
            'started_at' => $formatted,
            'ended_at' => $endFormatted,
            'duration_ms' => 100,
            'content' => 'parent action content',
            'created_at' => $formatted,
        ]);

        DB::table('agent_run_actions')->insert([
            'id' => $childActionId,
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => ActionType::LlmRequest->value,
            'target' => null,
            'attempt_group_id' => null,
            'parent_action_id' => $parentActionId,
            'outcome' => ActionOutcome::Success->value,
            'failure_reason' => null,
            'paused_at' => null,
            'started_at' => $formatted,
            'ended_at' => $endFormatted,
            'duration_ms' => 50,
            'content' => 'nested action content',
            'created_at' => $formatted,
        ]);

        return [$runId, $stepId, $parentActionId, $childActionId];
    }

    private function assertRunFullyPresent(string $runId, string $stepId, string $parentActionId, string $childActionId): void
    {
        $this->assertSame(1, DB::table('agent_runs')->where('id', $runId)->count());
        $this->assertSame(1, DB::table('agent_run_steps')->where('id', $stepId)->count());
        $this->assertSame(1, DB::table('agent_run_actions')->where('id', $parentActionId)->count());
        $this->assertSame(1, DB::table('agent_run_actions')->where('id', $childActionId)->count());
    }

    private function assertRunFullyGone(string $runId, string $stepId, string $parentActionId, string $childActionId): void
    {
        $this->assertSame(0, DB::table('agent_runs')->where('id', $runId)->count());
        $this->assertSame(0, DB::table('agent_run_steps')->where('id', $stepId)->count());
        $this->assertSame(0, DB::table('agent_run_actions')->where('id', $parentActionId)->count());
        $this->assertSame(0, DB::table('agent_run_actions')->where('id', $childActionId)->count());
    }

    // ========== AC1: no destination config → retained internally, viewable, no outbound calls ==========

    /** @test */
    public function no_destination_config_retains_the_record_makes_it_viewable_and_makes_zero_outbound_calls(): void
    {
        // Deliberately do NOT set run_trace.export.* — this is the
        // zero-configuration installation AC1 describes. Http::fake() with no
        // stub registered will throw if anything is actually dispatched
        // through the Http facade, so an accidental outbound call fails loud.
        Http::fake();

        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);

        $userId = (string) Str::uuid();
        $convId = (string) Str::uuid();

        $runId = $recorder->openRun(RunKind::Interactive, $userId, $convId);
        $this->assertNotNull($runId);

        $stepId = $recorder->openStep($runId);
        $recorder->closeStep($stepId, RunEndState::Completed);
        $recorder->closeRun($runId, RunEndState::Completed);

        // Retained inside the product.
        $run = $query->findRun($userId, $runId);
        $this->assertNotNull($run);
        $this->assertEquals(RunEndState::Completed, $run->end_state);

        // Viewable via the normal query path (steps).
        $steps = $query->stepsForRun($userId, $runId);
        $this->assertCount(1, $steps);

        // No outbound delivery was attempted.
        Http::assertNothingSent();
    }

    // ========== AC2 / SC-002: a record produced exactly 7 days ago survives the default 90-day window ==========

    /** @test */
    public function record_produced_exactly_seven_days_ago_is_still_available_under_the_default_window(): void
    {
        $userId = (string) Str::uuid();
        [$runId, $stepId, $parentActionId, $childActionId] = $this->insertAgedRunWithStepAndActions($userId, 7);

        $exitCode = Artisan::call('llm-client:purge-run-traces');

        $this->assertSame(0, $exitCode);
        $this->assertRunFullyPresent($runId, $stepId, $parentActionId, $childActionId);
    }

    // ========== AC3 / SC-003: mixed-age fixture purges only what's outside the configured window, no orphans ==========

    /** @test */
    public function mixed_age_fixture_purges_only_records_outside_the_configured_window_leaving_no_orphans(): void
    {
        // A configured window different from the 90-day default, per the
        // scenario's "a configured retention window."
        $this->app['config']->set('llm-client.run_trace.retention_days', 30);

        $userId = (string) Str::uuid();

        // Outside the 30-day window — must be purged, run/step/actions alike.
        [$outsideRunId, $outsideStepId, $outsideParentActionId, $outsideChildActionId]
            = $this->insertAgedRunWithStepAndActions($userId, 40);

        // Inside the 30-day window — must be untouched.
        [$insideRunId, $insideStepId, $insideParentActionId, $insideChildActionId]
            = $this->insertAgedRunWithStepAndActions($userId, 10);

        $exitCode = Artisan::call('llm-client:purge-run-traces');

        $this->assertSame(0, $exitCode);

        $this->assertRunFullyGone($outsideRunId, $outsideStepId, $outsideParentActionId, $outsideChildActionId);
        $this->assertRunFullyPresent($insideRunId, $insideStepId, $insideParentActionId, $insideChildActionId);

        // No orphaned fragments anywhere in the table, not just for these two ids.
        $this->assertSame(0, DB::table('agent_run_steps')->whereNotIn('run_id', DB::table('agent_runs')->pluck('id'))->count());
        $this->assertSame(0, DB::table('agent_run_actions')->whereNotIn('run_id', DB::table('agent_runs')->pluck('id'))->count());
    }

    // ========== AC4: an operator-set non-default retention window is the one applied on the next pass ==========

    /** @test */
    public function operator_set_retention_window_is_the_one_applied_on_the_next_aging_pass(): void
    {
        $userId = (string) Str::uuid();

        // 45 days old: inside the 90-day default, outside a 30-day operator window.
        [$runId, $stepId, $parentActionId, $childActionId] = $this->insertAgedRunWithStepAndActions($userId, 45);

        // First pass: operator has set a 60-day window — record is inside it, survives.
        $this->app['config']->set('llm-client.run_trace.retention_days', 60);
        $exitCode = Artisan::call('llm-client:purge-run-traces');
        $this->assertSame(0, $exitCode);
        $this->assertRunFullyPresent($runId, $stepId, $parentActionId, $childActionId);

        // Operator narrows the window to 30 days — the next aging pass applies
        // *that* window, not the previous one and not the 90-day default.
        $this->app['config']->set('llm-client.run_trace.retention_days', 30);
        $exitCode = Artisan::call('llm-client:purge-run-traces');
        $this->assertSame(0, $exitCode);
        $this->assertRunFullyGone($runId, $stepId, $parentActionId, $childActionId);
    }
}
