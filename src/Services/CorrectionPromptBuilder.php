<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\SchemaValidationError;

class CorrectionPromptBuilder
{
    /**
     * Build a correction prompt from a SchemaValidationError.
     *
     * The prompt instructs the LLM to fix its previous response based on
     * the validation violations, providing the expected schema as reference.
     *
     * @param SchemaValidationError $error The validation error.
     * @param string|null $customInstructions Optional custom instructions to prepend.
     * @return string The correction prompt.
     */
    public function build(SchemaValidationError $error, ?string $customInstructions = null): string
    {
        $parts = [];

        // Custom instructions if provided
        if ($customInstructions !== null && $customInstructions !== '') {
            $parts[] = $customInstructions;
        }

        // Header with retry context
        $retryAttempt = $error->getRetryAttempt();
        $maxRetries = $error->getMaxRetries();

        if ($maxRetries > 0) {
            $parts[] = sprintf(
                'Your previous response failed schema validation (Attempt %d of %d).',
                $retryAttempt + 1,
                $maxRetries
            );
        } else {
            $parts[] = 'Your previous response failed schema validation.';
        }

        // Error summary
        $parts[] = $error->getMessage();

        // Violation details
        $violations = $error->getViolations();
        if (!empty($violations)) {
            $parts[] = sprintf(
                'The response had %d violation(s):',
                count($violations)
            );

            foreach ($violations as $i => $v) {
                $property = $v['property'] ?? 'unknown';
                $message = $v['message'] ?? '';
                $parts[] = sprintf('  %d. Property "%s": %s', $i + 1, $property, $message);
            }
        }

        // Expected schema reference
        $schema = $error->getSchema();
        if (!empty($schema)) {
            $parts[] = '';
            $parts[] = 'Expected response schema:';
            $parts[] = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        // Call to action
        $parts[] = '';
        $parts[] = 'Please respond again with valid JSON that matches the schema.';

        return implode("\n", $parts);
    }
}
