<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Models\UsageRecord;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 1 end-to-end (FR-002, SC-001, SC-002): all three provider
 * shapes driven through MetricsRecorder::recordUsage() with provider-shaped
 * fixtures, asserting reused_input_tokens + fresh_input_tokens ===
 * input_tokens whenever reuse is known.
 */
class UsageReuseProviderShapesJourneyTest extends TestCase
{
    #[Test]
    public function anthropic_with_cache_reports_reuse_and_invariant_holds(): void
    {
        $recorder = new MetricsRecorder();

        // Simulates AnthropicProvider::mapResponse() after the D2 correction:
        // prompt_tokens already includes the cache-creation/cache-read tokens.
        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [
                'prompt_tokens' => 960,
                'completion_tokens' => 20,
                'total_tokens' => 980,
                'cache_read_input_tokens' => 900,
            ],
            inputText: 'anthropic input',
            outputText: 'anthropic output',
            model: 'claude-sonnet-4-20250514',
            providerType: 'anthropic',
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertSame(960, $record->input_tokens);
        $this->assertSame(900, $record->reused_input_tokens);
        $this->assertFalse(
            $record->reused_input_adjusted,
            'A normal Anthropic cache hit must never be flagged as an anomaly'
        );
        $this->assertSame(
            $record->input_tokens,
            $record->reused_input_tokens + $record->fresh_input_tokens,
            'FR-002: reused + fresh must equal the recorded total input whenever reuse is known'
        );
    }

    #[Test]
    public function openai_with_cache_reports_reuse_and_invariant_holds(): void
    {
        $recorder = new MetricsRecorder();

        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [
                'prompt_tokens' => 500,
                'completion_tokens' => 30,
                'total_tokens' => 530,
                'prompt_tokens_details' => ['cached_tokens' => 200],
            ],
            inputText: 'openai input',
            outputText: 'openai output',
            model: 'gpt-4o',
            providerType: 'openai',
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertSame(500, $record->input_tokens);
        $this->assertSame(200, $record->reused_input_tokens);
        $this->assertSame(
            $record->input_tokens,
            $record->reused_input_tokens + $record->fresh_input_tokens,
            'FR-002: reused + fresh must equal the recorded total input whenever reuse is known'
        );
    }

    #[Test]
    public function llama_cpp_no_report_reads_as_unknown_not_zero(): void
    {
        $recorder = new MetricsRecorder();

        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [
                'prompt_tokens' => 300,
                'completion_tokens' => 25,
                'total_tokens' => 325,
            ],
            inputText: 'llama input',
            outputText: 'llama output',
            model: 'llama-3',
            providerType: 'llama.cpp',
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertSame(300, $record->input_tokens);
        $this->assertNull(
            $record->reused_input_tokens,
            'llama.cpp reports no reuse information at all — must read as unknown, never as 0'
        );
        $this->assertNull(
            $record->fresh_input_tokens,
            'Fresh input cannot be decomposed from an unknown reused figure'
        );
    }
}
