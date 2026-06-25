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
use GuzzleHttp\Client;

class AgentLoopService
{
    private McpToolRegistry $toolRegistry;
    private McpToolExecutor $toolExecutor;
    private OperationCache $operationCache;

    public function __construct(McpToolRegistry $toolRegistry, McpToolExecutor $toolExecutor, OperationCache $operationCache)
    {
        $this->toolRegistry = $toolRegistry;
        $this->toolExecutor = $toolExecutor;
        $this->operationCache = $operationCache;
    }

    public function start(Conversation $conversation, int $iteration = 1): void
    {
        $conversation->update(['is_processing' => true]);

        $tools = $this->buildToolsPayload($conversation);
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
        $tools = $this->buildToolsPayload($conversation);
        $messages = $this->buildMessagesPayload($conversation);
        $this->dispatchStreamRequest($conversation, $messages, $tools, $iteration);
    }

    /**
     * Synchronous agent loop execution for external channel integrations.
     * Returns the final response array or a confirmation-required structure.
     */
    public function run(Conversation $conversation, string $message): array
    {
        $conversation->update(['is_processing' => true]);

        // Create the user message
        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $message,
            'role' => 'user',
            'user' => 'User',
            'responseTime' => 0,
        ]);

        $maxIterations = config('llm-client.agent_loop.max_iterations', 20);
        $tools = $this->buildToolsPayload($conversation);

        try {
            for ($iteration = 1; $iteration <= $maxIterations; $iteration++) {
                $messages = $this->buildMessagesPayload($conversation);
                $response = $this->callLlmSync($conversation, $messages, $tools);

                $choice = $response['choices'][0] ?? null;
                if (!$choice) {
                    $conversation->update(['is_processing' => false]);
                    return ['status' => 'error', 'content' => 'No response from LLM', 'message_id' => null];
                }

                $responseMessage = $choice['message'] ?? [];
                $content = $responseMessage['content'] ?? '';
                $toolCalls = $responseMessage['tool_calls'] ?? [];

                // No tool calls — plain text response
                if (empty($toolCalls)) {
                    $assistantMessage = Message::create([
                        'conversation_id' => $conversation->id,
                        'content' => $content,
                        'role' => 'assistant',
                        'user' => $conversation->character,
                        'responseTime' => 0,
                    ]);

                    $conversation->update(['is_processing' => false]);

                    // Generate title on first exchange
                    if ($conversation->title === null) {
                        $titleRequest = new \ClarionApp\LlmClient\OpenAIGenerateConversationTitleRequest($conversation);
                        $titleRequest->sendGenerateConversationTitle();
                    }

                    return [
                        'status' => 'completed',
                        'content' => $content,
                        'message_id' => $assistantMessage->id,
                    ];
                }

                // Handle tool calls
                $toolResults = [];
                $pendingConfirmation = null;

                foreach ($toolCalls as $toolCall) {
                    $toolName = $toolCall['function']['name'] ?? '';
                    $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
                    $toolCallId = $toolCall['id'] ?? '';

                    $result = $this->executeMetaTool($toolName, $arguments, $conversation);
                    $decoded = json_decode($result, true);

                    if (is_array($decoded) && !empty($decoded['__requires_confirmation'])) {
                        $pendingConfirmation = [
                            'tool_name' => 'execute_operation',
                            'operationId' => $decoded['operationId'],
                            'method' => $decoded['method'],
                            'path' => $decoded['path'],
                            'arguments' => $decoded['parameters'] ?? [],
                            'expires_at' => now()->addSeconds(config('llm-client.agent_loop.confirmation_timeout', 300))->toIso8601String(),
                        ];

                        // Store message with pending confirmation
                        $assistantMessage = Message::create([
                            'conversation_id' => $conversation->id,
                            'content' => $content ?: '',
                            'role' => 'assistant',
                            'user' => $conversation->character,
                            'responseTime' => 0,
                            'tool_data' => [
                                'tool_calls' => $toolCalls,
                                'tool_results' => null,
                                'iteration' => $iteration,
                                'pending_confirmation' => $pendingConfirmation,
                            ],
                        ]);

                        return [
                            'status' => 'confirmation_required',
                            'content' => $content ?: '',
                            'message_id' => $assistantMessage->id,
                            'confirmation' => [
                                'operationId' => $decoded['operationId'],
                                'method' => $decoded['method'],
                                'path' => $decoded['path'],
                                'arguments' => $decoded['parameters'] ?? [],
                                'expires_at' => $pendingConfirmation['expires_at'],
                            ],
                        ];
                    }

                    $toolResults[] = [
                        'tool_call_id' => $toolCallId,
                        'content' => $result,
                    ];
                }

                // Store the assistant message with tool data and continue loop
                Message::create([
                    'conversation_id' => $conversation->id,
                    'content' => $content ?: '',
                    'role' => 'assistant',
                    'user' => $conversation->character,
                    'responseTime' => 0,
                    'tool_data' => [
                        'tool_calls' => $toolCalls,
                        'tool_results' => $toolResults,
                        'iteration' => $iteration,
                        'pending_confirmation' => null,
                    ],
                ]);

                // If all tool calls were successful execute_operation calls,
                // stop the loop — no need for a summary response from the LLM.
                if ($this->allExecuteOperationsSucceeded($toolCalls, $toolResults)) {
                    $conversation->update(['is_processing' => false]);
                    return [
                        'status' => 'completed',
                        'content' => '',
                        'message_id' => null,
                    ];
                }
            }

            // Max iterations exceeded
            $conversation->update(['is_processing' => false]);
            return [
                'status' => 'error',
                'content' => 'Maximum iterations reached',
                'message_id' => null,
                'code' => 'max_iterations',
            ];
        } catch (\Throwable $e) {
            $conversation->update(['is_processing' => false]);
            throw $e;
        }
    }

    /**
     * Synchronous confirmation resolution for external channel integrations.
     */
    public function resumeSync(Conversation $conversation, Message $message, bool $approved): array
    {
        $toolData = $message->tool_data;
        $pending = $toolData['pending_confirmation'] ?? null;

        if (!$pending) {
            throw new \RuntimeException('No pending confirmation found on this message.');
        }

        $expiresAt = Carbon::parse($pending['expires_at']);
        if ($expiresAt->isPast()) {
            $conversation->update(['is_processing' => false]);
            throw new \RuntimeException('Confirmation has expired.');
        }

        $toolCallId = $toolData['tool_calls'][0]['id'] ?? null;

        if ($approved) {
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

        // Continue with synchronous loop
        $maxIterations = config('llm-client.agent_loop.max_iterations', 20);
        $tools = $this->buildToolsPayload($conversation);
        $iteration = ($toolData['iteration'] ?? 1) + 1;

        for (; $iteration <= $maxIterations; $iteration++) {
            $messages = $this->buildMessagesPayload($conversation);
            $response = $this->callLlmSync($conversation, $messages, $tools);

            $choice = $response['choices'][0] ?? null;
            if (!$choice) {
                $conversation->update(['is_processing' => false]);
                return ['status' => 'error', 'content' => 'No response from LLM', 'message_id' => null];
            }

            $responseMessage = $choice['message'] ?? [];
            $content = $responseMessage['content'] ?? '';
            $toolCalls = $responseMessage['tool_calls'] ?? [];

            if (empty($toolCalls)) {
                $assistantMessage = Message::create([
                    'conversation_id' => $conversation->id,
                    'content' => $content,
                    'role' => 'assistant',
                    'user' => $conversation->character,
                    'responseTime' => 0,
                ]);

                $conversation->update(['is_processing' => false]);
                return [
                    'status' => 'completed',
                    'content' => $content,
                    'message_id' => $assistantMessage->id,
                ];
            }

            // Handle tool calls in the continuation
            $toolResults = [];
            foreach ($toolCalls as $toolCall) {
                $toolName = $toolCall['function']['name'] ?? '';
                $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
                $result = $this->executeMetaTool($toolName, $arguments, $conversation);
                $decoded = json_decode($result, true);

                if (is_array($decoded) && !empty($decoded['__requires_confirmation'])) {
                    $pendingConfirmation = [
                        'tool_name' => 'execute_operation',
                        'operationId' => $decoded['operationId'],
                        'method' => $decoded['method'],
                        'path' => $decoded['path'],
                        'arguments' => $decoded['parameters'] ?? [],
                        'expires_at' => now()->addSeconds(config('llm-client.agent_loop.confirmation_timeout', 300))->toIso8601String(),
                    ];

                    $assistantMessage = Message::create([
                        'conversation_id' => $conversation->id,
                        'content' => $content ?: '',
                        'role' => 'assistant',
                        'user' => $conversation->character,
                        'responseTime' => 0,
                        'tool_data' => [
                            'tool_calls' => $toolCalls,
                            'tool_results' => null,
                            'iteration' => $iteration,
                            'pending_confirmation' => $pendingConfirmation,
                        ],
                    ]);

                    return [
                        'status' => 'confirmation_required',
                        'content' => $content ?: '',
                        'message_id' => $assistantMessage->id,
                        'confirmation' => [
                            'operationId' => $decoded['operationId'],
                            'method' => $decoded['method'],
                            'path' => $decoded['path'],
                            'arguments' => $decoded['parameters'] ?? [],
                            'expires_at' => $pendingConfirmation['expires_at'],
                        ],
                    ];
                }

                $toolResults[] = [
                    'tool_call_id' => $toolCall['id'] ?? '',
                    'content' => $result,
                ];
            }

            Message::create([
                'conversation_id' => $conversation->id,
                'content' => $content ?: '',
                'role' => 'assistant',
                'user' => $conversation->character,
                'responseTime' => 0,
                'tool_data' => [
                    'tool_calls' => $toolCalls,
                    'tool_results' => $toolResults,
                    'iteration' => $iteration,
                    'pending_confirmation' => null,
                ],
            ]);
        }

        $conversation->update(['is_processing' => false]);
        return ['status' => 'error', 'content' => 'Maximum iterations reached', 'message_id' => null];
    }

    /**
     * Make a synchronous (non-streaming) LLM API call.
     */
    private function callLlmSync(Conversation $conversation, array $messages, array $tools): array
    {
        $server = Server::find($conversation->server_id);
        if (!$server) {
            throw new \RuntimeException('No LLM server configured');
        }

        $body = [
            'temperature' => 1.0,
            'model' => $conversation->model,
            'stream' => false,
            'messages' => $messages,
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $client = new Client(['timeout' => 240]);

        $response = $client->post($server->server_url, [
            'headers' => [
                'Content-type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $server->token,
            ],
            'json' => $body,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    public function buildToolsPayload(?Conversation $conversation = null): array
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
                    'name' => 'execute_operation',
                    'description' => 'Execute an API operation. Pass the operationId from search_operations and a structured parameters object with optional "path", "query", and "body" sub-objects containing the respective parameters.',
                    'parameters' => $this->buildExecuteOperationSchema($conversation),
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_operations',
                    'description' => 'Search API operations by natural language intent. Returns ranked results with operation IDs, summaries, methods, paths, and parameter schemas.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Natural language description of what you want to do (e.g., "create a contact", "list tasks")',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build the execute_operation tool parameters schema.
     *
     * When a conversation is provided, resolves cached operation paramSchema
     * and injects operation-specific properties into the schema.
     * Falls back to a generic schema when no operations are cached.
     *
     * @param Conversation|null $conversation Optional conversation for cache lookup
     * @return array The parameters schema for execute_operation
     */
    private function buildExecuteOperationSchema(?Conversation $conversation = null): array
    {
        // Try to get paramSchema from cached operations (T008)
        $paramSchema = null;
        if ($conversation !== null) {
            $paramSchema = $this->resolveParamSchema($conversation);
        }

        // T010: Fallback to generic schema when no operations are cached
        if ($paramSchema === null || !is_array($paramSchema)) {
            return [
                'type' => 'object',
                'properties' => [
                    'operationId' => [
                        'type' => 'string',
                        'description' => 'The operationId from search_operations',
                    ],
                    'parameters' => [
                        'type' => 'object',
                        'description' => 'Operation parameters as a structured object with optional path, query, and body sub-objects.',
                        'properties' => [
                            'path' => [
                                'type' => 'object',
                                'description' => 'Path parameters for URL substitution (e.g., {"id": "123"} for /contacts/{id})',
                                'properties' => new \stdClass(),
                                'additionalProperties' => true,
                            ],
                            'query' => [
                                'type' => 'object',
                                'description' => 'Query string parameters (e.g., {"search": "john", "page": "1"})',
                                'properties' => new \stdClass(),
                                'additionalProperties' => true,
                            ],
                            'body' => [
                                'type' => 'object',
                                'description' => 'Request body fields for POST/PUT/PATCH operations',
                                'properties' => new \stdClass(),
                                'additionalProperties' => true,
                            ],
                        ],
                    ],
                ],
                'required' => ['operationId'],
            ];
        }

        return $this->buildDynamicParamSchema($paramSchema);
    }

    /**
     * Resolve paramSchema from the operation cache for a conversation.
     * Returns the most recent cached paramSchema or null if cache is empty.
     *
     * @param Conversation $conversation The conversation context
     * @return array|null The paramSchema array or null
     */
    private function resolveParamSchema(Conversation $conversation): ?array
    {
        $entries = $this->operationCache->getEntries($conversation->id, 20);
        if (empty($entries)) {
            return null;
        }

        // getEntries() returns entries ordered most-recently-used first,
        $firstEntry = $entries[0];
        return $firstEntry['paramSchema'] ?? null;
    }

    /**
     * Build a dynamic parameters schema from a paramSchema object.
     *
     * @param array $paramSchema The paramSchema with path/query/body groups
     * @return array The parameters schema for execute_operation
     */
    private function buildDynamicParamSchema(array $paramSchema): array
    {
        $pathSchema = $this->buildParamGroupSchema($paramSchema['path'] ?? null);
        $querySchema = $this->buildParamGroupSchema($paramSchema['query'] ?? null);
        $bodySchema = $this->buildParamGroupSchema($paramSchema['body'] ?? null);

        return [
            'type' => 'object',
            'properties' => [
                'operationId' => [
                    'type' => 'string',
                    'description' => 'The operationId from search_operations',
                ],
                'parameters' => [
                    'type' => 'object',
                    'description' => 'Operation-specific parameter schema',
                    'properties' => array_filter([
                        'path' => $pathSchema,
                        'query' => $querySchema,
                        'body' => $bodySchema,
                    ], fn($s) => $s !== null),
                ],
            ],
            'required' => ['operationId'],
        ];
    }

    /**
     * Build a schema for a parameter group (path, query, or body).
     *
     * @param array|null $params Map of paramName => paramSchema
     * @return array|null The group schema or null if no params
     */
    private function buildParamGroupSchema(?array $params): ?array
    {
        if (empty($params)) {
            return null;
        }

        $properties = [];
        $required = [];

        foreach ($params as $name => $schema) {
            // Build clean property schema (exclude internal fields like 'in', 'required')
            $propSchema = [];
            foreach ($schema as $key => $val) {
                if ($key !== 'in' && $key !== 'required') {
                    $propSchema[$key] = $val;
                }
            }
            $properties[$name] = $propSchema;

            if (!empty($schema['required'])) {
                $required[] = $name;
            }
        }

        $groupSchema = ['type' => 'object', 'properties' => $properties];
        if (!empty($required)) {
            $groupSchema['required'] = $required;
        }

        return $groupSchema;
    }

    public function executeMetaTool(string $toolName, array $arguments, Conversation $conversation): string
    {
        return match ($toolName) {
            'list_applications' => $this->handleListApplications(),
            'execute_operation' => $this->handleExecuteOperation($arguments, $conversation),
            'search_operations' => $this->handleSearchOperations($arguments),
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

    private function handleSearchOperations(array $arguments): string
    {
        $query = $arguments['query'] ?? '';
        if (empty($query)) {
            return json_encode(['error' => 'query parameter is required']);
        }

        // Silently truncate long queries to a safe length
        $query = mb_substr($query, 0, 500);

        // Graceful degradation: check table existence before search
        $searchService = app(OperationsSearchService::class);

        if (!$searchService->tableExists()) {
            return json_encode([
                'hint' => 'Search index is not available. Run reindex command first.',
                'results' => [],
            ]);
        }

        $results = $searchService->search($query);

        if (empty($results)) {
            // Check if the table exists but is empty vs no matches
            try {
                $count = \Illuminate\Support\Facades\DB::table('operation_search_index')->count();
                if ($count === 0) {
                    return json_encode([
                        'hint' => "Search index is empty. Run 'php artisan llm-client:reindex' first.",
                        'results' => [],
                    ]);
                }
                // Table has data but query returned no matches
                return json_encode([
                    'hint' => 'No operations matched your query. Try broader search terms or use list_applications to browse available applications.',
                    'results' => [],
                ]);
            } catch (\Throwable $e) {
                // Fallback if count fails
                return json_encode([
                    'hint' => 'Search index is not available. Run reindex command first.',
                    'results' => [],
                ]);
            }
        }

        // Format results - decode paramSchema from JSON string to array using safe helper
        $formatted = [];
        foreach ($results as $row) {
            $type = $row->type ?? 'operation';

            if ($type === 'prompt') {
                $formatted[] = [
                    'type' => 'prompt',
                    'id' => $row->operationId,
                    'package' => $row->package_name,
                    'summary' => $row->summary,
                    'content' => $row->promptContent,
                ];
            } else {
                $formatted[] = [
                    'type' => 'operation',
                    'operationId' => $row->operationId,
                    'summary' => $row->summary,
                    'method' => $row->method,
                    'path' => $row->path,
                    'paramSchema' => OperationsSearchService::safeDecodeParamSchema($row->paramSchema),
                ];
            }
        }

        return json_encode(['results' => $formatted]);
    }

    private function handleExecuteOperation(array $arguments, Conversation $conversation): string
    {
        $operationId = $arguments['operationId'] ?? '';
        if (empty($operationId)) {
            return json_encode(['error' => 'operationId is required']);
        }

        $params = $arguments['parameters'] ?? [];

        // Check cache first — skip ApiManager lookup on hit
        $cached = $this->operationCache->get($conversation->id, $operationId);
        if ($cached) {
            $method = $cached['method'];
            $pathTemplate = $cached['path'];
        } else {
            $details = ApiManager::getOperationDetails($operationId);
            if (empty((array) $details)) {
                return json_encode(['error' => "Unknown operation: {$operationId}"]);
            }

            // Cache the resolved operation details
            $this->operationCache->put($conversation->id, $operationId, $details);

            $method = strtoupper($details['method'] ?? 'GET');
            $pathTemplate = $details['path'] ?? '';
        }

        // Check confirmation/rejection
        $validation = ApiCallValidator::validate($operationId, $method, $pathTemplate);

        if ($validation['status'] === 'reject') {
            return json_encode(['error' => $validation['reason'] ?? 'Operation rejected']);
        }

        if ($validation['status'] === 'confirm') {
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
        $resolved = $this->toolExecutor->extractArguments($params, $pathTemplate);
        $result = $this->toolExecutor->executeHttpCall($method, $resolved['path'], $resolved['query'], $resolved['body'], $session);

        return $this->extractResultContent($result);
    }

    /**
     * Check if all tool calls in this turn were successful execute_operation calls.
     * When true, the agent loop can stop without asking the LLM for a summary.
     */
    public function allExecuteOperationsSucceeded(array $toolCalls, array $toolResults): bool
    {
        if (empty($toolCalls) || empty($toolResults)) {
            return false;
        }

        foreach ($toolCalls as $toolCall) {
            if (($toolCall['function']['name'] ?? '') !== 'execute_operation') {
                return false;
            }
        }

        foreach ($toolResults as $result) {
            $decoded = json_decode($result['content'] ?? '', true);
            if (is_array($decoded) && isset($decoded['error'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Builds a "Known Operations" section for the system prompt.
     *
     * Returns null when the cache has no entries for this conversation.
     * Returns a formatted markdown section with operation details otherwise.
     *
     * @param Conversation $conversation The conversation context
     * @return string|null The formatted section or null if empty
     */
    private function buildKnownOperationsSection(Conversation $conversation): ?string
    {
        $entries = $this->operationCache->getEntries($conversation->id, 20);

        if (empty($entries)) {
            return null;
        }

        $lines = [];
        $lines[] = '';
        $lines[] = '## Known Operations';
        $lines[] = '';

        foreach ($entries as $entry) {
            $operationId = $entry['operationId'] ?? 'unknown';
            $method = strtoupper($entry['method'] ?? 'GET');
            $path = $entry['path'] ?? '/';
            $summary = $entry['summary'] ?? '';
            $paramSchema = $entry['paramSchema'] ?? null;

            $lines[] = "**{$operationId}** ({$method} {$path})";
            $lines[] = "  - Summary: {$summary}";

            if ($paramSchema && is_array($paramSchema)) {
                $params = json_encode($paramSchema, JSON_UNESCAPED_SLASHES);
                $lines[] = "  - Parameters: {$params}";
            } else {
                $lines[] = "  - Parameters: none";
            }
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }

    public function buildMessagesPayload(Conversation $conversation): array
    {
        $dbMessages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get();

        $payload = [];

        $systemPrompt = config('llm-client.agent_loop.system_prompt', '');

        // Append "Known Operations" section when cache has entries
        $knownOpsSection = $this->buildKnownOperationsSection($conversation);
        if ($knownOpsSection !== null) {
            $systemPrompt .= $knownOpsSection;
        }

        if (!empty($systemPrompt)) {
            $payload[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

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
