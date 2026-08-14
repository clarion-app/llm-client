<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Presets\DelegationResultPreset;
use PHPUnit\Framework\Attributes\Test;

class DelegationResultPresetTest extends TestCase
{
    #[Test]
    public function preset_name_is_delegation_result(): void
    {
        $preset = new DelegationResultPreset();
        $this->assertEquals('delegation_result', $preset->getName());
    }

    #[Test]
    public function schema_type_is_object(): void
    {
        $preset = new DelegationResultPreset();
        $schema = $preset->getSchema();

        $this->assertEquals('object', $schema['type']);
    }

    #[Test]
    public function schema_properties_are_exactly_status_summary_output_undone(): void
    {
        $preset = new DelegationResultPreset();
        $schema = $preset->getSchema();

        $this->assertEqualsCanonicalizing(
            ['status', 'summary', 'output', 'undone'],
            array_keys($schema['properties'])
        );
    }

    #[Test]
    public function schema_status_field_is_enum_of_three_outcomes(): void
    {
        $preset = new DelegationResultPreset();
        $schema = $preset->getSchema();

        $this->assertEquals('string', $schema['properties']['status']['type']);
        $this->assertEqualsCanonicalizing(
            ['success', 'partial', 'failure'],
            $schema['properties']['status']['enum']
        );
    }

    #[Test]
    public function schema_summary_field_is_string(): void
    {
        $preset = new DelegationResultPreset();
        $schema = $preset->getSchema();

        $this->assertEquals('string', $schema['properties']['summary']['type']);
    }

    #[Test]
    public function schema_output_field_is_object(): void
    {
        $preset = new DelegationResultPreset();
        $schema = $preset->getSchema();

        $this->assertEquals('object', $schema['properties']['output']['type']);
    }

    #[Test]
    public function schema_undone_field_is_string(): void
    {
        $preset = new DelegationResultPreset();
        $schema = $preset->getSchema();

        $this->assertEquals('string', $schema['properties']['undone']['type']);
    }

    #[Test]
    public function schema_required_is_exactly_status_summary_output_undone(): void
    {
        $preset = new DelegationResultPreset();
        $schema = $preset->getSchema();

        $this->assertEqualsCanonicalizing(
            ['status', 'summary', 'output', 'undone'],
            $schema['required']
        );
    }

    #[Test]
    public function system_prompt_is_not_empty(): void
    {
        $preset = new DelegationResultPreset();
        $this->assertNotEmpty($preset->getSystemPrompt());
    }

    #[Test]
    public function system_prompt_mentions_all_three_status_values(): void
    {
        $preset = new DelegationResultPreset();
        $prompt = $preset->getSystemPrompt();

        $this->assertStringContainsString('success', $prompt);
        $this->assertStringContainsString('partial', $prompt);
        $this->assertStringContainsString('failure', $prompt);
    }
}
