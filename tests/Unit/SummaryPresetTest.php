<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Presets\SummaryPreset;
use PHPUnit\Framework\Attributes\Test;

class SummaryPresetTest extends TestCase
{
    #[Test]
    public function preset_name_is_summary(): void
    {
        $preset = new SummaryPreset();
        $this->assertEquals('summary', $preset->getName());
    }

    #[Test]
    public function schema_has_summary_string_field(): void
    {
        $preset = new SummaryPreset();
        $schema = $preset->getSchema();

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('summary', $schema['properties']);
        $this->assertEquals('string', $schema['properties']['summary']['type']);
    }

    #[Test]
    public function schema_has_key_points_array_field(): void
    {
        $preset = new SummaryPreset();
        $schema = $preset->getSchema();

        $this->assertArrayHasKey('key_points', $schema['properties']);
        $this->assertEquals('array', $schema['properties']['key_points']['type']);
        $this->assertEquals('string', $schema['properties']['key_points']['items']['type']);
    }

    #[Test]
    public function schema_requires_both_fields(): void
    {
        $preset = new SummaryPreset();
        $schema = $preset->getSchema();

        $this->assertContains('summary', $schema['required']);
        $this->assertContains('key_points', $schema['required']);
    }

    #[Test]
    public function system_prompt_contains_summary_guidance(): void
    {
        $preset = new SummaryPreset();
        $prompt = $preset->getSystemPrompt();

        $this->assertStringContainsString('summary', strtolower($prompt));
    }

    #[Test]
    public function description_is_not_empty(): void
    {
        $preset = new SummaryPreset();
        $this->assertNotNull($preset->getDescription());
        $this->assertNotEmpty($preset->getDescription());
    }
}
