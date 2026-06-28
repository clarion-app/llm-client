<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\SchemaMerger;
use PHPUnit\Framework\Attributes\Test;

class SchemaMergerTest extends TestCase
{
    private SchemaMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merger = new SchemaMerger();
    }

    #[Test]
    public function merge_returns_base_when_overrides_empty(): void
    {
        $base = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];
        $result = $this->merger->merge($base, []);
        $this->assertEquals($base, $result);
    }

    #[Test]
    public function merge_adds_new_properties(): void
    {
        $base = [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'boolean'],
            ],
        ];
        $overrides = [
            'properties' => [
                'confidence' => ['type' => 'number'],
            ],
        ];
        $result = $this->merger->merge($base, $overrides);

        $this->assertArrayHasKey('decision', $result['properties']);
        $this->assertArrayHasKey('confidence', $result['properties']);
        $this->assertEquals(['type' => 'boolean'], $result['properties']['decision']);
        $this->assertEquals(['type' => 'number'], $result['properties']['confidence']);
    }

    #[Test]
    public function merge_unions_required_arrays(): void
    {
        $base = [
            'type' => 'object',
            'required' => ['decision'],
        ];
        $overrides = [
            'required' => ['confidence'],
        ];
        $result = $this->merger->merge($base, $overrides);

        $this->assertEquals(['decision', 'confidence'], $result['required']);
    }

    #[Test]
    public function merge_replaces_scalar_values(): void
    {
        $base = [
            'type' => 'object',
            'description' => 'Original description',
        ];
        $overrides = [
            'description' => 'New description',
        ];
        $result = $this->merger->merge($base, $overrides);
        $this->assertEquals('New description', $result['description']);
    }

    #[Test]
    public function merge_removes_property_when_null_sentinel(): void
    {
        $base = [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'boolean'],
                'reasoning' => ['type' => 'string'],
            ],
        ];
        $overrides = [
            'properties' => [
                'reasoning' => null,
            ],
        ];
        $result = $this->merger->merge($base, $overrides);

        $this->assertArrayHasKey('decision', $result['properties']);
        $this->assertArrayNotHasKey('reasoning', $result['properties']);
    }

    #[Test]
    public function merge_overrides_nested_property_description(): void
    {
        $base = [
            'type' => 'object',
            'properties' => [
                'decision' => [
                    'type' => 'boolean',
                    'description' => 'The decision',
                ],
            ],
        ];
        $overrides = [
            'properties' => [
                'decision' => [
                    'description' => 'Whether to approve or reject',
                ],
            ],
        ];
        $result = $this->merger->merge($base, $overrides);

        // Should keep type from base, override description
        $this->assertEquals('boolean', $result['properties']['decision']['type']);
        $this->assertEquals('Whether to approve or reject', $result['properties']['decision']['description']);
    }

    #[Test]
    public function merge_handles_deep_nested_properties(): void
    {
        $base = [
            'type' => 'object',
            'properties' => [
                'metadata' => [
                    'type' => 'object',
                    'properties' => [
                        'source' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
        $overrides = [
            'properties' => [
                'metadata' => [
                    'properties' => [
                        'target' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
        $result = $this->merger->merge($base, $overrides);

        $this->assertArrayHasKey('source', $result['properties']['metadata']['properties']);
        $this->assertArrayHasKey('target', $result['properties']['metadata']['properties']);
    }
}
