<?php

namespace ClarionApp\LlmClient\Services;

/**
 * Adapter that implements {@see CallValidatorInterface} by delegating
 * to the static {@see ApiCallValidator::validate()} method.
 *
 * This allows {@see McpToolExecutor} to accept a mockable interface while
 * keeping the existing static API intact for other callers.
 */
class ApiCallValidatorAdapter implements CallValidatorInterface
{
    public function validate(string $operationId, string $method, string $path): array
    {
        return ApiCallValidator::validate($operationId, $method, $path);
    }
}
