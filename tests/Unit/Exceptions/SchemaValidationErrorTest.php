<?php

namespace ClarionApp\LlmClient\Tests\Unit\Exceptions;

use Tests\TestCase;
use ClarionApp\LlmClient\Exceptions\SchemaValidationError;

use PHPUnit\Framework\Attributes\Test;

class SchemaValidationErrorTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /* Constructor and basic getters                                      */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function extends_runtime_exception()
    {
        $error = new SchemaValidationError(
            'Validation failed',
            [],
            '{"key": "value"}',
            null,
            ['type' => 'object'],
            0,
            2
        );

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertEquals('Validation failed', $error->getMessage());
    }

    #[Test]
    public function get_violations_returns_violations_array()
    {
        $violations = [
            ['property' => '$.name', 'message' => 'Must be a string'],
            ['property' => '$.age', 'message' => 'Minimum value is 0'],
        ];

        $error = new SchemaValidationError(
            'Validation failed',
            $violations,
            '{"name": 123}',
            null,
            ['type' => 'object'],
            0,
            2
        );

        $this->assertEquals($violations, $error->getViolations());
    }

    #[Test]
    public function get_raw_content_returns_original_content()
    {
        $rawContent = '{"name": 123, "age": -1}';

        $error = new SchemaValidationError(
            'Validation failed',
            [],
            $rawContent,
            null,
            ['type' => 'object'],
            0,
            2
        );

        $this->assertEquals($rawContent, $error->getRawContent());
    }

    #[Test]
    public function get_stripped_content_returns_stripped_content()
    {
        $strippedContent = '{"name": "John"}';

        $error = new SchemaValidationError(
            'Validation failed',
            [],
            '```json\n' . $strippedContent . '\n```',
            $strippedContent,
            ['type' => 'object'],
            0,
            2
        );

        $this->assertEquals($strippedContent, $error->getStrippedContent());
    }

    #[Test]
    public function get_stripped_content_returns_null_when_no_fences()
    {
        $error = new SchemaValidationError(
            'Validation failed',
            [],
            '{"name": "John"}',
            null,
            ['type' => 'object'],
            0,
            2
        );

        $this->assertNull($error->getStrippedContent());
    }

    #[Test]
    public function get_schema_returns_schema()
    {
        $schema = ['type' => 'object', 'required' => ['name']];

        $error = new SchemaValidationError(
            'Validation failed',
            [],
            '{}',
            null,
            $schema,
            0,
            2
        );

        $this->assertEquals($schema, $error->getSchema());
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('schemaProviderTypes')]
    public function get_schema_accepts_array_or_string_schema($schema)    {
        $error = new SchemaValidationError(
            'Validation failed',
            [],
            '{}',
            null,
            $schema,
            0,
            2
        );

        $this->assertEquals($schema, $error->getSchema());
    }

    /* ------------------------------------------------------------------ */
    /* Retry tracking                                                     */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function get_retry_attempt_returns_attempt_number()
    {
        $error = new SchemaValidationError(
            'Validation failed',
            [],
            '{}',
            null,
            ['type' => 'object'],
            1,
            3
        );

        $this->assertEquals(1, $error->getRetryAttempt());
    }

    #[Test]
    public function get_retry_attempt_defaults_to_zero()
    {
        $error = new SchemaValidationError(
            'Validation failed',
            [],
            '{}',
            null,
            ['type' => 'object']
        );

        $this->assertEquals(0, $error->getRetryAttempt());
    }

    #[Test]
    public function get_max_retries_returns_max_retries()
    {
        $error = new SchemaValidationError(
            'Validation failed',
            [],
            '{}',
            null,
            ['type' => 'object'],
            1,
            3
        );

        $this->assertEquals(3, $error->getMaxRetries());
    }

    /* ------------------------------------------------------------------ */
    /* isRetryExhausted logic                                             */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function is_retry_exhausted_returns_true_when_attempt_equals_max()
    {
        $error = new SchemaValidationError(
            'Validation failed',
            [],
            '{}',
            null,
            ['type' => 'object'],
            2,
            2
        );

        $this->assertTrue($error->isRetryExhausted());
    }

    #[Test]
    public function is_retry_exhausted_returns_false_when_attempt_less_than_max()
    {
        $error = new SchemaValidationError(
            'Validation failed',
            [],
            '{}',
            null,
            ['type' => 'object'],
            1,
            3
        );

        $this->assertFalse($error->isRetryExhausted());
    }

    #[Test]
    public function is_retry_exhausted_returns_false_when_max_retries_is_zero()
    {
        $error = new SchemaValidationError(
            'Validation failed',
            [],
            '{}',
            null,
            ['type' => 'object'],
            0,
            0
        );

        $this->assertFalse($error->isRetryExhausted());
    }

    #[Test]
    public function is_retry_exhausted_returns_false_on_initial_attempt()
    {
        $error = new SchemaValidationError(
            'Validation failed',
            [],
            '{}',
            null,
            ['type' => 'object'],
            0,
            2
        );

        $this->assertFalse($error->isRetryExhausted());
    }

    /* ------------------------------------------------------------------ */
    /* Data providers                                                     */
    /* ------------------------------------------------------------------ */

    public static function schemaProviderTypes(): array
    {
        return [
            'array schema' => [['type' => 'object', 'required' => ['name']]],
            'string schema' => ['{"type": "object", "required": ["name"]}'],
        ];
    }
}
