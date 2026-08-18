<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\McpClientTextSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * McpClientTextSanitizer -- the write-time cleanup applied to a
 * server-supplied tool description before it is cached or shown to a
 * model: truncation to a configured bound, control-character stripping,
 * whitespace-run collapsing, and a deterministic provenance prefix.
 */
class McpClientTextSanitizerTest extends TestCase
{
    #[Test]
    public function truncates_a_description_longer_than_the_configured_bound(): void
    {
        config(['llm-client.mcp_client.description_max_length' => 20]);

        $result = (new McpClientTextSanitizer())->sanitize(str_repeat('a', 50), 'My Server');

        $this->assertSame('[External tool via My Server] ' . str_repeat('a', 20), $result);
    }

    #[Test]
    public function a_description_within_the_bound_is_left_intact(): void
    {
        config(['llm-client.mcp_client.description_max_length' => 500]);

        $result = (new McpClientTextSanitizer())->sanitize('Short description.', 'My Server');

        $this->assertSame('[External tool via My Server] Short description.', $result);
    }

    #[Test]
    public function control_characters_and_whitespace_runs_are_stripped_and_collapsed(): void
    {
        $description = "Line one\x07\nLine   two\ttabbed\x1B";

        $result = (new McpClientTextSanitizer())->sanitize($description, 'My Server');

        $this->assertSame('[External tool via My Server] Line one Line two tabbed', $result);
    }

    #[Test]
    public function applies_the_provenance_prefix_deterministically(): void
    {
        $first = (new McpClientTextSanitizer())->sanitize('Does a thing.', 'Weather API');
        $second = (new McpClientTextSanitizer())->sanitize('Does a thing.', 'Weather API');

        $this->assertSame('[External tool via Weather API] Does a thing.', $first);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function a_null_description_still_produces_a_deterministic_prefixed_result(): void
    {
        $result = (new McpClientTextSanitizer())->sanitize(null, 'Weather API');

        $this->assertSame('[External tool via Weather API]', $result);
    }
}
