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
        $result = $query->toolSummary('search_documents', 'day', $this->today(), null, (string) Str::uuid(), true);

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
        $result = $query->toolSummary('search_documents', 'day', $this->today(), null, (string) Str::uuid(), true);

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

        $todayResult = $query->toolSummary('search_documents', 'day', $this->today(), null, (string) Str::uuid(), true);
        $this->assertSame(5, $todayResult['invocation_count'], "today's read must not bleed in yesterday's figures");

        $yesterdayResult = $query->toolSummary('search_documents', 'day', $yesterday, null, (string) Str::uuid(), true);
        $this->assertSame(40, $yesterdayResult['invocation_count']);
        $this->assertSame(30, $yesterdayResult['failure_count']);
    }

    #[Test]
    public function a_scope_with_zero_matching_rows_returns_the_no_activity_shape_never_null_or_an_exception(): void
    {
        $query = new ToolReliabilityQuery();

        $result = $query->toolSummary('a_tool_name_that_has_never_existed', 'day', $this->today(), null, (string) Str::uuid(), true);

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
        $nine = $query->toolSummary('boundary_tool', 'day', $this->today(), null, (string) Str::uuid(), true);
        $this->assertTrue($nine['low_sample'], '9 invocations is below the fixed threshold of 10');
        $this->assertFalse($nine['no_activity']);

        DB::table('tool_reliability_summaries')
            ->where('tool_name', 'boundary_tool')
            ->update(['invocation_count' => 10, 'success_count' => 10]);

        $ten = $query->toolSummary('boundary_tool', 'day', $this->today(), null, (string) Str::uuid(), true);
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
        $result = $query->toolSummary('shared_tool', 'day', $this->today(), null, $caller, true);

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
        $result = $query->toolSummary('shared_tool', 'day', $this->today(), null, $owner, false);

        $this->assertIsArray($result, 'a non-operator scoping mismatch must never throw');
        $this->assertSame(3, $result['invocation_count'], "only the caller's own rows, never the stranger's");
        $this->assertSame(0, $result['failure_count']);
    }

    // === User Story 2 (Phase 4, T026): agent-scoping ===
    //
    // toolSummary() does not yet accept an $agentId parameter -- these tests
    // call it with the final signature data-model.md §4.4 declares
    // (toolName, periodType, date, agentId, callerId, isOperator), via named
    // arguments, so they fail against today's 5-parameter method with an
    // "Unknown named parameter $agentId" error until Phase 4's
    // implementation inserts it. toolAgentBreakdown() does not exist at all
    // yet and fails with an undefined-method error.

    #[Test]
    public function a_real_agent_id_filters_the_summary_to_that_agent_only(): void
    {
        $this->seedSummary(['tool_name' => 'shared_tool', 'agent_id' => 'agent-a', 'invocation_count' => 10, 'success_count' => 9, 'failure_count' => 1, 'failure_timeout_count' => 1]);
        $this->seedSummary(['tool_name' => 'shared_tool', 'agent_id' => 'agent-b', 'invocation_count' => 20, 'success_count' => 15, 'failure_count' => 5, 'failure_server_error_count' => 5]);

        $query = new ToolReliabilityQuery();
        $result = $query->toolSummary(
            toolName: 'shared_tool',
            periodType: 'day',
            date: $this->today(),
            agentId: 'agent-a',
            callerId: (string) Str::uuid(),
            isOperator: true,
        );

        $this->assertSame(10, $result['invocation_count'], 'agent-scoped read must reflect only agent-a, never agent-b');
        $this->assertSame(1, $result['failure_count']);
        $this->assertSame(1, $result['failure_breakdown']['timeout']);
        $this->assertSame(0, $result['failure_breakdown']['server_error'], "agent-b's failures must not leak into agent-a's scope");
    }

    #[Test]
    public function the_unattributed_sentinel_passed_explicitly_filters_to_unattributed_only(): void
    {
        $this->seedSummary(['tool_name' => 'shared_tool', 'agent_id' => ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET, 'invocation_count' => 4, 'success_count' => 4]);
        $this->seedSummary(['tool_name' => 'shared_tool', 'agent_id' => 'agent-a', 'invocation_count' => 10, 'success_count' => 10]);

        $query = new ToolReliabilityQuery();
        $result = $query->toolSummary(
            toolName: 'shared_tool',
            periodType: 'day',
            date: $this->today(),
            agentId: ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET,
            callerId: (string) Str::uuid(),
            isOperator: true,
        );

        $this->assertSame(4, $result['invocation_count'], 'the Unattributed sentinel scope must reflect only Unattributed rows, never a named agent');
    }

    #[Test]
    public function omitting_the_agent_scope_still_aggregates_across_every_agent(): void
    {
        $this->seedSummary(['tool_name' => 'shared_tool', 'agent_id' => 'agent-a', 'invocation_count' => 10, 'success_count' => 10]);
        $this->seedSummary(['tool_name' => 'shared_tool', 'agent_id' => 'agent-b', 'invocation_count' => 5, 'success_count' => 5]);
        $this->seedSummary(['tool_name' => 'shared_tool', 'agent_id' => ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET, 'invocation_count' => 3, 'success_count' => 3]);

        $query = new ToolReliabilityQuery();
        $result = $query->toolSummary(
            toolName: 'shared_tool',
            periodType: 'day',
            date: $this->today(),
            agentId: null,
            callerId: (string) Str::uuid(),
            isOperator: true,
        );

        $this->assertSame(18, $result['invocation_count'], 'US1 behavior is unchanged: omitting the agent scope sums across every agent bucket');
    }

    #[Test]
    public function tool_agent_breakdown_returns_one_entry_per_agent_including_an_explicit_unattributed_entry(): void
    {
        $this->seedSummary(['tool_name' => 'shared_tool', 'agent_id' => 'agent-a', 'invocation_count' => 100, 'success_count' => 99, 'failure_count' => 1, 'failure_timeout_count' => 1]);
        $this->seedSummary(['tool_name' => 'shared_tool', 'agent_id' => 'agent-b', 'invocation_count' => 20, 'success_count' => 5, 'failure_count' => 15, 'failure_server_error_count' => 15]);
        $this->seedSummary(['tool_name' => 'shared_tool', 'agent_id' => ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET, 'invocation_count' => 10, 'success_count' => 7, 'failure_count' => 3, 'failure_other_count' => 3]);

        $query = new ToolReliabilityQuery();
        $result = $query->toolAgentBreakdown(
            toolName: 'shared_tool',
            periodType: 'day',
            date: $this->today(),
            callerId: (string) Str::uuid(),
            isOperator: true,
        );

        $agentIds = array_column($result, 'agent_id');
        $this->assertContains('agent-a', $agentIds);
        $this->assertContains('agent-b', $agentIds);
        $this->assertContains(ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET, $agentIds, 'FR-013: invocations with no agent must appear as an explicit Unattributed entry, never silently dropped');
        $this->assertCount(3, $result);

        $this->assertSame('agent-b', $agentIds[0], 'ordering must be failure_count DESC (agent-b has the most failures)');
    }

    #[Test]
    public function access_scoping_applies_identically_to_the_agent_scoped_read_and_the_breakdown_list(): void
    {
        $owner = (string) Str::uuid();
        $stranger = (string) Str::uuid();

        $this->seedSummary(['tool_name' => 'shared_tool', 'agent_id' => 'agent-a', 'user_id' => $owner, 'invocation_count' => 3, 'success_count' => 3]);
        $this->seedSummary(['tool_name' => 'shared_tool', 'agent_id' => 'agent-a', 'user_id' => $stranger, 'invocation_count' => 50, 'success_count' => 0, 'failure_count' => 50, 'failure_other_count' => 50]);
        $this->seedSummary(['tool_name' => 'shared_tool', 'agent_id' => 'agent-b', 'user_id' => $stranger, 'invocation_count' => 9, 'success_count' => 9]);

        $query = new ToolReliabilityQuery();

        // Single-agent read: a non-operator's own contribution only, never 403.
        $singleAgent = $query->toolSummary(
            toolName: 'shared_tool',
            periodType: 'day',
            date: $this->today(),
            agentId: 'agent-a',
            callerId: $owner,
            isOperator: false,
        );
        $this->assertIsArray($singleAgent, 'a non-operator scoping mismatch must never throw');
        $this->assertSame(3, $singleAgent['invocation_count'], "only the caller's own agent-a rows, never the stranger's");
        $this->assertSame(0, $singleAgent['failure_count']);

        // Breakdown list: a non-operator sees only their own contribution to
        // each agent's total, and never an agent they never personally used.
        $breakdown = $query->toolAgentBreakdown(
            toolName: 'shared_tool',
            periodType: 'day',
            date: $this->today(),
            callerId: $owner,
            isOperator: false,
        );

        $agentIds = array_column($breakdown, 'agent_id');
        $this->assertContains('agent-a', $agentIds);
        $this->assertNotContains('agent-b', $agentIds, 'the caller never personally used agent-b for this tool, so it must not appear as a row');

        $agentARow = collect($breakdown)->firstWhere('agent_id', 'agent-a');
        $this->assertSame(3, $agentARow['invocation_count'], "the caller's own contribution only, never the stranger's 50 folded in");
    }

    // === 095-agent-summary-cards (T006, US2): agentSummary()/agentList() ===
    //
    // Neither method exists yet -- both fail with an undefined-method error
    // until data-model.md §6/research.md D2's implementation adds them.
    // Both are cross-tool (no $toolName at all, unlike every method above)
    // aggregates over a raw [$from, $to] range, mirroring
    // CostRollupQuery::agentTotal()/agentList()'s own shape exactly.

    #[Test]
    public function agent_list_sums_across_every_tool_name_for_a_given_agent(): void
    {
        $caller = (string) Str::uuid();
        $agentId = 'agent-multi-tool';

        $this->seedSummary(['tool_name' => 'search_documents', 'agent_id' => $agentId, 'user_id' => $caller, 'invocation_count' => 10, 'success_count' => 9, 'failure_count' => 1, 'failure_timeout_count' => 1]);
        $this->seedSummary(['tool_name' => 'send_email', 'agent_id' => $agentId, 'user_id' => $caller, 'invocation_count' => 5, 'success_count' => 5]);

        $query = new ToolReliabilityQuery();
        $result = $query->agentList($this->today(), $this->today(), $caller, true);

        $row = collect($result)->firstWhere('agent_id', $agentId);
        $this->assertNotNull($row, 'the agent must appear in the list, summed across every tool_name it used');
        $this->assertSame(15, $row['invocation_count'], 'invocation_count must be the sum across both tool_name rows, not just one');
        $this->assertSame(14, $row['success_count']);
        $this->assertSame(1, $row['failure_count']);
    }

    #[Test]
    public function agent_list_computes_low_sample_and_no_activity_identically_to_shapes_own_formula(): void
    {
        $caller = (string) Str::uuid();

        // Below ToolReliabilitySummary::LOW_SAMPLE_THRESHOLD (10).
        $this->seedSummary(['tool_name' => 'search_documents', 'agent_id' => 'agent-low-sample', 'user_id' => $caller, 'invocation_count' => 9, 'success_count' => 9]);
        // At the threshold, clears it.
        $this->seedSummary(['tool_name' => 'search_documents', 'agent_id' => 'agent-cleared', 'user_id' => $caller, 'invocation_count' => 10, 'success_count' => 10]);

        $query = new ToolReliabilityQuery();
        $result = $query->agentList($this->today(), $this->today(), $caller, true);

        $lowSampleRow = collect($result)->firstWhere('agent_id', 'agent-low-sample');
        $clearedRow = collect($result)->firstWhere('agent_id', 'agent-cleared');

        $this->assertNotNull($lowSampleRow);
        $this->assertNotNull($clearedRow);
        $this->assertTrue($lowSampleRow['low_sample'], '9 summed invocations is below the fixed threshold of 10, identical to shape()\'s own formula');
        $this->assertFalse($lowSampleRow['no_activity']);
        $this->assertFalse($clearedRow['low_sample'], '10 summed invocations clears the threshold, identical to shape()\'s own formula');
    }

    #[Test]
    public function agent_list_non_operator_scoping_restricts_to_the_callers_own_rows(): void
    {
        $owner = (string) Str::uuid();
        $stranger = (string) Str::uuid();
        $agentId = 'agent-shared-by-two-users';

        $this->seedSummary(['tool_name' => 'search_documents', 'agent_id' => $agentId, 'user_id' => $owner, 'invocation_count' => 3, 'success_count' => 3]);
        $this->seedSummary(['tool_name' => 'search_documents', 'agent_id' => $agentId, 'user_id' => $stranger, 'invocation_count' => 50, 'success_count' => 0, 'failure_count' => 50, 'failure_other_count' => 50]);

        $query = new ToolReliabilityQuery();

        $asOwner = $query->agentList($this->today(), $this->today(), $owner, false);
        $ownerRow = collect($asOwner)->firstWhere('agent_id', $agentId);
        $this->assertNotNull($ownerRow, 'a non-operator scoping mismatch must never throw or omit the caller\'s own row');
        $this->assertSame(3, $ownerRow['invocation_count'], "only the caller's own rows, never the stranger's");
        $this->assertSame(0, $ownerRow['failure_count']);

        $asOperator = $query->agentList($this->today(), $this->today(), $owner, true);
        $operatorRow = collect($asOperator)->firstWhere('agent_id', $agentId);
        $this->assertSame(53, $operatorRow['invocation_count'], 'an operator sees the full cross-user total');
    }

    #[Test]
    public function agent_list_omits_an_agent_with_zero_rows_in_range_rather_than_a_zero_value_row(): void
    {
        $caller = (string) Str::uuid();
        $this->seedSummary(['tool_name' => 'search_documents', 'agent_id' => 'agent-active', 'user_id' => $caller, 'invocation_count' => 5, 'success_count' => 5]);

        $query = new ToolReliabilityQuery();
        $result = $query->agentList($this->today(), $this->today(), $caller, true);

        $agentIds = array_column($result, 'agent_id');
        $this->assertContains('agent-active', $agentIds);
        $this->assertNotContains('agent-never-used-at-all', $agentIds, 'an agent with zero rows in range must be absent, never a zero-value row -- AgentSummaryQuery supplies the default shape for an absent key, not this method');
    }

    #[Test]
    public function agent_summary_sums_across_every_tool_name_for_the_given_agent_and_reports_no_activity_when_unmatched(): void
    {
        $caller = (string) Str::uuid();
        $agentId = 'agent-cross-tool';

        $this->seedSummary(['tool_name' => 'search_documents', 'agent_id' => $agentId, 'user_id' => $caller, 'invocation_count' => 6, 'success_count' => 6]);
        $this->seedSummary(['tool_name' => 'send_email', 'agent_id' => $agentId, 'user_id' => $caller, 'invocation_count' => 4, 'success_count' => 3, 'failure_count' => 1, 'failure_other_count' => 1]);

        $query = new ToolReliabilityQuery();
        $result = $query->agentSummary($agentId, $this->today(), $this->today(), $caller, true);

        $this->assertSame($agentId, $result['agent_id']);
        $this->assertSame(10, $result['invocation_count'], 'agentSummary() must sum across every tool_name for this agent, not just one');
        $this->assertSame(9, $result['success_count']);
        $this->assertSame(1, $result['failure_count']);
        $this->assertFalse($result['no_activity']);
        $this->assertFalse($result['low_sample']);

        $unmatched = $query->agentSummary('agent-never-used', $this->today(), $this->today(), $caller, true);
        $this->assertSame(0, $unmatched['invocation_count']);
        $this->assertTrue($unmatched['no_activity'], 'an unmatched agent must report the no_activity shape, never null or an exception');
    }
}
