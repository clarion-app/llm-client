<?php

namespace ClarionApp\LlmClient;

use ClarionApp\HttpQueue\HandleHttpStreamResponse;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Exceptions\SchemaValidationError;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\SchemaValidator;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent;
use ClarionApp\LlmClient\Services\ToolResultCondenser;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use Illuminate\Support\Facades\Log;

class AgentLoopStreamHandler extends HandleHttpStreamResponse
{
    public string $buffer = "";
    public string $reply = "";
    public ?Message $message = null;
    public array $toolCalls = [];
    private ?ToolResultCondenser $toolResultCondenser = null;
    private ?MetricsRecorder $metricsRecorder = null;
    private ?\ClarionApp\LlmClient\Services\RunTraceRecorder $runTraceRecorder = null;
    private string $attemptGroupId = '';
    private ?string $runId = null;
    private ?string $stepId = null;
    private ?string $actionId = null;
    private array $usage = [];

    public function __construct(
        ?ToolResultCondenser $toolResultCondenser = null,
        ?MetricsRecorder $metricsRecorder = null,
        ?\ClarionApp\LlmClient\Services\RunTraceRecorder $runTraceRecorder = null,
    ) {
        $this->toolResultCondenser = $toolResultCondenser;
        $this->metricsRecorder = $metricsRecorder;
        $this->runTraceRecorder = $runTraceRecorder;
    }

    public function handle($content, $data, $seconds)
    {
        $parsedData = is_string($data) ? json_decode($data, true) : $data;
        $conversationId = $parsedData['conversation_id'] ?? null;

        $conversation = Conversation::find($conversationId);
        if (!$conversation) return;

        $this->buffer .= $content;

        // Log raw chunks for debugging
        // Log::debug('AgentLoopStreamHandler: raw chunk', ['content' => $content]);

        // Split on "\n\n" delimiters — complete SSE messages are terminated by blank lines
        $parts = explode("\n\n", $this->buffer);
        $this->buffer = array_pop($parts); // Keep last (possibly incomplete) part in buffer

        foreach ($parts as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;
            if ($chunk === '[DONE]') continue;

            // Strip "data: " prefix if present
            if (str_starts_with($chunk, 'data: ')) {
                $chunk = substr($chunk, 6);
            }

            $json = json_decode($chunk, true);
            if ($json === null) continue;

            // Capture usage from whichever SSE chunk reports it (typically
            // the final chunk, alongside an empty choices array) — D4.
            if (isset($json['usage']) && $json['usage'] !== null) {
                $this->usage = (array) $json['usage'];
            }

            foreach ($json['choices'] ?? [] as $choice) {
                $delta = $choice['delta'] ?? [];

                // Handle text content deltas
                if (isset($delta['content'])) {
                    if ($this->message === null) {
                        $this->message = Message::create([
                            'conversation_id' => $conversation->id,
                            'responseTime' => 0,
                            'user' => $conversation->character,
                            'role' => 'assistant',
                            'content' => '',
                        ]);
                        event(new NewConversationMessageEvent($conversationId, $this->message->id));

                        // Record time-to-first-visible-output (074-latency-metrics
                        // FR-002/US1): this is the first assistant message row and
                        // the first broadcast the frontend receives for this
                        // response -- literally "first visible output." The
                        // handler is instantiated with `new` by the http-queue
                        // job (not container-resolved), so runTraceRecorder is
                        // lazily resolved here the same way finish() already does.
                        if ($this->runTraceRecorder === null) {
                            try {
                                $this->runTraceRecorder = app(\ClarionApp\LlmClient\Services\RunTraceRecorder::class);
                            } catch (\Throwable $e) {
                                $this->runTraceRecorder = null;
                            }
                        }
                        if ($this->runTraceRecorder !== null) {
                            $this->runTraceRecorder->recordFirstOutput($parsedData['run_id'] ?? $this->runId);
                        }
                    }

                    $this->reply .= $delta['content'];
                    event(new UpdateOpenAIConversationResponseEvent($conversationId, $this->message->id, $this->reply));
                }

                // Handle tool_calls deltas
                if (isset($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $toolCallDelta) {
                        $index = $toolCallDelta['index'] ?? 0;

                        // Initialize this tool call slot if needed
                        if (!isset($this->toolCalls[$index])) {
                            $this->toolCalls[$index] = [
                                'id' => $toolCallDelta['id'] ?? '',
                                'type' => $toolCallDelta['type'] ?? 'function',
                                'function' => [
                                    'name' => $toolCallDelta['function']['name'] ?? '',
                                    'arguments' => '',
                                ],
                            ];
                        } else {
                            // Update existing: accumulate arguments
                            if (isset($toolCallDelta['id'])) {
                                $this->toolCalls[$index]['id'] = $toolCallDelta['id'];
                            }
                            if (isset($toolCallDelta['function']['name'])) {
                                $this->toolCalls[$index]['function']['name'] = $toolCallDelta['function']['name'];
                            }
                        }

                        if (isset($toolCallDelta['function']['arguments'])) {
                            $this->toolCalls[$index]['function']['arguments'] .= $toolCallDelta['function']['arguments'];
                        }
                    }
                }
            }
        }
    }

    public function finish($data, $seconds)
    {
        $parsedData = is_string($data) ? json_decode($data, true) : $data;
        $conversationId = $parsedData['conversation_id'] ?? null;
        $iteration = $parsedData['iteration'] ?? 1;

        // Read run_id and step_id from payload (contracts §3.1).
        // A pre-feature payload has no run_id — mint a fresh run instead.
        $this->runId = $parsedData['run_id'] ?? null;
        $this->stepId = $parsedData['step_id'] ?? null;
        $this->actionId = $parsedData['action_id'] ?? null;

        $conversation = Conversation::find($conversationId);
        if (!$conversation) return;

        $maxIterations = config('llm-client.agent_loop.max_iterations', 20);

        // Ensure we have a runTraceRecorder (resolve from container if not injected).
        if ($this->runTraceRecorder === null) {
            try {
                $this->runTraceRecorder = app(\ClarionApp\LlmClient\Services\RunTraceRecorder::class);
            } catch (\Throwable $e) {
                $this->runTraceRecorder = null;
            }
        }

        // Generate attempt group ID for this turn (if not already set)
        if ($this->attemptGroupId === '') {
            $this->attemptGroupId = (string) \Illuminate\Support\Str::uuid();
        }

        // If no run_id was provided in the payload, mint a fresh run (contracts §3.3).
        if ($this->runId === null && $this->runTraceRecorder !== null) {
            $this->runId = $this->runTraceRecorder->openRun(
                \ClarionApp\LlmClient\ValueObjects\RunKind::Interactive,
                (string) $conversation->user_id,
                $conversation->id,
                streamed: true,
                model: $conversation->model,
                agentId: $conversation->character ?? $conversation->id,
            );
            // If we minted a run but have no step_id, open one now.
            if ($this->runId !== null && $this->stepId === null) {
                $this->stepId = $this->runTraceRecorder->openStep(
                    $this->runId,
                    null,
                    $this->attemptGroupId,
                );
            }
        }

        // Record LLM usage metrics for the final chunk (fire-and-forget, never throws)
        // Streaming responses may have usage in the final SSE chunk
        if ($this->metricsRecorder !== null) {
            $providerUsage = $this->usage;

            // Only rebuild the input payload when the provider omitted usage and
            // input tokens must be estimated — avoids the cost on the common path.
            $inputText = '';
            if (empty($providerUsage) || empty($providerUsage['prompt_tokens'])) {
                try {
                    $messages = app(AgentLoopService::class)->buildMessagesPayload($conversation);
                    $inputText = implode("\n", array_map(
                        fn ($m) => is_string($m['content'] ?? null) ? $m['content'] : '',
                        $messages
                    ));
                } catch (\Throwable $e) {
                    $inputText = '';
                }
            }

            $this->metricsRecorder->recordUsage(
                conversationId: $conversation->id,
                userId: (string) $conversation->user_id,
                attemptGroupId: $this->attemptGroupId,
                providerUsage: $providerUsage,
                inputText: $inputText,
                outputText: $this->reply,
                model: $conversation->model,
                providerType: $conversation->effectiveProviderType?->value,
                agentId: $conversation->character ?: null,
            );
        }

        // Close LLM request action before tool processing.
        if ($this->actionId !== null && $this->runTraceRecorder !== null) {
            try {
                $this->runTraceRecorder->closeAction(
                    $this->actionId,
                    \ClarionApp\LlmClient\ValueObjects\ActionOutcome::Success,
                    null,
                    json_encode(['reply_length' => strlen($this->reply)]),
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to close LLM request action in finish()', [
                    'action_id' => $this->actionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // If we have tool calls to execute
        if (!empty($this->toolCalls)) {
            // Check iteration limit
            if ($iteration >= $maxIterations) {
                $this->handleMaxIterationReached($conversation);
                return;
            }

            // The step is closed inside handleToolCalls(), once it is known whether
            // this round resolved or suspended for a confirmation — a suspended step
            // stays open across the pause so its duration covers the human wait.
            $this->handleToolCalls($conversation, $iteration);
            return;
        }

        Log::info('AgentLoopStreamHandler: finish called', [
            'conversation_id' => $conversationId,
            'iteration' => $iteration,
            'has_tool_calls' => !empty($this->toolCalls),
            'tool_calls_count' => count($this->toolCalls),
            'reply_length' => strlen($this->reply),
        ]);

        // Plain text response — save and finish
        if ($this->message === null) return;

        // Validate accumulated response against schema if configured
        $schema = $parsedData['schema'] ?? null;
        if ($schema !== null && !empty($schema)) {
            $validator = new SchemaValidator();
            try {
                $validated = $validator->validate($this->reply, $schema);
                // Use the validated content (re-encoded as JSON)
                $this->reply = json_encode($validated);
            } catch (SchemaValidationError $e) {
                Log::warning('Schema validation failed for streaming response', [
                    'conversation_id' => $conversationId,
                    'error' => $e->getMessage(),
                    'violations' => $e->getViolations(),
                ]);
                // For streaming, we log the warning but don't block the response
                // (no retry mechanism for streaming responses)
            }
        }

        $this->message->content = $this->reply;
        $this->message->responseTime = $seconds;
        $this->message->save();

        // Close the step and run for the plain-reply branch.
        $this->closeCurrentStep(\ClarionApp\LlmClient\ValueObjects\RunEndState::Completed);
        $this->closeRun(
            \ClarionApp\LlmClient\ValueObjects\RunEndState::Completed,
            $this->message->id,
        );

        event(new FinishOpenAIConversationResponseEvent($conversationId, $this->reply));

        $conversation->update(['is_processing' => false]);

        // Generate title on first conversation exchange (requires a server)
        if ($conversation->title === null && $conversation->server_id !== null) {
            $titleRequest = new OpenAIGenerateConversationTitleRequest($conversation);
            $titleRequest->sendGenerateConversationTitle();
        }

        // Check for unprocessed messages (FR-015)
        $this->checkForUnprocessedMessages($conversation);
    }

    private function handleToolCalls(Conversation $conversation, int $iteration): void
    {
        $conversationId = $conversation->id;
        $agentLoopService = app(AgentLoopService::class);

        // Create or reuse the assistant message for this tool call turn
        if ($this->message === null) {
            $this->message = Message::create([
                'conversation_id' => $conversationId,
                'responseTime' => 0,
                'user' => $conversation->character,
                'role' => 'assistant',
                'content' => $this->reply ?: '',
            ]);
            event(new NewConversationMessageEvent($conversationId, $this->message->id));
        }

        $toolResults = [];
        $metaToolNames = ['list_applications', 'execute_operation', 'search_operations'];
        $registry = app(\ClarionApp\LlmClient\Services\McpToolRegistry::class);

        foreach ($this->toolCalls as $toolCall) {
            $toolName = $toolCall['function']['name'] ?? '';
            $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
            $toolCallId = $toolCall['id'] ?? '';

            // Open ToolInvocation action for this tool call.
            $toolActionId = null;
            if ($this->runTraceRecorder !== null && $this->stepId !== null) {
                $toolActionId = $this->runTraceRecorder->openAction(
                    $this->stepId,
                    \ClarionApp\LlmClient\ValueObjects\ActionType::ToolInvocation,
                    $toolName,
                    $this->attemptGroupId,
                );
            }

            Log::info('AgentLoopStreamHandler: executing tool', [
                'tool' => $toolName,
                'arguments' => $arguments,
                'iteration' => $iteration,
            ]);

            event(new ToolExecutionEvent($conversationId, $toolName, 'executing'));

            // Non-meta tools: resolve via McpToolRegistry and check for confirmation
            $result = null;
            if (!in_array($toolName, $metaToolNames, true)) {
                $toolDef = $registry->findTool($toolName);
                if ($toolDef && !empty($toolDef['_meta'])) {
                    $meta = $toolDef['_meta'];
                    $method = $meta['method'] ?? '';

                    // destructive operations require user confirmation
                    if (strtoupper($method) === 'DELETE' || strtoupper($method) === 'PUT' || strtoupper($method) === 'PATCH') {
                        $pendingConfirmation = [
                            'tool_name' => $toolName,
                            'operationId' => $meta['operationId'] ?? null,
                            'method' => $method,
                            'path' => $meta['path'] ?? null,
                            'arguments' => $arguments,
                            'expires_at' => now()->addSeconds(config('llm-client.agent_loop.confirmation_timeout', 300))->toIso8601String(),
                        ];

                        $toolData = [
                            'tool_calls' => $this->toolCalls,
                            'tool_results' => null,
                            'iteration' => $iteration,
                            'pending_confirmation' => $pendingConfirmation,
                            'run_id' => $this->runId,
                            'step_id' => $this->stepId,
                            'paused_at' => now()->toIso8601String(),
                        ];

                        $this->message->update([
                            'content' => $this->reply ?: '',
                            'tool_data' => $toolData,
                        ]);

                        event(new ApiCallConfirmationRequiredEvent(
                            $conversationId,
                            $this->message->id,
                            $method,
                            $meta['path'] ?? '',
                            $arguments,
                            $toolName
                        ));

                        // Close tool action as awaiting_confirmation before suspend.
                        if ($toolActionId !== null && $this->runTraceRecorder !== null) {
                            try {
                                $this->runTraceRecorder->closeAction(
                                    $toolActionId,
                                    \ClarionApp\LlmClient\ValueObjects\ActionOutcome::AwaitingConfirmation,
                                );
                            } catch (\Throwable $e) {
                                Log::warning('Failed to mark tool action as awaiting_confirmation', [
                                    'action_id' => $toolActionId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        return; // Suspend for confirmation
                    }

                    // Safe read operations — execute via McpToolExecutor
                    $toolExecutor = app(\ClarionApp\LlmClient\Services\McpToolExecutor::class);
                    $session = \ClarionApp\LlmClient\Models\McpSession::where('user_id', $conversation->user_id)->first();
                    if (!$session) {
                        $session = \ClarionApp\LlmClient\Models\McpSession::create([
                            'user_id' => $conversation->user_id,
                            'protocol_version' => '2025-03-26',
                        ]);
                    }
                    $execResult = $toolExecutor->executeTool($toolName, $arguments, $session);
                    $result = json_encode($execResult);
                }
            }

            // Meta tools or unresolved non-meta tools: fall through to executeMetaTool
            if ($result === null) {
                $result = $agentLoopService->executeMetaTool($toolName, $arguments, $conversation);

                // Check if execute_operation needs confirmation
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

                    $toolData = [
                        'tool_calls' => $this->toolCalls,
                        'tool_results' => null,
                        'iteration' => $iteration,
                        'pending_confirmation' => $pendingConfirmation,
                        'run_id' => $this->runId,
                        'step_id' => $this->stepId,
                        'paused_at' => now()->toIso8601String(),
                    ];

                    $this->message->update([
                        'content' => $this->reply ?: '',
                        'tool_data' => $toolData,
                    ]);

                    event(new ApiCallConfirmationRequiredEvent(
                        $conversationId,
                        $this->message->id,
                        $decoded['method'],
                        $decoded['path'],
                        $decoded['parameters'] ?? [],
                        'execute_operation'
                    ));

                    // Close tool action as awaiting_confirmation before suspend.
                    if ($toolActionId !== null && $this->runTraceRecorder !== null) {
                        try {
                            $this->runTraceRecorder->closeAction(
                                $toolActionId,
                                \ClarionApp\LlmClient\ValueObjects\ActionOutcome::AwaitingConfirmation,
                            );
                        } catch (\Throwable $e) {
                            Log::warning('Failed to mark tool action as awaiting_confirmation', [
                                'action_id' => $toolActionId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    return; // Suspend for confirmation
                }
            }

            // Condense tool result if oversized (T054a: ContextReshape brackets)
            $reshapeActionId = null;
            if ($this->runTraceRecorder !== null && $toolActionId !== null) {
                $reshapeActionId = $this->runTraceRecorder->openAction(
                    $this->stepId,
                    \ClarionApp\LlmClient\ValueObjects\ActionType::ContextReshape,
                    'condense_tool_result',
                    $this->attemptGroupId,
                    $toolActionId,
                );
            }
            $toolResultEntry = $this->condenseToolResult($result, $conversationId, $toolName);
            if ($reshapeActionId !== null && $this->runTraceRecorder !== null) {
                try {
                    $this->runTraceRecorder->closeAction(
                        $reshapeActionId,
                        \ClarionApp\LlmClient\ValueObjects\ActionOutcome::Success,
                        null,
                        json_encode([
                            'original_tokens' => $toolResultEntry['original_tokens'] ?? null,
                            'condensed_tokens' => $toolResultEntry['condensed_tokens'] ?? null,
                            'method' => $toolResultEntry['method'] ?? null,
                        ]),
                    );
                } catch (\Throwable $e) {
                    Log::warning('Failed to close context_reshape action', [
                        'action_id' => $reshapeActionId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $toolResults[] = [
                'tool_call_id' => $toolCallId,
                'content' => $toolResultEntry['content'],
            ] + array_filter($toolResultEntry, fn ($k) => in_array($k, ['reference_id', 'original_tokens', 'condensed_tokens', 'method', 'condensed']), ARRAY_FILTER_USE_KEY);

            event(new ToolExecutionEvent($conversationId, $toolName, 'completed'));

            // Record tool invocation metrics (fire-and-forget, never throws).
            // Success/failure derived from the tool result: meta tools signal
            // failure with a JSON payload containing an "error" key.
            $toolError = null;
            if ($this->metricsRecorder !== null) {
                $resultDecoded = json_decode($result, true);
                $toolError = (is_array($resultDecoded) && isset($resultDecoded['error']))
                    ? (string) $resultDecoded['error']
                    : null;

                $this->metricsRecorder->recordToolInvocation(
                    conversationId: $conversation->id,
                    userId: (string) $conversation->user_id,
                    attemptGroupId: $this->attemptGroupId,
                    toolName: $toolName,
                    success: $toolError === null,
                    failureCategory: $toolError === null ? null : \ClarionApp\LlmClient\ValueObjects\ToolFailureCategory::fromErrorMessage($toolError),
                    agentId: $conversation->character ?: null,
                );
            }

            // Close ToolInvocation action.
            if ($toolActionId !== null && $this->runTraceRecorder !== null) {
                try {
                    $outcome = $toolError === null
                        ? \ClarionApp\LlmClient\ValueObjects\ActionOutcome::Success
                        : \ClarionApp\LlmClient\ValueObjects\ActionOutcome::Failure;
                    $this->runTraceRecorder->closeAction(
                        $toolActionId,
                        $outcome,
                        $toolError,
                        null,
                    );
                } catch (\Throwable $e) {
                    Log::warning('Failed to close tool_invocation action', [
                        'action_id' => $toolActionId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Store tool calls and results in message tool_data
        $toolData = [
            'tool_calls' => $this->toolCalls,
            'tool_results' => $toolResults,
            'iteration' => $iteration,
            'pending_confirmation' => null,
        ];

        $this->message->update([
            'content' => $this->reply ?: '',
            'tool_data' => $toolData,
        ]);

        // The round resolved without suspending — close its step. The next
        // iteration's step is opened by dispatchStreamRequest().
        $this->closeCurrentStep(\ClarionApp\LlmClient\ValueObjects\RunEndState::Completed);

        // If all tool calls were successful execute_operation calls,
        // finish the conversation — no need for a summary response from the LLM.
        $agentLoopService = app(AgentLoopService::class);
        if ($agentLoopService->allExecuteOperationsSucceeded($this->toolCalls, $toolResults)) {
            // The response ends here, so the run ends here too — matching the
            // synchronous path's equivalent exit (FR-005, FR-008).
            $this->closeRun(
                \ClarionApp\LlmClient\ValueObjects\RunEndState::Completed,
                $this->message->id,
            );

            event(new FinishOpenAIConversationResponseEvent($conversationId, ''));
            $conversation->update(['is_processing' => false]);
            $this->checkForUnprocessedMessages($conversation);
            return;
        }

        // Dispatch next iteration (requires a server for the LLM API call).
        // Carry the run_id forward so the same run continues across iterations.
        if ($conversation->server_id !== null) {
            $agentLoopService->start($conversation, $iteration + 1, $this->runId);
        } else {
            // No server to continue against: the run stops here rather than
            // staying in progress until the abandonment sweep finds it.
            $this->closeRun(
                \ClarionApp\LlmClient\ValueObjects\RunEndState::StoppedEarly,
                null,
                'No server configured to continue the agent loop',
            );
            $conversation->update(['is_processing' => false]);
        }
    }

    private function handleMaxIterationReached(Conversation $conversation): void
    {
        $errorContent = 'I\'ve reached the maximum number of iterations (' .
            config('llm-client.agent_loop.max_iterations', 20) .
            ') for this request. Please try breaking your request into smaller steps.';

        if ($this->message === null) {
            $this->message = Message::create([
                'conversation_id' => $conversation->id,
                'responseTime' => 0,
                'user' => $conversation->character,
                'role' => 'assistant',
                'content' => $errorContent,
            ]);
            event(new NewConversationMessageEvent($conversation->id, $this->message->id));
        } else {
            $this->message->update(['content' => $errorContent]);
        }

        // Close the step and run as stopped_early.
        $this->closeCurrentStep(
            \ClarionApp\LlmClient\ValueObjects\RunEndState::StoppedEarly,
            'Maximum iterations reached',
        );
        $this->closeRun(
            \ClarionApp\LlmClient\ValueObjects\RunEndState::StoppedEarly,
            null,
            'Maximum iterations reached',
        );

        event(new FinishOpenAIConversationResponseEvent($conversation->id, $errorContent));
        $conversation->update(['is_processing' => false]);
    }

    private function checkForUnprocessedMessages(Conversation $conversation): void
    {
        $latestUserMessage = Message::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->latest('created_at')
            ->first();

        $latestAssistantMessage = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->latest('created_at')
            ->first();

        if ($latestUserMessage && $latestAssistantMessage &&
            $latestUserMessage->created_at > $latestAssistantMessage->created_at) {
            $agentLoopService = app(AgentLoopService::class);

            try {
                $agentLoopService->start($conversation);
            } catch (BudgetExceededException $e) {
                // start()'s second call site, and the only one with no request
                // boundary above it: this runs inside the stream handler's own
                // queue job, mints a new run, and is therefore correctly gated
                // — but nobody is awaiting a 402 here, so an escaping exception
                // would surface only as a failed job. The gate has already
                // recorded the refusal; this catch is what turns "failed job"
                // into "recorded stop", leaving is_processing false and the
                // user's message genuinely unprocessed until the period resets
                // or the ceiling is raised.
                //
                // Deliberately NOT applied to the continuation call in
                // handleToolCalls(): that path carries the open run id and is
                // never gated in the first place.
                Log::info('AgentLoopStreamHandler: unprocessed message not started, spending ceiling reached', [
                    'conversation_id' => $conversation->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Condense a tool result if it exceeds the configured token threshold.
     */
    private function condenseToolResult(string $result, string $conversationId, string $toolName = 'unknown'): array
    {
        if (!$this->toolResultCondenser || !config('llm-client.tool_result_condensation.enabled', false)) {
            return ['content' => $result];
        }

        $condensed = $this->toolResultCondenser->condense($conversationId, $toolName, $result);

        return [
            'content' => $condensed['content'],
            'reference_id' => $condensed['reference_id'] ?? null,
            'original_tokens' => $condensed['original_tokens'] ?? null,
            'condensed_tokens' => $condensed['condensed_tokens'] ?? null,
            'method' => $condensed['method'] ?? null,
            'condensed' => $condensed['condensed'] ?? false,
        ];
    }

    /**
     * Close the current step if one is open. Never throws (delegates to recorder).
     */
    private function closeCurrentStep(
        \ClarionApp\LlmClient\ValueObjects\RunEndState $endState,
        ?string $reason = null,
    ): void {
        if ($this->runTraceRecorder !== null && $this->stepId !== null) {
            $this->runTraceRecorder->closeStep($this->stepId, $endState, $reason);
        }
    }

    /**
     * Close the current run if one is open. Links the reply message if provided.
     * Never throws (delegates to recorder).
     */
    private function closeRun(
        \ClarionApp\LlmClient\ValueObjects\RunEndState $endState,
        ?string $replyMessageId = null,
        ?string $reason = null,
    ): void {
        if ($this->runTraceRecorder !== null && $this->runId !== null) {
            $this->runTraceRecorder->closeRun($this->runId, $endState, $reason, $replyMessageId);
        }
    }
}
