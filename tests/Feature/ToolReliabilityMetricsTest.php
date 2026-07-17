<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
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
}
