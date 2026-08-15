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
 * 105-stage-pipeline, Phase 4 (US2), tasks.md T034 (US2 AC1).
 *
 * GET /sequence-runs/{id} mid-run: with a run paused partway (one stage
 * left `running` directly, simulating a worker still mid-delegate()),
 * confirms the response shows which stage is `running`, which are
 * `completed`, and which are `pending` -- all three states observable
 * simultaneously in a single read, per contracts §4's in-progress example.
 *
 * This exercises SequenceController::showRun()/SequenceQuery::
 * stageResultsForRun(), both already implemented in Phase 3 (T014/T029) --
 * this test proves that existing implementation already satisfies FR-004's
 * "observable at any point" requirement without further endpoint change,
 * per this phase's own Goal statement.
 */
class SequenceRunProgressReadTest extends TestCase
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
    private function createMidRunFixture(User $owner): array
    {
        $definition = StageSequenceDefinition::create([
            'owner_user_id' => $owner->id,
            'coordinator_agent_id' => (string) Str::uuid(),
            'name' => 'Mid-run progress sequence',
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
            'status' => 'in_progress',
            'starting_input' => json_encode(['topic' => 'progress read test']),
            'current_stage_position' => 2,
            'last_progress_at' => now(),
            'resume_count' => 0,
            'started_at' => now(),
        ]);

        // Stage 1: completed. Stage 2: running (paused mid-delegate()).
        // Stages 3-4: left at their pre-created pending default.
        StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[0]->id,
            'status' => 'completed',
            'input' => $run->starting_input,
            'output' => json_encode(['draft_text' => 'a first draft']),
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute(),
        ]);

        StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[1]->id,
            'status' => 'running',
            'input' => json_encode(['draft_text' => 'a first draft']),
            'started_at' => now(),
        ]);

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
    public function a_mid_run_read_shows_running_completed_and_pending_stages_simultaneously(): void
    {
        [, $stages, $run] = $this->createMidRunFixture($this->user);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}");

        $response->assertStatus(200);
        $this->assertSame($run->id, $response->json('sequence_run_id'));
        $this->assertSame('in_progress', $response->json('status'));
        $this->assertSame(2, $response->json('current_stage_position'));

        $stagesPayload = $response->json('stages');
        $this->assertCount(4, $stagesPayload, 'every stage must always be present regardless of run status');

        $byPosition = collect($stagesPayload)->keyBy('position');

        $this->assertSame('completed', $byPosition[1]['status']);
        $this->assertSame(['draft_text' => 'a first draft'], $byPosition[1]['output']);

        $this->assertSame('running', $byPosition[2]['status']);
        $this->assertNull($byPosition[2]['output'], 'a still-running stage has no output yet');

        $this->assertSame('pending', $byPosition[3]['status']);
        $this->assertNull($byPosition[3]['output']);

        $this->assertSame('pending', $byPosition[4]['status']);
        $this->assertNull($byPosition[4]['output']);

        // All three states (completed / running / pending) are present at
        // once in the SAME response -- this is the "simultaneously
        // observable" property US2 AC1 requires, not merely that each
        // state is reachable in isolation.
        $statuses = collect($stagesPayload)->pluck('status')->unique()->sort()->values()->all();
        $this->assertSame(['completed', 'pending', 'running'], $statuses);
    }

    #[Test]
    public function a_mid_run_read_is_owner_scoped(): void
    {
        [, , $run] = $this->createMidRunFixture($this->otherUser);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}");

        $response->assertStatus(404);
    }
}
