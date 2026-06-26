<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use ClarionApp\LlmClient\Services\ToolFormatter;
use ClarionApp\LlmClient\Contracts\ProviderType;

class ToolFormatterTest extends TestCase
{
    private ToolFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new ToolFormatter();
    }

    /**
     * T002: Test formatForProvider() method exists and returns array.
     */
    public function testFormatForProviderMethodExistsAndReturnsArray(): void
    {
        $result = $this->formatter->formatForProvider([], ProviderType::OpenAI);
        $this->assertIsArray($result);
    }

    /**
     * T005: Test OpenAI provider type returns tools unchanged (pass-through).
     */
    public function testOpenAIProviderReturnsToolsUnchanged(): void
    {
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'test_tool',
                    'description' => 'A test tool',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'description' => 'The ID'],
                        ],
                        'required' => ['id'],
                    ],
                ],
            ],
        ];

        $result = $this->formatter->formatForProvider($tools, ProviderType::OpenAI);

        $this->assertEquals($tools, $result);
    }

    /**
     * T006: Test LlamaCpp provider type returns tools unchanged (pass-through).
     */
    public function testLlamaCppProviderReturnsToolsUnchanged(): void
    {
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_contacts',
                    'description' => 'List contacts',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
        ];

        $result = $this->formatter->formatForProvider($tools, ProviderType::LlamaCpp);

        $this->assertEquals($tools, $result);
    }

    /**
     * T007: Test Anthropic provider type flattens {type, function} wrapper
     * to {name, description, input_schema}.
     */
    public function testAnthropicProviderFlattensToolWrapper(): void
    {
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'execute_operation',
                    'description' => 'Execute an API operation',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'operationId' => ['type' => 'string', 'description' => 'The operation ID'],
                        ],
                        'required' => ['operationId'],
                    ],
                ],
            ],
        ];

        $result = $this->formatter->formatForProvider($tools, ProviderType::Anthropic);

        $expected = [
            [
                'name' => 'execute_operation',
                'description' => 'Execute an API operation',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'operationId' => ['type' => 'string', 'description' => 'The operation ID'],
                    ],
                    'required' => ['operationId'],
                ],
            ],
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * T008: Test Anthropic format preserves nested properties, required arrays,
     * and description fields during transformation.
     */
    public function testAnthropicFormatPreservesNestedSchema(): void
    {
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'complex_tool',
                    'description' => 'A tool with nested parameters',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'operationId' => ['type' => 'string', 'description' => 'The operation ID'],
                            'parameters' => [
                                'type' => 'object',
                                'description' => 'Operation parameters',
                                'properties' => [
                                    'path' => [
                                        'type' => 'object',
                                        'description' => 'Path parameters',
                                        'properties' => new \stdClass(),
                                    ],
                                    'query' => [
                                        'type' => 'object',
                                        'description' => 'Query parameters',
                                        'properties' => [
                                            'page' => ['type' => 'integer', 'description' => 'Page number'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'required' => ['operationId'],
                    ],
                ],
            ],
        ];

        $result = $this->formatter->formatForProvider($tools, ProviderType::Anthropic);

        $this->assertEquals('complex_tool', $result[0]['name']);
        $this->assertEquals('A tool with nested parameters', $result[0]['description']);
        $this->assertArrayHasKey('input_schema', $result[0]);
        $this->assertArrayHasKey('operationId', $result[0]['input_schema']['properties']);
        $this->assertArrayHasKey('parameters', $result[0]['input_schema']['properties']);
        $this->assertArrayHasKey('path', $result[0]['input_schema']['properties']['parameters']['properties']);
        $this->assertArrayHasKey('query', $result[0]['input_schema']['properties']['parameters']['properties']);
        $this->assertEquals(['operationId'], $result[0]['input_schema']['required']);
    }

    /**
     * T009: Test Anthropic format handles empty stdClass properties correctly.
     */
    public function testAnthropicFormatHandlesEmptyStdClassProperties(): void
    {
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_applications',
                    'description' => 'List all available applications',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
        ];

        $result = $this->formatter->formatForProvider($tools, ProviderType::Anthropic);

        $this->assertEquals('list_applications', $result[0]['name']);
        $this->assertEquals('List all available applications', $result[0]['description']);
        $this->assertArrayHasKey('input_schema', $result[0]);
        $this->assertEquals('object', $result[0]['input_schema']['type']);
        $this->assertInstanceOf(\stdClass::class, $result[0]['input_schema']['properties']);
    }

    /**
     * T010: Test unsupported provider type throws InvalidArgumentException.
     */
    public function testUnsupportedProviderThrowsException(): void
    {
        $this->markTestIncomplete('ProviderType enum currently only has 3 cases - all handled. This test validates the default/throw path which may not be reachable with current enum.');
    }

    /**
     * T011: Test empty tools array returns empty array for all provider types.
     */
    public function testEmptyToolsArrayReturnsEmptyArray(): void
    {
        $this->assertEquals([], $this->formatter->formatForProvider([], ProviderType::OpenAI));
        $this->assertEquals([], $this->formatter->formatForProvider([], ProviderType::Anthropic));
        $this->assertEquals([], $this->formatter->formatForProvider([], ProviderType::LlamaCpp));
    }
}
