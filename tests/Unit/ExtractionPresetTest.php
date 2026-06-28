<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Presets\ExtractionPreset;
use PHPUnit\Framework\Attributes\Test;

class ExtractionPresetTest extends TestCase
{
    #[Test]
    public function preset_name_is_extraction(): void
    {
        $preset = new ExtractionPreset();
        $this->assertEquals('extraction', $preset->getName());
    }

    #[Test]
    public function schema_generates_properties_from_fields(): void
    {
        $preset = new ExtractionPreset();
        $schema = $preset->getSchema([
            'fields' => [
                'name' => 'string',
                'email' => 'string',
                'age' => 'integer',
            ]
        ]);

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertArrayHasKey('email', $schema['properties']);
        $this->assertArrayHasKey('age', $schema['properties']);
    }

    #[Test]
    public function schema_field_types_match_provided_definitions(): void
    {
        $preset = new ExtractionPreset();
        $schema = $preset->getSchema([
            'fields' => [
                'title' => 'string',
                'count' => 'integer',
                'active' => 'boolean',
            ]
        ]);

        $this->assertEquals('string', $schema['properties']['title']['type']);
        $this->assertEquals('integer', $schema['properties']['count']['type']);
        $this->assertEquals('boolean', $schema['properties']['active']['type']);
    }

    #[Test]
    public function schema_requires_all_fields(): void
    {
        $preset = new ExtractionPreset();
        $schema = $preset->getSchema([
            'fields' => [
                'name' => 'string',
                'age' => 'integer',
            ]
        ]);

        $this->assertContains('name', $schema['required']);
        $this->assertContains('age', $schema['required']);
    }

    #[Test]
    public function schema_with_empty_fields_returns_empty_properties(): void
    {
        $preset = new ExtractionPreset();
        $schema = $preset->getSchema(['fields' => []]);

        $this->assertEquals('object', $schema['type']);
        $this->assertEquals([], $schema['properties']);
        $this->assertEquals([], $schema['required']);
    }

    #[Test]
    public function system_prompt_contains_extraction_guidance(): void
    {
        $preset = new ExtractionPreset();
        $prompt = $preset->getSystemPrompt();

        $this->assertStringContainsString('extract', strtolower($prompt));
    }

    #[Test]
    public function description_is_not_empty(): void
    {
        $preset = new ExtractionPreset();
        $this->assertNotNull($preset->getDescription());
        $this->assertNotEmpty($preset->getDescription());
    }
}
