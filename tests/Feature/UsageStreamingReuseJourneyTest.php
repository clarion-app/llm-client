<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\HandleOpenAIConversationStreamResponse;
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
 * FR-012 — the reused figure must be captured from the same end-of-stream
 * point the existing input/output token counts already wait for, on both
 * streaming handlers, with no separate timing gap introduced.
 */
class UsageStreamingReuseJourneyTest extends TestCase
{
    #[Test]
    public function agent_loop_stream_handler_captures_reuse_from_the_final_usage_chunk(): void
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
            'choices' => [['delta' => ['content' => 'Hi there'], 'finish_reason' => null]],
        ]);

        $usageChunk = json_encode([
            'choices' => [],
            'usage' => [
                'prompt_tokens' => 800,
                'completion_tokens' => 60,
                'total_tokens' => 860,
                'prompt_tokens_details' => ['cached_tokens' => 350],
            ],
        ]);

        $handler->handle("data: {$contentChunk}\n\ndata: {$usageChunk}\n\n", $data, 0);
        $handler->finish($data, 2);

        $record = UsageRecord::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(800, $record->input_tokens);
        $this->assertFalse($record->input_estimated);
        $this->assertSame(
            350,
            $record->reused_input_tokens,
            'The reused figure must be captured from the same final-chunk usage the token counts wait for'
        );
    }

    #[Test]
    public function legacy_openai_stream_handler_captures_reuse_from_the_final_usage_chunk(): void
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
        ]);

        // Title set so finish() doesn't attempt real title-generation HTTP calls.
        $conversation = Conversation::factory()->create([
            'title' => 'Existing title',
            'provider_override' => ProviderType::OpenAI,
        ]);

        $handler = new HandleOpenAIConversationStreamResponse(new MetricsRecorder());

        $usageChunk = json_encode([
            'choices' => [],
            'usage' => [
                'prompt_tokens' => 500,
                'completion_tokens' => 50,
                'total_tokens' => 550,
                'prompt_tokens_details' => ['cached_tokens' => 200],
            ],
        ]);
        $dummyChunk = json_encode(['choices' => []]);

        $handler->handle("data: {$usageChunk}\n\ndata: {$dummyChunk}\n\n", $conversation->id, 0);
        $handler->finish($conversation->id, 2);

        $record = UsageRecord::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(
            500,
            $record->input_tokens,
            'This handler already captures usage correctly — no regression expected here'
        );
        $this->assertFalse($record->input_estimated);
        $this->assertSame(
            200,
            $record->reused_input_tokens,
            'The reused figure must come from the same already-captured usage, once extraction exists'
        );
    }
}
