<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * FR-021 access-scoping proof for GET /latency/models/{model} and
 * GET /latency/agents/{agentId} (contracts/latency-api.md §1): a
 * non-operator's read reflects only their own responses, an operator's read
 * is unrestricted, and neither route ever 403s -- only the visible scope
 * narrows, matching CostRollupController's userShow/agentShow shape rather
 * than ModelPriceController's unconditional-403 shape. Mirrors
 * RollupRoleScopingJourneyTest's structure for the cost-rollup surface.
 *
 * Fixture rows are inserted directly into agent_runs, matching
 * LatencyDistributionJourneyTest's approach.
 */
class LatencyAccessScopingJourneyTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('agent_runs')->delete();
        DB::table('users')->delete();
        parent::tearDown();
    }

    private function endpoint(string $path): string
    {
        return '/api/clarion-app/llm-client/latency/'.$path;
    }

    private function today(): string
    {
        return Carbon::now()->toDateString();
    }

    private function seedRun(array $overrides = []): void
    {
        $startedAt = $overrides['started_at'] ?? Carbon::now()->subMinutes(5);
        unset($overrides['started_at']);

        DB::table('agent_runs')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'kind' => 'interactive',
            'user_id' => (string) Str::uuid(),
            'conversation_id' => null,
            'source' => null,
            'end_state' => 'completed',
            'end_reason' => null,
            'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
            'ended_at' => $startedAt->copy()->addSeconds(1)->format('Y-m-d H:i:s.u'),
            'duration_ms' => 1000,
            'step_count' => 1,
            'created_at' => $startedAt->format('Y-m-d H:i:s'),
            'is_streamed' => false,
            'first_output_ms' => null,
            'model' => null,
            'agent_id' => null,
            'model_wait_ms' => null,
            'tool_exec_ms' => null,
            'confirm_wait_ms' => null,
            'product_ms' => null,
        ], $overrides));
    }

    #[Test]
    public function non_operator_model_distribution_reflects_only_their_own_responses_never_403(): void
    {
        $caller = User::factory()->create();
        $stranger = User::factory()->create();

        $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $caller->id, 'duration_ms' => 1000]);
        $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $stranger->id, 'duration_ms' => 99999]);

        $response = $this->actingAs($caller)->getJson(
            $this->endpoint("models/claude-sonnet-5?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200, 'a non-operator reading a model distribution must never be 403\'d');
        $body = $response->json();
        $this->assertSame(1, $body['sample_count'], 'only the caller\'s own response, never the stranger\'s');
        $this->assertSame(1000, $body['total']['whole']['p50_ms']);
    }

    #[Test]
    public function non_operator_agent_distribution_reflects_only_their_own_responses_never_403(): void
    {
        $caller = User::factory()->create();
        $stranger = User::factory()->create();

        $this->seedRun(['agent_id' => 'shared-agent', 'user_id' => $caller->id, 'duration_ms' => 1500]);
        $this->seedRun(['agent_id' => 'shared-agent', 'user_id' => $stranger->id, 'duration_ms' => 88888]);

        $response = $this->actingAs($caller)->getJson(
            $this->endpoint("agents/shared-agent?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200, 'a non-operator reading an agent distribution must never be 403\'d');
        $body = $response->json();
        $this->assertSame(1, $body['sample_count'], 'only the caller\'s own response, never the stranger\'s');
        $this->assertSame(1500, $body['total']['whole']['p50_ms']);
    }

    #[Test]
    public function non_operator_with_no_responses_of_their_own_gets_no_data_shape_not_403(): void
    {
        $caller = User::factory()->create();
        $stranger = User::factory()->create();

        $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $stranger->id, 'duration_ms' => 1000]);

        $response = $this->actingAs($caller)->getJson(
            $this->endpoint("models/claude-sonnet-5?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('sample_count'));
        $this->assertTrue($response->json('no_data'));
    }

    #[Test]
    public function operator_sees_every_users_responses_on_model_and_agent_distributions(): void
    {
        $operator = User::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$operator->id]]);

        $this->seedRun(['model' => 'claude-sonnet-5', 'agent_id' => 'shared-agent', 'user_id' => $userA->id, 'duration_ms' => 1000]);
        $this->seedRun(['model' => 'claude-sonnet-5', 'agent_id' => 'shared-agent', 'user_id' => $userB->id, 'duration_ms' => 2000]);

        // Model distribution: the operator has no responses of their own
        // against this model at all, yet sees both other users' records.
        $modelResponse = $this->actingAs($operator)->getJson(
            $this->endpoint("models/claude-sonnet-5?from={$this->today()}&to={$this->today()}")
        );
        $modelResponse->assertStatus(200);
        $this->assertSame(2, $modelResponse->json('sample_count'), 'an operator sees every user\'s responses for this model');

        // Agent distribution: same cross-user unrestricted read.
        $agentResponse = $this->actingAs($operator)->getJson(
            $this->endpoint("agents/shared-agent?from={$this->today()}&to={$this->today()}")
        );
        $agentResponse->assertStatus(200);
        $this->assertSame(2, $agentResponse->json('sample_count'), 'an operator sees every user\'s responses for this agent');
    }
}
