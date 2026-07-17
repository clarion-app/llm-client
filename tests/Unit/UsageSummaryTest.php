<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Models\UsageSummary;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use Illuminate\Support\Str;

class UsageSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    // ── User Story 1: Conversation Usage Totals ──

    public function test_conversation_summary_aggregates_multiple_records()
    {
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $recorder = new MetricsRecorder();

        // Record multiple usage entries for the same conversation
        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            inputText: '',
            outputText: '',
        );
        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 200, 'completion_tokens' => 100, 'total_tokens' => 300],
            inputText: '',
            outputText: '',
        );
        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 50, 'completion_tokens' => 25, 'total_tokens' => 75],
            inputText: '',
            outputText: '',
        );

        // Verify summary aggregates correctly
        $summary = UsageSummary::getConversationTotals($conversationId);
        $this->assertNotNull($summary);
        $this->assertEquals(350, $summary->input_tokens);
        $this->assertEquals(175, $summary->output_tokens);
        $this->assertEquals(525, $summary->total_tokens);
        $this->assertEquals(0, $summary->estimated_input_tokens);
        $this->assertEquals(0, $summary->estimated_output_tokens);
        $this->assertEquals(3, $summary->request_count);
    }

    public function test_conversation_summary_separates_estimated_vs_reported()
    {
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $recorder = new MetricsRecorder();

        // First record: provider-reported usage
        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            inputText: '',
            outputText: '',
        );

        // Second record: estimated usage (empty providerUsage)
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

        // Total tokens include both reported and estimated
        $this->assertGreaterThan(150, $summary->total_tokens);

        // Estimated tokens track only the estimated portion
        $this->assertGreaterThan(0, $summary->estimated_input_tokens);
        $this->assertGreaterThan(0, $summary->estimated_output_tokens);
        $this->assertGreaterThan(0, $summary->estimated_total_tokens);

        // Request count includes all records
        $this->assertEquals(2, $summary->request_count);
    }

    public function test_conversation_summary_returns_null_when_no_records()
    {
        $conversationId = (string) Str::uuid();
        $summary = UsageSummary::getConversationTotals($conversationId);
        $this->assertNull($summary);
    }

    public function test_usage_record_scope_for_conversation()
    {
        $conversationId = (string) Str::uuid();
        $otherConversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        UsageRecord::create([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
        ]);
        UsageRecord::create([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 200,
            'output_tokens' => 100,
            'total_tokens' => 300,
        ]);
        UsageRecord::create([
            'conversation_id' => $otherConversationId,
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 50,
            'output_tokens' => 25,
            'total_tokens' => 75,
        ]);

        $records = UsageRecord::forConversation($conversationId)->get();
        $this->assertCount(2, $records);
    }

    public function test_usage_record_scope_with_estimate_flags()
    {
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        UsageRecord::create([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'input_estimated' => false,
            'output_estimated' => false,
        ]);
        UsageRecord::create([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 80,
            'output_tokens' => 40,
            'total_tokens' => 120,
            'input_estimated' => true,
            'output_estimated' => false,
        ]);

        $estimatedRecords = UsageRecord::forConversation($conversationId)
            ->withEstimateFlags()
            ->get();
        $this->assertCount(1, $estimatedRecords);
        $this->assertTrue($estimatedRecords->first()->input_estimated);
    }

    // ── User Story 2: User Usage Over Time ──

    public function test_user_summary_aggregates_across_conversations()
    {
        $userId = (string) Str::uuid();
        $conv1 = (string) Str::uuid();
        $conv2 = (string) Str::uuid();
        $conv3 = (string) Str::uuid();
        $recorder = new MetricsRecorder();

        // Record usage across multiple conversations for the same user
        $recorder->recordUsage(
            conversationId: $conv1,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            inputText: '',
            outputText: '',
        );
        $recorder->recordUsage(
            conversationId: $conv2,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 200, 'completion_tokens' => 100, 'total_tokens' => 300],
            inputText: '',
            outputText: '',
        );
        $recorder->recordUsage(
            conversationId: $conv3,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 300, 'completion_tokens' => 150, 'total_tokens' => 450],
            inputText: '',
            outputText: '',
        );

        // User summary should aggregate all conversations
        $summary = UsageSummary::getUserTotals($userId);
        $this->assertNotNull($summary);
        $this->assertEquals(600, $summary->input_tokens);
        $this->assertEquals(300, $summary->output_tokens);
        $this->assertEquals(900, $summary->total_tokens);
        $this->assertEquals(3, $summary->request_count);
    }

    public function test_user_summary_is_independent_of_conversation_summary()
    {
        $userId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $recorder = new MetricsRecorder();

        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            inputText: '',
            outputText: '',
        );

        // Both summaries should exist with same values for single conversation
        $userSummary = UsageSummary::getUserTotals($userId);
        $convSummary = UsageSummary::getConversationTotals($conversationId);

        $this->assertNotNull($userSummary);
        $this->assertNotNull($convSummary);
        $this->assertEquals(100, $userSummary->input_tokens);
        $this->assertEquals(100, $convSummary->input_tokens);
        $this->assertEquals(1, $userSummary->request_count);
        $this->assertEquals(1, $convSummary->request_count);
    }

    public function test_user_summary_returns_null_when_no_records()
    {
        $userId = (string) Str::uuid();
        $summary = UsageSummary::getUserTotals($userId);
        $this->assertNull($summary);
    }

    public function test_usage_record_scope_for_user()
    {
        $userId = (string) Str::uuid();
        $otherUserId = (string) Str::uuid();

        UsageRecord::create([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
        ]);
        UsageRecord::create([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 200,
            'output_tokens' => 100,
            'total_tokens' => 300,
        ]);
        UsageRecord::create([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => $otherUserId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 50,
            'output_tokens' => 25,
            'total_tokens' => 75,
        ]);

        $records = UsageRecord::forUser($userId)->get();
        $this->assertCount(2, $records);
    }

    public function test_usage_record_scope_order_by_created_at_desc()
    {
        $userId = (string) Str::uuid();

        UsageRecord::create([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'created_at' => now()->subHours(2),
        ]);
        UsageRecord::create([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 200,
            'output_tokens' => 100,
            'total_tokens' => 300,
            'created_at' => now()->subHour(),
        ]);
        UsageRecord::create([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 300,
            'output_tokens' => 150,
            'total_tokens' => 450,
            'created_at' => now(),
        ]);

        $records = UsageRecord::forUser($userId)->orderByCreatedAtDesc()->get();
        $this->assertEquals(450, $records->first()->total_tokens);
        $this->assertEquals(150, $records->last()->total_tokens);
    }
}
