<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\StructuredOutputPreset;
use PHPUnit\Framework\Attributes\Test;

class StructuredOutputPresetTest extends TestCase
{
    #[Test]
    public function constructor_sets_name_and_description(): void
    {
        $preset = new StructuredOutputPreset(
            'decision',
            'A decision preset',
            ['type' => 'object'],
            'System prompt text'
        );

        $this->assertEquals('decision', $preset->getName());
        $this->assertEquals('A decision preset', $preset->getDescription());
    }

    #[Test]
    public function getSchema_returns_array_schema_directly(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'boolean'],
            ],
        ];
        $preset = new StructuredOutputPreset('decision', 'desc', $schema, 'prompt');
        $result = $preset->getSchema();

        $this->assertEquals($schema, $result);
    }

    #[Test]
    public function getSchema_invokes_callable_with_params(): void
    {
        $callable = function ($params) {
            $fields = $params['fields'] ?? [];
            $properties = [];
            foreach ($fields as $name => $type) {
                $properties[$name] = ['type' => $type];
            }
            return ['type' => 'object', 'properties' => $properties];
        };

        $preset = new StructuredOutputPreset('extraction', 'desc', $callable, 'prompt');
        $result = $preset->getSchema(['fields' => ['name' => 'string', 'age' => 'integer']]);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
        ], $result);
    }

    #[Test]
    public function getSchema_with_callable_ignores_null_params(): void
    {
        $callable = function ($params) {
            return ['type' => 'object', 'properties' => [], 'params_received' => $params ?? null];
        };

        $preset = new StructuredOutputPreset('test', 'desc', $callable, 'prompt');
        $result = $preset->getSchema(null);

        $this->assertArrayHasKey('params_received', $result);
    }

    #[Test]
    public function getSystemPrompt_returns_prompt(): void
    {
        $preset = new StructuredOutputPreset('decision', 'desc', ['type' => 'object'], 'Your system prompt here');
        $this->assertEquals('Your system prompt here', $preset->getSystemPrompt());
    }

    #[Test]
    public function preset_is_immutable_no_setters(): void
    {
        $preset = new StructuredOutputPreset('decision', 'desc', ['type' => 'object'], 'prompt');
        $reflection = new \ReflectionClass($preset);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        $methodNames = array_map(fn($m) => $m->getName(), $methods);

        // Should not have any setter methods
        $this->assertNotContains('setName', $methodNames);
        $this->assertNotContains('setDescription', $methodNames);
        $this->assertNotContains('setSchema', $methodNames);
        $this->assertNotContains('setSystemPrompt', $methodNames);
    }
}
