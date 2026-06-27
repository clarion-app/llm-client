<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\SchemaValidationError;
use JsonSchema\Validator;

class SchemaValidator
{
    private Validator $validator;

    public function __construct(?Validator $validator = null)
    {
        $this->validator = $validator ?? new Validator();
    }

    /**
     * Validate LLM response content against a JSON schema.
     *
     * @param string $content Raw LLM response content.
     * @param array|string $schema JSON Schema definition (PHP array or JSON string).
     * @return array Decoded and validated PHP array.
     * @throws SchemaValidationError If validation fails.
     */
    public function validate(string $content, $schema): array
    {
        // Normalize schema (accept PHP array or JSON string)
        $schema = $this->normalizeSchema($schema);

        // Strip markdown code fences if present
        $strippedContent = $this->stripFences($content);

        $jsonToParse = $strippedContent ?? $content;

        // Decode JSON as object for validation (JSON Schema expects objects, not arrays)
        $decodedObject = json_decode($jsonToParse);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new SchemaValidationError(
                message: 'Failed to parse JSON response: ' . json_last_error_msg(),
                violations: [],
                rawContent: $content,
                strippedContent: $strippedContent,
                schema: $schema,
            );
        }

        // Validate decoded data against schema
        $this->validator->reset();
        $schemaRef = $schema;
        try {
            $this->validator->validate($decodedObject, $schemaRef);
        } catch (\JsonSchema\Exception\InvalidArgumentException $e) {
            // Malformed schema (e.g., invalid type value)
            throw new SchemaValidationError(
                message: 'Malformed schema: ' . $e->getMessage(),
                violations: [['property' => '$', 'message' => $e->getMessage()]],
                rawContent: $content,
                strippedContent: $strippedContent,
                schema: $schema,
            );
        } catch (\Exception $e) {
            throw new SchemaValidationError(
                message: 'Schema validation error: ' . $e->getMessage(),
                violations: [['property' => '$', 'message' => $e->getMessage()]],
                rawContent: $content,
                strippedContent: $strippedContent,
                schema: $schema,
            );
        }

        if (!$this->validator->isValid()) {
            $violations = [];
            foreach ($this->validator->getErrors() as $error) {
                $violations[] = [
                    'property' => $error['property'] ?? '',
                    'message'  => $error['message'] ?? '',
                ];
            }

            throw new SchemaValidationError(
                message: sprintf('Schema validation failed with %d violation(s)', count($violations)),
                violations: $violations,
                rawContent: $content,
                strippedContent: $strippedContent,
                schema: $schema,
            );
        }

        // Convert validated object back to associative array for return
        return $this->objectToArray($decodedObject);
    }

    /**
     * Check if content needs validation (schema provided and non-empty).
     */
    public function shouldValidate(array $options): bool
    {
        $schema = $options['schema'] ?? null;

        if ($schema === null) {
            return false;
        }

        // If schema is a string, check it's not empty
        if (is_string($schema)) {
            return trim($schema) !== '';
        }

        // If schema is an array, check it's not empty
        if (is_array($schema)) {
            return !empty($schema);
        }

        return false;
    }

    /**
     * Normalize schema from JSON string to PHP array.
     */
    private function normalizeSchema($schema): array
    {
        if (is_string($schema)) {
            $decoded = json_decode($schema, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new SchemaValidationError(
                    message: 'Schema is not valid JSON: ' . json_last_error_msg(),
                    violations: [],
                    rawContent: $schema,
                    strippedContent: null,
                    schema: $schema,
                );
            }
            return (array) $decoded;
        }

        return (array) $schema;
    }

    /**
     * Strip markdown code fences from content.
     * Returns the stripped content or null if no fences found.
     */
    private function stripFences(string $content): ?string
    {
        // Match ```json ... ``` or ``` ... ``` with optional whitespace
        if (preg_match('/^\s*```(?:json)?\s*\n([\s\S]*?)\n```\s*$/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Convert an object (stdClass) recursively to an associative array.
     */
    private function objectToArray($data): array
    {
        if (is_object($data)) {
            $data = get_object_vars($data);
        }

        if (!is_array($data)) {
            return (array) $data;
        }

        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = is_array($value) || is_object($value)
                ? $this->objectToArray($value)
                : $value;
        }

        return $result;
    }
}
