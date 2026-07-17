<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\UsageEstimator;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Models\UsageSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

class MetricsRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Tables are already created in TestCase::defineDatabaseMigrations()
    }

    #[Test]
    public function it_records_usage_with_provider_data()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $attemptGroupId = (string) \Illuminate\Support\Str::uuid();

        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            providerUsage: [
                'prompt_tokens' => 100,
                'completion_tokens' => 200,
                'total_tokens' => 300,
            ],
            inputText: 'hello world',
            outputText: 'goodbye world',
            model: 'gpt-4',
            providerType: 'openai',
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertEquals(100, $record->input_tokens);
        $this->assertEquals(200, $record->output_tokens);
        $this->assertEquals(300, $record->total_tokens);
        $this->assertFalse($record->input_estimated);
        $this->assertFalse($record->output_estimated);
        $this->assertEquals('gpt-4', $record->model);
        $this->assertEquals('openai', $record->provider_type);
        $this->assertEquals($attemptGroupId, $record->attempt_group_id);

        // Verify summary updated
        $convSummary = UsageSummary::where('entity_type', 'conversation')
            ->where('entity_id', $conversationId)->first();
        $this->assertNotNull($convSummary);
        $this->assertEquals(100, $convSummary->input_tokens);
        $this->assertEquals(200, $convSummary->output_tokens);
        $this->assertEquals(300, $convSummary->total_tokens);
        $this->assertEquals(1, $convSummary->request_count);

        $userSummary = UsageSummary::where('entity_type', 'user')
            ->where('entity_id', $userId)->first();
        $this->assertNotNull($userSummary);
        $this->assertEquals(100, $userSummary->input_tokens);
    }

    #[Test]
    public function it_records_usage_with_estimation_when_provider_omits_data()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $attemptGroupId = (string) \Illuminate\Support\Str::uuid();

        // 130 chars ≈ 100 input tokens at 1.3 chars/token
        $inputText = str_repeat('a', 130);
        // 100 chars ≈ 100 output tokens at 1.0 chars/token
        $outputText = str_repeat('b', 100);

        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            providerUsage: [],
            inputText: $inputText,
            outputText: $outputText,
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertTrue($record->input_estimated);
        $this->assertTrue($record->output_estimated);
        $this->assertGreaterThan(0, $record->input_tokens);
        $this->assertGreaterThan(0, $record->output_tokens);

        // Verify summary tracks estimated tokens separately
        $convSummary = UsageSummary::where('entity_type', 'conversation')
            ->where('entity_id', $conversationId)->first();
        $this->assertNotNull($convSummary);
        $this->assertEquals($record->input_tokens, $convSummary->estimated_input_tokens);
        $this->assertEquals($record->output_tokens, $convSummary->estimated_output_tokens);
    }

    #[Test]
    public function it_records_tool_success()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $attemptGroupId = (string) \Illuminate\Support\Str::uuid();

        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            toolName: 'search_operations',
            success: true,
        );

        $record = ToolInvocationRecord::first();
        $this->assertNotNull($record);
        $this->assertEquals('search_operations', $record->tool_name);
        $this->assertEquals('success', $record->outcome);
        $this->assertNull($record->failure_category);
        $this->assertEquals($attemptGroupId, $record->attempt_group_id);
    }

    #[Test]
    public function it_records_tool_failure_with_category()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $attemptGroupId = (string) \Illuminate\Support\Str::uuid();

        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            toolName: 'execute_operation',
            success: false,
            failureCategory: ToolFailureCategory::Timeout,
        );

        $record = ToolInvocationRecord::first();
        $this->assertNotNull($record);
        $this->assertEquals('failure', $record->outcome);
        $this->assertSame(ToolFailureCategory::Timeout, $record->failure_category);
    }

    #[Test]
    public function it_persists_attempt_group_id()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $attemptGroupId = (string) \Illuminate\Support\Str::uuid();

        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            providerUsage: ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
            inputText: 'test',
            outputText: 'result',
        );

        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            toolName: 'some_tool',
            success: true,
        );

        $usageRecord = UsageRecord::first();
        $toolRecord = ToolInvocationRecord::first();

        $this->assertEquals($attemptGroupId, $usageRecord->attempt_group_id);
        $this->assertEquals($attemptGroupId, $toolRecord->attempt_group_id);
    }

    #[Test]
    public function it_persists_co_member_tags_when_supplied()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $attemptGroupId = (string) \Illuminate\Support\Str::uuid();
        $coMemberTags = ['user-abc-123', 'user-def-456'];

        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            providerUsage: ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
            inputText: 'test',
            outputText: 'result',
            coMemberTags: $coMemberTags,
        );

        $recorder->recordToolInvocation(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            toolName: 'some_tool',
            success: true,
            coMemberTags: $coMemberTags,
        );

        $usageRecord = UsageRecord::first();
        $toolRecord = ToolInvocationRecord::first();

        $this->assertEquals($coMemberTags, $usageRecord->co_member_tags);
        $this->assertEquals($coMemberTags, $toolRecord->co_member_tags);
    }

    #[Test]
    public function it_handles_db_error_gracefully_for_usage()
    {
        Log::swap($mock = \Mockery::mock());
        $mock->shouldReceive('warning')->once();

        // Simulate DB failure by disconnecting
        DB::disconnect();
        DB::purge(config('database.default'));
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent/path/db.sqlite',
        ]]);

        $recorder = new MetricsRecorder();

        // Should not throw
        $recorder->recordUsage(
            conversationId: (string) \Illuminate\Support\Str::uuid(),
            userId: (string) \Illuminate\Support\Str::uuid(),
            attemptGroupId: (string) \Illuminate\Support\Str::uuid(),
            providerUsage: ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
            inputText: 'test',
            outputText: 'result',
        );
    }

    #[Test]
    public function it_handles_db_error_gracefully_for_tool_invocation()
    {
        Log::swap($mock = \Mockery::mock());
        $mock->shouldReceive('warning')->once();

        DB::disconnect();
        DB::purge(config('database.default'));
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent/path/db.sqlite',
        ]]);

        $recorder = new MetricsRecorder();

        $recorder->recordToolInvocation(
            conversationId: (string) \Illuminate\Support\Str::uuid(),
            userId: (string) \Illuminate\Support\Str::uuid(),
            attemptGroupId: (string) \Illuminate\Support\Str::uuid(),
            toolName: 'some_tool',
            success: true,
        );
    }

    #[Test]
    public function it_updates_summary_atomically_on_multiple_records()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();

        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) \Illuminate\Support\Str::uuid(),
            providerUsage: ['prompt_tokens' => 100, 'completion_tokens' => 200, 'total_tokens' => 300],
            inputText: 'first',
            outputText: 'result1',
        );

        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) \Illuminate\Support\Str::uuid(),
            providerUsage: ['prompt_tokens' => 50, 'completion_tokens' => 150, 'total_tokens' => 200],
            inputText: 'second',
            outputText: 'result2',
        );

        $convSummary = UsageSummary::where('entity_type', 'conversation')
            ->where('entity_id', $conversationId)->first();
        $this->assertNotNull($convSummary);
        $this->assertEquals(150, $convSummary->input_tokens);
        $this->assertEquals(350, $convSummary->output_tokens);
        $this->assertEquals(500, $convSummary->total_tokens);
        $this->assertEquals(2, $convSummary->request_count);
    }
}
