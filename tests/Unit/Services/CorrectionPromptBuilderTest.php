<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Exceptions\SchemaValidationError;
use ClarionApp\LlmClient\Services\CorrectionPromptBuilder;

use PHPUnit\Framework\Attributes\Test;

class CorrectionPromptBuilderTest extends TestCase
{
    private CorrectionPromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new CorrectionPromptBuilder();
    }

    /* ------------------------------------------------------------------ */
    /* build() with violation details                                     */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function builds_correction_prompt_with_violation_details()
    {
        $error = new SchemaValidationError(
            message: 'Schema validation failed with 2 violation(s)',
            violations: [
                ['property' => '.name', 'message' => 'The property name is required'],
                ['property' => '.age', 'message' => 'Must be of type integer'],
            ],
            rawContent: '{"age": "not_a_number"}',
            strippedContent: null,
            schema: ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'age' => ['type' => 'integer']]],
        );

        $prompt = $this->builder->build($error);

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
        $this->assertStringContainsString('name', $prompt);
        $this->assertStringContainsString('age', $prompt);
        $this->assertStringContainsString('required', $prompt);
    }

    #[Test]
    public function builds_correction_prompt_with_single_violation()
    {
        $error = new SchemaValidationError(
            message: 'Schema validation failed',
            violations: [
                ['property' => '.email', 'message' => 'The property email is required'],
            ],
            rawContent: '{"name": "Test"}',
            strippedContent: null,
            schema: ['type' => 'object', 'required' => ['email']],
        );

        $prompt = $this->builder->build($error);

        $this->assertStringContainsString('email', $prompt);
        $this->assertTrue(
            str_contains($prompt, '1 violation') || str_contains($prompt, '1 error') || str_contains($prompt, 'violation')
        );
    }

    #[Test]
    public function builds_correction_prompt_with_nested_property_violations()
    {
        $error = new SchemaValidationError(
            message: 'Schema validation failed',
            violations: [
                ['property' => '.address.city', 'message' => 'The property city is required'],
                ['property' => '.address.zip', 'message' => 'Must match pattern ^[0-9]{5}$'],
            ],
            rawContent: '{"address": {}}',
            strippedContent: null,
            schema: [],
        );

        $prompt = $this->builder->build($error);

        $this->assertStringContainsString('address.city', $prompt);
        $this->assertStringContainsString('address.zip', $prompt);
    }

    /* ------------------------------------------------------------------ */
    /* Schema inclusion in prompt                                         */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function includes_schema_in_correction_prompt()
    {
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
        $error = new SchemaValidationError(
            message: 'Validation failed',
            violations: [['property' => '.name', 'message' => 'Required']],
            rawContent: '{}',
            strippedContent: null,
            schema: $schema,
        );

        $prompt = $this->builder->build($error);

        $this->assertStringContainsString('name', $prompt);
    }

    /* ------------------------------------------------------------------ */
    /* Custom instructions                                                */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function builds_prompt_with_custom_instructions()
    {
        $error = new SchemaValidationError(
            message: 'Validation failed',
            violations: [['property' => '.x', 'message' => 'Required']],
            rawContent: '{}',
            strippedContent: null,
            schema: [],
        );

        $prompt = $this->builder->build($error, 'Please ensure all required fields are present.');

        $this->assertStringContainsString('Please ensure all required fields are present.', $prompt);
    }

    /* ------------------------------------------------------------------ */
    /* Edge cases                                                         */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function handles_empty_violations_array()
    {
        $error = new SchemaValidationError(
            message: 'JSON parse error',
            violations: [],
            rawContent: 'not json at all',
            strippedContent: null,
            schema: ['type' => 'object'],
        );

        $prompt = $this->builder->build($error);

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
        $this->assertStringContainsString('JSON parse error', $prompt);
    }

    #[Test]
    public function formats_retry_attempt_info_when_provided()
    {
        $error = new SchemaValidationError(
            message: 'Validation failed',
            violations: [['property' => '.x', 'message' => 'Required']],
            rawContent: '{}',
            strippedContent: null,
            schema: [],
            retryAttempt: 1,
            maxRetries: 3,
        );

        $prompt = $this->builder->build($error);

        $this->assertTrue(
            str_contains($prompt, 'Attempt') || str_contains($prompt, 'attempt') || str_contains($prompt, 'retry')
        );
    }
}
