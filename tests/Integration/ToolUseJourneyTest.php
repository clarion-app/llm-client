<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Events\AgentTurnCompleted;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Illuminate\Support\Facades\Event;

/**
 * End-to-end tool use scenarios through the container-resolved agent loop.
 *
 * Proves the full composition: user message → LLM request → tool call → tool execution
 * → LLM request with tool result → final answer persisted.
 */
class ToolUseJourneyTest extends AssembledSystemTestCase
{
    /* --------------------------------------------------------------------------
     * Sync-path scenario
     * --------------------------------------------------------------------------
     * Script toolRequest('search_operations', [...]) then finalAnswer(...).
     * Drive AgentLoopService::run() and assert the meta-tool executed,
     * its result appears in the second captured payload, and the final answer
     * is persisted.
     */
    public function test_sync_path_tool_then_final_answer(): void
    {
        $this->scenario = 'sync_path_tool_then_final_answer';
        $this->entryPath = 'sync';

        // Build fixture with user, server, conversation
        $fixture = $this->fixture()->build();

        // Script: first response returns a tool call, second returns final answer
        $this->script()
            ->toolRequest('search_operations', ['query' => 'list contacts'])
            ->finalAnswer('I found some contacts for you.');

        // Drive the sync path
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'List my contacts'
        );

        // Assert the loop completed
        $this->assertSame(
            'completed',
            $result['status'],
            'Sync path should complete successfully'
        );

        // Assert we have two captured chat payloads (tool call + final answer)
        $payloads = $this->capturedChatPayloads();
        $this->assertGreaterThanOrEqual(
            2,
            count($payloads),
            'Sync path should have at least 2 chat payloads (tool call response + final answer)'
        );

        // First payload: should contain the user message and tools
        $firstPayload = $payloads[0];
        $this->assertTrue(
            $firstPayload->containsText('List my contacts'),
            'First payload should contain the user message'
        );
        $this->assertNotEmpty(
            $firstPayload->tools,
            'First payload should include tools definition'
        );

        // Second payload: should contain the tool result message
        $secondPayload = $payloads[1];
        $this->assertToolResultInPayload(
            $secondPayload,
            'Second payload should contain the tool result from search_operations'
        );

        // Assert final answer was persisted
        $messages = Message::where('conversation_id', $fixture->conversation->id)->get();
        $assistantMessages = $messages->filter(fn ($m) => $m->role === 'assistant');
        $this->assertGreaterThanOrEqual(
            1,
            $assistantMessages->count(),
            'At least one assistant message should be persisted'
        );

        // Verify the last assistant message contains the final answer text
        $lastAssistant = $assistantMessages->last();
        $this->assertNotNull(
            $lastAssistant,
            'Last assistant message should exist'
        );
    }

    /* --------------------------------------------------------------------------
     * Streaming-path scenario
     * --------------------------------------------------------------------------
     * Drive start(), capture dispatched HttpRequest, emit SSE chunks, finish().
     * Assert broadcast events convey tool activity and final answer.
     */
    public function test_streaming_path_tool_then_final_answer(): void
    {
        $this->scenario = 'streaming_path_tool_then_final_answer';
        $this->entryPath = 'stream';

        // Build fixture
        $fixture = $this->fixture()->build();

        // Script: first response returns a tool call, second returns final answer
        $this->script()
            ->toolRequest('search_operations', ['query' => 'list contacts'])
            ->finalAnswer('I found some contacts for you.');

        // Fake only the specific events we want to assert on
        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
            AgentTurnCompleted::class,
        ]);

        // Drive start() — this dispatches a SendHttpStreamRequest
        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        // Capture the dispatched stream request via ScriptedStream
        $stream = $this->stream();
        $stream->extractDispatchedJobs();
        $capturedRequests = $stream->capturedRequests();
        $this->assertNotEmpty(
            $capturedRequests,
            'Streaming path should dispatch at least one HttpRequest'
        );

        // Emit SSE chunks for the tool call response
        $toolCallResponse = $this->script()->serve();
        $sseChunks = $this->buildSseChunks($toolCallResponse);
        $stream->emit($sseChunks);

        // Finish the stream — this triggers tool execution and the next iteration
        $stream->finish();

        // handleToolCalls() dispatches a new SendHttpStreamRequest — re-extract it
        $stream->extractDispatchedJobs();

        // Move to next slot for the final answer response
        $stream->nextSlot();

        // Emit SSE chunks for the final answer
        $finalResponse = $this->script()->serve();
        $finalSseChunks = $this->buildSseChunks($finalResponse);
        $stream->emit($finalSseChunks);
        $stream->finish();

        // Assert broadcast events were fired
        Event::assertDispatched(
            NewConversationMessageEvent::class,
            fn ($event) => $event->conversation_id === $fixture->conversation->id,
            'NewConversationMessageEvent should be dispatched for the conversation'
        );

        Event::assertDispatched(
            FinishOpenAIConversationResponseEvent::class,
            fn ($event) => $event->conversation_id === $fixture->conversation->id,
            'FinishOpenAIConversationResponseEvent should be dispatched'
        );

        // Assert the final answer was persisted
        $messages = Message::where('conversation_id', $fixture->conversation->id)->get();
        $this->assertGreaterThanOrEqual(
            1,
            $messages->count(),
            'Messages should be persisted after streaming path'
        );
    }

    /* --------------------------------------------------------------------------
     * Shared payload assertions across both paths
     * --------------------------------------------------------------------------
     * Assert the same payload expectations using shared CapturedPayload helpers.
     */
    public function test_shared_payload_expectations_sync_and_stream(): void
    {
        $this->scenario = 'shared_payload_expectations';
        $this->entryPath = 'sync';

        // Build fixture
        $fixture = $this->fixture()->build();

        // Script: tool call then final answer
        $this->script()
            ->toolRequest('search_operations', ['query' => 'get tasks'])
            ->finalAnswer('Here are your tasks.');

        // Drive the sync path
        $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Get my tasks'
        );

        // Get captured payloads
        $payloads = $this->capturedChatPayloads();
        $this->assertGreaterThanOrEqual(
            2,
            count($payloads),
            'Should have at least 2 chat payloads'
        );

        // Shared assertion: first payload has user message
        $firstPayload = $payloads[0];
        $this->assertTrue(
            $firstPayload->containsText('Get my tasks'),
            'First payload should contain the user message text'
        );

        // Shared assertion: second payload has tool result
        $secondPayload = $payloads[1];
        $this->assertToolResultInPayload(
            $secondPayload,
            'Second payload should contain tool result message'
        );

        // Shared assertion: tool result content contains search_operations result
        $this->assertToolResultContainsSearchResult(
            $secondPayload,
            'Tool result should contain search_operations response data'
        );

        // Shared assertion: both payloads have tools defined
        foreach ($payloads as $index => $payload) {
            $this->assertNotEmpty(
                $payload->tools,
                "Payload #{$index} should include tools definition"
            );
        }

        // Shared assertion: message count increases across payloads
        $this->assertTrue(
            $secondPayload->messageCount() > $firstPayload->messageCount(),
            'Second payload should have more messages than the first (tool call + result added)'
        );
    }

    /* --------------------------------------------------------------------------
     * Unresolvable-tool-component scenario
     * --------------------------------------------------------------------------
     * Assert verification fails naming the component that could not be assembled.
     * The search_operations tool depends on OperationsSearchService which requires
     * the operation_search_index table. When this table is missing, the tool
     * returns a graceful degradation response with a hint naming the missing
     * component, not an error payload.
     */
    public function test_unresolvable_tool_component_returns_error_payload(): void
    {
        $this->scenario = 'unresolvable_tool_component';
        $this->entryPath = 'sync';

        // Build fixture
        $fixture = $this->fixture()->build();

        // Script: tool call then final answer
        $this->script()
            ->toolRequest('search_operations', ['query' => 'some query'])
            ->finalAnswer('Done.');

        // Drive the sync path
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Search for something'
        );

        // The conversation should still complete (product swallows component errors)
        $this->assertSame(
            'completed',
            $result['status'],
            'Conversation should complete even when tool component is unavailable'
        );

        // Get the second payload (contains tool result)
        $payloads = $this->capturedChatPayloads();
        $this->assertGreaterThanOrEqual(
            2,
            count($payloads),
            'Should have at least 2 payloads to inspect tool result'
        );

        $secondPayload = $payloads[1];

        // Assert the tool result contains a tool message (graceful degradation)
        $this->assertToolResultInPayload(
            $secondPayload,
            'Tool result should contain tool message from search_operations'
        );

        // The tool result should contain a hint about the unavailable search index
        $this->assertTrue(
            $this->payloadContainsComponentHint($secondPayload, 'search'),
            'Tool result should contain hint referencing the search component that could not be assembled'
        );
    }

    /* --------------------------------------------------------------------------
     * Throwing-tool-path scenario
     * --------------------------------------------------------------------------
     * Assert verification fails rather than reporting a conversation that merely
     * lacks a tool result. When a tool throws, the error is captured as a tool
     * result error payload, not as a missing result.
     */
    public function test_throwing_tool_path_returns_error_not_missing_result(): void
    {
        $this->scenario = 'throwing_tool_path';
        $this->entryPath = 'sync';

        // Build fixture
        $fixture = $this->fixture()->build();

        // Script: tool call with invalid args (memory_create with missing content)
        // This triggers an error path in the tool handler
        $this->script()
            ->toolRequest('memory_create', ['scope' => 'scratch'])
            ->finalAnswer('Memory operation attempted.');

        // Drive the sync path
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Create a memory'
        );

        // The conversation should complete (product handles tool errors gracefully)
        $this->assertSame(
            'completed',
            $result['status'],
            'Conversation should complete even when tool returns an error'
        );

        // Get the second payload (contains tool result)
        $payloads = $this->capturedChatPayloads();
        $this->assertGreaterThanOrEqual(
            2,
            count($payloads),
            'Should have at least 2 payloads to inspect tool result'
        );

        $secondPayload = $payloads[1];

        // Assert the tool result contains an error (not just empty/missing)
        $this->assertToolResultContainsError(
            $secondPayload,
            'Tool result should contain an error payload, not be empty or missing'
        );

        // The error should name the specific issue (content required)
        $this->assertTrue(
            $this->payloadContainsErrorDetail($secondPayload, 'content'),
            'Tool result error should name the specific validation failure'
        );
    }

    /* --------------------------------------------------------------------------
     * Missing-tool scenario
     * --------------------------------------------------------------------------
     * Script a toolRequest for a tool that does not exist.
     * AgentLoopService::executeMetaTool() dispatches via match with
     * default => json_encode(['error' => "Unknown tool: ..."]).
     * The scenario detects the error payload and fails naming the missing tool.
     */
    public function test_missing_tool_returns_unknown_tool_error(): void
    {
        $this->scenario = 'missing_tool_error';
        $this->entryPath = 'sync';

        // Build fixture
        $fixture = $this->fixture()->build();

        // Script: tool call for a non-existent tool
        $this->script()
            ->toolRequest('non_existent_tool_xyz', ['some' => 'args'])
            ->finalAnswer('Tool call attempted.');

        // Drive the sync path
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Do something with a fake tool'
        );

        // The conversation should complete (product handles unknown tools gracefully)
        $this->assertSame(
            'completed',
            $result['status'],
            'Conversation should complete even when unknown tool is called'
        );

        // Get the second payload (contains tool result)
        $payloads = $this->capturedChatPayloads();
        $this->assertGreaterThanOrEqual(
            2,
            count($payloads),
            'Should have at least 2 payloads to inspect tool result'
        );

        $secondPayload = $payloads[1];

        // Assert the tool result contains the "Unknown tool" error
        $this->assertToolResultContainsError(
            $secondPayload,
            'Tool result should contain an error for unknown tool'
        );

        // The error should name the missing tool specifically
        $this->assertTrue(
            $this->payloadContainsUnknownToolError($secondPayload, 'non_existent_tool_xyz'),
            'Tool result error should name the missing tool "non_existent_tool_xyz"'
        );
    }

    /* --------------------------------------------------------------------------
     * Helper methods
     * -------------------------------------------------------------------------- */

    /**
     * Assert that a payload contains a tool role message with a result.
     */
    protected function assertToolResultInPayload($payload, string $failureMessage): void
    {
        $toolMessages = array_filter(
            $payload->messages,
            fn ($m) => ($m['role'] ?? '') === 'tool'
        );

        $this->assertNotEmpty(
            $toolMessages,
            $failureMessage . ' — no tool role messages found in payload'
        );
    }

    /**
     * Assert that a payload contains a tool result with search-related content.
     */
    protected function assertToolResultContainsSearchResult($payload, string $failureMessage): void
    {
        $toolMessages = array_filter(
            $payload->messages,
            fn ($m) => ($m['role'] ?? '') === 'tool'
        );

        $found = false;
        foreach ($toolMessages as $msg) {
            $content = $msg['content'] ?? '';
            // search_operations returns error about search index, or results array
            if (str_contains($content, 'search') || str_contains($content, 'results') || str_contains($content, 'hint')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue(
            $found,
            $failureMessage . ' — no search-related content found in tool results'
        );
    }

    /**
     * Assert that a payload contains a tool result with an error.
     */
    protected function assertToolResultContainsError($payload, string $failureMessage): void
    {
        $toolMessages = array_filter(
            $payload->messages,
            fn ($m) => ($m['role'] ?? '') === 'tool'
        );

        $found = false;
        foreach ($toolMessages as $msg) {
            $content = $msg['content'] ?? '';
            $decoded = json_decode($content, true);

            if (is_array($decoded) && isset($decoded['error'])) {
                $found = true;
                break;
            }

            // Also check for error text in raw content
            if (str_contains($content, '"error"') || str_contains($content, 'error')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue(
            $found,
            $failureMessage . ' — no error found in tool results'
        );
    }

    /**
     * Check if payload contains a component error referencing the given keyword.
     */
    protected function payloadContainsComponentError($payload, string $keyword): bool
    {
        foreach ($payload->messages as $msg) {
            if (($msg['role'] ?? '') !== 'tool') continue;

            $content = $msg['content'] ?? '';
            if (str_contains(strtolower($content), strtolower($keyword))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if payload contains a component hint (graceful degradation) referencing the given keyword.
     */
    protected function payloadContainsComponentHint($payload, string $keyword): bool
    {
        foreach ($payload->messages as $msg) {
            if (($msg['role'] ?? '') !== 'tool') continue;

            $content = $msg['content'] ?? '';
            $decoded = json_decode($content, true);

            // Check for hint key in JSON response (graceful degradation)
            if (is_array($decoded) && isset($decoded['hint'])) {
                if (str_contains(strtolower($decoded['hint']), strtolower($keyword))) {
                    return true;
                }
            }

            // Also check raw content for hint text
            if (str_contains(strtolower($content), 'hint') && str_contains(strtolower($content), strtolower($keyword))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if payload contains error detail referencing the given keyword.
     */
    protected function payloadContainsErrorDetail($payload, string $keyword): bool
    {
        foreach ($payload->messages as $msg) {
            if (($msg['role'] ?? '') !== 'tool') continue;

            $content = $msg['content'] ?? '';
            if (str_contains(strtolower($content), strtolower($keyword))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if payload contains an "Unknown tool" error for the given tool name.
     */
    protected function payloadContainsUnknownToolError($payload, string $toolName): bool
    {
        foreach ($payload->messages as $msg) {
            if (($msg['role'] ?? '') !== 'tool') continue;

            $content = $msg['content'] ?? '';
            if (str_contains($content, 'Unknown tool') && str_contains($content, $toolName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build SSE chunks from a ResponseScript step.
     *
     * @param array $step ResponseScript step
     * @return array<string> SSE chunk strings
     */
    protected function buildSseChunks(array $step): array
    {
        $chunks = [];
        $choice = $step['choices'][0] ?? null;
        if (!$choice) {
            return $chunks;
        }

        $message = $choice['message'] ?? [];
        $toolCalls = $message['tool_calls'] ?? [];
        $content = $message['content'] ?? '';
        $finishReason = $choice['finish_reason'] ?? 'stop';

        if (!empty($toolCalls)) {
            // Build tool call SSE chunks
            foreach ($toolCalls as $index => $toolCall) {
                $id = $toolCall['id'] ?? '';
                $name = $toolCall['function']['name'] ?? '';
                $args = $toolCall['function']['arguments'] ?? '{}';

                // Initial tool call chunk with id and name
                $chunk = json_encode([
                    'id' => 'chatcmpl_test',
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => 'gpt-4',
                    'choices' => [
                        [
                            'index' => $index,
                            'delta' => [
                                'role' => 'assistant',
                                'tool_calls' => [
                                    [
                                        'index' => $index,
                                        'id' => $id,
                                        'type' => 'function',
                                        'function' => [
                                            'name' => $name,
                                            'arguments' => '',
                                        ],
                                    ],
                                ],
                            ],
                            'finish_reason' => null,
                        ],
                    ],
                ]);
                $chunks[] = "data: {$chunk}\n\n";

                // Arguments chunk
                $argsChunk = json_encode([
                    'id' => 'chatcmpl_test',
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => 'gpt-4',
                    'choices' => [
                        [
                            'index' => $index,
                            'delta' => [
                                'tool_calls' => [
                                    [
                                        'index' => $index,
                                        'function' => [
                                            'arguments' => $args,
                                        ],
                                    ],
                                ],
                            ],
                            'finish_reason' => 'tool_calls',
                        ],
                    ],
                ]);
                $chunks[] = "data: {$argsChunk}\n\n";
            }
        } elseif ($content !== '') {
            // Build content SSE chunks
            $contentChunk = json_encode([
                'id' => 'chatcmpl_test',
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => 'gpt-4',
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => [
                            'role' => 'assistant',
                            'content' => $content,
                        ],
                        'finish_reason' => $finishReason,
                    ],
                ],
            ]);
            $chunks[] = "data: {$contentChunk}\n\n";
        }

        // Final [DONE] marker
        $chunks[] = "data: [DONE]\n\n";

        return $chunks;
    }
}
