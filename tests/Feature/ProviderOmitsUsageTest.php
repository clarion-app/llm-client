<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Models\UsageSummary;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class ProviderOmitsUsageTest extends TestCase
{
    #[Test]
    public function provider_omits_usage_creates_estimated_record()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $attemptGroupId = (string) Str::uuid();

        $inputText = str_repeat('Hello world, this is a test message. ', 10);
        $outputText = str_repeat('Here is the response content. ', 10);

        // Empty providerUsage triggers full estimation
        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            providerUsage: [],
            inputText: $inputText,
            outputText: $outputText,
            model: 'gpt-4',
            providerType: 'open_ai',
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertTrue($record->input_estimated);
        $this->assertTrue($record->output_estimated);
        $this->assertGreaterThan(0, $record->input_tokens);
        $this->assertGreaterThan(0, $record->output_tokens);
        $this->assertEquals($record->input_tokens + $record->output_tokens, $record->total_tokens);
    }

    #[Test]
    public function estimated_values_are_reasonable()
    {
        $recorder = new MetricsRecorder();
        $inputText = str_repeat('a', 1300); // ~1000 tokens at 1.3 chars/token
        $outputText = str_repeat('b', 500); // ~500 tokens at 1.0 chars/token

        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [],
            inputText: $inputText,
            outputText: $outputText,
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);

        // Allow small margin of error from ceil()
        $this->assertGreaterThan(990, $record->input_tokens);
        $this->assertLessThan(1010, $record->input_tokens);
        $this->assertEquals(500, $record->output_tokens);
    }

    #[Test]
    public function estimated_usage_updates_summary_correctly()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [],
            inputText: str_repeat('a', 130),
            outputText: str_repeat('b', 50),
        );

        $summary = UsageSummary::getConversationTotals($conversationId);
        $this->assertNotNull($summary);

        // Summary should track estimated tokens separately
        $this->assertGreaterThan(0, $summary->estimated_input_tokens);
        $this->assertGreaterThan(0, $summary->estimated_output_tokens);
        $this->assertGreaterThan(0, $summary->estimated_total_tokens);
        $this->assertEquals(1, $summary->request_count);
    }

    #[Test]
    public function partial_provider_usage_mixed_reported_and_estimated()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // Provider reports input tokens but not output tokens
        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [
                'prompt_tokens' => 100,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ],
            inputText: 'some input text',
            outputText: str_repeat('x', 50),
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);

        // Input should be from provider (not estimated)
        $this->assertFalse($record->input_estimated);
        $this->assertEquals(100, $record->input_tokens);

        // Output should be estimated (provider reported 0)
        $this->assertTrue($record->output_estimated);
        $this->assertGreaterThan(0, $record->output_tokens);
    }

    #[Test]
    public function partial_provider_usage_input_only()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // Provider reports output tokens but not input tokens
        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [
                'prompt_tokens' => 0,
                'completion_tokens' => 75,
                'total_tokens' => 0,
            ],
            inputText: str_repeat('y', 130),
            outputText: 'response text',
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);

        // Input should be estimated (provider reported 0)
        $this->assertTrue($record->input_estimated);
        $this->assertGreaterThan(0, $record->input_tokens);

        // Output should be from provider (not estimated)
        $this->assertFalse($record->output_estimated);
        $this->assertEquals(75, $record->output_tokens);
    }

    #[Test]
    public function empty_input_and_output_produces_zero_estimates()
    {
        $recorder = new MetricsRecorder();

        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [],
            inputText: '',
            outputText: '',
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertEquals(0, $record->input_tokens);
        $this->assertEquals(0, $record->output_tokens);
        $this->assertEquals(0, $record->total_tokens);
    }

    #[Test]
    public function estimated_and_reported_records_mix_in_summary()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // First: provider-reported
        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [
                'prompt_tokens' => 200,
                'completion_tokens' => 100,
                'total_tokens' => 300,
            ],
            inputText: '',
            outputText: '',
        );

        // Second: estimated
        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [],
            inputText: str_repeat('a', 130),
            outputText: str_repeat('b', 50),
        );

        $summary = UsageSummary::getConversationTotals($conversationId);
        $this->assertNotNull($summary);

        $records = UsageRecord::forConversation($conversationId)->get();
        $this->assertCount(2, $records);

        // Total tokens should be sum of both records
        $totalFromRecords = $records->sum('total_tokens');
        $this->assertEquals($totalFromRecords, $summary->total_tokens);

        // Estimated tokens should match only the estimated record
        $estimatedRecord = $records->firstWhere('input_estimated', true);
        $this->assertNotNull($estimatedRecord);
        $this->assertEquals($estimatedRecord->input_tokens, $summary->estimated_input_tokens);
        $this->assertEquals($estimatedRecord->output_tokens, $summary->estimated_output_tokens);
    }
}
