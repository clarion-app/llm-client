<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\SchemaValidator;
use ClarionApp\LlmClient\Exceptions\SchemaValidationError;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SchemaValidator();
    }

    /* ------------------------------------------------------------------ */
    /* Phase 2: Foundational — Contract tests                             */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function validate_returns_array_on_valid_response()
    {
        $schema = [
            'type' => 'object',
            'required' => ['name', 'age'],
            'properties' => [
                'name' => ['type' => 'string'],
                'age'  => ['type' => 'integer'],
            ],
        ];

        $content = json_encode(['name' => 'Alice', 'age' => 30]);
        $result = $this->validator->validate($content, $schema);

        $this->assertIsArray($result);
        $this->assertEquals('Alice', $result['name']);
        $this->assertEquals(30, $result['age']);
    }

    #[Test]
    public function validate_throws_schema_validation_error_on_failure()
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];

        $content = json_encode(['name' => 123]); // name should be string

        $this->expectException(SchemaValidationError::class);
        $this->validator->validate($content, $schema);
    }

    #[Test]
    public function should_validate_returns_true_when_schema_present()
    {
        $options = [
            'schema' => ['type' => 'object'],
        ];

        $this->assertTrue($this->validator->shouldValidate($options));
    }

    #[Test]
    public function should_validate_returns_false_when_schema_missing()
    {
        $options = [];

        $this->assertFalse($this->validator->shouldValidate($options));
    }

    #[Test]
    public function should_validate_returns_false_when_schema_is_null()
    {
        $options = ['schema' => null];

        $this->assertFalse($this->validator->shouldValidate($options));
    }

    /* ------------------------------------------------------------------ */
    /* Phase 3: US1 — Code fence stripping and JSON decode               */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function validate_strips_json_code_fences()
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
            ],
        ];

        $content = "```json\n{\"message\": \"hello\"}\n```";
        $result = $this->validator->validate($content, $schema);

        $this->assertEquals('hello', $result['message']);
    }

    #[Test]
    public function validate_strips_plain_code_fences()
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'integer'],
            ],
        ];

        $content = "```\n{\"value\": 42}\n```";
        $result = $this->validator->validate($content, $schema);

        $this->assertEquals(42, $result['value']);
    }

    #[Test]
    public function validate_throws_on_invalid_json_parse()
    {
        $schema = ['type' => 'object'];
        $content = 'this is not json';

        $this->expectException(SchemaValidationError::class);
        $this->validator->validate($content, $schema);
    }

    #[Test]
    public function validate_handles_json_parse_error_with_details()
    {
        $schema = ['type' => 'object'];
        $content = '{bad json}';

        $this->expectException(SchemaValidationError::class);

        try {
            $this->validator->validate($content, $schema);
        } catch (SchemaValidationError $e) {
            $this->assertNotNull($e->getRawContent());
            $this->assertEquals($content, $e->getRawContent());
            throw $e;
        }
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('shouldValidateProvider')]
    public function should_validate_handles_various_options($options, $expected)    {
        $result = $this->validator->shouldValidate($options);
        $this->assertEquals($expected, $result, 'Options: ' . json_encode($options));
    }

    /* ------------------------------------------------------------------ */
    /* Phase 5: US3 — Schema validation and pass-through                 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function validate_with_two_different_schemas_independently()
    {
        $personSchema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];

        $taskSchema = [
            'type' => 'object',
            'required' => ['title', 'done'],
            'properties' => [
                'title' => ['type' => 'string'],
                'done'  => ['type' => 'boolean'],
            ],
        ];

        $personContent = json_encode(['name' => 'Alice']);
        $taskContent = json_encode(['title' => 'Buy milk', 'done' => false]);

        $personResult = $this->validator->validate($personContent, $personSchema);
        $taskResult = $this->validator->validate($taskContent, $taskSchema);

        $this->assertEquals('Alice', $personResult['name']);
        $this->assertEquals('Buy milk', $taskResult['title']);
        $this->assertFalse($taskResult['done']);
    }

    #[Test]
    public function validate_with_malformed_schema_throws_error()
    {
        $schema = ['type' => 'invalid_type']; // 'invalid_type' is not a valid JSON Schema type
        $content = json_encode(['name' => 'Alice']);

        $this->expectException(SchemaValidationError::class);
        $this->validator->validate($content, $schema);
    }

    /* ------------------------------------------------------------------ */
    /* Data providers                                                     */
    /* ------------------------------------------------------------------ */

    public static function shouldValidateProvider(): array
    {
        return [
            'empty options' => [[], false],
            'null schema' => [['schema' => null], false],
            'array schema' => [['schema' => ['type' => 'object']], true],
            'string schema' => [['schema' => '{"type":"object"}'], true],
            'empty array schema' => [['schema' => []], false],
            'schema with other options' => [['schema' => ['type' => 'object'], 'model' => 'gpt-4'], true],
        ];
    }
}
