<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Models\StageSequenceDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 105-stage-pipeline, Phase 5 (US3), tasks.md T043 (FR-009, SC-004).
 *
 * GET /sequence-runs/{id} on a STOPPED run: names every completed stage
 * (with its output), the failed/handoff_rejected stage and why, and every
 * never-reached stage -- matching contracts §4's failure-response example
 * exactly. Fixtures are built directly (no delegation chain needed) --
 * SequenceController::showRun()/SequenceQuery::stageResultsForRun() were
 * both already implemented in Phase 3 (T014/T029) and already carry
 * failure_reason through at both the run and per-StageResult level; this
 * test is the first to actually populate that field and read it back,
 * proving Phase 3's read path already satisfies FR-009 for a Phase-5-shaped
 * failure with no further change needed (T047 is verification-only unless
 * this test finds a gap).
 */
class SequenceRunFailureReadTest extends TestCase
{
    private User $user;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
    }

    protected function tearDown(): void
    {
        DB::table('stage_results')->delete();
        DB::table('sequence_runs')->delete();
        DB::table('stages')->delete();
        DB::table('stage_sequence_definitions')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    /**
     * @return array{0: StageSequenceDefinition, 1: array<int, Stage>, 2: SequenceRun}
     */
    private function createStoppedRunFixture(User $owner): array
    {
        $definition = StageSequenceDefinition::create([
            'owner_user_id' => $owner->id,
            'coordinator_agent_id' => (string) Str::uuid(),
            'name' => 'Stopped sequence',
            'description' => null,
        ]);

        $stageNames = ['Draft', 'Check', 'Revise', 'Finish'];
        $stages = [];
        foreach ($stageNames as $index => $name) {
            $stages[] = Stage::create([
                'sequence_definition_id' => $definition->id,
                'position' => $index + 1,
                'name' => $name,
                'helper_agent_id' => (string) Str::uuid(),
                'is_idempotent' => false,
            ]);
        }

        $run = SequenceRun::create([
            'sequence_definition_id' => $definition->id,
            'owner_user_id' => $owner->id,
            'conversation_id' => (string) Str::uuid(),
            'status' => 'failed',
            'starting_input' => json_encode(['topic' => 'failure read test']),
            'current_stage_position' => 2,
            'last_progress_at' => now(),
            'failure_reason' => "Stage 'Check' rejected the output of stage 'Draft': The property draft_text is required",
            'resume_count' => 0,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);

        // Stage 1: completed with real output.
        StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[0]->id,
            'status' => 'completed',
            'input' => $run->starting_input,
            'output' => json_encode(['summary' => 'a draft with no draft_text key']),
            'started_at' => now()->subMinutes(4),
            'completed_at' => now()->subMinutes(3),
        ]);

        // Stage 2: the handoff-rejected stopping point, with its own reason.
        StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[1]->id,
            'status' => 'handoff_rejected',
            'input' => json_encode(['summary' => 'a draft with no draft_text key']),
            'failure_reason' => 'The property draft_text is required',
            'completed_at' => now(),
        ]);

        // Stages 3-4: never reached.
        StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[2]->id,
            'status' => 'pending',
        ]);

        StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[3]->id,
            'status' => 'pending',
        ]);

        return [$definition, $stages, $run];
    }

    #[Test]
    public function a_stopped_run_names_every_completed_failed_and_never_reached_stage(): void
    {
        [, , $run] = $this->createStoppedRunFixture($this->user);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}");

        $response->assertStatus(200);
        $this->assertSame($run->id, $response->json('sequence_run_id'));
        $this->assertSame('failed', $response->json('status'));
        $this->assertNotNull($response->json('failure_reason'), 'the run level must surface a failure_reason, not just per-stage');
        $this->assertStringContainsString('draft_text', $response->json('failure_reason'));

        $stagesPayload = collect($response->json('stages'))->keyBy('position');
        $this->assertCount(4, $stagesPayload, 'every stage must always be present regardless of run status');

        // The completed stage, with its real output.
        $this->assertSame('completed', $stagesPayload[1]['status']);
        $this->assertSame(['summary' => 'a draft with no draft_text key'], $stagesPayload[1]['output']);

        // The stopping stage, named and with its own specific reason.
        $this->assertSame('handoff_rejected', $stagesPayload[2]['status']);
        $this->assertSame('The property draft_text is required', $stagesPayload[2]['failure_reason']);
        $this->assertNull($stagesPayload[2]['output']);

        // Every never-reached stage, distinctly pending.
        $this->assertSame('pending', $stagesPayload[3]['status']);
        $this->assertNull($stagesPayload[3]['output']);
        $this->assertSame('pending', $stagesPayload[4]['status']);
        $this->assertNull($stagesPayload[4]['output']);
    }

    #[Test]
    public function a_stopped_run_is_still_owner_scoped(): void
    {
        [, , $run] = $this->createStoppedRunFixture($this->otherUser);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}");

        $response->assertStatus(404);
    }
}
