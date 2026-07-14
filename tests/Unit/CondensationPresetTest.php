<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Presets\CondensationPreset;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CondensationPresetTest extends TestCase
{
    #[Test]
    public function preset_name_is_condensation(): void
    {
        $preset = new CondensationPreset();
        $this->assertEquals('condensation', $preset->getName());
    }

    #[Test]
    public function schema_has_decisions_array_field(): void
    {
        $preset = new CondensationPreset();
        $schema = $preset->getSchema();

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('decisions', $schema['properties']);
        $this->assertEquals('array', $schema['properties']['decisions']['type']);
        $this->assertEquals('string', $schema['properties']['decisions']['items']['type']);
    }

    #[Test]
    public function schema_has_constraints_array_field(): void
    {
        $preset = new CondensationPreset();
        $schema = $preset->getSchema();

        $this->assertArrayHasKey('constraints', $schema['properties']);
        $this->assertEquals('array', $schema['properties']['constraints']['type']);
    }

    #[Test]
    public function schema_has_open_questions_array_field(): void
    {
        $preset = new CondensationPreset();
        $schema = $preset->getSchema();

        $this->assertArrayHasKey('open_questions', $schema['properties']);
        $this->assertEquals('array', $schema['properties']['open_questions']['type']);
    }

    #[Test]
    public function schema_has_facts_array_field(): void
    {
        $preset = new CondensationPreset();
        $schema = $preset->getSchema();

        $this->assertArrayHasKey('facts', $schema['properties']);
        $this->assertEquals('array', $schema['properties']['facts']['type']);
    }

    #[Test]
    public function schema_has_commitments_array_field(): void
    {
        $preset = new CondensationPreset();
        $schema = $preset->getSchema();

        $this->assertArrayHasKey('commitments', $schema['properties']);
        $this->assertEquals('array', $schema['properties']['commitments']['type']);
    }

    #[Test]
    public function schema_has_optional_context_field(): void
    {
        $preset = new CondensationPreset();
        $schema = $preset->getSchema();

        $this->assertArrayHasKey('context', $schema['properties']);
        $this->assertEquals('string', $schema['properties']['context']['type']);
    }

    #[Test]
    public function schema_requires_structured_fields(): void
    {
        $preset = new CondensationPreset();
        $schema = $preset->getSchema();

        $this->assertContains('decisions', $schema['required']);
        $this->assertContains('constraints', $schema['required']);
        $this->assertContains('open_questions', $schema['required']);
        $this->assertContains('facts', $schema['required']);
        $this->assertContains('commitments', $schema['required']);
    }

    #[Test]
    public function system_prompt_contains_extraction_guidance(): void
    {
        $preset = new CondensationPreset();
        $prompt = $preset->getSystemPrompt();

        $this->assertStringContainsString('decisions', strtolower($prompt));
        $this->assertStringContainsString('constraints', strtolower($prompt));
        $this->assertStringContainsString('extract', strtolower($prompt));
    }

    #[Test]
    public function description_is_not_empty(): void
    {
        $preset = new CondensationPreset();
        $this->assertNotNull($preset->getDescription());
        $this->assertNotEmpty($preset->getDescription());
    }
}
