<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\ApiManager;
use Illuminate\Support\Facades\Log;

class ApiCallValidator
{
    const STATUS_REJECT = 'reject';
    const STATUS_ALLOW = 'allow';
    const STATUS_CONFIRM = 'confirm';

    /**
     * Validate an LLM-generated API call.
     *
     * @param string $operationId
     * @param string $method
     * @param string $path
     * @return array{status: string, reason?: string}
     */
    public static function validate(string $operationId, string $method, string $path): array
    {
        // Reject path traversal attempts
        $decodedPath = urldecode($path);
        if (str_contains($decodedPath, '../') || str_contains($decodedPath, '..\\')) {
            return [
                'status' => self::STATUS_REJECT,
                'reason' => 'Path traversal detected',
            ];
        }

        // Validate operationId exists in OpenAPI docs
        $operationDetails = ApiManager::getOperationDetails($operationId);
        if (empty((array)$operationDetails)) {
            return [
                'status' => self::STATUS_REJECT,
                'reason' => "Unknown operationId: {$operationId}",
            ];
        }

        // Check resolved path against denylist
        $denylist = config('llm-client.api_denylist', []);
        $normalizedPath = '/' . ltrim($path, '/');
        foreach ($denylist as $pattern) {
            if (fnmatch($pattern, $normalizedPath)) {
                return [
                    'status' => self::STATUS_REJECT,
                    'reason' => "Path is denylisted: {$normalizedPath}",
                ];
            }
        }

        // Check if HTTP method requires confirmation
        $confirmMethods = config('llm-client.confirm_methods', ['PUT', 'PATCH', 'DELETE']);
        if (in_array(strtoupper($method), $confirmMethods, true)) {
            return [
                'status' => self::STATUS_CONFIRM,
                'reason' => strtoupper($method) . ' requests require user confirmation',
            ];
        }

        return ['status' => self::STATUS_ALLOW];
    }
}
