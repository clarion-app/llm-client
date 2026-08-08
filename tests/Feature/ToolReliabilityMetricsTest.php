<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Models\ToolReliabilitySummary;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class ToolReliabilityMetricsTest extends TestCase
{
    #[Test]
    public function tool_invocation_records_success_outcome()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'search_documents',
            success: true,
        );

        $record = ToolInvocationRecord::first();
        $this->assertNotNull($record);
        $this->assertEquals('search_documents', $record->tool_name);
        $this->assertEquals('success', $record->outcome);
        $this->assertNull($record->failure_category);
    }

    #[Test]
    public function tool_invocation_records_failure_with_category()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'fetch_url',
            success: false,
            failureCategory: ToolFailureCategory::ConnectionFailure,
        );

        $record = ToolInvocationRecord::first();
        $this->assertNotNull($record);
        $this->assertEquals('fetch_url', $record->tool_name);
        $this->assertEquals('failure', $record->outcome);
        $this->assertEquals(ToolFailureCategory::ConnectionFailure, $record->failure_category);
    }

    #[Test]
    public function tool_invocation_records_all_failure_categories()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $attemptGroupId = (string) Str::uuid();

        $categories = [
            ToolFailureCategory::Timeout,
            ToolFailureCategory::ConnectionFailure,
            ToolFailureCategory::AuthenticationFailure,
            ToolFailureCategory::InvalidInput,
            ToolFailureCategory::ServerError,
            ToolFailureCategory::Other,
        ];

        foreach ($categories as $category) {
            $recorder->recordToolInvocation(
                conversationId: $conversationId,
                userId: $userId,
                attemptGroupId: $attemptGroupId,
                toolName: 'test_tool',
                success: false,
                failureCategory: $category,
            );
        }

        $this->assertDatabaseCount('tool_invocation_records', 6);

        $grouped = ToolInvocationRecord::groupByFailureCategory(conversationId: $conversationId);
        $this->assertCount(6, $grouped);
    }

    #[Test]
    public function failure_rate_calculation()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // 5 successes, 2 failures = 28.6% failure rate
        for ($i = 0; $i < 5; $i++) {
            $recorder->recordToolInvocation(
                conversationId: $conversationId,
                userId: $userId,
                attemptGroupId: (string) Str::uuid(),
                toolName: 'test_tool',
                success: true,
            );
        }
        for ($i = 0; $i < 2; $i++) {
            $recorder->recordToolInvocation(
                conversationId: $conversationId,
                userId: $userId,
                attemptGroupId: (string) Str::uuid(),
                toolName: 'test_tool',
                success: false,
                failureCategory: ToolFailureCategory::Timeout,
            );
        }

        $rate = ToolInvocationRecord::failureRate(conversationId: $conversationId);
        $this->assertEquals(2 / 7, $rate, 0.001);
    }

    #[Test]
    public function failure_rate_returns_zero_when_no_records()
    {
        $rate = ToolInvocationRecord::failureRate(conversationId: (string) Str::uuid());
        $this->assertEquals(0.0, $rate);
    }

    #[Test]
    public function failure_rate_by_tool_name()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // search_documents: 3 successes
        for ($i = 0; $i < 3; $i++) {
            $recorder->recordToolInvocation(
                conversationId: $conversationId,
                userId: $userId,
                attemptGroupId: (string) Str::uuid(),
                toolName: 'search_documents',
                success: true,
            );
        }

        // fetch_url: 1 success, 1 failure
        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'fetch_url',
            success: true,
        );
        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'fetch_url',
            success: false,
            failureCategory: ToolFailureCategory::ServerError,
        );

        $searchRate = ToolInvocationRecord::failureRate(conversationId: $conversationId, toolName: 'search_documents');
        $fetchRate = ToolInvocationRecord::failureRate(conversationId: $conversationId, toolName: 'fetch_url');

        $this->assertEquals(0.0, $searchRate);
        $this->assertEquals(0.5, $fetchRate);
    }

    #[Test]
    public function group_by_failure_category()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // 2 timeouts, 1 connection failure, 1 server error
        for ($i = 0; $i < 2; $i++) {
            $recorder->recordToolInvocation(
                conversationId: $conversationId,
                userId: $userId,
                attemptGroupId: (string) Str::uuid(),
                toolName: 'test_tool',
                success: false,
                failureCategory: ToolFailureCategory::Timeout,
            );
        }
        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'test_tool',
            success: false,
            failureCategory: ToolFailureCategory::ConnectionFailure,
        );
        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'test_tool',
            success: false,
            failureCategory: ToolFailureCategory::ServerError,
        );

        // Add some successes (should not appear in grouped failures)
        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'test_tool',
            success: true,
        );

        $grouped = ToolInvocationRecord::groupByFailureCategory(conversationId: $conversationId);
        $this->assertArrayHasKey('timeout', $grouped);
        $this->assertArrayHasKey('connection_failure', $grouped);
        $this->assertArrayHasKey('server_error', $grouped);
        $this->assertEquals(2, $grouped['timeout']);
        $this->assertEquals(1, $grouped['connection_failure']);
        $this->assertEquals(1, $grouped['server_error']);
    }

    #[Test]
    public function scope_for_conversation_filters_records()
    {
        $recorder = new MetricsRecorder();
        $conv1 = (string) Str::uuid();
        $conv2 = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $recorder->recordToolInvocation(
            conversationId: $conv1,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'tool_a',
            success: true,
        );
        $recorder->recordToolInvocation(
            conversationId: $conv1,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'tool_b',
            success: false,
            failureCategory: ToolFailureCategory::Timeout,
        );
        $recorder->recordToolInvocation(
            conversationId: $conv2,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'tool_c',
            success: true,
        );

        $conv1Records = ToolInvocationRecord::forConversation($conv1)->get();
        $this->assertCount(2, $conv1Records);

        $conv2Records = ToolInvocationRecord::forConversation($conv2)->get();
        $this->assertCount(1, $conv2Records);
    }

    #[Test]
    public function scope_between_dates_filters_by_time_range()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'tool_a',
            success: true,
        );

        $now = now();
        $yesterday = $now->copy()->subDay();

        $records = ToolInvocationRecord::forConversation($conversationId)
            ->betweenDates($yesterday, $now)
            ->get();
        $this->assertCount(1, $records);
    }

    #[Test]
    public function scope_recent_failures()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'tool_a',
            success: true,
        );
        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'tool_b',
            success: false,
            failureCategory: ToolFailureCategory::ServerError,
        );
        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'tool_c',
            success: false,
            failureCategory: ToolFailureCategory::Timeout,
        );

        $recentFailures = ToolInvocationRecord::forConversation($conversationId)
            ->recentFailures(24)
            ->get();
        $this->assertCount(2, $recentFailures);
        $this->assertTrue($recentFailures->every(fn($r) => $r->outcome === 'failure'));
    }

    /**
     * The new $agentId parameter is written verbatim to
     * tool_invocation_records.agent_id when passed, and NULL when omitted --
     * never a fabricated/derived value. An explicit created_at is also
     * captured on every call, non-null on the detail row.
     */
    #[Test]
    public function agent_id_is_written_verbatim_when_passed_and_null_when_omitted(): void
    {
        $recorder = new MetricsRecorder();
        $userId = (string) Str::uuid();

        $recorder->recordToolInvocation(
            conversationId: (string) Str::uuid(),
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'search_documents',
            success: true,
            agentId: 'research-assistant',
        );

        $withAgent = ToolInvocationRecord::where('tool_name', 'search_documents')->first();
        $this->assertNotNull($withAgent);
        $this->assertSame('research-assistant', $withAgent->agent_id);
        $this->assertNotNull($withAgent->created_at, 'created_at must be explicitly captured at write time, non-null on the detail row');

        $recorder->recordToolInvocation(
            conversationId: (string) Str::uuid(),
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'fetch_url',
            success: true,
        );

        $withoutAgent = ToolInvocationRecord::where('tool_name', 'fetch_url')->first();
        $this->assertNotNull($withoutAgent);
        $this->assertNull($withoutAgent->agent_id, 'Omitting agentId must record NULL, never a fabricated/derived identifier');
    }

    /**
     * One recordToolInvocation() call upserts exactly one
     * tool_reliability_summaries row, keyed (tool_name, agent_id-or-
     * Unattributed, user_id, period_date), with invocation_count = 1 and
     * exactly one of success_count/failure_count incremented. An invocation
     * carrying no agentId buckets under the reserved Unattributed sentinel,
     * never a NULL agent_id column.
     */
    #[Test]
    public function recording_a_tool_invocation_upserts_exactly_one_correctly_bucketed_summary_row(): void
    {
        $recorder = new MetricsRecorder();
        $userId = (string) Str::uuid();
        $today = now()->toDateString();

        $recorder->recordToolInvocation(
            conversationId: (string) Str::uuid(),
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'search_documents',
            success: true,
            agentId: 'research-assistant',
        );

        $this->assertDatabaseCount('tool_reliability_summaries', 1);

        $row = DB::table('tool_reliability_summaries')
            ->where('tool_name', 'search_documents')
            ->where('agent_id', 'research-assistant')
            ->where('user_id', $userId)
            ->where('period_date', $today)
            ->first();

        $this->assertNotNull($row, 'the summary row must be keyed (tool_name, agent_id, user_id, period_date)');
        $this->assertSame(1, (int) $row->invocation_count);
        $this->assertSame(1, (int) $row->success_count);
        $this->assertSame(0, (int) $row->failure_count);

        $recorder->recordToolInvocation(
            conversationId: (string) Str::uuid(),
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'fetch_url',
            success: false,
            failureCategory: ToolFailureCategory::Timeout,
        );

        $this->assertDatabaseCount('tool_reliability_summaries', 2);

        $unattributedRow = DB::table('tool_reliability_summaries')
            ->where('tool_name', 'fetch_url')
            ->where('agent_id', ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET)
            ->where('user_id', $userId)
            ->where('period_date', $today)
            ->first();

        $this->assertNotNull($unattributedRow, 'an invocation with no agentId must bucket under the reserved Unattributed sentinel, never a NULL agent_id column');
        $this->assertSame(1, (int) $unattributedRow->invocation_count);
        $this->assertSame(0, (int) $unattributedRow->success_count);
        $this->assertSame(1, (int) $unattributedRow->failure_count);
    }

    /**
     * A second recordToolInvocation() call for the identical bucket
     * accumulates atomically -- one row, invocation_count = 2, no lost
     * update.
     */
    #[Test]
    public function a_second_call_for_the_identical_bucket_accumulates_atomically(): void
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'search_documents',
            success: true,
            agentId: 'research-assistant',
        );
        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'search_documents',
            success: false,
            failureCategory: ToolFailureCategory::ServerError,
            agentId: 'research-assistant',
        );

        $this->assertDatabaseCount('tool_reliability_summaries', 1);

        $row = DB::table('tool_reliability_summaries')
            ->where('tool_name', 'search_documents')
            ->where('agent_id', 'research-assistant')
            ->where('user_id', $userId)
            ->first();

        $this->assertSame(2, (int) $row->invocation_count, 'no lost update across two calls for the identical bucket');
        $this->assertSame(1, (int) $row->success_count);
        $this->assertSame(1, (int) $row->failure_count);
        $this->assertSame(1, (int) $row->failure_server_error_count);
    }

    /**
     * A failure recorded with failureCategory: null increments
     * failure_uncategorized_count -- never failure_other_count, which is
     * itself a real, distinct classification.
     */
    #[Test]
    public function an_uncategorized_failure_increments_the_uncategorized_column_not_other(): void
    {
        $recorder = new MetricsRecorder();

        $recorder->recordToolInvocation(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            toolName: 'search_documents',
            success: false,
            failureCategory: null,
            agentId: 'research-assistant',
        );

        $row = DB::table('tool_reliability_summaries')->where('tool_name', 'search_documents')->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->failure_uncategorized_count);
        $this->assertSame(0, (int) $row->failure_other_count, 'an uncategorized failure must never be folded into the Other classification');
        $this->assertSame(1, (int) $row->failure_count);
    }

    /**
     * Each of the six named ToolFailureCategory cases increments its own
     * matching summary column, and no other.
     */
    #[Test]
    public function each_named_failure_category_increments_only_its_own_column(): void
    {
        $recorder = new MetricsRecorder();
        $userId = (string) Str::uuid();
        $toolName = 'category_probe_tool';

        $categories = [
            ToolFailureCategory::Timeout,
            ToolFailureCategory::ConnectionFailure,
            ToolFailureCategory::AuthenticationFailure,
            ToolFailureCategory::InvalidInput,
            ToolFailureCategory::ServerError,
            ToolFailureCategory::Other,
        ];

        foreach ($categories as $category) {
            $recorder->recordToolInvocation(
                conversationId: (string) Str::uuid(),
                userId: $userId,
                attemptGroupId: (string) Str::uuid(),
                toolName: $toolName,
                success: false,
                failureCategory: $category,
                agentId: 'research-assistant',
            );
        }

        $row = DB::table('tool_reliability_summaries')->where('tool_name', $toolName)->first();
        $this->assertNotNull($row);

        foreach (ToolReliabilitySummary::FAILURE_CATEGORY_COLUMNS as $value => $column) {
            $this->assertSame(1, (int) $row->{$column}, "column {$column} for category {$value} must be incremented exactly once");
        }
        $this->assertSame(0, (int) $row->failure_uncategorized_count, 'every failure in this batch carried a named category');
        $this->assertSame(6, (int) $row->failure_count);
    }

    /**
     * After a mixed batch of successes and every failure kind (the six named
     * categories plus one uncategorized failure), the sum of the seven
     * failure_*_count columns exactly equals failure_count (FR-002).
     */
    #[Test]
    public function failure_breakdown_columns_sum_to_failure_count_after_a_mixed_batch(): void
    {
        $recorder = new MetricsRecorder();
        $userId = (string) Str::uuid();
        $toolName = 'mixed_batch_tool';

        for ($i = 0; $i < 3; $i++) {
            $recorder->recordToolInvocation(
                conversationId: (string) Str::uuid(),
                userId: $userId,
                attemptGroupId: (string) Str::uuid(),
                toolName: $toolName,
                success: true,
                agentId: 'research-assistant',
            );
        }

        foreach ([
            ToolFailureCategory::Timeout,
            ToolFailureCategory::ConnectionFailure,
            ToolFailureCategory::AuthenticationFailure,
            ToolFailureCategory::InvalidInput,
            ToolFailureCategory::ServerError,
            ToolFailureCategory::Other,
        ] as $category) {
            $recorder->recordToolInvocation(
                conversationId: (string) Str::uuid(),
                userId: $userId,
                attemptGroupId: (string) Str::uuid(),
                toolName: $toolName,
                success: false,
                failureCategory: $category,
                agentId: 'research-assistant',
            );
        }

        $recorder->recordToolInvocation(
            conversationId: (string) Str::uuid(),
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            toolName: $toolName,
            success: false,
            failureCategory: null,
            agentId: 'research-assistant',
        );

        $row = DB::table('tool_reliability_summaries')->where('tool_name', $toolName)->first();
        $this->assertNotNull($row);
        $this->assertSame(10, (int) $row->invocation_count);
        $this->assertSame(3, (int) $row->success_count);
        $this->assertSame(7, (int) $row->failure_count);

        $breakdownSum = $row->failure_timeout_count
            + $row->failure_connection_failure_count
            + $row->failure_authentication_failure_count
            + $row->failure_invalid_input_count
            + $row->failure_server_error_count
            + $row->failure_other_count
            + $row->failure_uncategorized_count;

        $this->assertSame((int) $row->failure_count, (int) $breakdownSum, 'the seven breakdown columns must account for every failure counted in the summary (FR-002)');
    }
}
