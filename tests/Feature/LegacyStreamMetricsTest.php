<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class LegacyStreamMetricsTest extends TestCase
{
    #[Test]
    public function legacy_stream_metrics_record_usage_with_estimation()
    {
        $conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'character' => 'Assistant',
        ]);

        $recorder = new MetricsRecorder();
        $attemptGroupId = (string) Str::uuid();

        $recorder->recordUsage(
            conversationId: $conversation->id,
            userId: $conversation->user_id,
            attemptGroupId: $attemptGroupId,
            providerUsage: [],
            inputText: 'legacy stream input',
            outputText: 'legacy stream output',
            model: 'gpt-4',
            providerType: 'open_ai',
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertEquals($attemptGroupId, $record->attempt_group_id);
        $this->assertTrue($record->input_estimated);
        $this->assertTrue($record->output_estimated);
    }
}
