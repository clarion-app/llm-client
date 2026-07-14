<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use ClarionApp\LlmClient\Services\StructureReducer;
use ClarionApp\LlmClient\Services\ToolResultCondenser;

class StructureReducerTest extends TestCase
{
    private StructureReducer $reducer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reducer = new StructureReducer();
    }

    // T015: Array reduction tests

    public function test_array_reduction_keeps_sample_items(): void
    {
        $items = [];
        for ($i = 0; $i < 20; $i++) {
            $items[] = ['id' => "item-{$i}", 'name' => "Item {$i}", 'description' => str_repeat('x', 50)];
        }

        $reduced = $this->reducer->reduce($items, 500, 3);

        // Should have metadata
        $this->assertArrayHasKey('_meta', $reduced);
        $this->assertEquals(20, $reduced['_meta']['total_count']);
        $this->assertEquals(3, $reduced['_meta']['sample_count']);

        // Should have 3 sample items + _meta + _truncated
        $this->assertArrayHasKey('_truncated', $reduced);
    }

    public function test_array_reduction_preserves_total_count(): void
    {
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = ['id' => $i];
        }

        $reduced = $this->reducer->reduce($items, 500, 5);

        $this->assertEquals(100, $reduced['_meta']['total_count']);
    }

    public function test_array_reduction_preserves_fields(): void
    {
        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = ['id' => $i, 'name' => "Name {$i}", 'email' => "user{$i}@example.com"];
        }

        $reduced = $this->reducer->reduce($items, 500, 3);

        $this->assertIsArray($reduced['_meta']['fields']);
        $this->assertContains('id', $reduced['_meta']['fields']);
        $this->assertContains('name', $reduced['_meta']['fields']);
        $this->assertContains('email', $reduced['_meta']['fields']);
    }

    public function test_small_array_passed_through_with_reduction(): void
    {
        $items = [['id' => 1], ['id' => 2], ['id' => 3]];

        $reduced = $this->reducer->reduce($items, 500, 5);

        // Small arrays (<= sampleItems) keep all items without _meta wrapper
        $this->assertArrayNotHasKey('_meta', $reduced);
        $this->assertCount(3, $reduced);
    }

    // T016: Object reduction tests

    public function test_object_reduction_preserves_top_level_keys(): void
    {
        $data = [
            'id' => 'abc-123',
            'name' => 'Test Object',
            'description' => str_repeat('x', 300),
            'items' => ['a', 'b', 'c'],
            'nested' => ['key1' => 'value1', 'key2' => 'value2'],
        ];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertArrayHasKey('id', $reduced);
        $this->assertArrayHasKey('name', $reduced);
        $this->assertArrayHasKey('description', $reduced);
        $this->assertArrayHasKey('items', $reduced);
        $this->assertArrayHasKey('nested', $reduced);
    }

    public function test_object_reduction_truncates_long_values(): void
    {
        $data = [
            'id' => 'abc-123',
            'description' => str_repeat('x', 500),
        ];

        $reduced = $this->reducer->reduce($data, 500, 5);

        // Long string values should be truncated to 200 chars + ellipsis
        $this->assertLessThan(500, strlen($reduced['description']));
        $this->assertStringEndsWith('...', $reduced['description']);
    }

    public function test_object_reduction_handles_nested_arrays(): void
    {
        $largeArray = [];
        for ($i = 0; $i < 50; $i++) {
            $largeArray[] = ['id' => $i, 'value' => "val-{$i}"];
        }

        $data = ['items' => $largeArray, 'total' => 50];

        $reduced = $this->reducer->reduce($data, 500, 3);

        // Nested large array should be reduced
        $this->assertArrayHasKey('_meta', $reduced['items']);
        $this->assertEquals(50, $reduced['items']['_meta']['total_count']);
    }

    public function test_object_reduction_handles_nested_objects(): void
    {
        $data = [
            'outer' => [
                'inner' => [
                    'deep' => [
                        'very_deep' => str_repeat('x', 300),
                        'id' => 'deep-id-123',
                    ],
                ],
            ],
        ];

        $reduced = $this->reducer->reduce($data, 500, 5);

        // Should have reduced nested structure
        $this->assertArrayHasKey('outer', $reduced);
        $this->assertArrayHasKey('inner', $reduced['outer']);
    }

    public function test_object_reduction_respects_max_depth(): void
    {
        $data = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'deep']]]]]];

        $reduced = $this->reducer->reduce($data, 500, 5);

        // Max depth is 3, so deeply nested structures should be truncated
        $this->assertArrayHasKey('a', $reduced);
    }

    public function test_aggressive_reduction_for_large_data(): void
    {
        // Create data that will exceed budget even after normal reduction
        $items = [];
        for ($i = 0; $i < 1000; $i++) {
            $items[] = [
                'id' => "item-{$i}",
                'name' => "Item {$i}",
                'description' => str_repeat('x', 200),
                'metadata' => ['key1' => 'val1', 'key2' => 'val2'],
            ];
        }

        $reduced = $this->reducer->reduce($items, 10, 2);

        // Should fallback to aggressive reduction
        $this->assertArrayHasKey('_meta', $reduced);
        $this->assertArrayHasKey('_truncated', $reduced);
    }

    public function test_reduce_with_object_input(): void
    {
        $data = (object) ['id' => '123', 'name' => 'Test'];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertIsArray($reduced);
        $this->assertEquals('123', $reduced['id']);
        $this->assertEquals('Test', $reduced['name']);
    }

    public function test_reduce_with_scalar_input(): void
    {
        $reduced = $this->reducer->reduce('simple string', 500, 5);

        $this->assertEquals(['value' => 'simple string'], $reduced);
    }
}
