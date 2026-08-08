<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\ToolReliabilityQuery;
use ClarionApp\LlmClient\Models\ToolReliabilitySummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for ToolReliabilityQuery (075-tool-reliability-rates, User
 * Story 1) -- role-scoped rollup reads directly against seeded
 * tool_reliability_summaries rows, independent of both the write path
 * (MetricsRecorder, covered by ToolReliabilityMetricsTest) and the HTTP
 * layer (ToolReliabilityController, covered by
 * ToolReliabilityRateJourneyTest instead).
 *
 * ToolReliabilityQuery does not exist yet at the time this test is written.
 * This test assumes the User Story 1 signature exactly as tasks.md's Phase
 * 3 implementation task describes it -- the all-agents aggregate only, with
 * no agent-scoping parameter yet (that arrives in the following phase,
 * which inserts a new $agentId parameter into the middle of this
 * signature):
 *
 *   toolSummary(string $toolName, string $periodType, string $date, ?string $callerId, bool $isOperator): array
 *   toolList(string $periodType, string $date, ?string $callerId, bool $isOperator): array
 *
 * Each toolSummary() call is expected to return the response shape:
 *   [
 *     'tool_name' => string, 'agent_id' => null,
 *     'period' => ['type' => ..., 'from' => ..., 'to' => ...],
 *     'invocation_count' => int, 'success_count' => int, 'failure_count' => int,
 *     'failure_breakdown' => ['timeout' => int, 'connection_failure' => int,
 *       'authentication_failure' => int, 'invalid_input' => int,
 *       'server_error' => int, 'other' => int, 'uncategorized' => int],
 *     'low_sample' => bool, 'no_activity' => bool,
 *   ]
 *
 * $callerId/$isOperator mirror CostRollupQueryTest's/LatencyQueryTest's
 * pattern, resolved by the controller from Auth::id()/
 * OperatorAccess::isOperator() and passed through -- this test exercises
 * the scoping decision at the service layer directly rather than through an
 * HTTP request.
 */
class ToolReliabilityQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('tool_reliability_summaries')->delete();
        parent::tearDown();
    }

    private function today(): string
    {
        return Carbon::now()->toDateString();
    }

    private function seedSummary(array $overrides = []): void
    {
        DB::table('tool_reliability_summaries')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tool_name' => 'search_documents',
            'agent_id' => ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET,
            'user_id' => (string) Str::uuid(),
            'period_date' => $this->today(),
            'invocation_count' => 1,
            'success_count' => 1,
            'failure_count' => 0,
            'failure_timeout_count' => 0,
            'failure_connection_failure_count' => 0,
            'failure_authentication_failure_count' => 0,
            'failure_invalid_input_count' => 0,
            'failure_server_error_count' => 0,
            'failure_other_count' => 0,
            'failure_uncategorized_count' => 0,
            'updated_at' => Carbon::now(),
        ], $overrides));
    }

    #[Test]
    public function a_mixed_period_reports_correct_counts_and_a_reconciling_breakdown(): void
    {
        $this->seedSummary([
            'tool_name' => 'search_documents',
            'invocation_count' => 8,
            'success_count' => 6,
            'failure_count' => 2,
            'failure_timeout_count' => 1,
            'failure_invalid_input_count' => 1,
        ]);

        $query = new ToolReliabilityQuery();
        $result = $query->toolSummary('search_documents', 'day', $this->today(), (string) Str::uuid(), true);

        $this->assertSame('search_documents', $result['tool_name']);
        $this->assertSame(8, $result['invocation_count']);
        $this->assertSame(6, $result['success_count']);
        $this->assertSame(2, $result['failure_count']);
        $this->assertSame(1, $result['failure_breakdown']['timeout']);
        $this->assertSame(1, $result['failure_breakdown']['invalid_input']);
        $this->assertSame(0, $result['failure_breakdown']['connection_failure']);

        $breakdownSum = array_sum($result['failure_breakdown']);
        $this->assertSame($result['failure_count'], $breakdownSum, 'the breakdown must account for every failure counted (FR-002)');
    }

    #[Test]
    public function a_period_with_only_successes_reports_a_real_all_zero_breakdown_not_omitted(): void
    {
        $this->seedSummary([
            'tool_name' => 'search_documents',
            'invocation_count' => 12,
            'success_count' => 12,
            'failure_count' => 0,
        ]);

        $query = new ToolReliabilityQuery();
        $result = $query->toolSummary('search_documents', 'day', $this->today(), (string) Str::uuid(), true);

        $this->assertSame(0, $result['failure_count']);
        $this->assertArrayHasKey('failure_breakdown', $result);
        foreach ($result['failure_breakdown'] as $kind => $count) {
            $this->assertSame(0, $count, "failure_breakdown.{$kind} must be a real computed 0, never omitted");
        }
        $this->assertFalse($result['no_activity']);
    }

    #[Test]
    public function two_different_dates_for_the_same_period_type_are_independent(): void
    {
        $yesterday = Carbon::now()->subDay()->toDateString();

        $this->seedSummary(['tool_name' => 'search_documents', 'period_date' => $this->today(), 'invocation_count' => 5, 'success_count' => 5]);
        $this->seedSummary(['tool_name' => 'search_documents', 'period_date' => $yesterday, 'invocation_count' => 40, 'success_count' => 10, 'failure_count' => 30, 'failure_server_error_count' => 30]);

        $query = new ToolReliabilityQuery();

        $todayResult = $query->toolSummary('search_documents', 'day', $this->today(), (string) Str::uuid(), true);
        $this->assertSame(5, $todayResult['invocation_count'], "today's read must not bleed in yesterday's figures");

        $yesterdayResult = $query->toolSummary('search_documents', 'day', $yesterday, (string) Str::uuid(), true);
        $this->assertSame(40, $yesterdayResult['invocation_count']);
        $this->assertSame(30, $yesterdayResult['failure_count']);
    }

    #[Test]
    public function a_scope_with_zero_matching_rows_returns_the_no_activity_shape_never_null_or_an_exception(): void
    {
        $query = new ToolReliabilityQuery();

        $result = $query->toolSummary('a_tool_name_that_has_never_existed', 'day', $this->today(), (string) Str::uuid(), true);

        $this->assertIsArray($result);
        $this->assertSame(0, $result['invocation_count']);
        $this->assertSame(0, $result['success_count']);
        $this->assertSame(0, $result['failure_count']);
        $this->assertTrue($result['no_activity']);
        foreach ($result['failure_breakdown'] as $count) {
            $this->assertSame(0, $count);
        }
    }

    #[Test]
    public function low_sample_is_true_at_nine_summed_invocations_and_false_at_ten(): void
    {
        $this->seedSummary(['tool_name' => 'boundary_tool', 'invocation_count' => 9, 'success_count' => 9]);

        $query = new ToolReliabilityQuery();
        $nine = $query->toolSummary('boundary_tool', 'day', $this->today(), (string) Str::uuid(), true);
        $this->assertTrue($nine['low_sample'], '9 invocations is below the fixed threshold of 10');
        $this->assertFalse($nine['no_activity']);

        DB::table('tool_reliability_summaries')
            ->where('tool_name', 'boundary_tool')
            ->update(['invocation_count' => 10, 'success_count' => 10]);

        $ten = $query->toolSummary('boundary_tool', 'day', $this->today(), (string) Str::uuid(), true);
        $this->assertFalse($ten['low_sample'], '10 invocations must clear the threshold');
    }

    #[Test]
    public function tool_list_returns_one_entry_per_tool_ordered_by_failure_count_desc_then_invocation_count_desc(): void
    {
        $this->seedSummary(['tool_name' => 'low_failures_high_volume', 'invocation_count' => 900, 'success_count' => 895, 'failure_count' => 5, 'failure_other_count' => 5]);
        $this->seedSummary(['tool_name' => 'worst_offender', 'invocation_count' => 50, 'success_count' => 0, 'failure_count' => 50, 'failure_server_error_count' => 50]);
        $this->seedSummary(['tool_name' => 'mid_offender', 'invocation_count' => 20, 'success_count' => 10, 'failure_count' => 10, 'failure_timeout_count' => 10]);
        // A second row for worst_offender the same day, a different agent
        // bucket, proving the list aggregates across every row for a tool
        // rather than reading a single row.
        $this->seedSummary(['tool_name' => 'worst_offender', 'agent_id' => 'research-assistant', 'invocation_count' => 5, 'success_count' => 0, 'failure_count' => 5, 'failure_server_error_count' => 5]);

        $query = new ToolReliabilityQuery();
        $result = $query->toolList('day', $this->today(), (string) Str::uuid(), true);

        $names = array_column($result, 'tool_name');
        $this->assertSame(['worst_offender', 'mid_offender', 'low_failures_high_volume'], $names, 'ordering must be failure_count DESC, then invocation_count DESC');

        $worst = $result[0];
        $this->assertSame(55, $worst['failure_count'], 'the list must sum every in-scope row for the tool, not just one');
    }

    #[Test]
    public function tool_list_omits_a_tool_with_zero_activity_in_the_period(): void
    {
        $this->seedSummary(['tool_name' => 'active_tool', 'invocation_count' => 3, 'success_count' => 3]);

        $query = new ToolReliabilityQuery();
        $result = $query->toolList('day', $this->today(), (string) Str::uuid(), true);

        $names = array_column($result, 'tool_name');
        $this->assertContains('active_tool', $names);
        $this->assertNotContains('a_tool_with_no_rows_at_all', $names);
    }

    #[Test]
    public function an_operators_read_is_unrestricted_across_every_user_id(): void
    {
        $userA = (string) Str::uuid();
        $userB = (string) Str::uuid();
        $caller = (string) Str::uuid();

        $this->seedSummary(['tool_name' => 'shared_tool', 'user_id' => $userA, 'invocation_count' => 3, 'success_count' => 3]);
        $this->seedSummary(['tool_name' => 'shared_tool', 'user_id' => $userB, 'invocation_count' => 4, 'success_count' => 4]);

        $query = new ToolReliabilityQuery();
        $result = $query->toolSummary('shared_tool', 'day', $this->today(), $caller, true);

        $this->assertSame(7, $result['invocation_count'], "an operator sees every user's contribution, including a caller who contributed none");
    }

    #[Test]
    public function a_non_operators_read_is_narrowed_to_their_own_rows_never_a_403_or_exception(): void
    {
        $owner = (string) Str::uuid();
        $stranger = (string) Str::uuid();

        $this->seedSummary(['tool_name' => 'shared_tool', 'user_id' => $owner, 'invocation_count' => 3, 'success_count' => 3]);
        $this->seedSummary(['tool_name' => 'shared_tool', 'user_id' => $stranger, 'invocation_count' => 9, 'success_count' => 0, 'failure_count' => 9, 'failure_other_count' => 9]);

        $query = new ToolReliabilityQuery();
        $result = $query->toolSummary('shared_tool', 'day', $this->today(), $owner, false);

        $this->assertIsArray($result, 'a non-operator scoping mismatch must never throw');
        $this->assertSame(3, $result['invocation_count'], "only the caller's own rows, never the stranger's");
        $this->assertSame(0, $result['failure_count']);
    }
}
