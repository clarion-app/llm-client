<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Models\UsageSummary;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class SyncAgentLoopMetricsTest extends TestCase
{
    #[Test]
    public function sync_agent_loop_records_usage_and_shares_attempt_group_id()
    {
        $server = Server::create([
            'id' => (string) Str::uuid(),
            'url' => 'https://api.example.com',
            'provider_type' => 'open_ai',
            'default_model' => 'gpt-4',
            'api_key' => 'sk-test-key',
        ]);

        $conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'server_id' => $server->id,
            'character' => 'Assistant',
        ]);

        $usageBefore = UsageRecord::count();
        $summaryBefore = UsageSummary::count();

        $recorder = new MetricsRecorder();
        $attemptGroupId = (string) Str::uuid();

        $recorder->recordUsage(
            conversationId: $conversation->id,
            userId: $conversation->user_id,
            attemptGroupId: $attemptGroupId,
            providerUsage: [
                'prompt_tokens' => 50,
                'completion_tokens' => 100,
                'total_tokens' => 150,
            ],
            inputText: 'test input',
            outputText: 'test output',
            model: 'gpt-4',
            providerType: 'open_ai',
        );

        $recorder->recordToolInvocation(
            conversationId: $conversation->id,
            userId: $conversation->user_id,
            attemptGroupId: $attemptGroupId,
            toolName: 'execute_operation',
            success: true,
        );

        $usageRecord = UsageRecord::first();
        $this->assertNotNull($usageRecord);
        $this->assertEquals($attemptGroupId, $usageRecord->attempt_group_id);
        $this->assertEquals(50, $usageRecord->input_tokens);
        $this->assertEquals(100, $usageRecord->output_tokens);
        $this->assertEquals($conversation->id, $usageRecord->conversation_id);

        $toolRecord = ToolInvocationRecord::first();
        $this->assertNotNull($toolRecord);
        $this->assertEquals($attemptGroupId, $toolRecord->attempt_group_id);
        $this->assertEquals('success', $toolRecord->outcome);
        $this->assertEquals('execute_operation', $toolRecord->tool_name);

        $summary = UsageSummary::where('entity_id', $conversation->id)->first();
        $this->assertNotNull($summary);
        $this->assertEquals(50, $summary->input_tokens);
        $this->assertEquals(100, $summary->output_tokens);
        $this->assertEquals(1, $summary->request_count);
    }

    #[Test]
    public function multiple_turns_accumulate_in_summary()
    {
        $conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'character' => 'Assistant',
        ]);

        $recorder = new MetricsRecorder();

        for ($i = 0; $i < 3; $i++) {
            $recorder->recordUsage(
                conversationId: $conversation->id,
                userId: $conversation->user_id,
                attemptGroupId: (string) Str::uuid(),
                providerUsage: [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 200,
                    'total_tokens' => 300,
                ],
                inputText: 'input',
                outputText: 'output',
            );
        }

        $summary = UsageSummary::where('entity_id', $conversation->id)->first();
        $this->assertNotNull($summary);
        $this->assertEquals(300, $summary->input_tokens);
        $this->assertEquals(600, $summary->output_tokens);
        $this->assertEquals(900, $summary->total_tokens);
        $this->assertEquals(3, $summary->request_count);
    }
}
