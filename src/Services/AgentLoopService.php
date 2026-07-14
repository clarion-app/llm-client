<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Exceptions\PresetNotFoundException;
use ClarionApp\LlmClient\Exceptions\SchemaValidationError;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\McpSession;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\SchemaValidator;
use ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry;
use ClarionApp\LlmClient\Contracts\MemoryService as MemoryServiceContract;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Contracts\EpisodicMemoryService as EpisodicMemoryServiceContract;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Events\AgentTurnCompleted;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Services\ContextWindowBudgeter;
use ClarionApp\LlmClient\Services\ConversationCondenser;
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
    private ProviderRegistry $providerRegistry;
    private MessageFormatter $messageFormatter;
    private ToolFormatter $toolFormatter;
    private SchemaValidator $schemaValidator;
    private ?StructuredOutputPresetRegistry $presetRegistry;
    private ?MemoryServiceContract $memoryService;
    private ?EpisodicMemoryServiceContract $episodicMemoryService;
    private ?DeclarativeMemoryServiceContract $declarativeMemoryService;
    private ContextWindowBudgeter $contextWindowBudgeter;
    private ?ConversationCondenser $conversationCondenser;

    public function __construct(
        McpToolRegistry $toolRegistry,
        McpToolExecutor $toolExecutor,
        OperationCache $operationCache,
        ?ProviderRegistry $providerRegistry = null,
        ?MessageFormatter $messageFormatter = null,
        ?ToolFormatter $toolFormatter = null,
        ?SchemaValidator $schemaValidator = null,
        ?StructuredOutputPresetRegistry $presetRegistry = null,
        ?MemoryServiceContract $memoryService = null,
        ?EpisodicMemoryServiceContract $episodicMemoryService = null,
        ?DeclarativeMemoryServiceContract $declarativeMemoryService = null,
        ?ContextWindowBudgeter $contextWindowBudgeter = null,
        ?ConversationCondenser $conversationCondenser = null
    ) {
        $this->toolRegistry = $toolRegistry;
        $this->toolExecutor = $toolExecutor;
        $this->operationCache = $operationCache;
        $this->providerRegistry = $providerRegistry ?? new ProviderRegistry();
        $this->messageFormatter = $messageFormatter ?? new MessageFormatter();
        $this->toolFormatter = $toolFormatter ?? new ToolFormatter();
        $this->schemaValidator = $schemaValidator ?? new SchemaValidator();
        $this->presetRegistry = $presetRegistry;
        $this->memoryService = $memoryService;
        $this->episodicMemoryService = $episodicMemoryService;
        $this->declarativeMemoryService = $declarativeMemoryService;
        $this->contextWindowBudgeter = $contextWindowBudgeter ?? new ContextWindowBudgeter();
        $this->conversationCondenser = $conversationCondenser;
    }

    public function start(Conversation $conversation, int $iteration = 1): void
    {
        $conversation->update(['is_processing' => true]);

        $tools = $this->buildToolsPayload($conversation);
        $formattedTools = $this->formatTools($conversation, $tools);
        $rawMessages = $this->buildMessagesPayload($conversation);
        $trimmed = $this->applyContextWindowTrim($conversation, $rawMessages);
        $formatted = $this->formatMessages($conversation, $trimmed);

        $this->dispatchStreamRequest($conversation, $formatted['messages'], $formattedTools, $iteration, $formatted['system']);
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

        $confirmationType = $pending['confirmation_type'] ?? 'api_call';

        if ($approved) {
            if ($confirmationType === 'declarative_memory') {
                // Route declarative_memory proposals to applyAgentWrite with confirmation
                $type = $pending['type'] ?? '';
                $content = $pending['content'] ?? '';
                $existingId = $pending['existingId'] ?? null;

                if ($this->declarativeMemoryService !== null && $type && $content) {
                    $entry = $this->declarativeMemoryService->applyAgentWrite(
                        $conversation->user_id,
                        $type,
                        $content,
                        true,
                        $existingId
                    );
                    $resultContent = json_encode([
                        'id' => $entry->id,
                        'type' => $entry->type,
                        'content' => $entry->content,
                        'source' => $entry->source,
                        'created' => true,
                    ]);
                } else {
                    $resultContent = json_encode(['error' => 'Declarative memory service not available']);
                }

                $toolData['tool_results'] = [
                    ['tool_call_id' => $toolCallId, 'content' => $resultContent],
                ];
            } else {
                // Execute the confirmed API operation (existing path, unchanged)
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
            }
        } else {
            $toolData['tool_results'] = [
                ['tool_call_id' => $toolCallId, 'content' => 'User cancelled this operation.'],
            ];
        }

        $toolData['pending_confirmation'] = null;
        $message->update(['tool_data' => $toolData]);

        // Continue the agent loop
        $tools = $this->buildToolsPayload($conversation);
        $formattedTools = $this->formatTools($conversation, $tools);
        $rawMessages = $this->buildMessagesPayload($conversation);
        $trimmed = $this->applyContextWindowTrim($conversation, $rawMessages);
        $formatted = $this->formatMessages($conversation, $trimmed);
        $this->dispatchStreamRequest($conversation, $formatted['messages'], $formattedTools, $iteration, $formatted['system']);
    }

    /**
     * Synchronous agent loop execution for external channel integrations.
     * Returns the final response array or a confirmation-required structure.
     *
     * @param Conversation $conversation The conversation context.
     * @param string $message The user message.
     * @param array $options Optional: ['preset' => 'decision', 'preset_params' => [...], 'schema_overrides' => [...], 'schema' => [...], 'retry_on_validation_failure' => bool, 'max_schema_retries' => int]
     */
    public function run(Conversation $conversation, string $message, array $options = []): array
    {
        $conversation->update(['is_processing' => true]);

        // Resolve preset schema if a preset name is specified
        $presetName = $options['preset'] ?? null;
        $presetParams = $options['preset_params'] ?? null;
        $schemaOverrides = $options['schema_overrides'] ?? null;
        $presetSystemPrompt = '';

        if ($presetName && $this->presetRegistry !== null) {
            try {
                $resolvedSchema = $this->presetRegistry->resolveSchema($presetName, $presetParams, $schemaOverrides);
                // If no explicit schema was provided, use the resolved preset schema
                if (empty($options['schema'])) {
                    $options['schema'] = $resolvedSchema;
                }
                // Fetch the preset's system prompt for injection
                $preset = $this->presetRegistry->find($presetName);
                $presetSystemPrompt = $preset->getSystemPrompt();
            } catch (PresetNotFoundException $e) {
                throw new \RuntimeException(sprintf('Structured output preset "%s" not found. %s', $presetName, $e->getMessage()));
            }
        } elseif (!empty($schemaOverrides) && $this->presetRegistry !== null) {
            // schema_overrides without a preset name — treat as error
            throw new \RuntimeException('schema_overrides requires a preset name. Specify "preset" option.');
        }

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
        $formattedTools = $this->formatTools($conversation, $tools);

        $shouldValidate = $this->schemaValidator->shouldValidate($options);
        $retryOnValidationFailure = $options['retry_on_validation_failure'] ?? false;
        $maxSchemaRetries = $options['max_schema_retries'] ?? config('llm-client.schema_validation.max_retries', 2);
        $schemaRetryCount = 0;
        $correctionPromptBuilder = new CorrectionPromptBuilder();

        try {
            for ($iteration = 1; $iteration <= $maxIterations; $iteration++) {
                $rawMessages = $this->buildMessagesPayload($conversation);
                $trimmed = $this->applyContextWindowTrim($conversation, $rawMessages);
                $formatted = $this->formatMessages($conversation, $trimmed);
                // Inject preset system prompt into the base system prompt if present
                // NOTE: the preset system prompt appended below is covered by the
                // injected_section_reserve in the context_window config budget.
                $systemPrompt = $formatted['system'];
                if ($presetSystemPrompt !== '') {
                    $systemPrompt = $systemPrompt . "\n\n" . $presetSystemPrompt;
                }
                $response = $this->callLlmSync($conversation, $formatted['messages'], $formattedTools, $systemPrompt);

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
                    // Validate response against schema if configured
                    $validatedContent = null;
                    $validationError = null;

                    if ($shouldValidate && !empty($options['schema'])) {
                        try {
                            $validatedContent = $this->schemaValidator->validate($content, $options['schema']);
                        } catch (SchemaValidationError $e) {
                            $validationError = $e;

                            // Check if we should retry
                            if ($retryOnValidationFailure && $schemaRetryCount < $maxSchemaRetries && !$e->isRetryExhausted()) {
                                $schemaRetryCount++;

                                // Build correction prompt and inject as user message
                                $correctionPrompt = $correctionPromptBuilder->build(
                                    $e->withRetryInfo($schemaRetryCount, $maxSchemaRetries)
                                );

                                // Create correction message to feed back to LLM
                                Message::create([
                                    'conversation_id' => $conversation->id,
                                    'content' => $correctionPrompt,
                                    'role' => 'user',
                                    'user' => 'system',
                                    'responseTime' => 0,
                                ]);

                                // Continue the loop to retry
                                continue;
                            }

                            // Retry exhausted or disabled — throw the error
                            if ($validationError && $retryOnValidationFailure) {
                                throw $validationError->withRetryInfo($schemaRetryCount, $maxSchemaRetries);
                            }
                            throw $validationError;
                        }
                    }

                    $assistantMessage = Message::create([
                        'conversation_id' => $conversation->id,
                        'content' => $content,
                        'role' => 'assistant',
                        'user' => $conversation->character,
                        'responseTime' => 0,
                    ]);

                    $agentId = $conversation->character ?? $conversation->id;

                    $conversation->update(['is_processing' => false]);

                    // Fire ConversationEnded for short-term memory cleanup (T018)
                    \Illuminate\Support\Facades\Event::dispatch(
                        new ConversationEnded($conversation->id, $agentId)
                    );

                    // Generate title on first exchange
                    if ($conversation->title === null) {
                        $titleRequest = new \ClarionApp\LlmClient\OpenAIGenerateConversationTitleRequest($conversation);
                        $titleRequest->sendGenerateConversationTitle();
                    }

                    return [
                        'status' => 'completed',
                        'content' => $validatedContent !== null ? json_encode($validatedContent) : $content,
                        'validated' => $validatedContent,
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
                        $confirmationType = $decoded['confirmation_type'] ?? 'api_call';

                        if ($confirmationType === 'declarative_memory') {
                            $pendingConfirmation = [
                                'tool_name' => 'propose_declarative_memory',
                                'confirmation_type' => 'declarative_memory',
                                'type' => $decoded['type'] ?? '',
                                'content' => $decoded['content'] ?? '',
                                'existingId' => $decoded['existingId'] ?? null,
                                'expires_at' => now()->addSeconds(config('llm-client.agent_loop.confirmation_timeout', 300))->toIso8601String(),
                            ];

                            $confirmationPayload = [
                                'confirmation_type' => 'declarative_memory',
                                'type' => $decoded['type'] ?? '',
                                'content' => $decoded['content'] ?? '',
                                'existingId' => $decoded['existingId'] ?? null,
                                'expires_at' => $pendingConfirmation['expires_at'],
                            ];
                        } else {
                            // Default: execute_operation (api_call)
                            $pendingConfirmation = [
                                'tool_name' => 'execute_operation',
                                'confirmation_type' => 'api_call',
                                'operationId' => $decoded['operationId'],
                                'method' => $decoded['method'],
                                'path' => $decoded['path'],
                                'arguments' => $decoded['parameters'] ?? [],
                                'expires_at' => now()->addSeconds(config('llm-client.agent_loop.confirmation_timeout', 300))->toIso8601String(),
                            ];

                            $confirmationPayload = [
                                'confirmation_type' => 'api_call',
                                'operationId' => $decoded['operationId'],
                                'method' => $decoded['method'],
                                'path' => $decoded['path'],
                                'arguments' => $decoded['parameters'] ?? [],
                                'expires_at' => $pendingConfirmation['expires_at'],
                            ];
                        }

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
                            'confirmation' => $confirmationPayload,
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

                // Fire AgentTurnCompleted for scratch memory cleanup (T013)
                \Illuminate\Support\Facades\Event::dispatch(
                    new AgentTurnCompleted((string)$iteration, $conversation->id)
                );

                // If all tool calls were successful execute_operation calls,
                // stop the loop — no need for a summary response from the LLM.
                if ($this->allExecuteOperationsSucceeded($toolCalls, $toolResults)) {
                    $agentId = $conversation->character ?? $conversation->id;
                    $conversation->update(['is_processing' => false]);

                    // Fire ConversationEnded for short-term memory cleanup (T018)
                    \Illuminate\Support\Facades\Event::dispatch(
                        new ConversationEnded($conversation->id, $agentId)
                    );

                    return [
                        'status' => 'completed',
                        'content' => '',
                        'message_id' => null,
                    ];
                }
            }

            // Max iterations exceeded
            $agentId = $conversation->character ?? $conversation->id;
            $conversation->update(['is_processing' => false]);

            // Fire ConversationEnded for short-term memory cleanup (T018)
            \Illuminate\Support\Facades\Event::dispatch(
                new ConversationEnded($conversation->id, $agentId)
            );

            return [
                'status' => 'error',
                'content' => 'Maximum iterations reached',
                'message_id' => null,
                'code' => 'max_iterations',
            ];
        } catch (\Throwable $e) {
            $agentId = $conversation->character ?? $conversation->id;
            $conversation->update(['is_processing' => false]);

            // Fire ConversationEnded for short-term memory cleanup (T018)
            \Illuminate\Support\Facades\Event::dispatch(
                new ConversationEnded($conversation->id, $agentId)
            );

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
            $agentId = $conversation->character ?? $conversation->id;
            $conversation->update(['is_processing' => false]);

            // Fire ConversationEnded for short-term memory cleanup (T018)
            \Illuminate\Support\Facades\Event::dispatch(
                new ConversationEnded($conversation->id, $agentId)
            );

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
        $formattedTools = $this->formatTools($conversation, $tools);
        $iteration = ($toolData['iteration'] ?? 1) + 1;

        for (; $iteration <= $maxIterations; $iteration++) {
            $rawMessages = $this->buildMessagesPayload($conversation);
            $trimmed = $this->applyContextWindowTrim($conversation, $rawMessages);
            $formatted = $this->formatMessages($conversation, $trimmed);
            $response = $this->callLlmSync($conversation, $formatted['messages'], $formattedTools, $formatted['system']);

            $choice = $response['choices'][0] ?? null;
            if (!$choice) {
                $agentId = $conversation->character ?? $conversation->id;
                $conversation->update(['is_processing' => false]);

                // Fire ConversationEnded for short-term memory cleanup (T018)
                \Illuminate\Support\Facades\Event::dispatch(
                    new ConversationEnded($conversation->id, $agentId)
                );

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

                $agentId = $conversation->character ?? $conversation->id;
                $conversation->update(['is_processing' => false]);

                // Fire ConversationEnded for short-term memory cleanup (T018)
                \Illuminate\Support\Facades\Event::dispatch(
                    new ConversationEnded($conversation->id, $agentId)
                );

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
                    $confirmationType = $decoded['confirmation_type'] ?? 'api_call';

                    if ($confirmationType === 'declarative_memory') {
                        $pendingConfirmation = [
                            'tool_name' => 'propose_declarative_memory',
                            'confirmation_type' => 'declarative_memory',
                            'type' => $decoded['type'] ?? '',
                            'content' => $decoded['content'] ?? '',
                            'existingId' => $decoded['existingId'] ?? null,
                            'expires_at' => now()->addSeconds(config('llm-client.agent_loop.confirmation_timeout', 300))->toIso8601String(),
                        ];

                        $confirmationPayload = [
                            'confirmation_type' => 'declarative_memory',
                            'type' => $decoded['type'] ?? '',
                            'content' => $decoded['content'] ?? '',
                            'existingId' => $decoded['existingId'] ?? null,
                            'expires_at' => $pendingConfirmation['expires_at'],
                        ];
                    } else {
                        // Default: execute_operation (api_call)
                        $pendingConfirmation = [
                            'tool_name' => 'execute_operation',
                            'confirmation_type' => 'api_call',
                            'operationId' => $decoded['operationId'],
                            'method' => $decoded['method'],
                            'path' => $decoded['path'],
                            'arguments' => $decoded['parameters'] ?? [],
                            'expires_at' => now()->addSeconds(config('llm-client.agent_loop.confirmation_timeout', 300))->toIso8601String(),
                        ];

                        $confirmationPayload = [
                            'confirmation_type' => 'api_call',
                            'operationId' => $decoded['operationId'],
                            'method' => $decoded['method'],
                            'path' => $decoded['path'],
                            'arguments' => $decoded['parameters'] ?? [],
                            'expires_at' => $pendingConfirmation['expires_at'],
                        ];
                    }

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
                        'confirmation' => $confirmationPayload,
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

        $agentId = $conversation->character ?? $conversation->id;
        $conversation->update(['is_processing' => false]);

        // Fire ConversationEnded for short-term memory cleanup (T018)
        \Illuminate\Support\Facades\Event::dispatch(
            new ConversationEnded($conversation->id, $agentId)
        );

        return ['status' => 'error', 'content' => 'Maximum iterations reached', 'message_id' => null];
    }

    /**
     * Apply context window trimming to the canonical message array.
     * Inserts between buildMessagesPayload() and formatMessages() — the single shared seam.
     *
     * The trimmed array is used only for the request payload; the stored transcript is untouched.
     *
     * @param Conversation $conversation The conversation context.
     * @param array $messages Canonical OpenAI-shaped message array from buildMessagesPayload().
     * @return array Trimmed canonical message array.
     */
    private function applyContextWindowTrim(Conversation $conversation, array $messages): array
    {
        $providerType = $conversation->effectiveProviderType;
        $server = $conversation->server;

        // Resolve the provider and build the estimator closure.
        $provider = $this->providerRegistry->resolveByType($providerType, $server);
        $model = $conversation->model;
        $estimator = fn (string $text) => $provider->countTokens($text, $model);

        // Try condensation first if available, then fall back to trimming
        if ($this->conversationCondenser) {
            return $this->conversationCondenser->condenseOrTrim(
                $messages,
                $model,
                $providerType,
                $estimator,
                $conversation->id
            );
        }

        return $this->contextWindowBudgeter->trim(
            $messages,
            $model,
            $providerType,
            $estimator,
            $conversation->id
        );
    }

    /**
     * Format messages using MessageFormatter for the conversation's effective provider type.
     * Uses provider_override if set, otherwise falls back to server provider_type.
     */
    private function formatMessages(Conversation $conversation, array $messages): array
    {
        $providerType = $conversation->effectiveProviderType;
        return $this->messageFormatter->formatForProvider($messages, $providerType);
    }

    /**
     * Format tools using ToolFormatter for the conversation's effective provider type.
     * Uses provider_override if set, otherwise falls back to server provider_type.
     */
    private function formatTools(Conversation $conversation, array $tools): array
    {
        $providerType = $conversation->effectiveProviderType;
        return $this->toolFormatter->formatForProvider($tools, $providerType);
    }

    /**
     * Make a synchronous (non-streaming) LLM API call.
     * Delegates to the resolved provider based on the conversation's effective provider type.
     */
    private function callLlmSync(Conversation $conversation, array $messages, array $tools, string $system = '', ?string $responseFormat = null): array
    {
        $server = $conversation->server;
        if (!$server) {
            throw new \RuntimeException('No LLM server configured');
        }

        $providerType = $conversation->effectiveProviderType;
        $provider = $this->providerRegistry->resolveByType($providerType, $server);

        // Use provider-specific default model when conversation model is null
        $model = $conversation->model;
        if ($model === null) {
            $model = config('llm-client.providers.' . $providerType->value . '.default_model');
        }

        $options = [
            'model' => $model,
            'temperature' => 1.0,
        ];

        // Pass system prompt for providers that support it (Anthropic)
        if ($system !== '') {
            $options['system'] = $system;
        }

        // Pass response_format for JSON mode support
        if (isset($responseFormat) && $responseFormat !== null) {
            $options['response_format'] = $responseFormat;
        }

        return $provider->chat($messages, $tools, $options);
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
            [
                'type' => 'function',
                'function' => [
                    'name' => 'memory_create',
                    'description' => 'Create or update an entry in the memory store. Supports three scopes: scratch (ephemeral, discarded after this turn), short_term (persists across turns in this session), long_term (persists across sessions with LRU eviction).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'scope' => [
                                'type' => 'string',
                                'enum' => ['scratch', 'short_term', 'long_term'],
                                'description' => 'Memory scope: scratch (per-turn), short_term (per-session), long_term (persistent)',
                            ],
                            'key' => [
                                'type' => 'string',
                                'description' => 'Optional key for direct lookup (max 64 chars). Auto-generated UUID if omitted.',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'The content to store',
                            ],
                        ],
                        'required' => ['scope', 'content'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'memory_read',
                    'description' => 'Read a memory entry by key or UUID. Updates last_accessed_at for LRU tracking.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'scope' => [
                                'type' => 'string',
                                'enum' => ['scratch', 'short_term', 'long_term'],
                                'description' => 'Memory scope',
                            ],
                            'identifier' => [
                                'type' => 'string',
                                'description' => 'Entry key or UUID',
                            ],
                        ],
                        'required' => ['scope', 'identifier'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'memory_search',
                    'description' => 'Search memory entries within a scope. Supports key_prefix (prefix match on key) and content (full-text search) modes.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'scope' => [
                                'type' => 'string',
                                'enum' => ['scratch', 'short_term', 'long_term'],
                                'description' => 'Memory scope',
                            ],
                            'query' => [
                                'type' => 'string',
                                'description' => 'Search query string',
                            ],
                            'mode' => [
                                'type' => 'string',
                                'enum' => ['key_prefix', 'content'],
                                'description' => 'Search mode: key_prefix (default) or content',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Maximum results (default 20, max 100)',
                            ],
                        ],
                        'required' => ['scope', 'query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'memory_delete',
                    'description' => 'Delete a memory entry by key or UUID.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'scope' => [
                                'type' => 'string',
                                'enum' => ['scratch', 'short_term', 'long_term'],
                                'description' => 'Memory scope',
                            ],
                            'identifier' => [
                                'type' => 'string',
                                'description' => 'Entry key or UUID',
                            ],
                        ],
                        'required' => ['scope', 'identifier'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'propose_declarative_memory',
                    'description' => 'Propose a new declarative memory (fact, preference, or rule) to the user for confirmation. Nothing is persisted until the user explicitly confirms. Use this when you infer a new fact or preference about the user, or when you want to suggest a behavioral rule.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => ['fact', 'preference', 'rule'],
                                'description' => 'Type of declarative memory: fact (objective information), preference (user preference), or rule (binding behavioral constraint)',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'The content to propose (e.g., "User prefers dark mode", "Always confirm before destructive actions")',
                            ],
                            'existingId' => [
                                'type' => 'string',
                                'description' => 'Optional: UUID of an existing entry to update (for inferred updates). Omit for new entries.',
                            ],
                        ],
                        'required' => ['type', 'content'],
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
            'memory_create' => $this->handleMemoryCreate($arguments, $conversation),
            'memory_read' => $this->handleMemoryRead($arguments, $conversation),
            'memory_search' => $this->handleMemorySearch($arguments, $conversation),
            'memory_delete' => $this->handleMemoryDelete($arguments, $conversation),
            'propose_declarative_memory' => $this->handleProposeDeclarativeMemory($arguments, $conversation),
            default => json_encode(['error' => "Unknown tool: {$toolName}"]),
        };
    }

    private function handleMemoryCreate(array $arguments, Conversation $conversation): string
    {
        if ($this->memoryService === null) {
            return json_encode(['error' => 'Memory service not available']);
        }

        $scopeValue = $arguments['scope'] ?? '';
        $scope = MemoryScope::tryFrom($scopeValue);
        if (!$scope) {
            return json_encode(['error' => 'Invalid scope. Must be scratch, short_term, or long_term']);
        }

        $content = $arguments['content'] ?? '';
        if ($content === '') {
            return json_encode(['error' => 'content is required']);
        }

        $key = $arguments['key'] ?? null;
        $agent_id = $conversation->character ?? $conversation->id;
        $user_id = $conversation->user_id;
        $conversation_id = $conversation->id;

        // For scratch scope, turn_id is required - use current iteration
        $turn_id = $arguments['turn_id'] ?? null;

        try {
            $entry = $this->memoryService->create(
                $scope,
                $agent_id,
                $user_id,
                $conversation_id,
                $turn_id,
                $key,
                $content
            );

            return json_encode([
                'id' => $entry->id,
                'key' => $entry->key,
                'scope' => $entry->scope->value,
                'created' => true,
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    private function handleMemoryRead(array $arguments, Conversation $conversation): string
    {
        if ($this->memoryService === null) {
            return json_encode(['error' => 'Memory service not available']);
        }

        $scopeValue = $arguments['scope'] ?? '';
        $scope = MemoryScope::tryFrom($scopeValue);
        if (!$scope) {
            return json_encode(['error' => 'Invalid scope']);
        }

        $identifier = $arguments['identifier'] ?? '';
        if ($identifier === '') {
            return json_encode(['error' => 'identifier is required']);
        }

        $agent_id = $conversation->character ?? $conversation->id;

        $entry = $this->memoryService->read($scope, $agent_id, $identifier);
        if (!$entry) {
            return json_encode(['found' => false, 'error' => 'Entry not found']);
        }

        return json_encode([
            'id' => $entry->id,
            'key' => $entry->key,
            'scope' => $entry->scope->value,
            'content' => $entry->content,
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ]);
    }

    private function handleMemorySearch(array $arguments, Conversation $conversation): string
    {
        if ($this->memoryService === null) {
            return json_encode(['error' => 'Memory service not available']);
        }

        $scopeValue = $arguments['scope'] ?? '';
        $scope = MemoryScope::tryFrom($scopeValue);
        if (!$scope) {
            return json_encode(['error' => 'Invalid scope']);
        }

        $query = $arguments['query'] ?? '';
        if ($query === '') {
            return json_encode(['error' => 'query is required']);
        }

        $mode = $arguments['mode'] ?? 'key_prefix';
        $limit = (int) ($arguments['limit'] ?? config('llm-client.memory.search_default_limit', 20));

        $agent_id = $conversation->character ?? $conversation->id;

        $entries = $this->memoryService->search($scope, $agent_id, $query, $mode, $limit);

        $results = array_map(function ($entry) {
            return [
                'id' => $entry->id,
                'key' => $entry->key,
                'scope' => $entry->scope->value,
                'content' => $entry->content,
                'last_accessed_at' => $entry->last_accessed_at?->toIso8601String(),
            ];
        }, $entries);

        return json_encode(['results' => $results, 'count' => count($results)]);
    }

    private function handleMemoryDelete(array $arguments, Conversation $conversation): string
    {
        if ($this->memoryService === null) {
            return json_encode(['error' => 'Memory service not available']);
        }

        $scopeValue = $arguments['scope'] ?? '';
        $scope = MemoryScope::tryFrom($scopeValue);
        if (!$scope) {
            return json_encode(['error' => 'Invalid scope']);
        }

        $identifier = $arguments['identifier'] ?? '';
        if ($identifier === '') {
            return json_encode(['error' => 'identifier is required']);
        }

        $agent_id = $conversation->character ?? $conversation->id;

        $deleted = $this->memoryService->delete($scope, $agent_id, $identifier);

        return json_encode(['deleted' => $deleted]);
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

    /**
     * Build episodic memory recall section for system prompt (T019/T020b).
     *
     * Extracts topic keywords from the user's most recent message and retrieves
     * relevant past episodic memories to inject into agent context.
     *
     * @return string|null The formatted episodic memory section or null if empty
     */
    private function buildEpisodicMemorySection(Conversation $conversation): ?string
    {
        if (!$this->episodicMemoryService) {
            return null;
        }

        // Get the most recent user message to extract topics
        $lastUserMessage = Message::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->latest('created_at')
            ->first();

        if (!$lastUserMessage) {
            return null;
        }

        // Extract topic keywords from user's message (simple keyword extraction)
        $topics = $this->extractTopicsFromMessage($lastUserMessage->content);

        if (empty($topics)) {
            return null;
        }

        // Recall relevant episodic memories for each topic
        $relevantMemories = [];
        $userId = (string) $conversation->user_id;

        foreach ($topics as $topic) {
            $memories = $this->episodicMemoryService->recall($userId, $topic);
            foreach ($memories as $memory) {
                // Deduplicate by memory ID
                if (!in_array($memory->id, array_column($relevantMemories, 'id'))) {
                    $relevantMemories[] = $memory;
                }
            }

            // Limit to top 5 memories to avoid bloating context
            if (count($relevantMemories) >= 5) {
                break;
            }
        }

        if (empty($relevantMemories)) {
            return null;
        }

        // Build the episodic memory section (T020b)
        $lines = [];
        $lines[] = '';
        $lines[] = '## Past Context (Episodic Memory)';
        $lines[] = '';
        $lines[] = 'The user has had past conversations on related topics. Reference these memories when relevant to the current conversation:';
        $lines[] = '';

        foreach ($relevantMemories as $memory) {
            $date = $memory->created_at->format('M j, Y');
            $topicsStr = implode(', ', $memory->topics ?? []);
            $lines[] = "- **{$date}** (topics: {$topicsStr})";
            $lines[] = "  - {$memory->summary}";
            $lines[] = '';
        }

        $lines[] = 'When responding, cite these past memories naturally in your first exchange if relevant (e.g., "Last week we agreed on..."). Skip citation when no memories are relevant.';

        return implode(PHP_EOL, $lines);
    }

    /**
     * Extract topic keywords from a user message for episodic memory recall.
     *
     * Uses simple keyword extraction based on common technical/topic words.
     *
     * @return string[] Extracted topic keywords
     */
    private function extractTopicsFromMessage(string $message): array
    {
        // Common technical/topic keywords to look for
        $commonTopics = [
            'deployment', 'kubernetes', 'docker', 'database', 'api', 'security',
            'authentication', 'authorization', 'performance', 'scaling', 'monitoring',
            'logging', 'testing', 'ci/cd', 'infrastructure', 'configuration',
            'migration', 'backup', 'recovery', 'compliance', 'audit',
            'microservices', 'architecture', 'design', 'planning',
            'scheduling', 'timelines', 'hiring', 'team', 'budget',
            'canary', 'blue-green', 'rollback', 'release', 'versioning',
            'frontend', 'backend', 'mobile', 'web', 'cloud',
        ];

        $lowerMessage = strtolower($message);
        $foundTopics = [];

        foreach ($commonTopics as $topic) {
            if (stripos($message, $topic) !== false) {
                $foundTopics[] = $topic;
            }
        }

        // If no common topics found, extract content words (nouns/verbs) as fallback
        if (empty($foundTopics)) {
            // Remove common stop words and punctuation
            $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
                'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
                'could', 'should', 'may', 'might', 'shall', 'can', 'need', 'dare',
                'ought', 'used', 'to', 'of', 'in', 'for', 'on', 'with', 'at', 'by',
                'from', 'as', 'into', 'through', 'during', 'before', 'after', 'above',
                'below', 'between', 'out', 'off', 'over', 'under', 'again', 'further',
                'then', 'once', 'here', 'there', 'when', 'where', 'why', 'how', 'all',
                'both', 'each', 'few', 'more', 'most', 'other', 'some', 'such', 'no',
                'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very', 's',
                't', 'just', 'don', 'now', 'i', 'me', 'my', 'myself', 'we', 'our',
                'you', 'your', 'he', 'him', 'his', 'she', 'her', 'it', 'its', 'they',
                'them', 'their', 'what', 'which', 'who', 'whom', 'this', 'that', 'these',
                'those', 'am', 'about', 'if', 'because', 'while', 'and', 'but', 'or',
                'i', 'me', 'my', 'is', 'it', 'we', 'my', 'the', 'a', 'an'];

            $words = preg_replace('/[^\p{L}\p{N}\s]/u', '', $lowerMessage);
            $words = explode(' ', trim($words));
            $words = array_filter($words, fn($w) => strlen($w) > 2 && !in_array($w, $stopWords));

            // Take first 3 meaningful words as topics
            $foundTopics = array_slice(array_unique($words), 0, 3);
        }

        return array_slice($foundTopics, 0, 3);
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

        // Append "Episodic Memory Recall" section with past relevant context (T019)
        $episodicMemorySection = $this->buildEpisodicMemorySection($conversation);
        if ($episodicMemorySection !== null) {
            $systemPrompt .= $episodicMemorySection;
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

    private function dispatchStreamRequest(Conversation $conversation, array $messages, array $tools, int $iteration, string $system = '', ?string $responseFormat = null): void
    {
        $server = Server::find($conversation->server_id);

        $body = new \stdClass();
        $body->temperature = 1.0;
        $body->model = $conversation->model;
        $body->stream = true;
        $body->messages = $messages;

        // Include system prompt for providers that support it (Anthropic)
        if ($system !== '') {
            $body->system = $system;
        }

        // Include response_format for JSON mode support
        if (isset($responseFormat) && $responseFormat !== null) {
            $body->response_format = $responseFormat;
        }

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

    /**
     * Handle the propose_declarative_memory tool call.
     *
     * Returns a __requires_confirmation marker so the agent loop pauses
     * and awaits user confirmation. Nothing is persisted at this point.
     */
    private function handleProposeDeclarativeMemory(array $arguments, Conversation $conversation): string
    {
        $type = $arguments['type'] ?? '';
        $content = $arguments['content'] ?? '';
        $existingId = $arguments['existingId'] ?? null;

        if (!in_array($type, ['fact', 'preference', 'rule'], true)) {
            return json_encode(['error' => 'Invalid type. Must be fact, preference, or rule']);
        }

        if ($content === '') {
            return json_encode(['error' => 'content is required']);
        }

        // Return __requires_confirmation marker with confirmation_type: 'declarative_memory'
        // This is transient — nothing is persisted yet
        return json_encode([
            '__requires_confirmation' => true,
            'confirmation_type' => 'declarative_memory',
            'type' => $type,
            'content' => $content,
            'existingId' => $existingId,
        ]);
    }
}
