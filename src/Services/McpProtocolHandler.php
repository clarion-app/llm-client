<?php

namespace ClarionApp\LlmClient\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class McpProtocolHandler
{
    private McpSessionManager $sessionManager;

    public function __construct(McpSessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
    }

    public function dispatch(Request $request, string $userId): array
    {
        $body = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->errorResponse(null, -32700, 'Parse error: Invalid JSON');
        }

        if (!isset($body['jsonrpc']) || $body['jsonrpc'] !== '2.0') {
            return $this->errorResponse($body['id'] ?? null, -32600, 'Invalid Request: Missing or invalid jsonrpc field');
        }

        if (!isset($body['method']) || !is_string($body['method'])) {
            return $this->errorResponse($body['id'] ?? null, -32600, 'Invalid Request: Missing method field');
        }

        $method = $body['method'];
        $params = $body['params'] ?? [];
        $id = $body['id'] ?? null;
        $sessionId = $request->header('Mcp-Session-Id');

        // initialize does not require a session
        if ($method === 'initialize') {
            return $this->handleInitialize($params, $id, $userId);
        }

        // notifications/initialized requires a session but is a notification
        if ($method === 'notifications/initialized') {
            return $this->handleNotificationsInitialized($sessionId, $userId);
        }

        // All other methods require a valid session
        $session = $this->enforceSession($sessionId, $userId);
        if ($session === null) {
            return $this->errorResponse($id, -32600, 'Invalid Request: Valid Mcp-Session-Id header required');
        }

        $this->sessionManager->touchSession($session->id);

        switch ($method) {
            case 'ping':
                return $this->handlePing($id);
            case 'tools/list':
                return $this->handleToolsList($params, $id, $session);
            case 'tools/call':
                return $this->handleToolsCall($params, $id, $session);
            default:
                return $this->errorResponse($id, -32601, "Method not found: {$method}");
        }
    }

    private function handleInitialize(array $params, ?int $id, string $userId): array
    {
        $protocolVersion = $params['protocolVersion'] ?? null;
        $supportedVersions = config('llm-client.mcp.supported_versions', ['2025-03-26']);

        if (!$protocolVersion || !in_array($protocolVersion, $supportedVersions, true)) {
            return $this->errorResponse($id, -32602, 'Invalid params: Unsupported protocol version');
        }

        $clientInfo = $params['clientInfo'] ?? [];
        $capabilities = $params['capabilities'] ?? null;

        $session = $this->sessionManager->createSession(
            $userId,
            $protocolVersion,
            $clientInfo['name'] ?? null,
            $clientInfo['version'] ?? null,
            $capabilities
        );

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => $protocolVersion,
                'capabilities' => [
                    'tools' => [
                        'listChanged' => false,
                    ],
                ],
                'serverInfo' => [
                    'name' => 'clarion-mcp-server',
                    'version' => '1.0.0',
                ],
            ],
            '_sessionId' => $session->id,
        ];
    }

    private function handleNotificationsInitialized(?string $sessionId, string $userId): array
    {
        if ($sessionId) {
            $this->sessionManager->touchSession($sessionId);
        }

        return ['_noContent' => true];
    }

    private function handlePing(?int $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => new \stdClass(),
        ];
    }

    public function handleToolsList(array $params, ?int $id, $session): array
    {
        $toolRegistry = app(McpToolRegistry::class);
        $cursor = $params['cursor'] ?? null;
        $package = $params['_package'] ?? null;

        $result = $toolRegistry->getTools($cursor, $package);

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    public function handleToolsCall(array $params, ?int $id, $session): array
    {
        $toolExecutor = app(McpToolExecutor::class);
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (!$name) {
            return $this->errorResponse($id, -32602, 'Invalid params: Missing tool name');
        }

        $result = $toolExecutor->executeTool($name, $arguments, $session);

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    private function enforceSession(?string $sessionId, string $userId)
    {
        if (!$sessionId) {
            return null;
        }

        return $this->sessionManager->validateSession($sessionId, $userId);
    }

    private function errorResponse(?int $id, int $code, string $message, $data = null): array
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }
}
