<?php

namespace ClarionApp\LlmClient;

use ClarionApp\HttpQueue\HandleHttpStreamResponse;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent;
use Illuminate\Support\Facades\Log;

class AgentLoopStreamHandler extends HandleHttpStreamResponse
{
    public string $buffer = "\n\n";
    public string $reply = "";
    public ?Message $message = null;
    public array $toolCalls = [];

    public function handle($content, $data, $seconds)
    {
        $parsedData = is_string($data) ? json_decode($data, true) : $data;
        $conversationId = $parsedData['conversation_id'] ?? null;

        $conversation = Conversation::find($conversationId);
        if (!$conversation) return;

        $this->buffer .= $content;

        // Log raw chunks for debugging
        // Log::debug('AgentLoopStreamHandler: raw chunk', ['content' => $content]);

        $check = explode("\n\ndata: ", $this->buffer);

        while (count($check) > 1) {
            $chunk = array_shift($check);
            $this->buffer = implode("\n\ndata: ", $check);

            $chunk = trim($chunk);
            if ($chunk === '[DONE]') continue;

            $json = json_decode($chunk, true);
            if ($json === null) continue;

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

        $conversation = Conversation::find($conversationId);
        if (!$conversation) return;

        $maxIterations = config('llm-client.agent_loop.max_iterations', 20);

        // If we have tool calls to execute
        if (!empty($this->toolCalls)) {
            // Check iteration limit
            if ($iteration >= $maxIterations) {
                $this->handleMaxIterationReached($conversation);
                return;
            }

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

        $this->message->content = $this->reply;
        $this->message->responseTime = $seconds;
        $this->message->save();

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

                    return; // Suspend for confirmation
                }
            }

            $toolResults[] = [
                'tool_call_id' => $toolCallId,
                'content' => $result,
            ];

            event(new ToolExecutionEvent($conversationId, $toolName, 'completed'));
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

        // If all tool calls were successful execute_operation calls,
        // finish the conversation — no need for a summary response from the LLM.
        $agentLoopService = app(AgentLoopService::class);
        if ($agentLoopService->allExecuteOperationsSucceeded($this->toolCalls, $toolResults)) {
            event(new FinishOpenAIConversationResponseEvent($conversationId, ''));
            $conversation->update(['is_processing' => false]);
            $this->checkForUnprocessedMessages($conversation);
            return;
        }

        // Dispatch next iteration (requires a server for the LLM API call)
        if ($conversation->server_id !== null) {
            $agentLoopService->start($conversation, $iteration + 1);
        } else {
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
            $agentLoopService->start($conversation);
        }
    }
}
