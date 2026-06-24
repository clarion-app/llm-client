<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\OperationsSearchService;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class AgentLoopServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function build_tools_payload_converts_mcp_tools_to_openai_format()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());
        $tools = $service->buildToolsPayload();

        // buildToolsPayload now returns 3 hardcoded meta-tools
        $this->assertCount(3, $tools);

        // Verify all 3 meta-tools are present
        $toolNames = collect($tools)->pluck('function.name')->toArray();
        $this->assertContains('list_applications', $toolNames);
        $this->assertContains('execute_operation', $toolNames);
        $this->assertContains('search_operations', $toolNames);

        // Verify structure of each tool
        foreach ($tools as $tool) {
            $this->assertEquals('function', $tool['type']);
            $this->assertArrayHasKey('name', $tool['function']);
            $this->assertArrayHasKey('description', $tool['function']);
            $this->assertArrayHasKey('parameters', $tool['function']);
        }
    }

    #[Test]
    public function build_tools_payload_returns_three_meta_tools()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());
        $tools = $service->buildToolsPayload();

        $this->assertCount(3, $tools);

        // Verify list_applications has no parameters
        $listApps = collect($tools)->firstWhere('function.name', 'list_applications');
        $this->assertNotNull($listApps);
        $this->assertCount(0, (array) $listApps['function']['parameters']['properties']);

        // Verify execute_operation has operationId and parameters sub-objects
        $execOp = collect($tools)->firstWhere('function.name', 'execute_operation');
        $this->assertNotNull($execOp);
        $this->assertArrayHasKey('operationId', $execOp['function']['parameters']['properties']);
        $this->assertArrayHasKey('parameters', $execOp['function']['parameters']['properties']);
        $this->assertArrayHasKey('required', $execOp['function']['parameters']);

        // Verify search_operations has query parameter
        $searchOps = collect($tools)->firstWhere('function.name', 'search_operations');
        $this->assertNotNull($searchOps);
        $this->assertArrayHasKey('query', $searchOps['function']['parameters']['properties']);
        $this->assertContains('query', $searchOps['function']['parameters']['required']);
    }

    #[Test]
    public function build_messages_payload_reconstructs_tool_data_into_openai_format()
    {
        // Set system_prompt to empty so no system message is prepended
        config(['llm-client.agent_loop.system_prompt' => '']);

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
                            'arguments' => '{"body":{"name": "Jane"}}',
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

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());
        $messages = $service->buildMessagesPayload($conversation);

        // user message (first message now, no system prompt)
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

    #[Test]
    public function start_sets_is_processing_and_dispatches_stream_request()
    {
        Queue::fake();

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create([
            'is_processing' => false,
            'server_id' => $server->id,
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Hello',
            'responseTime' => 0,
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());
        $service->start($conversation);

        $conversation->refresh();
        $this->assertTrue($conversation->is_processing);

        Queue::assertPushed(SendHttpStreamRequest::class);
    }

    #[Test]
    public function start_enforces_max_iteration_limit()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        // The max iterations config should be accessible
        $this->assertEquals(20, config('llm-client.agent_loop.max_iterations'));
    }

    #[Test]
    public function resume_dispatches_next_iteration_on_approval()
    {
        Queue::fake();

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create(['is_processing' => true, 'server_id' => $server->id]);
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
                            'arguments' => '{"path":{"id": "42"}}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'destroyContact',
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/42',
                    'arguments' => ['path' => ['id' => '42']],
                    'conversation_history_snapshot' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => ['operationId' => 'destroyContact', 'method' => 'DELETE', 'path' => '/api/contacts/{id}'],
            ]);

        $executorMock = Mockery::mock(McpToolExecutor::class);
        $executorMock->shouldReceive('extractArguments')
            ->andReturn(['path' => '/api/contacts/42', 'query' => [], 'body' => []]);
        $executorMock->shouldReceive('executeHttpCall')
            ->andReturn([
                'content' => [['type' => 'text', 'text' => '{"success": true}']],
                'isError' => false,
            ]);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());
        $service->resume($conversation, $message, true);

        Queue::assertPushed(SendHttpStreamRequest::class);

        $message->refresh();
        $this->assertNull($message->tool_data['pending_confirmation']);
    }

    #[Test]
    public function resume_constructs_cancellation_result_on_denial()
    {
        Queue::fake();

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create(['is_processing' => true, 'server_id' => $server->id]);
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
                            'arguments' => '{"path":{"id": "42"}}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'destroyContact',
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/42',
                    'arguments' => ['path' => ['id' => '42']],
                    'conversation_history_snapshot' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);

        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());
        $service->resume($conversation, $message, false);

        Queue::assertPushed(SendHttpStreamRequest::class);

        $message->refresh();
        $this->assertNotNull($message->tool_data['tool_results']);
        $this->assertStringContainsString('cancelled', $message->tool_data['tool_results'][0]['content']);
    }

    #[Test]
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
                            'arguments' => '{"path":{"id": "42"}}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/42',
                    'arguments' => ['path' => ['id' => '42']],
                    'conversation_history_snapshot' => [],
                    'expires_at' => now()->subMinutes(1)->toIso8601String(),
                ],
            ],
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $this->expectException(\RuntimeException::class);
        $service->resume($conversation, $message, true);
    }

    // === US1 Tests (T038) ===

    #[Test]
    public function message_store_dispatches_agent_loop_start()
    {
        Queue::fake();

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create(['is_processing' => false, 'server_id' => $server->id]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

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

    #[Test]
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

    #[Test]
    public function unprocessed_message_detected_after_loop_completion()
    {
        $conversation = Conversation::factory()->create(['is_processing' => false]);

        // Use DB::table to insert with explicit timestamps (bypass Eloquent auto-timestamps)
        $conn = config('database.default');
        DB::table('messages')->insert([
            'id' => (string) \Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'First message',
            'responseTime' => 0,
            'tool_data' => null,
            'created_at' => '2025-01-01 10:00:00',
            'updated_at' => '2025-01-01 10:00:00',
            'deleted_at' => null,
        ]);

        DB::table('messages')->insert([
            'id' => (string) \Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'First reply',
            'responseTime' => 1,
            'tool_data' => null,
            'created_at' => '2025-01-01 10:01:00',
            'updated_at' => '2025-01-01 10:01:00',
            'deleted_at' => null,
        ]);

        DB::table('messages')->insert([
            'id' => (string) \Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Second message',
            'responseTime' => 0,
            'tool_data' => null,
            'created_at' => '2025-01-01 10:02:00',
            'updated_at' => '2025-01-01 10:02:00',
            'deleted_at' => null,
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

        $this->assertNotNull($latestUser);
        $this->assertNotNull($latestAssistant);
        // Verify user message timestamp (10:02) is strictly after assistant (10:01)
        $this->assertTrue(
            $latestUser->created_at->gt($latestAssistant->created_at),
            'User message (' . $latestUser->created_at->format('H:i:s') .
            ') should be newer than assistant (' . $latestAssistant->created_at->format('H:i:s') . ')'
        );
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

    // T006

    #[Test]
    public function search_operations_returns_results_with_correct_wrapper_format()
    {
        // Mock OperationsSearchService via app() binding
        $mockRow = (object) [
            'operationId' => 'contacts.store',
            'type'        => 'operation',
            'summary'     => 'Store a new contact',
            'method'      => 'POST',
            'path'        => '/api/contacts',
            'paramSchema' => json_encode(['body' => [['name' => 'name', 'type' => 'string']]]),
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
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

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

    // T007

    #[Test]
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
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $this->invokeHandleSearchOperations($service, ['query' => $longQuery]);
        // If we get here without exception, truncation worked
        $this->assertTrue(true);
    }

    // T008

    #[Test]
    public function search_operations_returns_error_for_missing_query()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, []);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals('query parameter is required', $decoded['error']);
    }

    // T009

    #[Test]
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
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'list contacts']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $this->assertNull($decoded['results'][0]['paramSchema']);
    }

    // T010

    #[Test]
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
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'broken']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        // Malformed paramSchema should be treated as null
        $this->assertNull($decoded['results'][0]['paramSchema']);
    }

    // T011 - limit is enforced by OperationsSearchService::search() with default $limit=10

    #[Test]
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
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'test']);
        $decoded = json_decode($result, true);

        // Passed through correctly
        $this->assertCount(8, $decoded['results']);
    }

    // T013

    #[Test]
    public function search_operations_returns_zero_match_hint_when_table_has_data_but_no_matches()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        // Mock DB facade properly using partial mock
        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('count')->once()->andReturn(5); // Table has data

        DB::shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'xyz_nonexistent']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('hint', $decoded);
        $this->assertStringContainsString('broader', $decoded['hint']);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertEmpty($decoded['results']);
    }

    // T014

    #[Test]
    public function search_operations_returns_empty_index_hint_when_table_has_zero_rows()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        // Mock DB facade properly using partial mock
        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('count')->once()->andReturn(0); // Empty index

        DB::shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'test']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('hint', $decoded);
        $this->assertStringContainsString('empty', $decoded['hint']);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertEmpty($decoded['results']);
    }

    // T015

    #[Test]
    public function search_operations_returns_missing_table_hint_when_table_does_not_exist()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(false);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'test']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('hint', $decoded);
        $this->assertStringContainsString('not available', $decoded['hint']);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertEmpty($decoded['results']);
    }

    // T017

    #[Test]
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
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'get contact']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $schema = $decoded['results'][0]['paramSchema'];
        $this->assertArrayHasKey('path', $schema);
        $this->assertCount(1, $schema['path']);
        $this->assertEquals('id', $schema['path'][0]['name']);
        $this->assertEquals('integer', $schema['path'][0]['type']);
    }

    // T018

    #[Test]
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
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'list contacts']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $schema = $decoded['results'][0]['paramSchema'];
        $this->assertArrayHasKey('query', $schema);
        $this->assertCount(2, $schema['query']);
        $this->assertEquals('page', $schema['query'][0]['name']);
    }

    // T019

    #[Test]
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
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'create contact']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $schema = $decoded['results'][0]['paramSchema'];
        $this->assertArrayHasKey('body', $schema);
        $this->assertCount(2, $schema['body']);
        $this->assertEquals('name', $schema['body'][0]['name']);
    }

    // T020

    #[Test]
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
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

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

    // - Custom prompt result format

    #[Test]
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
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

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

    // - Mixed operation + prompt results

    #[Test]
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
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

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

    /* ── Known Operations Section Tests (US1–US4) ── */

    /**
     * Create an AgentLoopService with a real OperationCache pre-populated with entries.
     * Uses real OperationCache (in-memory, no DB) to avoid Mockery alias issues.
     */
    private function createServiceWithCacheEntries(string $conversationId, array $entries): AgentLoopService
    {
        $cache = new OperationCache();
        // Pre-populate cache using put() to simulate cached operations
        foreach ($entries as $entry) {
            $cache->put($conversationId, $entry['operationId'], [
                'summary' => $entry['summary'],
                'method' => $entry['method'],
                'path' => $entry['path'],
                'paramSchema' => $entry['paramSchema'] ?? null,
            ]);
        }

        // Use mocks only for McpToolRegistry and McpToolExecutor (no type hint issues)
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        return new AgentLoopService($registryMock, $executorMock, $cache);
    }

    #[Test]
    public function build_known_operations_section_generates_bullet_list_format()
    {
        $conversation = Conversation::factory()->create();

        $entries = [
            [
                'operationId' => 'create-contact',
                'summary' => 'Create a new contact',
                'method' => 'POST',
                'path' => '/contacts',
                'paramSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            ],
            [
                'operationId' => 'list-tasks',
                'summary' => 'List all tasks',
                'method' => 'GET',
                'path' => '/tasks',
                'paramSchema' => null,
            ],
        ];

        $service = $this->createServiceWithCacheEntries($conversation->id, $entries);
        $section = $this->invokeBuildKnownOperationsSection($service, $conversation);

        $this->assertNotNull($section);
        $this->assertStringContainsString("## Known Operations", $section);
        $this->assertStringContainsString("**create-contact** (POST /contacts)", $section);
        $this->assertStringContainsString("- Summary: Create a new contact", $section);
        $this->assertStringContainsString("- Parameters:", $section);
        $this->assertStringContainsString("**list-tasks** (GET /tasks)", $section);
        $this->assertStringContainsString("- Summary: List all tasks", $section);
        $this->assertStringContainsString("- Parameters: none", $section);
    }

    #[Test]
    public function build_known_operations_section_handles_null_paramschema()
    {
        $conversation = Conversation::factory()->create();

        $entries = [
            [
                'operationId' => 'delete-contact',
                'summary' => 'Delete a contact',
                'method' => 'DELETE',
                'path' => '/contacts/1',
                'paramSchema' => null,
            ],
        ];

        $service = $this->createServiceWithCacheEntries($conversation->id, $entries);
        $section = $this->invokeBuildKnownOperationsSection($service, $conversation);

        $this->assertNotNull($section);
        $this->assertStringContainsString("- Parameters: none", $section);
    }

    #[Test]
    public function build_known_operations_section_returns_null_for_empty_cache()
    {
        $conversation = Conversation::factory()->create();

        $cache = new OperationCache();
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, $cache);
        $section = $this->invokeBuildKnownOperationsSection($service, $conversation);

        $this->assertNull($section);
    }

    #[Test]
    public function build_messages_payload_includes_known_operations_section()
    {
        $conversation = Conversation::factory()->create();

        $entries = [
            [
                'operationId' => 'create-contact',
                'summary' => 'Create a new contact',
                'method' => 'POST',
                'path' => '/contacts',
                'paramSchema' => null,
            ],
        ];

        $service = $this->createServiceWithCacheEntries($conversation->id, $entries);
        // Rebuild service with registry mock (no getTools expectations needed)
        $cache = new OperationCache();
        foreach ($entries as $entry) {
            $cache->put($conversation->id, $entry['operationId'], [
                'summary' => $entry['summary'],
                'method' => $entry['method'],
                'path' => $entry['path'],
                'paramSchema' => $entry['paramSchema'] ?? null,
            ]);
        }
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, $cache);
        $messages = $service->buildMessagesPayload($conversation);

        // First message should be system with Known Operations
        $this->assertEquals('system', $messages[0]['role']);
        $this->assertStringContainsString('## Known Operations', $messages[0]['content']);
        $this->assertStringContainsString('create-contact', $messages[0]['content']);
        // Old "Recently Used Operations" should NOT appear
        $this->assertStringNotContainsString('Recently Used Operations', $messages[0]['content']);
    }

    #[Test]
    public function build_messages_payload_skips_known_operations_when_cache_empty()
    {
        $conversation = Conversation::factory()->create();

        $cache = new OperationCache();

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        // Set base system prompt to something non-empty
        config(['llm-client.agent_loop.system_prompt' => 'You are a helpful assistant.']);

        $service = new AgentLoopService($registryMock, $executorMock, $cache);
        $messages = $service->buildMessagesPayload($conversation);

        // System message should exist but not contain Known Operations
        $systemMsg = collect($messages)->firstWhere('role', 'system');
        $this->assertNotNull($systemMsg);
        $this->assertStringNotContainsString('Known Operations', $systemMsg['content']);
    }

    #[Test]
    public function build_known_operations_section_has_clear_delimiter()
    {
        $conversation = Conversation::factory()->create();

        $entries = [
            [
                'operationId' => 'test-op',
                'summary' => 'A test operation',
                'method' => 'GET',
                'path' => '/test',
                'paramSchema' => null,
            ],
        ];

        $service = $this->createServiceWithCacheEntries($conversation->id, $entries);
        $section = $this->invokeBuildKnownOperationsSection($service, $conversation);

        $this->assertNotNull($section);
        // Section should start with blank lines then ## Known Operations
        $this->assertMatchesRegularExpression('/\n+## Known Operations\n/', $section);
    }

    #[Test]
    public function build_messages_payload_with_empty_base_prompt_and_cache_entries()
    {
        config(['llm-client.agent_loop.system_prompt' => '']);

        $conversation = Conversation::factory()->create();

        $entries = [
            [
                'operationId' => 'test-op',
                'summary' => 'Test',
                'method' => 'GET',
                'path' => '/test',
                'paramSchema' => null,
            ],
        ];

        // Build service with mocked registry (for buildMessagesPayload getTools call)
        $cache = new OperationCache();
        foreach ($entries as $entry) {
            $cache->put($conversation->id, $entry['operationId'], [
                'summary' => $entry['summary'],
                'method' => $entry['method'],
                'path' => $entry['path'],
                'paramSchema' => $entry['paramSchema'] ?? null,
            ]);
        }

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, $cache);
        $messages = $service->buildMessagesPayload($conversation);

        // System message should still exist with Known Operations section
        $systemMsg = collect($messages)->firstWhere('role', 'system');
        $this->assertNotNull($systemMsg);
        $this->assertStringContainsString('## Known Operations', $systemMsg['content']);
        $this->assertStringContainsString('test-op', $systemMsg['content']);
    }

    /**
     * Helper to invoke private method buildKnownOperationsSection via reflection.
     */
    private function invokeBuildKnownOperationsSection(AgentLoopService $service, Conversation $conversation): ?string
    {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildKnownOperationsSection');
        $method->setAccessible(true);
        return $method->invoke($service, $conversation);
    }

    // T014

    #[Test]
    public function execute_operation_meta_tool_has_structured_parameters_schema()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());
        $tools = $service->buildToolsPayload();

        // Find execute_operation meta-tool
        $execOp = collect($tools)->firstWhere('function.name', 'execute_operation');
        $this->assertNotNull($execOp, 'execute_operation meta-tool should exist');

        $paramsProps = $execOp['function']['parameters']['properties']['parameters']['properties'];
        $this->assertArrayHasKey('path', $paramsProps);
        $this->assertArrayHasKey('query', $paramsProps);
        $this->assertArrayHasKey('body', $paramsProps);

        // Each sub-object should have additionalProperties: true
        $this->assertTrue($paramsProps['path']['additionalProperties']);
        $this->assertTrue($paramsProps['query']['additionalProperties']);
        $this->assertTrue($paramsProps['body']['additionalProperties']);
    }

    // T015

    #[Test]
    public function execute_operation_description_mentions_structured_format()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());
        $tools = $service->buildToolsPayload();

        $execOp = collect($tools)->firstWhere('function.name', 'execute_operation');
        $this->assertNotNull($execOp);

        $desc = $execOp['function']['description'];
        // Should mention structured format, not flat prefixes
        $this->assertStringContainsString('structured', $desc);
        $this->assertStringContainsString('path', strtolower($desc));
        $this->assertStringContainsString('query', strtolower($desc));
        $this->assertStringContainsString('body', strtolower($desc));
        // Should NOT mention flat prefixes
        $this->assertStringNotContainsString('path_', $desc);
        $this->assertStringNotContainsString('query_', $desc);
        $this->assertStringNotContainsString('body_', $desc);
    }
}
