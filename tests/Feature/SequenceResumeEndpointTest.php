<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Models\StageSequenceDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 105-stage-pipeline, Phase 6 (US4), tasks.md T053/T054.
 *
 * T053: POST /sequence-runs/{id}/resume on a run that is NOT 'failed'
 * (in_progress/resumed/completed) returns 409 run_not_failed naming
 * current_status; on a run absent or not owned, 404.
 *
 * T054 (FR-017): after a resume completes (or fails again), GET
 * /sequence-runs/{id} shows both the carried-over stages and the
 * resumption's own stages as one coherent array, resume_count > 0, and
 * status ending at completed or failed -- never a second run row, never
 * stuck at 'resumed' as a terminal value.
 *
 * Plain fixture-based (no delegation chain needed) -- both concerns here
 * are about the endpoint's own status/ownership/read-coherence logic, not
 * about actually driving stage execution (already proven by
 * SequenceResumeJourneyTest/SequenceResumeSafetyTest).
 *
 * Written before resume() is implemented -- expected to FAIL red (501)
 * until T058 lands.
 */
class SequenceResumeEndpointTest extends TestCase
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
    private function makeRunFixture(User $owner, string $status): array
    {
        $definition = StageSequenceDefinition::create([
            'owner_user_id' => $owner->id,
            'coordinator_agent_id' => (string) Str::uuid(),
            'name' => 'Resume endpoint fixture',
            'description' => null,
        ]);

        $stages = [];
        foreach (['Draft', 'Check'] as $index => $name) {
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
            'status' => $status,
            'starting_input' => json_encode(['topic' => 'resume endpoint test']),
            'current_stage_position' => 1,
            'last_progress_at' => now(),
            'resume_count' => 0,
            'started_at' => now()->subMinutes(5),
            'completed_at' => in_array($status, ['completed', 'failed'], true) ? now() : null,
        ]);

        StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[0]->id,
            'status' => $status === 'completed' ? 'completed' : 'pending',
        ]);
        StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[1]->id,
            'status' => $status === 'completed' ? 'completed' : 'pending',
        ]);

        return [$definition, $stages, $run];
    }

    // =================================================================
    // T053: resume attempted on a non-failed run / absent / not owned
    // =================================================================

    #[Test]
    public function resume_on_an_in_progress_run_returns_409_run_not_failed(): void
    {
        [, , $run] = $this->makeRunFixture($this->user, 'in_progress');

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}/resume");

        $response->assertStatus(409);
        $this->assertSame('run_not_failed', $response->json('error'));
        $this->assertSame('in_progress', $response->json('current_status'));
    }

    #[Test]
    public function resume_on_an_already_resumed_run_returns_409_run_not_failed(): void
    {
        [, , $run] = $this->makeRunFixture($this->user, 'resumed');

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}/resume");

        $response->assertStatus(409);
        $this->assertSame('run_not_failed', $response->json('error'));
        $this->assertSame('resumed', $response->json('current_status'));
    }

    #[Test]
    public function resume_on_a_completed_run_returns_409_run_not_failed(): void
    {
        [, , $run] = $this->makeRunFixture($this->user, 'completed');

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}/resume");

        $response->assertStatus(409);
        $this->assertSame('run_not_failed', $response->json('error'));
        $this->assertSame('completed', $response->json('current_status'));
    }

    #[Test]
    public function resume_on_an_absent_run_returns_404(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/sequence-runs/'.((string) Str::uuid()).'/resume');

        $response->assertStatus(404);
    }

    #[Test]
    public function resume_on_a_run_owned_by_another_user_returns_404(): void
    {
        [, , $run] = $this->makeRunFixture($this->otherUser, 'failed');

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}/resume");

        $response->assertStatus(404);
    }

    // =================================================================
    // T054 (FR-017): resumed-run history coherence
    // =================================================================

    #[Test]
    public function a_resumed_run_that_later_completes_reads_as_one_coherent_history(): void
    {
        [, $stages, $run] = $this->makeRunFixture($this->user, 'failed');

        // Stage 1 already completed before the failure; stage 2 is the
        // blocking, resumable stage (left 'pending' by makeRunFixture's own
        // non-'completed' branch).
        StageResult::where('sequence_run_id', $run->id)->where('stage_id', $stages[0]->id)->update([
            'status' => 'completed',
            'output' => json_encode(['drafted' => true]),
            'completed_at' => now()->subMinutes(3),
        ]);

        Queue::fake();
        $resumeResponse = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}/resume");
        $resumeResponse->assertStatus(202);

        // Simulate the resumed job finishing stage 2 and finalizing the run
        // (the job's own resume-point logic is proven elsewhere -- this
        // test is about the read endpoint's coherence once that has
        // happened).
        StageResult::where('sequence_run_id', $run->id)->where('stage_id', $stages[1]->id)->update([
            'status' => 'completed',
            'output' => json_encode(['checked' => true]),
            'completed_at' => now(),
        ]);
        $run->refresh();
        $run->status = 'completed';
        $run->completed_at = now();
        $run->save();

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}");

        $response->assertStatus(200);
        $this->assertSame($run->id, $response->json('sequence_run_id'));
        $this->assertSame('completed', $response->json('status'), 'a resumed-then-succeeded run must end at completed, never stuck at resumed');
        $this->assertGreaterThan(0, $response->json('resume_count'));

        $stagesPayload = collect($response->json('stages'))->keyBy('position');
        $this->assertCount(2, $stagesPayload, 'both the carried-over stage and the resumption stage appear in ONE array');
        $this->assertSame('completed', $stagesPayload[1]['status']);
        $this->assertSame(['drafted' => true], $stagesPayload[1]['output']);
        $this->assertSame('completed', $stagesPayload[2]['status']);
        $this->assertSame(['checked' => true], $stagesPayload[2]['output']);

        // Never a second run row.
        $this->assertSame(1, SequenceRun::count());
    }

    #[Test]
    public function a_resumed_run_that_fails_again_reads_as_one_coherent_history(): void
    {
        [, $stages, $run] = $this->makeRunFixture($this->user, 'failed');

        StageResult::where('sequence_run_id', $run->id)->where('stage_id', $stages[0]->id)->update([
            'status' => 'completed',
            'output' => json_encode(['drafted' => true]),
            'completed_at' => now()->subMinutes(3),
        ]);

        Queue::fake();
        $resumeResponse = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}/resume");
        $resumeResponse->assertStatus(202);

        // Simulate the resumed job's stage 2 failing again.
        StageResult::where('sequence_run_id', $run->id)->where('stage_id', $stages[1]->id)->update([
            'status' => 'failed',
            'failure_reason' => 'The checker failed again on resume.',
            'completed_at' => now(),
        ]);
        $run->refresh();
        $run->status = 'failed';
        $run->failure_reason = "Stage 'Check' failed: The checker failed again on resume.";
        $run->completed_at = now();
        $run->save();

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}");

        $response->assertStatus(200);
        $this->assertSame('failed', $response->json('status'), 'a resumed run that fails again must end at failed, never stuck at resumed');
        $this->assertGreaterThan(0, $response->json('resume_count'));
        $this->assertNotNull($response->json('failure_reason'));

        $stagesPayload = collect($response->json('stages'))->keyBy('position');
        $this->assertCount(2, $stagesPayload);
        $this->assertSame('completed', $stagesPayload[1]['status'], 'the carried-over completed stage is still shown');
        $this->assertSame('failed', $stagesPayload[2]['status']);
        $this->assertSame('The checker failed again on resume.', $stagesPayload[2]['failure_reason']);

        $this->assertSame(1, SequenceRun::count());
    }
}
