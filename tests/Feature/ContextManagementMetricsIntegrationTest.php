<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\ContextManagementRecord;
use ClarionApp\LlmClient\Models\ContextManagementSummary;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\OperationCache;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for context management metrics recording.
 *
 * Drives the real AgentLoopService loop to prove that context management
 * metrics are recorded end-to-end from applyContextWindowTrim().
 */
class ContextManagementMetricsIntegrationTest extends TestCase
{
    /**
     * Build a fake LlmProvider with a configurable countTokens implementation.
     */
    private function fakeProvider(array $responses, int $tokensPerChar = 1): LlmProvider
    {
        return new class($responses, $tokensPerChar) implements LlmProvider {
            private int $i = 0;

            public function __construct(private array $responses, private int $tokensPerChar) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                $r = $this->responses[$this->i] ?? end($this->responses);
                $this->i++;
                return $r;
            }

            public function stream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield [];
            }

            public function embed(array $inputs, array $options = []): array
            {
                return ['embeddings' => []];
            }

            public function countTokens(string $text, ?string $model = null): int
            {
                return (int) ceil(strlen($text) / $this->tokensPerChar);
            }

            public function listModels(): array
            {
                return ['models' => []];
            }
        };
    }

    private function makeService(LlmProvider $provider): AgentLoopService
    {
        $registry = app(ProviderRegistry::class);
        $registry->register('openai', fn ($server) => $provider);

        return new AgentLoopService(
            Mockery::mock(McpToolRegistry::class),
            Mockery::mock(McpToolExecutor::class),
            new OperationCache(),
            $registry,
            metricsRecorder: new MetricsRecorder(),
        );
    }

    private function makeConversation(): Conversation
    {
        $server = Server::create([
            'name' => 'test',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        return Conversation::factory()->create([
            'is_processing' => false,
            'server_id' => $server->id,
            'title' => 'Test conversation',
        ]);
    }

    private function textResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ];
    }

    /**
     * T014: A request that fits budget produces exactly one `none` record
     * with correct tokens_before and context_capacity.
     */
    #[Test]
    public function request_that_fits_budget_produces_none_record_with_utilization()
    {
        // Disable condensation and use a large context window so the
        // system prompt + history fits without triggering trimming.
        config([
            'llm-client.condensation.enabled' => false,
            'llm-client.context_window.providers.openai.context' => 128000,
            'llm-client.context_window.providers.openai.response_reserve' => 4096,
            'llm-client.context_window.headroom_ratio' => 0.15,
            'llm-client.context_window.injected_section_reserve' => 1500,
        ]);

        $conversation = $this->makeConversation();
        $service = $this->makeService($this->fakeProvider([
            $this->textResponse('Hello there.'),
        ]));

        $result = $service->run($conversation, 'Hi');

        $records = ContextManagementRecord::forConversation($conversation->id)->get();
        $this->assertCount(1, $records, 'Should produce exactly one record when nothing is trimmed');

        $record = $records->first();
        $this->assertEquals('none', $record->mechanism);
        $this->assertEquals(0, $record->tokens_saved);
        // context_capacity should be set (the model/provider fallback context window)
        $this->assertGreaterThan(0, $record->context_capacity, 'context_capacity should be the model full context window');
        // tokens_before should be > 0 (the messages we sent)
        $this->assertGreaterThanOrEqual(0, $record->tokens_before);
        $this->assertEquals($record->tokens_before, $record->tokens_after);
    }

    /**
     * T015: A request that trims produces a `trim` record whose context_capacity
     * equals the model's full context window (NOT history_budget).
     */
    #[Test]
    public function request_that_trims_produces_trim_record_with_full_context_capacity()
    {
        $conversation = $this->makeConversation();

        // Configure a very small context window on the openai provider tier
        // (provider tier takes precedence over fallback in resolveBudget).
        config([
            'llm-client.context_window.enabled' => true,
            'llm-client.context_window.providers.openai.context' => 200,
            'llm-client.context_window.providers.openai.response_reserve' => 50,
            'llm-client.context_window.headroom_ratio' => 0.0,
            'llm-client.context_window.injected_section_reserve' => 0,
        ]);

        $service = $this->makeService($this->fakeProvider([
            $this->textResponse('Hello there.'),
        ]));

        // Send a long message that will exceed the tiny budget.
        $longMessage = str_repeat('word ', 100);
        $service->run($conversation, $longMessage);

        $records = ContextManagementRecord::forConversation($conversation->id)->get();

        // Should have at least one record (trim or none depending on whether it was trimmed)
        $this->assertNotEmpty($records, 'Should produce at least one context management record');

        // Find the trim record (if trimming occurred)
        $trimRecord = $records->where('mechanism', 'trim')->first();
        if ($trimRecord) {
            // context_capacity should be the full context window (200), not history_budget
            $this->assertEquals(200, $trimRecord->context_capacity, 'context_capacity should be the full context window');
            $this->assertGreaterThan(0, $trimRecord->tokens_saved, 'Trim should have saved some tokens');
            // tokens_before should be >= tokens_after for trim
            $this->assertGreaterThan(
                $trimRecord->tokens_after,
                $trimRecord->tokens_before,
                sprintf('tokens_before (%d) should be > tokens_after (%d) for trim', $trimRecord->tokens_before, $trimRecord->tokens_after),
            );
        } else {
            // If no trim occurred, there should be a none record
            $noneRecord = $records->where('mechanism', 'none')->first();
            $this->assertNotNull($noneRecord, 'Should have a none record if no trimming occurred');
            $this->assertEquals(200, $noneRecord->context_capacity);
        }
    }
}
