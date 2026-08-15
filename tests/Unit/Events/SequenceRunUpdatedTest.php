<?php

namespace ClarionApp\LlmClient\Tests\Unit\Events;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\SequenceRunUpdated;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Models\StageSequenceDefinition;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 105-stage-pipeline, Phase 4 (US2), tasks.md T032.
 *
 * SequenceRunUpdated mirrors ManagedTaskUpdated exactly (research.md D8,
 * contracts/stage-pipeline-api.md §6, Grounding note item 8):
 * broadcastOn() re-resolves the SequenceRun from the database at broadcast
 * time and returns [] if it has since been purged, using
 * PrivateChannel('User.' . $run->owner_user_id) -- owner_user_id, NOT
 * user_id (the identifier-comparison defect class 070's own reconciliation
 * twice found and fixed). broadcastWith() re-queries fresh
 * completed_stage_count/total_stage_count/current_stage_position from the
 * database rather than trusting constructor-supplied state.
 *
 * Written before ClarionApp\LlmClient\Events\SequenceRunUpdated exists --
 * every test below is expected to fail with a class-not-found error until
 * T035 lands. That failure is the correct, expected state right now.
 */
class SequenceRunUpdatedTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
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
    private function createDefinitionWithStages(int $stageCount): array
    {
        $definition = StageSequenceDefinition::create([
            'owner_user_id' => $this->user->id,
            'coordinator_agent_id' => (string) Str::uuid(),
            'name' => 'Progress test sequence',
            'description' => null,
        ]);

        $stages = [];
        for ($position = 1; $position <= $stageCount; $position++) {
            $stages[] = Stage::create([
                'sequence_definition_id' => $definition->id,
                'position' => $position,
                'name' => "Stage {$position}",
                'helper_agent_id' => (string) Str::uuid(),
                'is_idempotent' => false,
            ]);
        }

        $run = SequenceRun::create([
            'sequence_definition_id' => $definition->id,
            'owner_user_id' => $this->user->id,
            'conversation_id' => (string) Str::uuid(),
            'status' => 'in_progress',
            'starting_input' => json_encode(['x' => 1]),
            'current_stage_position' => null,
            'last_progress_at' => now(),
            'resume_count' => 0,
            'started_at' => now(),
        ]);

        foreach ($stages as $stage) {
            StageResult::create([
                'sequence_run_id' => $run->id,
                'stage_id' => $stage->id,
                'status' => 'pending',
            ]);
        }

        return [$definition, $stages, $run];
    }

    #[Test]
    public function broadcast_on_resolves_to_the_owners_private_channel_for_an_existing_run(): void
    {
        [, , $run] = $this->createDefinitionWithStages(3);

        $event = new SequenceRunUpdated($run->id);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-User.' . $this->user->id, (string) $channels[0]);
    }

    #[Test]
    public function broadcast_on_resolves_to_empty_array_when_the_run_has_since_been_purged(): void
    {
        $event = new SequenceRunUpdated((string) Str::uuid());

        $this->assertSame([], $event->broadcastOn());
    }

    #[Test]
    public function broadcast_with_returns_empty_array_when_the_run_has_since_been_purged(): void
    {
        $event = new SequenceRunUpdated((string) Str::uuid());

        $this->assertSame([], $event->broadcastWith());
    }

    #[Test]
    public function broadcast_with_matches_contracts_6_payload_shape(): void
    {
        [, $stages, $run] = $this->createDefinitionWithStages(4);

        $run->current_stage_position = 2;
        $run->save();

        StageResult::where('sequence_run_id', $run->id)
            ->where('stage_id', $stages[0]->id)
            ->update(['status' => 'completed']);

        $payload = (new SequenceRunUpdated($run->id))->broadcastWith();

        $this->assertSame(
            ['sequence_run_id', 'status', 'current_stage_position', 'completed_stage_count', 'total_stage_count', 'resume_count'],
            array_keys($payload),
        );
        $this->assertSame($run->id, $payload['sequence_run_id']);
        $this->assertSame('in_progress', $payload['status']);
        $this->assertSame(2, $payload['current_stage_position']);
        $this->assertSame(1, $payload['completed_stage_count']);
        $this->assertSame(4, $payload['total_stage_count']);
        $this->assertSame(0, $payload['resume_count']);
    }

    #[Test]
    public function broadcast_with_reflects_state_at_broadcast_time_not_at_construction_time(): void
    {
        [, $stages, $run] = $this->createDefinitionWithStages(2);
        $event = new SequenceRunUpdated($run->id);

        // Every write happens strictly AFTER the event object is
        // constructed -- broadcastWith() must still reflect it, since
        // ShouldBroadcastNow events are constructed and dispatched from
        // the very same write points they report on.
        StageResult::where('sequence_run_id', $run->id)->update(['status' => 'completed']);
        $run->update([
            'status' => 'completed',
            'current_stage_position' => 2,
            'completed_at' => now(),
        ]);

        $payload = $event->broadcastWith();

        $this->assertSame('completed', $payload['status']);
        $this->assertSame(2, $payload['completed_stage_count']);
        $this->assertSame(2, $payload['total_stage_count']);
    }

    #[Test]
    public function broadcast_with_recomputes_completed_stage_count_from_stage_results_not_from_a_stored_counter(): void
    {
        [, $stages, $run] = $this->createDefinitionWithStages(5);

        StageResult::where('sequence_run_id', $run->id)
            ->whereIn('stage_id', [$stages[0]->id, $stages[1]->id, $stages[2]->id])
            ->update(['status' => 'completed']);

        $payload = (new SequenceRunUpdated($run->id))->broadcastWith();

        $this->assertSame(3, $payload['completed_stage_count']);
        $this->assertSame(5, $payload['total_stage_count']);
    }
}
