<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * research.md D4, contracts §4 — AgentLoopStreamHandler::handle() never
 * inspects $json['usage'] at all today, so finish()'s
 * $providerUsage = $parsedData['usage'] ?? [] reads the fixed job-reference
 * payload (which never carries usage) instead of anything the stream
 * actually reported. Every request through this path silently falls back
 * to full character-based estimation regardless of what the provider sent.
 *
 * This test is RED against the current handler by construction (D4/S4) —
 * it must stay red until handle() captures $json['usage'] into an instance
 * property and finish() reads that property instead of the job payload.
 */
class AgentLoopStreamHandlerUsageCaptureTest extends TestCase
{
    #[Test]
    public function usage_captured_during_handle_is_used_by_finish_instead_of_estimation(): void
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create([
            'provider_override' => ProviderType::OpenAI,
        ]);

        $handler = new AgentLoopStreamHandler(null, new MetricsRecorder(), null);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        $contentChunk = json_encode([
            'choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]],
        ]);

        // The final SSE chunk of a stream with include_usage-style reporting:
        // empty choices, populated usage.
        $usageChunk = json_encode([
            'choices' => [],
            'usage' => [
                'prompt_tokens' => 120,
                'completion_tokens' => 40,
                'total_tokens' => 160,
                'prompt_tokens_details' => ['cached_tokens' => 30],
            ],
        ]);

        // Both chunks are complete SSE messages terminated by a blank line —
        // AgentLoopStreamHandler::handle() processes every complete part in
        // one call, keeping only a trailing incomplete fragment buffered.
        $handler->handle(
            "data: {$contentChunk}\n\ndata: {$usageChunk}\n\n",
            $data,
            0
        );

        $handler->finish($data, 2);

        $record = UsageRecord::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(
            120,
            $record->input_tokens,
            'finish() must use the usage handle() captured from the stream, not character-count estimation'
        );
        $this->assertSame(40, $record->output_tokens);
        $this->assertFalse($record->input_estimated);
        $this->assertFalse($record->output_estimated);
        $this->assertSame(
            30,
            $record->reused_input_tokens,
            'Once usage is genuinely captured, the reused figure must be extractable from it too'
        );
    }
}
