<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\UsageRecord;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * data-model.md §2 / contracts §3 R1-R2 — getFreshInputTokensAttribute()
 * is a computed, non-persisted accessor: NULL when reuse is unknown,
 * input_tokens - reused_input_tokens otherwise, never negative for any
 * value this feature's own write path can produce (the clamp guarantees
 * reused_input_tokens <= input_tokens whenever it is not NULL).
 */
class UsageRecordFreshInputAccessorTest extends TestCase
{
    private function makeRecord(?int $reusedInputTokens, int $inputTokens): UsageRecord
    {
        $record = new UsageRecord([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'attempt_group_id' => (string) Str::uuid(),
        ]);
        $record->input_tokens = $inputTokens;
        $record->output_tokens = 0;
        $record->total_tokens = $inputTokens;
        $record->reused_input_tokens = $reusedInputTokens;

        return $record;
    }

    #[Test]
    public function returns_null_when_reused_input_tokens_is_null(): void
    {
        $record = $this->makeRecord(null, 100);

        $this->assertNull($record->fresh_input_tokens);
    }

    #[Test]
    public function returns_the_difference_when_reused_input_tokens_is_known(): void
    {
        $record = $this->makeRecord(30, 100);

        $this->assertSame(70, $record->fresh_input_tokens);
    }

    #[Test]
    public function never_negative_when_reused_equals_input_tokens(): void
    {
        $record = $this->makeRecord(100, 100);

        $this->assertSame(0, $record->fresh_input_tokens);
    }
}
