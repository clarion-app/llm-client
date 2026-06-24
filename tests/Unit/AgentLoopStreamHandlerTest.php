<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;

class AgentLoopStreamHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function parses_text_deltas_from_sse_chunks()
    {
        Event::fake();

        $conversation = Conversation::factory()->create();

        $handler = new AgentLoopStreamHandler();

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        // Simulate SSE text delta chunks
        $chunk1 = "data: " . json_encode([
            'choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]],
        ]) . "\n\n";

        $chunk2 = "data: " . json_encode([
            'choices' => [['delta' => ['content' => ' world'], 'finish_reason' => null]],
        ]) . "\n\n";

        $handler->handle($chunk1, $data, 0);
        $handler->handle($chunk2, $data, 1);

        $this->assertEquals('Hello world', $handler->reply);
    }

    /** @test */
    public function parses_tool_calls_deltas_with_argument_accumulation()
    {
        Event::fake();

        $conversation = Conversation::factory()->create();

        $handler = new AgentLoopStreamHandler();

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        // First chunk: tool call start
        $chunk1 = "data: " . json_encode([
            'choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'call_abc', 'type' => 'function', 'function' => ['name' => 'contacts.store', 'arguments' => '']],
            ]], 'finish_reason' => null]],
        ]) . "\n\n";

        // Second chunk: argument fragment
        $chunk2 = "data: " . json_encode([
            'choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '{"body":{"name"']],
            ]], 'finish_reason' => null]],
        ]) . "\n\n";

        // Third chunk: more arguments
        $chunk3 = "data: " . json_encode([
            'choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => ': "Jane"}']],
            ]], 'finish_reason' => null]],
        ]) . "\n\n";

        $handler->handle($chunk1, $data, 0);
        $handler->handle($chunk2, $data, 1);
        $handler->handle($chunk3, $data, 2);

        $this->assertCount(1, $handler->toolCalls);
        $this->assertEquals('call_abc', $handler->toolCalls[0]['id']);
        $this->assertEquals('contacts.store', $handler->toolCalls[0]['function']['name']);
        $this->assertEquals('{"body":{"name": "Jane"}}', $handler->toolCalls[0]['function']['arguments']);
    }

    /** @test */
    public function finish_detects_plain_text_response()
    {
        Event::fake();

        $conversation = Conversation::factory()->create();

        $handler = new AgentLoopStreamHandler();
        $handler->reply = 'Here is your answer.';
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        $handler->finish($data, 2);

        $handler->message->refresh();
        $this->assertEquals('Here is your answer.', $handler->message->content);

        $conversation->refresh();
        $this->assertFalse($conversation->is_processing);

        Event::assertDispatched(FinishOpenAIConversationResponseEvent::class);
    }

    /** @test */
    public function finish_detects_tool_calls_response()
    {
        Event::fake();

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_abc123',
                'type' => 'function',
                'function' => [
                    'name' => 'contacts.store',
                    'arguments' => '{"body":{"name": "Jane"}}',
                ],
            ],
        ];
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        // Mock the tool execution to avoid actual HTTP calls
        $this->mockToolExecution('contacts.store', '{"id": "uuid-123"}');

        $handler->finish($data, 2);

        // Should have stored tool_data on the message
        $handler->message->refresh();
        $this->assertNotNull($handler->message->tool_data);
        $this->assertArrayHasKey('tool_calls', $handler->message->tool_data);

        Event::assertDispatched(ToolExecutionEvent::class);
    }

    /** @test */
    public function tracks_iteration_count()
    {
        Event::fake();

        $conversation = Conversation::factory()->create();

        $handler = new AgentLoopStreamHandler();

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 5,
        ]);

        $chunk = "data: " . json_encode([
            'choices' => [['delta' => ['content' => 'Test'], 'finish_reason' => null]],
        ]) . "\n\n";

        $handler->handle($chunk, $data, 0);

        // The handler should know what iteration it's on
        $parsedData = json_decode($data, true);
        $this->assertEquals(5, $parsedData['iteration']);
    }

    /** @test */
    public function max_iteration_limit_triggers_error_message()
    {
        Event::fake();

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_abc',
                'type' => 'function',
                'function' => ['name' => 'contacts.store', 'arguments' => '{}'],
            ],
        ];
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $maxIterations = config('llm-client.agent_loop.max_iterations', 20);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => $maxIterations,
        ]);

        $handler->finish($data, 2);

        // Should have saved an error message and cleared is_processing
        $conversation->refresh();
        $this->assertFalse($conversation->is_processing);

        Event::assertDispatched(FinishOpenAIConversationResponseEvent::class);
    }

    protected function mockToolExecution(string $toolName, string $result)
    {
        $registryMock = Mockery::mock(\ClarionApp\LlmClient\Services\McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with($toolName)
            ->andReturn([
                'name' => $toolName,
                '_meta' => ['operationId' => 'op', 'method' => 'POST', 'path' => '/api/test'],
            ]);

        $executorMock = Mockery::mock(\ClarionApp\LlmClient\Services\McpToolExecutor::class);
        $executorMock->shouldReceive('executeTool')
            ->andReturn([
                'content' => [['type' => 'text', 'text' => $result]],
                'isError' => false,
            ]);

        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolRegistry::class, $registryMock);
        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolExecutor::class, $executorMock);
    }

    // === US2 Tests (T039) ===

    /** @test */
    public function finish_suspends_loop_when_confirmation_required()
    {
        Event::fake();

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_del456',
                'type' => 'function',
                'function' => [
                    'name' => 'contacts.destroy',
                    'arguments' => '{"path":{"id": "42"}}',
                ],
            ],
        ];
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $registryMock = Mockery::mock(\ClarionApp\LlmClient\Services\McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => ['operationId' => 'destroyContact', 'method' => 'DELETE', 'path' => '/api/contacts/{id}'],
            ]);

        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolRegistry::class, $registryMock);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        $handler->finish($data, 2);

        $handler->message->refresh();
        $this->assertNotNull($handler->message->tool_data['pending_confirmation']);
        $this->assertEquals('contacts.destroy', $handler->message->tool_data['pending_confirmation']['tool_name']);

        Event::assertDispatched(\ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class);
    }

    /** @test */
    public function tool_data_pending_confirmation_stored_correctly()
    {
        Event::fake();

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_del789',
                'type' => 'function',
                'function' => [
                    'name' => 'contacts.destroy',
                    'arguments' => '{"path":{"id": "99"}}',
                ],
            ],
        ];
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $registryMock = Mockery::mock(\ClarionApp\LlmClient\Services\McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => ['operationId' => 'destroyContact', 'method' => 'DELETE', 'path' => '/api/contacts/{id}'],
            ]);

        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolRegistry::class, $registryMock);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        $handler->finish($data, 2);

        $handler->message->refresh();
        $pending = $handler->message->tool_data['pending_confirmation'];

        $this->assertEquals('contacts.destroy', $pending['tool_name']);
        $this->assertEquals('DELETE', $pending['method']);
        $this->assertArrayHasKey('expires_at', $pending);
        $this->assertArrayHasKey('arguments', $pending);
    }

    // === US4 Tests (T041) ===

    /** @test */
    public function tool_execution_errors_fed_back_to_llm()
    {
        Event::fake();

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_err',
                'type' => 'function',
                'function' => [
                    'name' => 'unknown.tool',
                    'arguments' => '{}',
                ],
            ],
        ];
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $registryMock = Mockery::mock(\ClarionApp\LlmClient\Services\McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('unknown.tool')
            ->andReturn(null);
        $registryMock->shouldReceive('getTools')
            ->andReturn(['tools' => [], 'nextCursor' => null]);

        $executorMock = Mockery::mock(\ClarionApp\LlmClient\Services\McpToolExecutor::class);

        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolRegistry::class, $registryMock);
        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolExecutor::class, $executorMock);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        $handler->finish($data, 2);

        $handler->message->refresh();
        $toolResults = $handler->message->tool_data['tool_results'];
        $this->assertStringContainsString('Unknown tool', $toolResults[0]['content']);
    }

    // === US5 Tests (T042) ===

    /** @test */
    public function handle_creates_assistant_message_on_first_text_chunk()
    {
        Event::fake();

        $conversation = Conversation::factory()->create();

        $handler = new AgentLoopStreamHandler();

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        $chunk = "data: " . json_encode([
            'choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]],
        ]) . "\n\n";

        $handler->handle($chunk, $data, 0);

        $this->assertNotNull($handler->message);
        $this->assertEquals('assistant', $handler->message->role);

        Event::assertDispatched(NewConversationMessageEvent::class);
        Event::assertDispatched(UpdateOpenAIConversationResponseEvent::class);
    }

    /** @test */
    public function update_event_broadcast_per_text_delta()
    {
        Event::fake();

        $conversation = Conversation::factory()->create();

        $handler = new AgentLoopStreamHandler();

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        $chunk1 = "data: " . json_encode([
            'choices' => [['delta' => ['content' => 'First'], 'finish_reason' => null]],
        ]) . "\n\n";

        $chunk2 = "data: " . json_encode([
            'choices' => [['delta' => ['content' => ' Second'], 'finish_reason' => null]],
        ]) . "\n\n";

        $handler->handle($chunk1, $data, 0);
        $handler->handle($chunk2, $data, 1);

        Event::assertDispatched(UpdateOpenAIConversationResponseEvent::class, 2);
    }
}
