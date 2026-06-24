<?php

namespace ClarionApp\LlmClient\Services;

interface CallValidatorInterface
{
    /**
     * Validate an API call.
     *
     * @param string $operationId Operation identifier from OpenAPI docs.
     * @param string $method      HTTP method (GET, POST, etc.).
     * @param string $path        Resolved URL path.
     * @return array{status: string, reason?: string}
     */
    public function validate(string $operationId, string $method, string $path): array;
}
