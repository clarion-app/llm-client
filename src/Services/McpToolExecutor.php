<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Models\McpConfirmationToken;
use ClarionApp\LlmClient\Models\McpSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;
use ClarionApp\Backend\Models\User;

class McpToolExecutor
{
    private McpToolRegistry $toolRegistry;

    public function __construct(McpToolRegistry $toolRegistry)
    {
        $this->toolRegistry = $toolRegistry;
    }

    public function executeTool(string $name, array $arguments, McpSession $session): array
    {
        $tool = $this->toolRegistry->findTool($name);
        if (!$tool) {
            return $this->errorResult("Unknown tool: {$name}");
        }

        $meta = $tool['_meta'] ?? [];
        $operationId = $meta['operationId'] ?? null;
        $method = strtoupper($meta['method'] ?? 'GET');
        $pathTemplate = $meta['path'] ?? '';

        // Extract confirmation token before building arguments
        $confirmationTokenId = $arguments['_confirmation_token'] ?? null;
        $cleanArguments = $arguments;
        unset($cleanArguments['_confirmation_token']);

        // If confirmation token provided, validate and execute
        if ($confirmationTokenId) {
            return $this->handleConfirmedCall(
                $confirmationTokenId,
                $name,
                $cleanArguments,
                $session,
                $operationId,
                $method,
                $pathTemplate
            );
        }

        // Unflatten arguments
        $resolved = $this->unflattenArguments($cleanArguments, $pathTemplate);

        // Validate via ApiCallValidator
        $validation = ApiCallValidator::validate($operationId, $method, $resolved['path']);

        switch ($validation['status']) {
            case ApiCallValidator::STATUS_REJECT:
                return $this->errorResult($validation['reason'] ?? 'Request rejected');

            case ApiCallValidator::STATUS_CONFIRM:
                return $this->createConfirmationToken($name, $cleanArguments, $session);

            case ApiCallValidator::STATUS_ALLOW:
                return $this->executeHttpCall($method, $resolved['path'], $resolved['query'], $resolved['body'], $session);

            default:
                return $this->errorResult('Unexpected validation status');
        }
    }

    private function handleConfirmedCall(
        string $tokenId,
        string $toolName,
        array $arguments,
        McpSession $session,
        string $operationId,
        string $method,
        string $pathTemplate
    ): array {
        $token = McpConfirmationToken::find($tokenId);
        if (!$token) {
            return $this->errorResult('Invalid confirmation token');
        }

        $argumentsHash = $this->hashArguments($arguments);

        if (!$token->isValid($session->id, $toolName, $argumentsHash)) {
            if ($token->used_at !== null) {
                return $this->errorResult('Confirmation token already used');
            }
            if ($token->expires_at->isPast()) {
                return $this->errorResult('Confirmation token expired');
            }
            return $this->errorResult('Invalid confirmation token');
        }

        $token->consume();

        $resolved = $this->unflattenArguments($arguments, $pathTemplate);
        return $this->executeHttpCall($method, $resolved['path'], $resolved['query'], $resolved['body'], $session);
    }

    private function createConfirmationToken(string $toolName, array $arguments, McpSession $session): array
    {
        $expirySeconds = config('llm-client.mcp.confirmation_token_expiry', 300);
        $argumentsHash = $this->hashArguments($arguments);

        $token = McpConfirmationToken::create([
            'session_id' => $session->id,
            'tool_name' => $toolName,
            'arguments_hash' => $argumentsHash,
            'arguments_snapshot' => $arguments,
            'expires_at' => Carbon::now()->addSeconds($expirySeconds),
        ]);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'mimeType' => 'application/json',
                    'text' => json_encode([
                        'confirmation_required' => true,
                        'confirmation_token' => $token->id,
                        'tool_name' => $toolName,
                        'message' => 'This is a destructive operation. Resubmit with confirmation_token to proceed.',
                        'expires_in_seconds' => $expirySeconds,
                    ]),
                ],
            ],
            'isError' => false,
        ];
    }

    public function executeHttpCall(string $method, string $path, array $query, array $body, McpSession $session): array
    {
        $user = User::find($session->user_id);
        if (!$user) {
            return $this->errorResult('Session user not found');
        }

        $accessToken = $user->createToken('McpToolCall')->accessToken;

        $baseUrl = rtrim(env('APP_URL'), '/');
        $url = $baseUrl . '/api' . $path;

        \Log::info("Executing tool call: {$method} {$url}", ['query' => $query, 'body' => $body]);

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $httpClient = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ])->withoutVerifying();

        try {
            $response = match ($method) {
                'GET' => $httpClient->get($url),
                'POST' => $httpClient->post($url, $body),
                'PUT' => $httpClient->put($url, $body),
                'PATCH' => $httpClient->patch($url, $body),
                'DELETE' => $httpClient->delete($url),
                default => $httpClient->get($url),
            };

            if ($response->failed()) {
                return $this->errorResult($response->body());
            }

            $responseBody = $response->body();
            if (empty($responseBody)) {
                $responseBody = json_encode(['status' => 'success']);
            }

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'mimeType' => 'application/json',
                        'text' => $responseBody,
                    ],
                ],
                'isError' => false,
            ];
        } catch (\Exception $e) {
            return $this->errorResult('Internal error: ' . $e->getMessage());
        }
    }

    public function unflattenArguments(array $arguments, string $pathTemplate): array
    {
        $path = $pathTemplate;
        $query = [];
        $body = [];

        foreach ($arguments as $key => $value) {
            if (str_starts_with($key, 'path_')) {
                $paramName = substr($key, 5);
                $path = str_replace('{' . $paramName . '}', (string) $value, $path);
            } elseif (str_starts_with($key, 'query_')) {
                $paramName = substr($key, 6);
                $query[$paramName] = $value;
            } elseif (str_starts_with($key, 'body_')) {
                $paramName = substr($key, 5);
                $body[$paramName] = $value;
            } else {
                // No prefix — treat as body param
                $body[$key] = $value;
            }
        }

        return [
            'path' => $path,
            'query' => $query,
            'body' => $body,
        ];
    }

    private function hashArguments(array $arguments): string
    {
        return hash('sha256', json_encode($arguments, JSON_SORT_KEYS));
    }

    private function errorResult(string $message): array
    {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => "Error: {$message}",
                ],
            ],
            'isError' => true,
        ];
    }
}
