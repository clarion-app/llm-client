<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Models\UsageRecord;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * FR-014 / research.md D9 — clamping an inconsistent reused-input figure
 * into [0, input_tokens], flagged as adjusted only when clamping actually
 * changed the stored value (contracts §1 W5).
 */
class MetricsRecorderReuseClampTest extends TestCase
{
    private function record(array $providerUsage): UsageRecord
    {
        $recorder = new MetricsRecorder();

        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: $providerUsage,
            inputText: 'input text',
            outputText: 'output text',
        );

        return UsageRecord::first();
    }

    #[Test]
    public function reused_exceeding_input_tokens_clamps_and_flags_adjusted(): void
    {
        $record = $this->record([
            'prompt_tokens' => 50,
            'completion_tokens' => 10,
            'total_tokens' => 60,
            'cache_read_input_tokens' => 900,
        ]);

        $this->assertNotNull($record);
        $this->assertSame(
            50,
            $record->reused_input_tokens,
            'A reused figure exceeding input_tokens must clamp to input_tokens'
        );
        $this->assertTrue($record->reused_input_adjusted);
    }

    #[Test]
    public function negative_raw_value_clamps_to_zero_and_flags_adjusted(): void
    {
        $record = $this->record([
            'prompt_tokens' => 50,
            'completion_tokens' => 10,
            'total_tokens' => 60,
            'cache_read_input_tokens' => -5,
        ]);

        $this->assertNotNull($record);
        $this->assertSame(0, $record->reused_input_tokens, 'A negative raw reused figure must clamp to 0');
        $this->assertTrue($record->reused_input_adjusted);
    }

    #[Test]
    public function in_range_value_stored_as_is_not_flagged_adjusted(): void
    {
        $record = $this->record([
            'prompt_tokens' => 50,
            'completion_tokens' => 10,
            'total_tokens' => 60,
            'cache_read_input_tokens' => 20,
        ]);

        $this->assertNotNull($record);
        $this->assertSame(20, $record->reused_input_tokens);
        $this->assertFalse(
            $record->reused_input_adjusted,
            'An in-range reported value is not clamping, so it must not be flagged adjusted'
        );
    }

    #[Test]
    public function boundary_value_equal_to_input_tokens_is_not_flagged_adjusted(): void
    {
        $record = $this->record([
            'prompt_tokens' => 50,
            'completion_tokens' => 10,
            'total_tokens' => 60,
            'cache_read_input_tokens' => 50,
        ]);

        $this->assertNotNull($record);
        $this->assertSame(
            50,
            $record->reused_input_tokens,
            'A fully-reused request stores the reported value as-is'
        );
        $this->assertFalse(
            $record->reused_input_adjusted,
            'A value that exactly equals input_tokens is not an anomaly — it must not be flagged adjusted'
        );
    }
}
