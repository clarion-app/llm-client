<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Models\UsageRecord;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * research.md D9a — reuse is forced to unknown whenever the base
 * input_tokens figure itself required character-based estimation, even if
 * the raw provider payload happened to contain a cache-reuse key. There is
 * no character-count heuristic for reuse, so "cannot be estimated" is
 * always true here (FR-004; User Story 1 Acceptance Scenario 4).
 */
class MetricsRecorderReuseEstimatedInputTest extends TestCase
{
    #[Test]
    public function partial_input_estimation_forces_reused_figure_unknown(): void
    {
        $recorder = new MetricsRecorder();

        // prompt_tokens omitted/zero forces character-based input estimation,
        // even though the payload also carries a cache-reuse key.
        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [
                'prompt_tokens' => 0,
                'completion_tokens' => 20,
                'total_tokens' => 20,
                'cache_read_input_tokens' => 999,
            ],
            inputText: str_repeat('a', 260),
            outputText: 'short output',
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertTrue(
            $record->input_estimated,
            'Sanity check: this fixture must actually trigger input estimation'
        );
        $this->assertNull(
            $record->reused_input_tokens,
            'Reuse must never be reported against a fabricated (estimated) input total, regardless of what the raw provider payload contained'
        );
        $this->assertFalse(
            $record->reused_input_estimated,
            'There is no character-count heuristic for reuse — the D9a design produces unknown, not a derived estimate'
        );
    }

    #[Test]
    public function full_estimation_fallback_forces_reused_figure_unknown(): void
    {
        $recorder = new MetricsRecorder();

        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [],
            inputText: str_repeat('a', 260),
            outputText: str_repeat('b', 100),
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertTrue($record->input_estimated);
        $this->assertTrue($record->output_estimated);
        $this->assertNull($record->reused_input_tokens);
        $this->assertFalse($record->reused_input_estimated);
    }
}
