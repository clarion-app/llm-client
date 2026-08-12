<?php

namespace ClarionApp\LlmClient\Tests\Unit\Events;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\EvalRunUpdated;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EvalRunUpdated broadcasts a run's full detail shape to every configured
 * operator's own private channel (there is no single run owner to target
 * instead), re-resolving the run fresh from the database at broadcast
 * time so a pushed payload can never disagree with what
 * GET /eval-runs/{runId} itself would return for the same run at the same
 * instant.
 *
 * Written before the ClarionApp\LlmClient\Events\EvalRunUpdated class
 * exists -- every test below is expected to fail with a class-not-found
 * error until that class is created. That failure is the correct,
 * expected state right now.
 */
class EvalRunUpdatedTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('eval_case_results')->delete();
        DB::table('eval_runs')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function createRun(array $overrides = []): EvalRun
    {
        return EvalRun::create(array_merge([
            'suite_id' => (string) Str::uuid(),
            'agent_label' => 'watch-live-agent',
            'status' => EvalRunStatus::InProgress,
            'case_count' => 1,
            'started_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function broadcast_on_resolves_to_one_private_channel_per_configured_operator(): void
    {
        $operatorA = (string) Str::uuid();
        $operatorB = (string) Str::uuid();
        config(['llm-client.cost.operator_user_ids' => [$operatorA, $operatorB]]);

        $run = $this->createRun();

        $channels = (new EvalRunUpdated($run->id))->broadcastOn();

        $this->assertCount(2, $channels);
        foreach ($channels as $channel) {
            $this->assertInstanceOf(PrivateChannel::class, $channel);
        }
        $this->assertSame(
            ['private-User.'.$operatorA, 'private-User.'.$operatorB],
            array_map(fn (PrivateChannel $c) => (string) $c, $channels),
        );
    }

    #[Test]
    public function broadcast_on_excludes_a_non_string_entry_configured_by_mistake(): void
    {
        $realOperator = (string) Str::uuid();
        // A non-string entry landing in the config array -- e.g. a
        // copy/paste or JSON-decoding slip -- must never turn into a
        // channel, mirroring the fan-out precedent this event follows.
        config(['llm-client.cost.operator_user_ids' => [$realOperator, 12345]]);

        $run = $this->createRun();

        $channels = (new EvalRunUpdated($run->id))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('private-User.'.$realOperator, (string) $channels[0]);
    }

    #[Test]
    public function broadcast_on_resolves_to_empty_array_when_no_operators_are_configured(): void
    {
        config(['llm-client.cost.operator_user_ids' => []]);

        $run = $this->createRun();

        $this->assertSame([], (new EvalRunUpdated($run->id))->broadcastOn());
    }

    #[Test]
    public function broadcast_on_resolves_to_empty_array_when_the_run_has_since_been_purged(): void
    {
        config(['llm-client.cost.operator_user_ids' => [(string) Str::uuid()]]);

        $event = new EvalRunUpdated((string) Str::uuid());

        $this->assertSame([], $event->broadcastOn());
    }

    #[Test]
    public function broadcast_with_matches_the_rest_run_detail_endpoint_exactly(): void
    {
        $operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$operator->id]]);

        $run = $this->createRun(['agent_label' => 'watch-live-payload-agent']);
        EvalCaseResult::create([
            'run_id' => $run->id,
            'eval_run_case_id' => (string) Str::uuid(),
            'eval_case_id' => (string) Str::uuid(),
            'eval_case_version_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'outcome' => 'pass',
            'expectation_results' => [],
        ]);

        $restResponse = $this->actingAs($operator)
            ->getJson('/api/clarion-app/llm-client/eval-runs/'.$run->id);
        $restResponse->assertStatus(200);

        $payload = (new EvalRunUpdated($run->id))->broadcastWith();

        $this->assertSame($restResponse->json(), $payload);
        // The full thirteen-field shape, not a subset -- omitting
        // failure_reason/overall would silently disagree with the REST
        // endpoint for an interrupted or failed-to-start run.
        $this->assertSame(
            [
                'id', 'suite_id', 'agent_label', 'status', 'case_count', 'completed_count',
                'remaining_count', 'started_at', 'completed_at', 'failure_reason', 'overall',
                'outcome_counts', 'consumption',
            ],
            array_keys($payload),
        );
    }

    #[Test]
    public function broadcast_with_reflects_the_current_state_at_broadcast_time_not_at_construction_time(): void
    {
        $operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$operator->id]]);

        $run = $this->createRun(['status' => EvalRunStatus::InProgress]);
        $event = new EvalRunUpdated($run->id);

        $run->update([
            'status' => EvalRunStatus::FailedToStart,
            'failure_reason' => 'No inference model is assigned.',
            'completed_at' => now(),
        ]);

        $payload = $event->broadcastWith();

        $this->assertSame('failed_to_start', $payload['status']);
        $this->assertSame('No inference model is assigned.', $payload['failure_reason']);
    }
}
