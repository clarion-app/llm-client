<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Journey tests for User Story 2 (spec.md, 074-latency-metrics): an
 * operator can look at how fast responses have actually been -- typical and
 * worst-case figures, scoped to one model or one agent, over a chosen
 * period -- via the four new GET /latency/... endpoints
 * (contracts/latency-api.md §1). Covers spec.md User Story 2 Acceptance
 * Scenarios 1-5.
 *
 * Fixture rows are inserted directly into agent_runs so every column
 * relevant to a distribution (end_state, duration_ms, is_streamed,
 * first_output_ms, model, agent_id, user_id, started_at) can be set to an
 * exact, deterministic value, matching LatencyQueryTest's approach.
 */
class LatencyDistributionJourneyTest extends TestCase
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

    /**
     * Acceptance Scenario 1: GET /latency/models/{model} reports a typical
     * figure and a worst-case figure, not only an average -- and the
     * response follows contracts/latency-api.md §1's documented shape.
     */
    #[Test]
    public function get_model_distribution_returns_typical_and_worst_case_figures(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 9; $i++) {
            $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $user->id, 'duration_ms' => 1000]);
        }
        $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $user->id, 'duration_ms' => 20000]);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("models/claude-sonnet-5?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertSame('model', $body['scope']['type']);
        $this->assertSame('claude-sonnet-5', $body['scope']['value']);
        $this->assertSame(10, $body['sample_count']);
        $this->assertFalse($body['no_data']);
        $this->assertArrayHasKey('total', $body);
        $this->assertArrayHasKey('whole', $body['total']);
        $this->assertArrayHasKey('streamed', $body['total']);
        $this->assertArrayHasKey('first_output', $body);
        $this->assertSame(1000, $body['total']['whole']['p50_ms']);
        $this->assertSame(20000, $body['total']['whole']['p95_ms']);
        $this->assertNotSame($body['total']['whole']['p50_ms'], $body['total']['whole']['p95_ms']);
    }

    /**
     * GET /latency/models (list form) returns one LatencyDistribution entry
     * per model with at least one response in the period.
     */
    #[Test]
    public function get_models_list_returns_one_entry_per_model_with_data_in_period(): void
    {
        $user = User::factory()->create();

        $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $user->id, 'duration_ms' => 1200]);
        $this->seedRun(['model' => 'llama3.1-70b', 'user_id' => $user->id, 'duration_ms' => 800]);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("models?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200);
        $values = collect($response->json('data'))->pluck('scope.value')->all();
        $this->assertContains('claude-sonnet-5', $values);
        $this->assertContains('llama3.1-70b', $values);
    }

    /**
     * Acceptance Scenario 2: GET /latency/agents/{agentId} scoped by
     * agent_id reflects only that agent's records, even when other agents'
     * records exist in the same period.
     */
    #[Test]
    public function get_agent_distribution_reflects_only_that_agents_records(): void
    {
        $user = User::factory()->create();

        $this->seedRun(['agent_id' => 'research-assistant', 'user_id' => $user->id, 'duration_ms' => 1500]);
        $this->seedRun(['agent_id' => 'research-assistant', 'user_id' => $user->id, 'duration_ms' => 2500]);
        $this->seedRun(['agent_id' => 'other-agent', 'user_id' => $user->id, 'duration_ms' => 99999]);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("agents/research-assistant?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertSame('agent', $body['scope']['type']);
        $this->assertSame('research-assistant', $body['scope']['value']);
        $this->assertSame(2, $body['sample_count'], 'other-agent\'s records must not leak into this scope');
    }

    /**
     * GET /latency/agents (list form) includes an "unattributed" entry when
     * at least one in-scope response has agent_id IS NULL.
     */
    #[Test]
    public function get_agents_list_includes_an_unattributed_entry(): void
    {
        $user = User::factory()->create();

        $this->seedRun(['agent_id' => 'research-assistant', 'user_id' => $user->id, 'duration_ms' => 1200]);
        $this->seedRun(['agent_id' => null, 'user_id' => $user->id, 'duration_ms' => 900]);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("agents?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200);
        $values = collect($response->json('data'))->pluck('scope.value')->all();
        $this->assertContains('research-assistant', $values);
        $this->assertContains('unattributed', $values, 'a null agent_id must surface as the literal "unattributed", never a raw sentinel');
    }

    /**
     * The reserved "unattributed" path segment on the single-scope agent
     * endpoint resolves to WHERE agent_id IS NULL -- never merged into a
     * named agent's entry, and never the literal string "unattributed"
     * matched against the column.
     */
    #[Test]
    public function unattributed_path_segment_resolves_to_null_agent_id_scope(): void
    {
        $user = User::factory()->create();

        $this->seedRun(['agent_id' => null, 'user_id' => $user->id, 'duration_ms' => 700]);
        $this->seedRun(['agent_id' => null, 'user_id' => $user->id, 'duration_ms' => 1300]);
        $this->seedRun(['agent_id' => 'research-assistant', 'user_id' => $user->id, 'duration_ms' => 5000]);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("agents/unattributed?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertSame(2, $body['sample_count'], 'only the two null-agent_id rows belong to the unattributed scope');
        $this->assertSame('unattributed', $body['scope']['value']);
    }

    /**
     * 422 when from/to are missing.
     */
    #[Test]
    public function missing_from_or_to_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('models/claude-sonnet-5'));
        $response->assertStatus(422);

        $response = $this->actingAs($user)->getJson($this->endpoint("models/claude-sonnet-5?from={$this->today()}"));
        $response->assertStatus(422);

        $response = $this->actingAs($user)->getJson($this->endpoint("agents/research-assistant?to={$this->today()}"));
        $response->assertStatus(422);
    }

    /**
     * 422 when from/to are present but not valid dates.
     */
    #[Test]
    public function invalid_from_or_to_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(
            $this->endpoint('models/claude-sonnet-5?from=not-a-date&to='.$this->today())
        );
        $response->assertStatus(422);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint('agents/research-assistant?from='.$this->today().'&to=also-not-a-date')
        );
        $response->assertStatus(422);
    }

    /**
     * Acceptance Scenario 5 / FR-020 / SC-007: a period including a
     * failed/cancelled response reflects that response's elapsed time in
     * the sample -- verified by comparing sample_count and the percentile
     * shift against the same query with that one record excluded (deleted
     * between the two requests).
     */
    #[Test]
    public function failed_response_in_period_shifts_sample_count_and_percentile(): void
    {
        $user = User::factory()->create();

        $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $user->id, 'end_state' => 'completed', 'duration_ms' => 1000]);
        $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $user->id, 'end_state' => 'completed', 'duration_ms' => 1200]);

        $failedId = (string) Str::uuid();
        $this->seedRun([
            'id' => $failedId,
            'model' => 'claude-sonnet-5',
            'user_id' => $user->id,
            'end_state' => 'failed',
            'end_reason' => 'provider_error',
            'duration_ms' => 45000,
        ]);

        $withFailed = $this->actingAs($user)->getJson(
            $this->endpoint("models/claude-sonnet-5?from={$this->today()}&to={$this->today()}")
        )->json();

        $this->assertSame(3, $withFailed['sample_count']);
        $this->assertSame(45000, $withFailed['total']['whole']['p95_ms'], 'the failed response\'s elapsed time must be reflected in the worst-case figure');

        DB::table('agent_runs')->where('id', $failedId)->delete();

        $withoutFailed = $this->actingAs($user)->getJson(
            $this->endpoint("models/claude-sonnet-5?from={$this->today()}&to={$this->today()}")
        )->json();

        $this->assertSame(2, $withoutFailed['sample_count']);
        $this->assertNotSame(
            $withFailed['total']['whole']['p95_ms'],
            $withoutFailed['total']['whole']['p95_ms'],
            'removing the failed response must visibly change the reported distribution'
        );
    }

    /**
     * A response abandoned mid-flight (swept, not explicitly failed) is
     * also reflected -- exercising the second non-completed end_state
     * alongside "failed" above.
     */
    #[Test]
    public function abandoned_response_in_period_is_reflected_in_sample_count(): void
    {
        $user = User::factory()->create();

        $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $user->id, 'end_state' => 'completed', 'duration_ms' => 900]);
        $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $user->id, 'end_state' => 'abandoned', 'end_reason' => 'swept', 'duration_ms' => 600000]);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("models/claude-sonnet-5?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('sample_count'));
    }

    /**
     * Acceptance Scenario 3: comparing an earlier period against a more
     * recent one for the same model shows the difference rather than one
     * blended all-time figure.
     */
    #[Test]
    public function comparing_an_earlier_and_a_more_recent_period_shows_the_difference(): void
    {
        $user = User::factory()->create();

        // An earlier period: fast responses.
        $earlier = Carbon::now()->subDays(30);
        for ($i = 0; $i < 5; $i++) {
            $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $user->id, 'duration_ms' => 500, 'started_at' => $earlier->copy()]);
        }

        // A more recent period: slow responses (a regression).
        $recent = Carbon::now()->subDay();
        for ($i = 0; $i < 5; $i++) {
            $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $user->id, 'duration_ms' => 8000, 'started_at' => $recent->copy()]);
        }

        $earlierWindowFrom = Carbon::now()->subDays(31)->toDateString();
        $earlierWindowTo = Carbon::now()->subDays(29)->toDateString();
        $recentWindowFrom = Carbon::now()->subDays(2)->toDateString();
        $recentWindowTo = Carbon::now()->toDateString();

        $earlierResult = $this->actingAs($user)->getJson(
            $this->endpoint("models/claude-sonnet-5?from={$earlierWindowFrom}&to={$earlierWindowTo}")
        )->json();

        $recentResult = $this->actingAs($user)->getJson(
            $this->endpoint("models/claude-sonnet-5?from={$recentWindowFrom}&to={$recentWindowTo}")
        )->json();

        $this->assertSame(5, $earlierResult['sample_count']);
        $this->assertSame(5, $recentResult['sample_count']);
        $this->assertSame(500, $earlierResult['total']['whole']['p50_ms']);
        $this->assertSame(8000, $recentResult['total']['whole']['p50_ms']);
        $this->assertGreaterThan(
            $earlierResult['total']['whole']['p50_ms'],
            $recentResult['total']['whole']['p50_ms'],
            'the recent period\'s regression must be visible rather than hidden in a single blended figure'
        );
    }

    /**
     * Acceptance Scenario 4: streamed time-to-first-visible-output figures
     * and whole-response total-time figures are shown distinctly rather
     * than blended into one number.
     */
    #[Test]
    public function streamed_and_whole_figures_are_shown_distinctly(): void
    {
        $user = User::factory()->create();

        $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $user->id, 'is_streamed' => false, 'duration_ms' => 1800]);
        $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $user->id, 'is_streamed' => true, 'duration_ms' => 3200, 'first_output_ms' => 410]);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("models/claude-sonnet-5?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertSame(1800, $body['total']['whole']['p50_ms']);
        $this->assertSame(3200, $body['total']['streamed']['p50_ms']);
        $this->assertSame(410, $body['first_output']['p50_ms']);
        $this->assertNotSame($body['total']['whole']['p50_ms'], $body['total']['streamed']['p50_ms']);
    }
}
