<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry;
use ClarionApp\LlmClient\Services\StructuredOutputPreset;
use ClarionApp\LlmClient\Exceptions\PresetNotFoundException;
use PHPUnit\Framework\Attributes\Test;

class StructuredOutputPresetRegistryTest extends TestCase
{
    private StructuredOutputPresetRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new StructuredOutputPresetRegistry();
    }

    #[Test]
    public function register_adds_preset_to_registry(): void
    {
        $preset = new StructuredOutputPreset('decision', 'Decision preset', ['type' => 'object'], 'prompt');
        $this->registry->register($preset);

        $this->assertTrue($this->registry->has('decision'));
    }

    #[Test]
    public function has_returns_false_for_missing_preset(): void
    {
        $this->assertFalse($this->registry->has('nonexistent'));
    }

    #[Test]
    public function find_returns_preset_by_name(): void
    {
        $preset = new StructuredOutputPreset('decision', 'Decision preset', ['type' => 'object'], 'prompt');
        $this->registry->register($preset);

        $found = $this->registry->find('decision');
        $this->assertEquals('decision', $found->getName());
        $this->assertEquals('Decision preset', $found->getDescription());
    }

    #[Test]
    public function find_throws_when_preset_not_found(): void
    {
        $this->registry->register(
            new StructuredOutputPreset('decision', 'desc', ['type' => 'object'], 'prompt')
        );

        $this->expectException(PresetNotFoundException::class);
        $this->expectExceptionMessage('summary');
        $this->registry->find('summary');
    }

    #[Test]
    public function list_returns_metadata_for_all_presets(): void
    {
        $this->registry->register(
            new StructuredOutputPreset('decision', 'Decide yes/no', ['type' => 'object'], 'prompt')
        );
        $this->registry->register(
            new StructuredOutputPreset('summary', 'Summarize text', ['type' => 'object'], 'prompt')
        );

        $list = $this->registry->list();

        $this->assertCount(2, $list);
        $this->assertArrayHasKey('decision', $list);
        $this->assertArrayHasKey('summary', $list);
        $this->assertEquals('Decide yes/no', $list['decision']['description']);
        $this->assertEquals('Summarize text', $list['summary']['description']);
    }

    #[Test]
    public function resolveSchema_returns_preset_schema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'boolean'],
            ],
        ];
        $this->registry->register(
            new StructuredOutputPreset('decision', 'desc', $schema, 'prompt')
        );

        $result = $this->registry->resolveSchema('decision');
        $this->assertEquals($schema, $result);
    }

    #[Test]
    public function resolveSchema_with_overrides_merges_schema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'boolean'],
            ],
            'required' => ['decision'],
        ];
        $this->registry->register(
            new StructuredOutputPreset('decision', 'desc', $schema, 'prompt')
        );

        $overrides = [
            'properties' => [
                'confidence' => ['type' => 'number'],
            ],
            'required' => ['confidence'],
        ];
        $result = $this->registry->resolveSchema('decision', null, $overrides);

        // Should have both properties
        $this->assertArrayHasKey('decision', $result['properties']);
        $this->assertArrayHasKey('confidence', $result['properties']);
        // Required should be union
        $this->assertEquals(['decision', 'confidence'], $result['required']);
    }

    #[Test]
    public function resolveSchema_with_params_for_parameterized_preset(): void
    {
        $callable = function ($params) {
            $fields = $params['fields'] ?? [];
            $properties = [];
            foreach ($fields as $name => $type) {
                $properties[$name] = ['type' => $type];
            }
            return ['type' => 'object', 'properties' => $properties];
        };
        $this->registry->register(
            new StructuredOutputPreset('extraction', 'desc', $callable, 'prompt')
        );

        $result = $this->registry->resolveSchema('extraction', ['fields' => ['name' => 'string']]);

        $this->assertEquals([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ], $result);
    }

    #[Test]
    public function resolveSchema_with_params_and_overrides_combines_all(): void
    {
        $callable = function ($params) {
            $fields = $params['fields'] ?? [];
            $properties = [];
            foreach ($fields as $name => $type) {
                $properties[$name] = ['type' => $type];
            }
            return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($fields)];
        };
        $this->registry->register(
            new StructuredOutputPreset('extraction', 'desc', $callable, 'prompt')
        );

        $params = ['fields' => ['name' => 'string']];
        $overrides = [
            'properties' => ['extra' => ['type' => 'boolean']],
            'required' => ['extra'],
        ];

        $result = $this->registry->resolveSchema('extraction', $params, $overrides);

        $this->assertArrayHasKey('name', $result['properties']);
        $this->assertArrayHasKey('extra', $result['properties']);
        $this->assertEquals(['name', 'extra'], $result['required']);
    }

    #[Test]
    public function resolveSchema_throws_for_unknown_preset(): void
    {
        $this->expectException(PresetNotFoundException::class);
        $this->registry->resolveSchema('nonexistent');
    }

    #[Test]
    public function list_includes_schema_preview(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'boolean'],
            ],
        ];
        $this->registry->register(
            new StructuredOutputPreset('decision', 'Decide yes/no', $schema, 'prompt')
        );

        $list = $this->registry->list();
        $this->assertEquals($schema, $list['decision']['schema']);
    }

    #[Test]
    public function find_throws_with_available_presets_in_exception(): void
    {
        $this->registry->register(
            new StructuredOutputPreset('decision', 'desc', ['type' => 'object'], 'prompt')
        );
        $this->registry->register(
            new StructuredOutputPreset('summary', 'desc', ['type' => 'object'], 'prompt')
        );

        try {
            $this->registry->find('missing');
            $this->fail('Expected PresetNotFoundException');
        } catch (PresetNotFoundException $e) {
            $available = $e->getAvailablePresets();
            $this->assertContains('decision', $available);
            $this->assertContains('summary', $available);
        }
    }

    #[Test]
    public function resolveSchema_removes_properties_via_null_sentinel(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'boolean'],
                'reasoning' => ['type' => 'string'],
            ],
        ];
        $this->registry->register(
            new StructuredOutputPreset('decision', 'desc', $schema, 'prompt')
        );

        $overrides = [
            'properties' => [
                'reasoning' => null,
            ],
        ];
        $result = $this->registry->resolveSchema('decision', null, $overrides);

        $this->assertArrayHasKey('decision', $result['properties']);
        $this->assertArrayNotHasKey('reasoning', $result['properties']);
    }

    #[Test]
    public function resolveSchema_handles_nested_deep_merge(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'metadata' => [
                    'type' => 'object',
                    'properties' => [
                        'source' => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['metadata'],
        ];
        $this->registry->register(
            new StructuredOutputPreset('nested', 'desc', $schema, 'prompt')
        );

        $overrides = [
            'properties' => [
                'metadata' => [
                    'properties' => [
                        'target' => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['metadata', 'extra'],
        ];
        $result = $this->registry->resolveSchema('nested', null, $overrides);

        // Both source and target should exist
        $this->assertArrayHasKey('source', $result['properties']['metadata']['properties']);
        $this->assertArrayHasKey('target', $result['properties']['metadata']['properties']);
        // Required should be union
        $this->assertEquals(['metadata', 'extra'], $result['required']);
    }
}
