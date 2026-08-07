<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Models\UsageRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * research.md D3, D9, D9b — extractReusedInputTokens() shape detection and
 * failure isolation, proven via recordUsage()'s observable output (the
 * helper itself is protected, not part of the public write contract).
 */
class MetricsRecorderReuseTest extends TestCase
{
    private function baseArgs(): array
    {
        return [
            'conversationId' => (string) Str::uuid(),
            'userId' => (string) Str::uuid(),
            'attemptGroupId' => (string) Str::uuid(),
            'inputText' => 'input text',
            'outputText' => 'output text',
        ];
    }

    #[Test]
    public function anthropic_shape_cache_read_tokens_present_is_reported(): void
    {
        $recorder = new MetricsRecorder();
        $args = $this->baseArgs();

        $recorder->recordUsage(
            conversationId: $args['conversationId'],
            userId: $args['userId'],
            attemptGroupId: $args['attemptGroupId'],
            providerUsage: [
                'prompt_tokens' => 1000,
                'completion_tokens' => 50,
                'total_tokens' => 1050,
                'cache_read_input_tokens' => 900,
            ],
            inputText: $args['inputText'],
            outputText: $args['outputText'],
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertSame(
            900,
            $record->reused_input_tokens,
            'A reported cache_read_input_tokens figure (Anthropic shape) must be recorded, not left unknown'
        );
    }

    #[Test]
    public function anthropic_shape_reports_exactly_zero_is_distinguishable_from_unknown(): void
    {
        $recorder = new MetricsRecorder();
        $args = $this->baseArgs();

        $recorder->recordUsage(
            conversationId: $args['conversationId'],
            userId: $args['userId'],
            attemptGroupId: $args['attemptGroupId'],
            providerUsage: [
                'prompt_tokens' => 200,
                'completion_tokens' => 10,
                'total_tokens' => 210,
                'cache_read_input_tokens' => 0,
            ],
            inputText: $args['inputText'],
            outputText: $args['outputText'],
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertNotNull(
            $record->reused_input_tokens,
            'An explicitly reported zero must not read as unknown (NULL)'
        );
        $this->assertSame(0, $record->reused_input_tokens);
    }

    #[Test]
    public function openai_shape_cached_tokens_present_is_reported(): void
    {
        $recorder = new MetricsRecorder();
        $args = $this->baseArgs();

        $recorder->recordUsage(
            conversationId: $args['conversationId'],
            userId: $args['userId'],
            attemptGroupId: $args['attemptGroupId'],
            providerUsage: [
                'prompt_tokens' => 500,
                'completion_tokens' => 60,
                'total_tokens' => 560,
                'prompt_tokens_details' => ['cached_tokens' => 400],
            ],
            inputText: $args['inputText'],
            outputText: $args['outputText'],
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertSame(400, $record->reused_input_tokens);
    }

    #[Test]
    public function openai_shape_reports_exactly_zero_is_distinguishable_from_unknown(): void
    {
        $recorder = new MetricsRecorder();
        $args = $this->baseArgs();

        $recorder->recordUsage(
            conversationId: $args['conversationId'],
            userId: $args['userId'],
            attemptGroupId: $args['attemptGroupId'],
            providerUsage: [
                'prompt_tokens' => 300,
                'completion_tokens' => 20,
                'total_tokens' => 320,
                'prompt_tokens_details' => ['cached_tokens' => 0],
            ],
            inputText: $args['inputText'],
            outputText: $args['outputText'],
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertNotNull($record->reused_input_tokens);
        $this->assertSame(0, $record->reused_input_tokens);
    }

    #[Test]
    public function neither_shape_present_is_unknown_never_zero(): void
    {
        $recorder = new MetricsRecorder();
        $args = $this->baseArgs();

        $recorder->recordUsage(
            conversationId: $args['conversationId'],
            userId: $args['userId'],
            attemptGroupId: $args['attemptGroupId'],
            providerUsage: [
                'prompt_tokens' => 150,
                'completion_tokens' => 15,
                'total_tokens' => 165,
            ],
            inputText: $args['inputText'],
            outputText: $args['outputText'],
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertNull(
            $record->reused_input_tokens,
            'A provider that never reports reuse information must read as unknown, never 0'
        );
    }

    #[Test]
    public function full_estimation_fallback_reused_stays_null_even_with_non_empty_input_text(): void
    {
        $recorder = new MetricsRecorder();
        $args = $this->baseArgs();

        $recorder->recordUsage(
            conversationId: $args['conversationId'],
            userId: $args['userId'],
            attemptGroupId: $args['attemptGroupId'],
            providerUsage: [],
            inputText: str_repeat('a', 260),
            outputText: str_repeat('b', 100),
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertTrue($record->input_estimated);
        $this->assertNull(
            $record->reused_input_tokens,
            'A fully-estimated request never carries a reused figure, even with non-empty input text'
        );
    }

    #[Test]
    public function extraction_failure_is_isolated_and_does_not_suppress_the_base_record(): void
    {
        Log::spy();

        // A test-double subclass forcing extractReusedInputTokens() to throw
        // (research.md D9b — the helper is protected specifically so a test
        // can override it to exercise the inner try/catch deterministically).
        $recorder = new class extends MetricsRecorder {
            protected function extractReusedInputTokens(array $providerUsage): ?int
            {
                throw new \RuntimeException('boom');
            }
        };

        $args = $this->baseArgs();

        $recorder->recordUsage(
            conversationId: $args['conversationId'],
            userId: $args['userId'],
            attemptGroupId: $args['attemptGroupId'],
            providerUsage: [
                'prompt_tokens' => 80,
                'completion_tokens' => 40,
                'total_tokens' => 120,
                'cache_read_input_tokens' => 20,
            ],
            inputText: $args['inputText'],
            outputText: $args['outputText'],
        );

        $record = UsageRecord::first();
        $this->assertNotNull(
            $record,
            'A failure specific to reuse extraction must not suppress the rest of the usage record'
        );
        $this->assertSame(80, $record->input_tokens);
        $this->assertSame(40, $record->output_tokens);
        $this->assertNull($record->reused_input_tokens);
        $this->assertFalse($record->reused_input_adjusted);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message) && stripos($message, 'reuse') !== false)
            ->atLeast()->once();
    }
}
