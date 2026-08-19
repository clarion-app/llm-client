<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Models\McpConfirmationToken;
use ClarionApp\LlmClient\Models\McpSession;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;
use ClarionApp\Backend\Models\User;

class McpToolExecutor
{
    private McpToolRegistry $toolRegistry;
    private CallValidatorInterface $validator;
    private \Closure $tokenFactory;

    public function __construct(
        McpToolRegistry $toolRegistry,
        ?CallValidatorInterface $validator = null,
        ?\Closure $tokenFactory = null
    ) {
        $this->toolRegistry = $toolRegistry;
        $this->validator = $validator ?? new ApiCallValidatorAdapter();
        $this->tokenFactory = $tokenFactory ?? function (User $user) {
            return $user->createToken('McpToolCall')->accessToken;
        };
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
                $pathTemplate,
                $tool
            );
        }

        // Extract arguments
        $resolved = $this->extractArguments($cleanArguments, $pathTemplate);

        // Validate via CallValidator
        $validation = $this->validator->validate($operationId, $method, $resolved['path']);

        switch ($validation['status']) {
            case 'reject':
                return $this->errorResult($validation['reason'] ?? 'Request rejected');

            case 'confirm':
                return $this->createConfirmationToken($name, $cleanArguments, $session);

            case 'allow':
                return Context::get('eval_run_simulating_tools', false)
                    ? $this->simulateCall($tool)
                    : $this->executeHttpCall($method, $resolved['path'], $resolved['query'], $resolved['body'], $session);

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
        string $pathTemplate,
        array $tool
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

        $resolved = $this->extractArguments($arguments, $pathTemplate);

        return Context::get('eval_run_simulating_tools', false)
            ? $this->simulateCall($tool)
            : $this->executeHttpCall($method, $resolved['path'], $resolved['query'], $resolved['body'], $session);
    }

    /**
     * Returns the same MCP content-envelope shape executeHttpCall()/
     * errorResult() return — indistinguishable in shape to every
     * downstream consumer — built from a schema-shaped synthetic result
     * (research.md D3/D4) instead of a real HTTP call.
     */
    public function simulateCall(array $tool): array
    {
        $synthetic = app(ToolResponseSimulator::class)->simulate($tool['inputSchema'] ?? []);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'mimeType' => 'application/json',
                    'text' => json_encode($synthetic),
                ],
            ],
            'isError' => false,
        ];
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

    /**
     * $extraHeaders (122-workspace-browser-ui, US3, research.md D5) is
     * additive only -- merged into the outgoing request's headers, on top
     * of Authorization/Accept, which always win if a caller somehow
     * collides with either name. Every existing call site's outgoing
     * request shape is completely unchanged, since it defaults to empty.
     */
    public function executeHttpCall(string $method, string $path, array $query, array $body, McpSession $session, array $extraHeaders = []): array
    {
        $user = User::find($session->user_id);
        if (!$user) {
            return $this->errorResult('Session user not found');
        }

        $accessToken = ($this->tokenFactory)($user);

        $baseUrl = rtrim(env('APP_URL'), '/');
        $url = $baseUrl . '/api' . $path;

        \Log::info("Executing tool call: {$method} {$url}", ['query' => $query, 'body' => $body]);

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $httpClient = Http::withHeaders(array_merge($extraHeaders, [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ]))->withoutVerifying();

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
                // The HTTP status code rides along on this array's own
                // 'status' key -- additive, ignored by every consumer that
                // only reads 'content'/'isError', consulted by
                // AgentLoopService's own retry loop (via
                // RetryEligibility::isTransient()) to tell a plausibly-
                // transient 5xx/429 apart from an ordinary 4xx.
                $failure = $this->errorResult($response->body());
                $failure['status'] = $response->status();

                return $failure;
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
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            return $this->errorResult('Internal error: ' . $e->getMessage());
        }
    }

    public function extractArguments(array $arguments, string $pathTemplate): array
    {
        $path = $pathTemplate;
        $query = [];
        $body = [];

        // Handle structured format: {path: {...}, query: {...}, body: {...}}
        $pathParams = $arguments['path'] ?? [];
        $queryParams = $arguments['query'] ?? [];
        $bodyParams = $arguments['body'] ?? [];

        // Path parameters - substitute into path template
        foreach ($pathParams as $key => $value) {
            if ($value === null) continue;
            $path = str_replace('{' . $key . '}', (string) $value, $path);
        }

        // Query parameters
        foreach ($queryParams as $key => $value) {
            if ($value === null) continue;
            $query[$key] = $value;
        }

        // Body parameters
        foreach ($bodyParams as $key => $value) {
            if ($value === null) continue;
            $body[$key] = $value;
        }

        return [
            'path' => $path,
            'query' => $query,
            'body' => $body,
        ];
    }

    private function hashArguments(array $arguments): string
    {
        return hash('sha256', json_encode($arguments, JSON_THROW_ON_ERROR));
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
