<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\UsageEstimator;
use PHPUnit\Framework\Attributes\Test;

class UsageEstimatorTest extends TestCase
{
    #[Test]
    public function it_estimates_input_tokens_from_text()
    {
        $estimator = new UsageEstimator();
        $text = str_repeat('a', 1300); // 1300 chars ≈ 1000 tokens at 1.3 chars/token

        $tokens = $estimator->estimateInput($text);
        $this->assertEquals(1000, $tokens);
    }

    #[Test]
    public function it_estimates_output_tokens_from_text()
    {
        $estimator = new UsageEstimator();
        $text = str_repeat('b', 500); // 500 chars ≈ 500 tokens at 1.0 chars/token

        $tokens = $estimator->estimateOutput($text);
        $this->assertEquals(500, $tokens);
    }

    #[Test]
    public function it_returns_zero_for_empty_input()
    {
        $estimator = new UsageEstimator();

        $this->assertEquals(0, $estimator->estimateInput(''));
        $this->assertEquals(0, $estimator->estimateOutput(''));
    }

    #[Test]
    public function it_estimates_both_directions_at_once()
    {
        $estimator = new UsageEstimator();
        $inputText = str_repeat('x', 1300);  // 1000 tokens
        $outputText = str_repeat('y', 500);  // 500 tokens

        $result = $estimator->estimate($inputText, $outputText);

        $this->assertEquals(1000, $result['input_tokens']);
        $this->assertEquals(500, $result['output_tokens']);
        $this->assertEquals(1500, $result['total_tokens']);
    }

    #[Test]
    public function it_handles_long_text_correctly()
    {
        $estimator = new UsageEstimator();
        $text = str_repeat('long text content ', 1000); // 18 chars * 1000 = 18000 chars

        $inputTokens = $estimator->estimateInput($text);
        $this->assertGreaterThan(0, $inputTokens);
        // 18000 / 1.3 ≈ 13846.15, ceil = 13847
        $this->assertEquals(13847, $inputTokens);
    }

    #[Test]
    public function it_handles_short_text_with_ceil()
    {
        $estimator = new UsageEstimator();

        // 1 char / 1.3 = 0.769, ceil = 1
        $this->assertEquals(1, $estimator->estimateInput('a'));
        // 1 char / 1.0 = 1, ceil = 1
        $this->assertEquals(1, $estimator->estimateOutput('a'));
    }
}
