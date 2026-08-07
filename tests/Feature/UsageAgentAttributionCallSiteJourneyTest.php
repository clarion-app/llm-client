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
 * FR-005/FR-006, US2 Acceptance Scenarios 1 & 3 — driven through the two
 * real streaming call sites (AgentLoopStreamHandler::finish() and
 * HandleOpenAIConversationStreamResponse::finish()), not through
 * MetricsRecorder directly.
 *
 * UsageAgentAttributionJourneyTest already proves the recordUsage()/
 * forAgent() contract itself; this file closes the gap flagged by the
 * mutation-testing pass (`$conversation->character ?: null` swapped for
 * `$conversation->character ?? $conversation->id` at each call site went
 * undetected) by asserting on UsageRecord.agent_id after actually driving a
 * conversation through each handler's handle()/finish() pair.
 */
class UsageAgentAttributionCallSiteJourneyTest extends TestCase
{
    #[Test]
    public function agent_loop_stream_handler_attributes_usage_to_the_conversations_character(): void
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create([
            'character' => 'Researcher',
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
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40, 'total_tokens' => 160],
        ]);

        $handler->handle("data: {$contentChunk}\n\ndata: {$usageChunk}\n\n", $data, 0);
        $handler->finish($data, 2);

        $record = UsageRecord::forConversation($conversation->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('Researcher', $record->agent_id);
    }

    #[Test]
    public function agent_loop_stream_handler_records_no_agent_attribution_when_conversation_has_no_character(): void
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create([
            'character' => null,
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
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40, 'total_tokens' => 160],
        ]);

        $handler->handle("data: {$contentChunk}\n\ndata: {$usageChunk}\n\n", $data, 0);
        $handler->finish($data, 2);

        $record = UsageRecord::forConversation($conversation->id)->first();
        $this->assertNotNull($record);
        $this->assertNull($record->agent_id);
        $this->assertNotSame($conversation->id, $record->agent_id);
    }

    #[Test]
    public function legacy_openai_stream_handler_attributes_usage_to_the_conversations_character(): void
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
        ]);

        // title set so finish() doesn't attempt real title-generation HTTP calls.
        $conversation = Conversation::factory()->create([
            'character' => 'Researcher',
            'title' => 'Existing title',
            'provider_override' => ProviderType::OpenAI,
        ]);

        $handler = new HandleOpenAIConversationStreamResponse(new MetricsRecorder());

        $usageChunk = json_encode([
            'choices' => [],
            'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 50, 'total_tokens' => 550],
        ]);
        $dummyChunk = json_encode(['choices' => []]);

        $handler->handle("data: {$usageChunk}\n\ndata: {$dummyChunk}\n\n", $conversation->id, 0);
        $handler->finish($conversation->id, 2);

        $record = UsageRecord::forConversation($conversation->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('Researcher', $record->agent_id);
    }

    #[Test]
    public function legacy_openai_stream_handler_records_no_agent_attribution_when_conversation_has_no_character(): void
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
        ]);

        $conversation = Conversation::factory()->create([
            'character' => null,
            'title' => 'Existing title',
            'provider_override' => ProviderType::OpenAI,
        ]);

        $handler = new HandleOpenAIConversationStreamResponse(new MetricsRecorder());

        $usageChunk = json_encode([
            'choices' => [],
            'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 50, 'total_tokens' => 550],
        ]);
        $dummyChunk = json_encode(['choices' => []]);

        $handler->handle("data: {$usageChunk}\n\ndata: {$dummyChunk}\n\n", $conversation->id, 0);
        $handler->finish($conversation->id, 2);

        $record = UsageRecord::forConversation($conversation->id)->first();
        $this->assertNotNull($record);
        $this->assertNull($record->agent_id);
        $this->assertNotSame($conversation->id, $record->agent_id);
    }
}
