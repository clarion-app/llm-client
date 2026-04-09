<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\McpSession;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AgentLoopService
{
    private McpToolRegistry $toolRegistry;
    private McpToolExecutor $toolExecutor;

    public function __construct(McpToolRegistry $toolRegistry, McpToolExecutor $toolExecutor)
    {
        $this->toolRegistry = $toolRegistry;
        $this->toolExecutor = $toolExecutor;
    }

    public function start(Conversation $conversation, int $iteration = 1): void
    {
        $conversation->update(['is_processing' => true]);

        $tools = $this->buildToolsPayload();
        $messages = $this->buildMessagesPayload($conversation);

        $this->dispatchStreamRequest($conversation, $messages, $tools, $iteration);
    }

    public function resume(Conversation $conversation, Message $message, bool $approved): void
    {
        $toolData = $message->tool_data;
        $pending = $toolData['pending_confirmation'] ?? null;

        if (!$pending) {
            throw new \RuntimeException('No pending confirmation found on this message.');
        }

        // Check for expiration
        $expiresAt = Carbon::parse($pending['expires_at']);
        if ($expiresAt->isPast()) {
            $conversation->update(['is_processing' => false]);
            throw new \RuntimeException('Confirmation has expired.');
        }

        $toolCallId = $toolData['tool_calls'][0]['id'] ?? null;
        $iteration = ($toolData['iteration'] ?? 1) + 1;

        if ($approved) {
            // Execute the confirmed operation
            $resultContent = $this->executeApiCall(
                $pending['operationId'],
                $pending['method'],
                $pending['path'],
                $pending['arguments'] ?? [],
                $conversation
            );

            $toolData['tool_results'] = [
                ['tool_call_id' => $toolCallId, 'content' => $resultContent],
            ];
        } else {
            $toolData['tool_results'] = [
                ['tool_call_id' => $toolCallId, 'content' => 'User cancelled this operation.'],
            ];
        }

        $toolData['pending_confirmation'] = null;
        $message->update(['tool_data' => $toolData]);

        // Continue the agent loop
        $tools = $this->buildToolsPayload();
        $messages = $this->buildMessagesPayload($conversation);
        $this->dispatchStreamRequest($conversation, $messages, $tools, $iteration);
    }

    public function buildToolsPayload(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_applications',
                    'description' => 'List all available API applications/packages that can be interacted with. Call this first to discover what applications are available.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_operations',
                    'description' => 'List the available API operations for a specific application. Returns operation IDs, summaries, HTTP methods, and paths. Call this after list_applications to see what you can do with a specific app.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'application' => [
                                'type' => 'string',
                                'description' => 'The application package name as returned by list_applications (e.g. "clarion-app/contacts")',
                            ],
                        ],
                        'required' => ['application'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'execute_operation',
                    'description' => 'Execute an API operation. Pass the operationId from list_operations and any required parameters. Path parameters should be prefixed with "path_", query parameters with "query_", and request body parameters with "body_".',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'operationId' => [
                                'type' => 'string',
                                'description' => 'The operationId from list_operations',
                            ],
                            'parameters' => [
                                'type' => 'object',
                                'description' => 'Operation parameters. Use "path_" prefix for path params, "query_" for query params, "body_" for request body fields.',
                            ],
                        ],
                        'required' => ['operationId'],
                    ],
                ],
            ],
        ];
    }

    public function executeMetaTool(string $toolName, array $arguments, Conversation $conversation): string
    {
        return match ($toolName) {
            'list_applications' => $this->handleListApplications(),
            'list_operations' => $this->handleListOperations($arguments),
            'execute_operation' => $this->handleExecuteOperation($arguments, $conversation),
            default => json_encode(['error' => "Unknown tool: {$toolName}"]),
        };
    }

    private function handleListApplications(): string
    {
        $packages = ClarionPackageServiceProvider::getPackageDescriptions();
        $apps = [];
        foreach ($packages as $name => $meta) {
            $apps[] = [
                'name' => $name,
                'description' => $meta['description'] ?? $name,
            ];
        }
        return json_encode($apps);
    }

    private function handleListOperations(array $arguments): string
    {
        $appName = $arguments['application'] ?? '';
        if (empty($appName)) {
            return json_encode(['error' => 'application parameter is required']);
        }

        $operations = ClarionPackageServiceProvider::getPackageOperations($appName);

        // Enrich with method and path from API docs
        $enriched = [];
        foreach ($operations as $op) {
            $operationId = $op['operationId'] ?? null;
            if (!$operationId) continue;

            $details = ApiManager::getOperationDetails($operationId);
            if (empty((array) $details)) continue;

            $opDetails = $details['details'] ?? [];
            $enriched[] = [
                'operationId' => $operationId,
                'summary' => $op['summary'] ?? $operationId,
                'method' => strtoupper($details['method'] ?? 'GET'),
                'path' => $details['path'] ?? '',
                'parameters' => $this->summarizeParameters($opDetails),
            ];
        }

        return json_encode($enriched);
    }

    private function summarizeParameters(array $opDetails): array
    {
        $params = [];

        foreach ($opDetails['parameters'] ?? [] as $param) {
            $name = $param['name'] ?? null;
            if (!$name) continue;
            $in = $param['in'] ?? 'query';
            $prefix = $in === 'path' ? 'path_' : 'query_';
            $params[] = [
                'name' => $prefix . $name,
                'type' => $param['schema']['type'] ?? 'string',
                'required' => !empty($param['required']),
                'description' => $param['description'] ?? '',
            ];
        }

        $requestBody = $opDetails['requestBody'] ?? null;
        if ($requestBody) {
            $content = $requestBody['content'] ?? [];
            $jsonSchema = $content['application/json']['schema'] ?? null;
            if ($jsonSchema && isset($jsonSchema['properties'])) {
                $bodyRequired = $jsonSchema['required'] ?? [];
                foreach ($jsonSchema['properties'] as $propName => $propSchema) {
                    $params[] = [
                        'name' => 'body_' . $propName,
                        'type' => $propSchema['type'] ?? 'string',
                        'required' => in_array($propName, $bodyRequired),
                        'description' => $propSchema['description'] ?? '',
                    ];
                }
            }
        }

        return $params;
    }

    private function handleExecuteOperation(array $arguments, Conversation $conversation): string
    {
        $operationId = $arguments['operationId'] ?? '';
        if (empty($operationId)) {
            return json_encode(['error' => 'operationId is required']);
        }

        $params = $arguments['parameters'] ?? [];

        $details = ApiManager::getOperationDetails($operationId);
        if (empty((array) $details)) {
            return json_encode(['error' => "Unknown operation: {$operationId}"]);
        }

        $method = strtoupper($details['method'] ?? 'GET');
        $pathTemplate = $details['path'] ?? '';

        // Check confirmation/rejection
        $validation = ApiCallValidator::validate($operationId, $method, $pathTemplate);

        if ($validation['status'] === ApiCallValidator::STATUS_REJECT) {
            return json_encode(['error' => $validation['reason'] ?? 'Operation rejected']);
        }

        if ($validation['status'] === ApiCallValidator::STATUS_CONFIRM) {
            // Return a special marker — the stream handler will detect this and suspend
            return json_encode([
                '__requires_confirmation' => true,
                'operationId' => $operationId,
                'method' => $method,
                'path' => $pathTemplate,
                'parameters' => $params,
            ]);
        }

        // Execute directly
        return $this->executeApiCall($operationId, $method, $pathTemplate, $params, $conversation);
    }

    public function executeApiCall(string $operationId, string $method, string $pathTemplate, array $params, Conversation $conversation): string
    {
        $session = $this->getOrCreateSession($conversation);
        $resolved = $this->toolExecutor->unflattenArguments($params, $pathTemplate);
        $result = $this->toolExecutor->executeHttpCall($method, $resolved['path'], $resolved['query'], $resolved['body'], $session);

        return $this->extractResultContent($result);
    }

    public function buildMessagesPayload(Conversation $conversation): array
    {
        $dbMessages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get();

        $payload = [];

        foreach ($dbMessages as $msg) {
            if ($msg->tool_data && !empty($msg->tool_data['tool_calls'])) {
                // Assistant message with tool calls
                $assistantMsg = [
                    'role' => 'assistant',
                    'content' => $msg->content ?: null,
                    'tool_calls' => $msg->tool_data['tool_calls'],
                ];
                $payload[] = $assistantMsg;

                // Tool result messages
                if (!empty($msg->tool_data['tool_results'])) {
                    foreach ($msg->tool_data['tool_results'] as $result) {
                        $payload[] = [
                            'role' => 'tool',
                            'tool_call_id' => $result['tool_call_id'],
                            'content' => $result['content'],
                        ];
                    }
                }
            } else {
                // Regular message (user, assistant text, system)
                $payload[] = [
                    'role' => strtolower($msg->role),
                    'content' => $msg->content,
                ];
            }
        }

        return $payload;
    }

    private function dispatchStreamRequest(Conversation $conversation, array $messages, array $tools, int $iteration): void
    {
        $server = Server::find($conversation->server_id);

        $body = new \stdClass();
        $body->temperature = 1.0;
        $body->model = $conversation->model;
        $body->stream = true;
        $body->messages = $messages;

        if (!empty($tools)) {
            $body->tools = $tools;
        }

        $request = new HttpRequest();
        $request->url = $server->server_url;
        $request->method = "POST";
        $request->headers = [
            'Content-type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $server->token,
        ];
        $request->body = $body;

        Log::info('AgentLoopService: sending request to LLM', [
            'url' => $request->url,
            'model' => $body->model,
            'iteration' => $iteration,
            'tools_count' => count($tools),
            'messages_count' => count($messages),
            'body' => json_encode($body, JSON_PRETTY_PRINT),
        ]);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => $iteration,
        ]);

        SendHttpStreamRequest::dispatch(
            $request,
            "ClarionApp\\LlmClient\\AgentLoopStreamHandler",
            $data
        );
    }

    private function getOrCreateSession(Conversation $conversation): McpSession
    {
        $session = McpSession::where('user_id', $conversation->user_id)->first();
        if (!$session) {
            $session = McpSession::create([
                'user_id' => $conversation->user_id,
                'protocol_version' => '2025-03-26',
            ]);
        }
        return $session;
    }

    private function extractResultContent(array $result): string
    {
        if (!empty($result['content'])) {
            return $result['content'][0]['text'] ?? json_encode($result['content']);
        }
        return json_encode($result);
    }
}
