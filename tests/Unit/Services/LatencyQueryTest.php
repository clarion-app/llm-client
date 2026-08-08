<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\LatencyQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for LatencyQuery (074-latency-metrics, User Story 2) --
 * role-scoped percentile reads directly against seeded agent_runs rows,
 * independent of both the write path (RunTraceRecorder, covered by the
 * User Story 1 tests) and the HTTP layer (LatencyController, covered by
 * LatencyDistributionJourneyTest instead).
 *
 * LatencyQuery does not exist yet at the time this test is written (T032
 * precedes T034 per tasks.md's Dependencies section), so its method names
 * are not fixed by data-model.md/contracts/latency-api.md -- those describe
 * the LatencyDistribution array shape (data-model.md §4) and the four HTTP
 * endpoints (contracts/latency-api.md §1), but not the underlying service's
 * API. This test assumes the following shape, the most direct mirror of
 * CostRollupQuery's per-scope show/list naming (plan.md, research.md D6),
 * documented here so the later implementation task (T034) can either match
 * it or this file can be adjusted to match a different real signature:
 *
 *   modelDistribution(string $model, string $from, string $to, ?string $callerId, bool $isOperator): array
 *   modelList(string $from, string $to, ?string $callerId, bool $isOperator): array
 *   agentDistribution(?string $agentId, string $from, string $to, ?string $callerId, bool $isOperator): array
 *   agentList(string $from, string $to, ?string $callerId, bool $isOperator): array
 *
 * agentDistribution's $agentId is nullable -- null means the reserved
 * "unattributed" scope (WHERE agent_id IS NULL, research.md D8), resolved
 * by the controller (T035) from the "unattributed" path segment.
 *
 * Each *Distribution() method returns data-model.md §4's exact
 * LatencyDistribution shape:
 *   [
 *     'scope' => ['type' => 'model'|'agent', 'value' => string],
 *     'from' => string, 'to' => string,
 *     'sample_count' => int, 'no_data' => bool,
 *     'total' => [
 *         'whole' => ['p50_ms' => int, 'p95_ms' => int]|null,
 *         'streamed' => ['p50_ms' => int, 'p95_ms' => int]|null,
 *     ],
 *     'first_output' => ['p50_ms' => int, 'p95_ms' => int]|null,
 *   ]
 *
 * The $callerId/$isOperator pair mirrors CostRollupQueryTest's pattern,
 * resolved by the controller from Auth::id()/OperatorAccess::isOperator()
 * and passed through -- this test exercises the scoping decision at the
 * service layer directly rather than through an HTTP request.
 */
class LatencyQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('agent_runs')->delete();
        parent::tearDown();
    }

    private function today(): string
    {
        return Carbon::now()->toDateString();
    }

    /**
     * Inserts one agent_runs row directly, bypassing RunTraceRecorder so
     * every column relevant to LatencyQuery (end_state, duration_ms,
     * is_streamed, first_output_ms, model, agent_id, user_id, started_at)
     * can be set to an exact, deterministic value.
     */
    private function seedRun(array $overrides = []): void
    {
        $startedAt = Carbon::now()->subMinutes(5);

        DB::table('agent_runs')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'kind' => 'interactive',
            'user_id' => (string) Str::uuid(),
            'conversation_id' => null,
            'source' => null,
            'end_state' => 'completed',
            'end_reason' => null,
            'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
            'ended_at' => Carbon::now()->format('Y-m-d H:i:s.u'),
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
     * A skewed sample -- nine responses at 100ms and one outlier at
     * 9000ms -- proves the service reports nearest-rank percentiles, not
     * the mean. Nearest-rank over the sorted 10-value sample:
     *   p50: index = ceil(0.5 * 10) - 1 = 4  -> 100
     *   p95: index = ceil(0.95 * 10) - 1 = 9 -> 9000 (the outlier)
     * The mean would be 990 -- distinct from both nearest-rank figures,
     * so an implementation using the mean instead would fail this
     * assertion (mutation-checklist row 8).
     */
    #[Test]
    public function model_scoped_fetch_reports_nearest_rank_percentiles_not_the_mean(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->seedRun(['model' => 'claude-sonnet-5', 'duration_ms' => 100, 'is_streamed' => false]);
        }
        $this->seedRun(['model' => 'claude-sonnet-5', 'duration_ms' => 9000, 'is_streamed' => false]);

        $query = new LatencyQuery();
        $result = $query->modelDistribution('claude-sonnet-5', $this->today(), $this->today(), (string) Str::uuid(), true);

        $this->assertSame(10, $result['sample_count']);
        $this->assertFalse($result['no_data']);
        $this->assertSame(100, $result['total']['whole']['p50_ms']);
        $this->assertSame(9000, $result['total']['whole']['p95_ms']);
        $this->assertNotSame($result['total']['whole']['p50_ms'], $result['total']['whole']['p95_ms']);
    }

    /**
     * The same nearest-rank proof, scoped by agent_id instead of model.
     */
    #[Test]
    public function agent_scoped_fetch_reports_nearest_rank_percentiles_not_the_mean(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->seedRun(['agent_id' => 'research-assistant', 'duration_ms' => 200, 'is_streamed' => false]);
        }
        $this->seedRun(['agent_id' => 'research-assistant', 'duration_ms' => 18000, 'is_streamed' => false]);

        $query = new LatencyQuery();
        $result = $query->agentDistribution('research-assistant', $this->today(), $this->today(), (string) Str::uuid(), true);

        $this->assertSame(10, $result['sample_count']);
        $this->assertSame(200, $result['total']['whole']['p50_ms']);
        $this->assertSame(18000, $result['total']['whole']['p95_ms']);
    }

    /**
     * The worst-case figure is read from
     * config('llm-client.latency.worst_case_percentile'), not hardcoded to
     * 95 -- lowering it to 50 collapses the worst-case figure onto the
     * typical figure for this sample.
     */
    #[Test]
    public function worst_case_percentile_is_read_from_config(): void
    {
        config(['llm-client.latency.worst_case_percentile' => 50]);

        for ($i = 0; $i < 9; $i++) {
            $this->seedRun(['model' => 'claude-sonnet-5', 'duration_ms' => 100, 'is_streamed' => false]);
        }
        $this->seedRun(['model' => 'claude-sonnet-5', 'duration_ms' => 9000, 'is_streamed' => false]);

        $query = new LatencyQuery();
        $result = $query->modelDistribution('claude-sonnet-5', $this->today(), $this->today(), (string) Str::uuid(), true);

        $this->assertSame(100, $result['total']['whole']['p50_ms']);
        $this->assertSame(
            $result['total']['whole']['p50_ms'],
            $result['total']['whole']['p95_ms'],
            'with worst_case_percentile configured to 50, the worst-case figure must equal the typical figure for this sample'
        );
    }

    /**
     * Row selection excludes only end_state = 'in_progress' -- a failed or
     * a cancelled/abandoned response's duration_ms must still be reflected
     * in the scoped sample (FR-020, mutation-checklist row 7). Filtering to
     * end_state = 'completed' only is the natural-looking mistake this test
     * guards against.
     */
    #[Test]
    public function row_selection_includes_failed_and_abandoned_responses_but_excludes_in_progress(): void
    {
        $this->seedRun(['model' => 'claude-sonnet-5', 'end_state' => 'completed', 'duration_ms' => 1000]);
        $this->seedRun(['model' => 'claude-sonnet-5', 'end_state' => 'failed', 'duration_ms' => 30000, 'end_reason' => 'provider_error']);
        $this->seedRun(['model' => 'claude-sonnet-5', 'end_state' => 'abandoned', 'duration_ms' => 60000, 'end_reason' => 'swept']);
        // An in_progress run has no final duration_ms yet -- must be excluded.
        $this->seedRun(['model' => 'claude-sonnet-5', 'end_state' => 'in_progress', 'duration_ms' => null]);

        $query = new LatencyQuery();
        $result = $query->modelDistribution('claude-sonnet-5', $this->today(), $this->today(), (string) Str::uuid(), true);

        $this->assertSame(3, $result['sample_count'], 'completed + failed + abandoned = 3; in_progress excluded');
        // p95 (nearest-rank over [1000, 30000, 60000]) must reflect the slow
        // failed/abandoned responses, not just the successful one.
        $this->assertSame(60000, $result['total']['whole']['p95_ms']);
    }

    /**
     * total.whole and total.streamed are computed and reported as separate
     * objects, never blended into one figure -- a scope with only whole
     * responses reports total.streamed as null, and vice versa.
     */
    #[Test]
    public function total_whole_and_total_streamed_are_separate_and_null_when_empty(): void
    {
        $this->seedRun(['model' => 'claude-sonnet-5', 'is_streamed' => false, 'duration_ms' => 1500]);
        $this->seedRun(['model' => 'claude-sonnet-5', 'is_streamed' => false, 'duration_ms' => 2500]);

        $query = new LatencyQuery();
        $result = $query->modelDistribution('claude-sonnet-5', $this->today(), $this->today(), (string) Str::uuid(), true);

        $this->assertNotNull($result['total']['whole']);
        $this->assertNull($result['total']['streamed'], 'no streamed rows in scope -- must be null, never a fabricated figure');

        DB::table('agent_runs')->delete();

        $this->seedRun(['model' => 'claude-sonnet-5', 'is_streamed' => true, 'duration_ms' => 4000, 'first_output_ms' => 300]);
        $this->seedRun(['model' => 'claude-sonnet-5', 'is_streamed' => true, 'duration_ms' => 5000, 'first_output_ms' => 400]);

        $result = $query->modelDistribution('claude-sonnet-5', $this->today(), $this->today(), (string) Str::uuid(), true);

        $this->assertNull($result['total']['whole'], 'no whole rows in scope -- must be null');
        $this->assertNotNull($result['total']['streamed']);
    }

    /**
     * first_output percentiles are computed only over
     * is_streamed = true AND first_output_ms IS NOT NULL rows -- a streamed
     * response that never reached visible output (first_output_ms null)
     * contributes to total.streamed but must not pull first_output's
     * percentiles toward a fabricated value.
     */
    #[Test]
    public function first_output_percentiles_exclude_streamed_rows_with_no_first_output(): void
    {
        $this->seedRun(['model' => 'claude-sonnet-5', 'is_streamed' => true, 'duration_ms' => 4000, 'first_output_ms' => 300]);
        $this->seedRun(['model' => 'claude-sonnet-5', 'is_streamed' => true, 'duration_ms' => 5000, 'first_output_ms' => 500]);
        // Streamed but failed before any output -- must be excluded from first_output.
        $this->seedRun(['model' => 'claude-sonnet-5', 'is_streamed' => true, 'end_state' => 'failed', 'end_reason' => 'timeout', 'duration_ms' => 100, 'first_output_ms' => null]);

        $query = new LatencyQuery();
        $result = $query->modelDistribution('claude-sonnet-5', $this->today(), $this->today(), (string) Str::uuid(), true);

        $this->assertSame(3, $result['sample_count'], 'sanity: three streamed rows in the sample overall');
        $this->assertNotNull($result['first_output']);
        // Both first_output_ms values (300, 500) are within [300,500] -- the
        // null-first_output row must not appear as a 0 or otherwise skew this.
        $this->assertGreaterThanOrEqual(300, $result['first_output']['p50_ms']);
        $this->assertLessThanOrEqual(500, $result['first_output']['p95_ms']);
    }

    /**
     * An empty scope/period returns sample_count: 0, no_data: true, with
     * every percentile object null -- never a fabricated zero.
     */
    #[Test]
    public function empty_scope_returns_no_data_with_every_percentile_object_null(): void
    {
        $query = new LatencyQuery();
        $result = $query->modelDistribution('some-unused-model', $this->today(), $this->today(), (string) Str::uuid(), true);

        $this->assertSame(0, $result['sample_count']);
        $this->assertTrue($result['no_data']);
        $this->assertNull($result['total']['whole']);
        $this->assertNull($result['total']['streamed']);
        $this->assertNull($result['first_output']);
    }

    /**
     * An empty agent scope behaves identically to an empty model scope.
     */
    #[Test]
    public function empty_agent_scope_returns_no_data(): void
    {
        $query = new LatencyQuery();
        $result = $query->agentDistribution('unused-agent', $this->today(), $this->today(), (string) Str::uuid(), true);

        $this->assertSame(0, $result['sample_count']);
        $this->assertTrue($result['no_data']);
    }

    /**
     * Role-scoping: an operator's fetch is unrestricted (sees every user's
     * responses for the scope); a non-operator's fetch is restricted to
     * their own (FR-021), mirroring CostRollupQuery's split.
     */
    #[Test]
    public function operator_fetch_is_unrestricted_non_operator_fetch_is_restricted_to_own(): void
    {
        $ownerId = (string) Str::uuid();
        $strangerId = (string) Str::uuid();

        $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $ownerId, 'duration_ms' => 1000]);
        $this->seedRun(['model' => 'claude-sonnet-5', 'user_id' => $strangerId, 'duration_ms' => 2000]);

        $query = new LatencyQuery();

        $operatorResult = $query->modelDistribution('claude-sonnet-5', $this->today(), $this->today(), $strangerId, true);
        $this->assertSame(2, $operatorResult['sample_count'], 'an operator sees every user\'s responses for the model');

        $nonOperatorResult = $query->modelDistribution('claude-sonnet-5', $this->today(), $this->today(), $strangerId, false);
        $this->assertSame(1, $nonOperatorResult['sample_count'], 'a non-operator sees only their own responses');
    }

    /**
     * Role-scoping applies identically to the agent-scoped fetch.
     */
    #[Test]
    public function non_operator_agent_fetch_is_restricted_to_own_responses(): void
    {
        $ownerId = (string) Str::uuid();
        $strangerId = (string) Str::uuid();

        $this->seedRun(['agent_id' => 'shared-agent', 'user_id' => $ownerId, 'duration_ms' => 1000]);
        $this->seedRun(['agent_id' => 'shared-agent', 'user_id' => $strangerId, 'duration_ms' => 2000]);

        $query = new LatencyQuery();

        $nonOperatorResult = $query->agentDistribution('shared-agent', $this->today(), $this->today(), $strangerId, false);
        $this->assertSame(1, $nonOperatorResult['sample_count']);

        $operatorResult = $query->agentDistribution('shared-agent', $this->today(), $this->today(), $strangerId, true);
        $this->assertSame(2, $operatorResult['sample_count']);
    }
}
