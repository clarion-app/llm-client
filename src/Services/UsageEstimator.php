<?php

namespace ClarionApp\LlmClient\Services;

/**
 * Estimates token counts from text when provider omits usage data.
 *
 * Uses character-based estimation:
 * - Input: ~1.3 chars per token (GPT-4K approximation)
 * - Output: ~1.0 chars per token
 */
class UsageEstimator
{
    private const INPUT_CHARS_PER_TOKEN = 1.3;
    private const OUTPUT_CHARS_PER_TOKEN = 1.0;

    /**
     * Estimate input tokens from text.
     */
    public function estimateInput(string $text): int
    {
        if ($text === '') return 0;
        return (int) ceil(strlen($text) / self::INPUT_CHARS_PER_TOKEN);
    }

    /**
     * Estimate output tokens from text.
     */
    public function estimateOutput(string $text): int
    {
        if ($text === '') return 0;
        return (int) ceil(strlen($text) / self::OUTPUT_CHARS_PER_TOKEN);
    }

    /**
     * Estimate both input and output tokens at once.
     *
     * @return array{input_tokens: int, output_tokens: int, total_tokens: int}
     */
    public function estimate(string $inputText, string $outputText): array
    {
        $inputTokens = $this->estimateInput($inputText);
        $outputTokens = $this->estimateOutput($outputText);

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
        ];
    }
}
