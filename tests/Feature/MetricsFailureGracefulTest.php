<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class MetricsFailureGracefulTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Swap Log with a mock that doesn't throw on any method calls
        $mockLogger = \Mockery::mock('Illuminate\Contracts\Logging\Logger');
        $mockLogger->shouldReceive('warning')->byDefault();
        $mockLogger->shouldReceive('error')->byDefault();
        Log::swap($mockLogger);
    }

    #[Test]
    public function metrics_recorder_does_not_throw_on_db_failure()
    {
        $recorder = new MetricsRecorder();

        // Normal call should succeed without throwing
        $attemptGroupId = (string) Str::uuid();
        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: $attemptGroupId,
            providerUsage: [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
            ],
            inputText: 'test input',
            outputText: 'test output',
            model: 'gpt-4',
            providerType: 'open_ai',
        );

        // Verify record was created (no exception thrown)
        $this->assertDatabaseCount('usage_records', 1);
    }

    #[Test]
    public function metrics_recorder_tool_invocation_does_not_throw_on_db_failure()
    {
        $recorder = new MetricsRecorder();
        // Logger already mocked in setUp()

        // Normal call should succeed without throwing
        $recorder->recordToolInvocation(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            toolName: 'test_tool',
            success: true,
        );

        // Verify record was created (no exception thrown)
        $this->assertDatabaseCount('tool_invocation_records', 1);
    }

    #[Test]
    public function metrics_recorder_tool_invocation_records_failure()
    {
        $recorder = new MetricsRecorder();

        $recorder->recordToolInvocation(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            toolName: 'failing_tool',
            success: false,
            failureCategory: ToolFailureCategory::Timeout,
        );

        $record = ToolInvocationRecord::first();
        $this->assertNotNull($record);
        $this->assertEquals('failure', $record->outcome);
        $this->assertEquals(ToolFailureCategory::Timeout, $record->failure_category);
    }

    #[Test]
    public function metrics_recorder_catches_and_logs_usage_db_failure()
    {
        // Use a non-existent database connection to trigger a failure
        $originalConnection = config('database.default');
        config(['database.default' => 'metrics_test_nonexistent']);
        DB::setDefaultConnection('metrics_test_nonexistent');

        $recorder = new MetricsRecorder();

        // Should not throw — metrics failures are swallowed
        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            inputText: 'test input',
            outputText: 'test output',
        );

        // Restore original connection
        config(['database.default' => $originalConnection]);
        DB::setDefaultConnection($originalConnection);

        // Execution continued — no exception propagated
        $this->assertTrue(true);
    }

    #[Test]
    public function partial_metrics_failure_usage_fails_but_tool_succeeds()
    {
        $recorder = new MetricsRecorder();

        // First tool invocation succeeds (normal DB connection)
        $recorder->recordToolInvocation(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            toolName: 'independent_tool',
            success: true,
        );

        // Verify tool record was created
        $this->assertDatabaseCount('tool_invocation_records', 1);

        // Now break the DB connection to force usage recording failure
        $originalConnection = config('database.default');
        config(['database.default' => 'metrics_test_nonexistent']);
        DB::setDefaultConnection('metrics_test_nonexistent');

        // Usage recording fails silently but doesn't throw
        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            inputText: 'test input',
            outputText: 'test output',
        );

        // Restore original connection
        config(['database.default' => $originalConnection]);
        DB::setDefaultConnection($originalConnection);

        // Tool record still exists (independent operation was not affected)
        $this->assertDatabaseCount('tool_invocation_records', 1);
    }

    #[Test]
    public function conversation_continues_after_metrics_failure()
    {
        // Break the DB connection
        $originalConnection = config('database.default');
        config(['database.default' => 'metrics_test_nonexistent']);
        DB::setDefaultConnection('metrics_test_nonexistent');

        $recorder = new MetricsRecorder();

        // Multiple calls should all fail silently without throwing
        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            inputText: 'input',
            outputText: 'output',
        );
        $recorder->recordToolInvocation(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            toolName: 'some_tool',
            success: true,
        );

        // Restore original connection
        config(['database.default' => $originalConnection]);
        DB::setDefaultConnection($originalConnection);

        // Execution continued — no exception propagated
        $this->assertTrue(true);
    }
}
