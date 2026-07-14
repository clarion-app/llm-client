<?php

namespace Tests\Unit\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use ClarionApp\LlmClient\Services\ToolResultCondenser;
use ClarionApp\LlmClient\Events\ToolResultCondensed;

class ToolResultCondenserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('llm-client.tool_result_condensation', [
            'enabled' => true,
            'threshold_tokens' => 2000,
            'max_condensed_tokens' => 500,
            'sample_items' => 5,
            'summarization_timeout_seconds' => 5,
            'cache_ttl_minutes' => 240,
        ]);
    }

    // T006: Threshold boundary behavior tests

    public function test_below_threshold_returns_passthrough(): void
    {
        // Content ~1000 tokens (4000 chars) — below 2000 threshold.
        $content = str_repeat('x', 4000);
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'test_tool', $content);

        $this->assertFalse($result['condensed']);
        $this->assertSame($content, $result['content']);
    }

    public function test_at_threshold_returns_passthrough(): void
    {
        // Content ~8000 chars = exactly 2000 tokens.
        $content = str_repeat('x', 8000);
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'test_tool', $content);

        $this->assertFalse($result['condensed']);
        $this->assertSame($content, $result['content']);
    }

    public function test_above_threshold_triggers_condensation(): void
    {
        // Content ~8001 chars = 2001 tokens — just above threshold.
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = ['id' => 'item-' . $i, 'data' => str_repeat('d', 100)];
        }
        $content = json_encode(['items' => $items]);
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'test_tool', $content);

        $this->assertTrue($result['condensed']);
        $this->assertArrayHasKey('reference_id', $result);
        $this->assertArrayHasKey('original_tokens', $result);
        $this->assertArrayHasKey('condensed_tokens', $result);
        $this->assertLessThan(strlen($content), strlen($result['content']));
    }

    public function test_disabled_config_returns_passthrough(): void
    {
        $items = [];
        for ($i = 0; $i < 200; $i++) {
            $items[] = ['id' => 'item-' . $i, 'data' => str_repeat('d', 200)];
        }
        $content = json_encode(['items' => $items]);
        $condenser = new ToolResultCondenser(null, null, null, [
            'enabled' => false,
            'threshold_tokens' => 100,
        ]);

        $result = $condenser->condense('conv-1', 'test_tool', $content);

        $this->assertFalse($result['condensed']);
        $this->assertSame($content, $result['content']);
    }

    // T007: Small result passthrough tests

    public function test_small_result_zero_latency_impact(): void
    {
        $content = '{"status": "ok"}';
        $condenser = new ToolResultCondenser();

        $start = microtime(true);
        $result = $condenser->condense('conv-1', 'test_tool', $content);
        $elapsed = microtime(true) - $start;

        $this->assertFalse($result['condensed']);
        $this->assertSame($content, $result['content']);
        $this->assertLessThan(0.01, $elapsed); // Should be under 10ms.
    }

    public function test_small_result_not_modified(): void
    {
        $content = '{"id": "abc-123", "name": "Test Item", "value": 42}';
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'test_tool', $content);

        $this->assertFalse($result['condensed']);
        $this->assertSame($content, $result['content']);
    }

    // T008: Condensation metadata tests

    public function test_condensed_result_contains_metadata(): void
    {
        $items = [];
        for ($i = 0; $i < 150; $i++) {
            $items[] = ['id' => 'item-' . $i, 'data' => str_repeat('d', 150)];
        }
        $content = json_encode(['items' => $items]);
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'test_tool', $content);

        $this->assertTrue($result['condensed']);
        $this->assertArrayHasKey('reference_id', $result);
        $this->assertArrayHasKey('original_tokens', $result);
        $this->assertArrayHasKey('condensed_tokens', $result);
        $this->assertArrayHasKey('method', $result);
        $this->assertIsString($result['reference_id']);
        $this->assertGreaterThan(0, $result['original_tokens']);
        $this->assertGreaterThan(0, $result['condensed_tokens']);
        $this->assertLessThan($result['original_tokens'], $result['condensed_tokens']);
    }

    public function test_reference_id_is_retrievable_from_cache(): void
    {
        $items = [];
        for ($i = 0; $i < 150; $i++) {
            $items[] = ['id' => 'item-' . $i, 'data' => str_repeat('d', 150)];
        }
        $content = json_encode(['items' => $items]);
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'test_tool', $content);

        $this->assertTrue($result['condensed']);
        $referenceId = $result['reference_id'];

        $retrieved = $condenser->get('conv-1', $referenceId);
        $this->assertSame($content, $retrieved);
    }

    public function test_condensed_event_dispatched(): void
    {
        Event::fake([ToolResultCondensed::class]);

        $items = [];
        for ($i = 0; $i < 150; $i++) {
            $items[] = ['id' => 'item-' . $i, 'data' => str_repeat('d', 150)];
        }
        $content = json_encode(['items' => $items]);
        $condenser = new ToolResultCondenser();

        $condenser->condense('conv-1', 'big_query_tool', $content);

        Event::assertDispatched(ToolResultCondensed::class, function ($event) {
            return $event->conversationId === 'conv-1'
                && $event->toolName === 'big_query_tool'
                && $event->method === 'deterministic'
                && $event->fallback === false
                && $event->originalTokens > $event->condensedTokens;
        });
    }

    // T008B: Binary/non-text passthrough tests

    public function test_binary_content_with_null_bytes_passes_through(): void
    {
        // Content with null bytes (binary indicator).
        $content = "Some binary data\x00\x01\x02\x03" . str_repeat('x', 10000);
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'file_tool', $content);

        $this->assertFalse($result['condensed']);
        $this->assertSame($content, $result['content']);
    }

    public function test_high_non_printable_ratio_passes_through(): void
    {
        // Content with many non-printable characters.
        $binaryChars = '';
        for ($i = 0; $i < 10000; $i++) {
            $binaryChars .= chr(rand(1, 8)); // Control characters.
        }
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'file_tool', $binaryChars);

        $this->assertFalse($result['condensed']);
        $this->assertSame($binaryChars, $result['content']);
    }

    public function test_empty_content_passes_through(): void
    {
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'empty_tool', '');

        $this->assertFalse($result['condensed']);
        $this->assertSame('', $result['content']);
    }

    // Token estimation tests

    public function test_estimate_tokens_matches_convention(): void
    {
        // 8000 chars should be ~2000 tokens (4 chars per token).
        $content = str_repeat('x', 8000);
        $tokens = ToolResultCondenser::estimateTokens($content);

        $this->assertSame(2000, $tokens);
    }

    public function test_estimate_tokens_minimum_one(): void
    {
        $tokens = ToolResultCondenser::estimateTokens('');

        $this->assertSame(1, $tokens);
    }

    public function test_estimate_tokens_short_string(): void
    {
        $tokens = ToolResultCondenser::estimateTokens('hello');

        $this->assertSame(2, $tokens); // 5 chars / 4 = 1.25, ceil = 2.
    }

    // T023: Prose detection tests

    public function test_prose_content_routes_to_prose_path(): void
    {
        // Large prose content (non-JSON) should be condensed.
        $prose = str_repeat('This is a paragraph of prose text. ', 500); // ~20000 chars
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'text_tool', $prose);

        // Prose should be condensed (truncation fallback without LLM provider).
        $this->assertTrue($result['condensed']);
        $this->assertNotSame($prose, $result['content']);
    }

    public function test_prose_detection_for_multiline_text(): void
    {
        $prose = implode("\n\n", [
            'First paragraph with some content.',
            'Second paragraph with more details.',
            'Third paragraph continuing the story.',
        ]);
        // Make it large enough to exceed threshold.
        $prose = str_repeat($prose . "\n", 200);
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'text_tool', $prose);

        // Should be condensed.
        $this->assertTrue($result['condensed']);
    }

    // T024: Truncation fallback tests

    public function test_truncation_fallback_without_llm_provider(): void
    {
        // Without LLM provider, prose should fall back to truncation.
        $prose = str_repeat('Lorem ipsum dolor sit amet. ', 500);
        $condenser = new ToolResultCondenser(); // No LLM provider injected.

        $result = $condenser->condense('conv-1', 'text_tool', $prose);

        $this->assertTrue($result['condensed']);
        $this->assertEquals('truncation', $result['method']);
        $this->assertStringContainsString('truncated', strtolower($result['content']));
    }

    public function test_truncation_fallback_preserves_reference_id(): void
    {
        $prose = str_repeat('Lorem ipsum dolor sit amet. ', 500);
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'text_tool', $prose);

        $this->assertArrayHasKey('reference_id', $result);
        $this->assertNotNull($result['reference_id']);
        $this->assertNotEmpty($result['reference_id']);
    }

    public function test_truncation_preserves_error_messages(): void
    {
        $prose = "Error: Something went wrong.\n\n" . str_repeat('Stack trace line. ', 500);
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'text_tool', $prose);

        // Error messages should be preserved in truncated output.
        $this->assertStringContainsString('Error', $result['content']);
    }

    public function test_truncation_preserves_paths(): void
    {
        $prose = "Failed to open /var/log/app/error.log\n\n" . str_repeat('Debug output line. ', 500);
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'text_tool', $prose);

        // Paths should be preserved.
        $this->assertStringContainsString('/var/log/app/error.log', $result['content']);
    }

    public function test_truncation_includes_reference_indicator(): void
    {
        $prose = str_repeat('Lorem ipsum dolor sit amet. ', 500);
        $condenser = new ToolResultCondenser();

        $result = $condenser->condense('conv-1', 'text_tool', $prose);

        // Should include reference ID in truncation message.
        $this->assertStringContainsString($result['reference_id'], $result['content']);
    }
}
