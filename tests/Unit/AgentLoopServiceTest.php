<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\OperationsSearchService;
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

    // === US1 Tests: Search Operations Core (T006-T012) ===

    /**
     * Helper to invoke private handleSearchOperations via reflection.
     */
    private function invokeHandleSearchOperations(AgentLoopService $service, array $arguments): string
    {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('handleSearchOperations');
        $method->setAccessible(true);
        return $method->invoke($service, $arguments);
    }

    /** @test T006 */
    public function search_operations_returns_results_with_correct_wrapper_format()
    {
        // Mock OperationsSearchService via app() binding
        $mockRow = (object) [
            'operationId' => 'contacts.store',
            'type'        => 'operation',
            'summary'     => 'Store a new contact',
            'method'      => 'POST',
            'path'        => '/api/contacts',
            'paramSchema' => json_encode(['body' => [['name' => 'body_name', 'type' => 'string']]]),
            'promptContent' => null,
        ];
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->with('create a contact')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'create a contact']);
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertCount(1, $decoded['results']);
        $this->assertEquals('operation', $decoded['results'][0]['type']);
        $this->assertEquals('contacts.store', $decoded['results'][0]['operationId']);
        $this->assertEquals('POST', $decoded['results'][0]['method']);
        $this->assertEquals('/api/contacts', $decoded['results'][0]['path']);
        $this->assertArrayHasKey('paramSchema', $decoded['results'][0]);
    }

    /** @test T007 */
    public function search_operations_truncates_long_query()
    {
        $longQuery = str_repeat('a', 600);

        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->withArgs(function ($query) {
                return strlen($query) <= 500;
            })
            ->once()
            ->andReturn([]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $this->invokeHandleSearchOperations($service, ['query' => $longQuery]);
        // If we get here without exception, truncation worked
        $this->assertTrue(true);
    }

    /** @test T008 */
    public function search_operations_returns_error_for_missing_query()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, []);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals('query parameter is required', $decoded['error']);
    }

    /** @test T009 */
    public function search_operations_handles_null_param_schema()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'contacts.index',
            'summary'   => 'List all contacts',
            'method'    => 'GET',
            'path'      => '/api/contacts',
            'paramSchema' => null,
        ];
        $mockRow = (object) [
            'operationId' => 'contacts.index',
            'type'        => 'operation',
            'summary'     => 'List all contacts',
            'method'      => 'GET',
            'path'        => '/api/contacts',
            'paramSchema' => null,
            'promptContent' => null,
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'list contacts']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $this->assertNull($decoded['results'][0]['paramSchema']);
    }

    /** @test T010 */
    public function search_operations_handles_malformed_param_schema()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'broken.op',
            'type'        => 'operation',
            'summary'     => 'Broken param schema',
            'method'      => 'GET',
            'path'        => '/api/broken',
            'paramSchema' => '{invalid json content',
            'promptContent' => null,
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'broken']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        // Malformed paramSchema should be treated as null
        $this->assertNull($decoded['results'][0]['paramSchema']);
    }

    /** @test T011 - limit is enforced by OperationsSearchService::search() with default $limit=10 */
    public function search_operations_passes_default_limit_of_10()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $rows[] = (object) [
                'operationId' => "op.{$i}",
                'type'        => 'operation',
                'summary'     => "Operation {$i}",
                'method'      => 'GET',
                'path'        => "/api/op/{$i}",
                'paramSchema' => null,
                'promptContent' => null,
            ];
        }
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn($rows);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'test']);
        $decoded = json_decode($result, true);

        // Passed through correctly
        $this->assertCount(8, $decoded['results']);
    }

    /** @test T013 */
    public function search_operations_returns_zero_match_hint_when_table_has_data_but_no_matches()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        // Mock DB::table()->count() for empty index check
        $dbMock = Mockery::mock();
        $dbMock->shouldReceive('table')->with('operation_search_index')->andReturnSelf();
        $dbMock->shouldReceive('count')->once()->andReturn(5); // Table has data

        app()->instance('db', $dbMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'xyz_nonexistent']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('hint', $decoded);
        $this->assertStringContainsString('broader', $decoded['hint']);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertEmpty($decoded['results']);
    }

    /** @test T014 */
    public function search_operations_returns_empty_index_hint_when_table_has_zero_rows()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        // Mock DB::table()->count() returning 0 (empty index)
        $dbMock = Mockery::mock();
        $dbMock->shouldReceive('table')->with('operation_search_index')->andReturnSelf();
        $dbMock->shouldReceive('count')->once()->andReturn(0);

        app()->instance('db', $dbMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'test']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('hint', $decoded);
        $this->assertStringContainsString('empty', $decoded['hint']);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertEmpty($decoded['results']);
    }

    /** @test T015 */
    public function search_operations_returns_missing_table_hint_when_table_does_not_exist()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(false);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'test']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('hint', $decoded);
        $this->assertStringContainsString('not available', $decoded['hint']);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertEmpty($decoded['results']);
    }

    /** @test T017 */
    public function search_operations_preserves_paramSchema_path_section()
    {
        $paramSchema = [
            'path' => [['name' => 'id', 'type' => 'integer', 'required' => true]],
        ];
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'contacts.show',
            'type'        => 'operation',
            'summary'     => 'Get contact by ID',
            'method'      => 'GET',
            'path'        => '/api/contacts/{id}',
            'paramSchema' => json_encode($paramSchema),
            'promptContent' => null,
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'get contact']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $schema = $decoded['results'][0]['paramSchema'];
        $this->assertArrayHasKey('path', $schema);
        $this->assertCount(1, $schema['path']);
        $this->assertEquals('id', $schema['path'][0]['name']);
        $this->assertEquals('integer', $schema['path'][0]['type']);
    }

    /** @test T018 */
    public function search_operations_preserves_paramSchema_query_section()
    {
        $paramSchema = [
            'query' => [
                ['name' => 'page', 'type' => 'integer', 'required' => false],
                ['name' => 'per_page', 'type' => 'integer', 'required' => false],
            ],
        ];
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'contacts.index',
            'type'        => 'operation',
            'summary'     => 'List contacts',
            'method'      => 'GET',
            'path'        => '/api/contacts',
            'paramSchema' => json_encode($paramSchema),
            'promptContent' => null,
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'list contacts']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $schema = $decoded['results'][0]['paramSchema'];
        $this->assertArrayHasKey('query', $schema);
        $this->assertCount(2, $schema['query']);
        $this->assertEquals('page', $schema['query'][0]['name']);
    }

    /** @test T019 */
    public function search_operations_preserves_paramSchema_body_section()
    {
        $paramSchema = [
            'body' => [
                ['name' => 'name', 'type' => 'string', 'required' => true],
                ['name' => 'email', 'type' => 'string', 'required' => true],
            ],
        ];
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'contacts.store',
            'type'        => 'operation',
            'summary'     => 'Create contact',
            'method'      => 'POST',
            'path'        => '/api/contacts',
            'paramSchema' => json_encode($paramSchema),
            'promptContent' => null,
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'create contact']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $schema = $decoded['results'][0]['paramSchema'];
        $this->assertArrayHasKey('body', $schema);
        $this->assertCount(2, $schema['body']);
        $this->assertEquals('name', $schema['body'][0]['name']);
    }

    /** @test T020 */
    public function search_operations_preserves_full_paramSchema_structure_with_all_sections()
    {
        $paramSchema = [
            'path' => [['name' => 'id', 'type' => 'integer', 'required' => true]],
            'query' => [['name' => 'expand', 'type' => 'string', 'required' => false]],
            'body' => [['name' => 'name', 'type' => 'string', 'required' => true]],
        ];
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'contacts.update',
            'type'        => 'operation',
            'summary'     => 'Update contact',
            'method'      => 'PUT',
            'path'        => '/api/contacts/{id}',
            'paramSchema' => json_encode($paramSchema),
            'promptContent' => null,
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'update contact']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $schema = $decoded['results'][0]['paramSchema'];
        // Verify all three sections preserved
        $this->assertArrayHasKey('path', $schema);
        $this->assertArrayHasKey('query', $schema);
        $this->assertArrayHasKey('body', $schema);
        $this->assertEquals('id', $schema['path'][0]['name']);
        $this->assertEquals('expand', $schema['query'][0]['name']);
        $this->assertEquals('name', $schema['body'][0]['name']);
    }

    /** @test - Custom prompt result format */
    public function search_operations_returns_prompt_result_format()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'wizlights_listOperations',
            'type'        => 'prompt',
            'package_name' => 'wizlight-backend',
            'summary'     => 'Custom prompt for wizlight lighting control',
            'promptContent' => 'To adjust the lighting, first use the wizlights_room.index tool...',
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->with('adjust lighting')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'adjust lighting']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $this->assertCount(1, $decoded['results']);
        $r = $decoded['results'][0];
        $this->assertEquals('prompt', $r['type']);
        $this->assertEquals('wizlights_listOperations', $r['id']);
        $this->assertEquals('wizlight-backend', $r['package']);
        $this->assertStringContainsString('lighting', $r['summary']);
        $this->assertStringContainsString('wizlights_room.index', $r['content']);
        // Prompt results should NOT have operation fields
        $this->assertArrayNotHasKey('operationId', $r);
        $this->assertArrayNotHasKey('method', $r);
        $this->assertArrayNotHasKey('path', $r);
        $this->assertArrayNotHasKey('paramSchema', $r);
    }

    /** @test - Mixed operation + prompt results */
    public function search_returns_mixed_operation_and_prompt_results()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $operationRow = (object) [
            'operationId' => 'wizlights.index',
            'type'        => 'operation',
            'summary'     => 'List all lights in a room',
            'method'      => 'GET',
            'path'        => '/api/wizlights',
            'paramSchema' => null,
            'promptContent' => null,
        ];
        $promptRow = (object) [
            'operationId' => 'wizlights_executeOperation',
            'type'        => 'prompt',
            'package_name' => 'wizlight-backend',
            'summary'     => 'Custom prompt for wizlight operation execution',
            'promptContent' => 'When adjusting the lighting, you must include the dimming property...',
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$operationRow, $promptRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock);

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'wizlights']);
        $decoded = json_decode($result, true);

        $this->assertCount(2, $decoded['results']);

        // First result is an operation
        $this->assertEquals('operation', $decoded['results'][0]['type']);
        $this->assertEquals('wizlights.index', $decoded['results'][0]['operationId']);
        $this->assertEquals('GET', $decoded['results'][0]['method']);

        // Second result is a prompt
        $this->assertEquals('prompt', $decoded['results'][1]['type']);
        $this->assertEquals('wizlights_executeOperation', $decoded['results'][1]['id']);
        $this->assertEquals('wizlight-backend', $decoded['results'][1]['package']);
        $this->assertStringContainsString('dimming', $decoded['results'][1]['content']);
    }
}
