<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Presets\DecisionPreset;
use PHPUnit\Framework\Attributes\Test;

class DecisionPresetTest extends TestCase
{
    #[Test]
    public function preset_name_is_decision(): void
    {
        $preset = new DecisionPreset();
        $this->assertEquals('decision', $preset->getName());
    }

    #[Test]
    public function schema_has_decision_boolean_field(): void
    {
        $preset = new DecisionPreset();
        $schema = $preset->getSchema();

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('decision', $schema['properties']);
        $this->assertEquals('boolean', $schema['properties']['decision']['type']);
    }

    #[Test]
    public function schema_has_reasoning_string_field(): void
    {
        $preset = new DecisionPreset();
        $schema = $preset->getSchema();

        $this->assertArrayHasKey('reasoning', $schema['properties']);
        $this->assertEquals('string', $schema['properties']['reasoning']['type']);
    }

    #[Test]
    public function schema_requires_both_fields(): void
    {
        $preset = new DecisionPreset();
        $schema = $preset->getSchema();

        $this->assertContains('decision', $schema['required']);
        $this->assertContains('reasoning', $schema['required']);
    }

    #[Test]
    public function system_prompt_contains_decision_guidance(): void
    {
        $preset = new DecisionPreset();
        $prompt = $preset->getSystemPrompt();

        $this->assertStringContainsString('decision', strtolower($prompt));
    }

    #[Test]
    public function description_is_not_empty(): void
    {
        $preset = new DecisionPreset();
        $this->assertNotNull($preset->getDescription());
        $this->assertNotEmpty($preset->getDescription());
    }
}
