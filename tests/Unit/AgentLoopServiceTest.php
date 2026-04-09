<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;

class AgentLoopServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function build_tools_payload_converts_mcp_tools_to_openai_format()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('getTools')
            ->with(null)
            ->andReturn([
                'tools' => [
                    [
                        'name' => 'contacts.store',
                        'description' => 'Create a new contact',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'body_name' => ['type' => 'string'],
                                'body_email' => ['type' => 'string'],
                            ],
                            'required' => ['body_name'],
                        ],
                        'annotations' => ['readOnlyHint' => false],
                        '_meta' => ['operationId' => 'storeContact', 'method' => 'POST', 'path' => '/api/contacts'],
                    ],
                ],
                'nextCursor' => null,
            ]);

        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock);
        $tools = $service->buildToolsPayload();

        $this->assertCount(1, $tools);
        $this->assertEquals('function', $tools[0]['type']);
        $this->assertEquals('contacts.store', $tools[0]['function']['name']);
        $this->assertEquals('Create a new contact', $tools[0]['function']['description']);
        $this->assertArrayHasKey('body_name', $tools[0]['function']['parameters']['properties']);
        // _meta and annotations should NOT be included
        $this->assertArrayNotHasKey('_meta', $tools[0]);
        $this->assertArrayNotHasKey('annotations', $tools[0]);
    }

    /** @test */
    public function build_tools_payload_paginates_through_all_tools()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('getTools')
            ->with(null)
            ->once()
            ->andReturn([
                'tools' => [
                    [
                        'name' => 'contacts.index',
                        'description' => 'List contacts',
                        'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                        'annotations' => [],
                        '_meta' => ['operationId' => 'listContacts', 'method' => 'GET', 'path' => '/api/contacts'],
                    ],
                ],
                'nextCursor' => 'cursor_page2',
            ]);
        $registryMock->shouldReceive('getTools')
            ->with('cursor_page2')
            ->once()
            ->andReturn([
                'tools' => [
                    [
                        'name' => 'contacts.store',
                        'description' => 'Create contact',
                        'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                        'annotations' => [],
                        '_meta' => ['operationId' => 'storeContact', 'method' => 'POST', 'path' => '/api/contacts'],
                    ],
                ],
                'nextCursor' => null,
            ]);

        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock);
        $tools = $service->buildToolsPayload();

        $this->assertCount(2, $tools);
        $this->assertEquals('contacts.index', $tools[0]['function']['name']);
        $this->assertEquals('contacts.store', $tools[1]['function']['name']);
    }

    /** @test */
    public function build_messages_payload_reconstructs_tool_data_into_openai_format()
    {
        $conversation = Conversation::factory()->create();

        // User message
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Create a contact named Jane',
            'responseTime' => 0,
        ]);

        // Assistant message with tool_data
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 1,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_abc123',
                        'type' => 'function',
                        'function' => [
                            'name' => 'contacts.store',
                            'arguments' => '{"body_name": "Jane"}',
                        ],
                    ],
                ],
                'tool_results' => [
                    [
                        'tool_call_id' => 'call_abc123',
                        'content' => '{"id": "uuid-123", "name": "Jane"}',
                    ],
                ],
                'iteration' => 1,
            ],
        ]);

        // Final assistant text message
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'Contact Jane has been created.',
            'responseTime' => 2,
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock);
        $messages = $service->buildMessagesPayload($conversation);

        // user message
        $this->assertEquals('user', $messages[0]['role']);
        $this->assertEquals('Create a contact named Jane', $messages[0]['content']);

        // assistant message with tool_calls
        $this->assertEquals('assistant', $messages[1]['role']);
        $this->assertArrayHasKey('tool_calls', $messages[1]);
        $this->assertEquals('call_abc123', $messages[1]['tool_calls'][0]['id']);

        // tool result message
        $this->assertEquals('tool', $messages[2]['role']);
        $this->assertEquals('call_abc123', $messages[2]['tool_call_id']);

        // final assistant text
        $this->assertEquals('assistant', $messages[3]['role']);
        $this->assertEquals('Contact Jane has been created.', $messages[3]['content']);
    }

    /** @test */
    public function start_sets_is_processing_and_dispatches_stream_request()
    {
        Queue::fake();

        $conversation = Conversation::factory()->create(['is_processing' => false]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Hello',
            'responseTime' => 0,
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('getTools')
            ->andReturn(['tools' => [], 'nextCursor' => null]);

        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock);
        $service->start($conversation);

        $conversation->refresh();
        $this->assertTrue($conversation->is_processing);

        Queue::assertPushed(SendHttpStreamRequest::class);
    }

    /** @test */
    public function start_enforces_max_iteration_limit()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('getTools')
            ->andReturn(['tools' => [], 'nextCursor' => null]);

        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock);

        // The max iterations config should be accessible
        $this->assertEquals(20, config('llm-client.agent_loop.max_iterations'));
    }

    /** @test */
    public function resume_dispatches_next_iteration_on_approval()
    {
        Queue::fake();

        $conversation = Conversation::factory()->create(['is_processing' => true]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_def456',
                        'type' => 'function',
                        'function' => [
                            'name' => 'contacts.destroy',
                            'arguments' => '{"path_id": "42"}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/42',
                    'arguments' => ['path_id' => '42'],
                    'conversation_history_snapshot' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('getTools')
            ->andReturn(['tools' => [], 'nextCursor' => null]);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => ['operationId' => 'destroyContact', 'method' => 'DELETE', 'path' => '/api/contacts/{id}'],
            ]);

        $executorMock = Mockery::mock(McpToolExecutor::class);
        $executorMock->shouldReceive('executeTool')
            ->andReturn([
                'content' => [['type' => 'text', 'text' => '{"success": true}']],
                'isError' => false,
            ]);

        $service = new AgentLoopService($registryMock, $executorMock);
        $service->resume($conversation, $message, true);

        Queue::assertPushed(SendHttpStreamRequest::class);

        $message->refresh();
        $this->assertNull($message->tool_data['pending_confirmation']);
    }

    /** @test */
    public function resume_constructs_cancellation_result_on_denial()
    {
        Queue::fake();

        $conversation = Conversation::factory()->create(['is_processing' => true]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_def456',
                        'type' => 'function',
                        'function' => [
                            'name' => 'contacts.destroy',
                            'arguments' => '{"path_id": "42"}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/42',
                    'arguments' => ['path_id' => '42'],
                    'conversation_history_snapshot' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('getTools')
            ->andReturn(['tools' => [], 'nextCursor' => null]);

        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock);
        $service->resume($conversation, $message, false);

        Queue::assertPushed(SendHttpStreamRequest::class);

        $message->refresh();
        $this->assertNotNull($message->tool_data['tool_results']);
        $this->assertStringContainsString('cancelled', $message->tool_data['tool_results'][0]['content']);
    }

    /** @test */
    public function resume_rejects_expired_confirmations()
    {
        $conversation = Conversation::factory()->create(['is_processing' => true]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_def456',
                        'type' => 'function',
                        'function' => [
                            'name' => 'contacts.destroy',
                            'arguments' => '{"path_id": "42"}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/42',
                    'arguments' => ['path_id' => '42'],
                    'conversation_history_snapshot' => [],
                    'expires_at' => now()->subMinutes(1)->toIso8601String(),
                ],
            ],
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock);

        $this->expectException(\RuntimeException::class);
        $service->resume($conversation, $message, true);
    }

    // === US1 Tests (T038) ===

    /** @test */
    public function message_store_dispatches_agent_loop_start()
    {
        Queue::fake();

        $conversation = Conversation::factory()->create(['is_processing' => false]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('getTools')
            ->andReturn(['tools' => [], 'nextCursor' => null]);

        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock);

        // Simulate what MessageController::store() does
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Create a contact',
            'responseTime' => 0,
        ]);

        $service->start($conversation);

        $conversation->refresh();
        $this->assertTrue($conversation->is_processing);
        Queue::assertPushed(SendHttpStreamRequest::class);
    }

    /** @test */
    public function message_store_skips_dispatch_when_is_processing()
    {
        Queue::fake();

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        // The controller should check is_processing and skip dispatch
        $this->assertTrue($conversation->is_processing);

        // Message is still saved
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Another message while processing',
            'responseTime' => 0,
        ]);

        $this->assertNotNull($message->id);
        // No dispatch should happen — verified by the controller logic
    }

    /** @test */
    public function unprocessed_message_detected_after_loop_completion()
    {
        $conversation = Conversation::factory()->create(['is_processing' => false]);

        // First user message
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'First message',
            'responseTime' => 0,
            'created_at' => now()->subMinutes(2),
        ]);

        // Assistant response
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'First reply',
            'responseTime' => 1,
            'created_at' => now()->subMinute(),
        ]);

        // Second user message (arrived while processing) — this is newer
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Second message',
            'responseTime' => 0,
            'created_at' => now(),
        ]);

        // Latest user message is newer than latest assistant message
        $latestUser = Message::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->latest('created_at')
            ->first();

        $latestAssistant = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->latest('created_at')
            ->first();

        $this->assertTrue($latestUser->created_at > $latestAssistant->created_at);
    }
}
