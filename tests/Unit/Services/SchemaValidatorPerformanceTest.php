<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\SchemaValidator;

use PHPUnit\Framework\Attributes\Test;

class SchemaValidatorPerformanceTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SchemaValidator();
    }

    /* ------------------------------------------------------------------ */
    /* Performance benchmarks                                             */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function validates_typical_schema_within_10ms()
    {
        $schema = [
            'type' => 'object',
            'required' => ['name', 'email'],
            'properties' => [
                'name'  => ['type' => 'string'],
                'email' => ['type' => 'string', 'format' => 'email'],
                'age'   => ['type' => 'integer'],
                'active' => ['type' => 'boolean'],
                'tags'  => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];

        $content = json_encode([
            'name'   => 'John Doe',
            'email'  => 'john@example.com',
            'age'    => 30,
            'active' => true,
            'tags'   => ['developer', 'php'],
        ]);

        $iterations = 100;
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $this->validator->validate($content, $schema);
        }

        $elapsed = (microtime(true) - $start) / $iterations;

        // Each validation should be under 10ms (generous threshold for CI)
        $this->assertLessThan(0.01, $elapsed, sprintf(
            'Average validation took %.3fms (expected <10ms)',
            $elapsed * 1000
        ));
    }

    #[Test]
    public function pass_through_has_zero_overhead()
    {
        // When no schema is provided, shouldValidate returns false immediately
        $options = [];

        $start = microtime(true);
        for ($i = 0; $i < 10000; $i++) {
            $this->validator->shouldValidate($options);
        }
        $elapsed = microtime(true) - $start;

        // 10,000 calls should take less than 100ms total (~0.01ms per call)
        $this->assertLessThan(0.1, $elapsed, sprintf(
            'Pass-through overhead too high: %.4fms for 10,000 calls',
            $elapsed * 1000
        ));
    }

    #[Test]
    public function should_validate_returns_false_for_null_schema()
    {
        $this->assertFalse($this->validator->shouldValidate([]));
        $this->assertFalse($this->validator->shouldValidate(['schema' => null]));
    }

    #[Test]
    public function should_validate_returns_true_for_valid_schema()
    {
        $schema = ['type' => 'object'];
        $this->assertTrue($this->validator->shouldValidate(['schema' => $schema]));
    }

    #[Test]
    public function validates_larger_schema_within_50ms()
    {
        // Larger schema with nested objects and arrays
        $schema = [
            'type' => 'object',
            'required' => ['id', 'data'],
            'properties' => [
                'id'    => ['type' => 'string', 'format' => 'uuid'],
                'data'  => [
                    'type' => 'object',
                    'properties' => [
                        'nested' => [
                            'type' => 'object',
                            'properties' => [
                                'deep' => ['type' => 'string'],
                                'values' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            ],
                        ],
                    ],
                ],
                'metadata' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
            ],
        ];

        $content = json_encode([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'data' => [
                'nested' => [
                    'deep' => 'value',
                    'values' => [1, 2, 3, 4, 5],
                ],
            ],
            'metadata' => ['key' => 'value'],
        ]);

        $start = microtime(true);
        $result = $this->validator->validate($content, $schema);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.05, $elapsed, sprintf(
            'Large schema validation took %.3fms (expected <50ms)',
            $elapsed * 1000
        ));
        $this->assertIsArray($result);
    }
}
