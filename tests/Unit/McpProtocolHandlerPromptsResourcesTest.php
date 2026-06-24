<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\McpProtocolHandler;
use ClarionApp\LlmClient\Services\McpPromptRegistry;
use ClarionApp\LlmClient\Services\McpResourceHandler;
use ClarionApp\LlmClient\Services\McpSessionManager;
use ClarionApp\LlmClient\Models\McpSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class McpProtocolHandlerPromptsResourcesTest extends TestCase
{
    private McpProtocolHandler $handler;
    private string $userId;
    private string $sessionId;
    private McpSession $mockSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (string) Str::uuid();
        $this->sessionId = (string) Str::uuid();

        // Create a real (but unsaved) McpSession model for proper type checking
        $this->mockSession = new McpSession();
        $this->mockSession->id = $this->sessionId;
        $this->mockSession->user_id = $this->userId;

        // Mock session manager to avoid database access
        $mockSessionManager = Mockery::mock(McpSessionManager::class);
        $mockSessionManager->shouldReceive('validateSession')
            ->with($this->sessionId, $this->userId)
            ->andReturn($this->mockSession);
        $mockSessionManager->shouldReceive('touchSession')
            ->with($this->sessionId)
            ->andReturn(true);

        $this->handler = new McpProtocolHandler($mockSessionManager);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function prompts_list_dispatch_returns_jsonrpc_response_with_prompts_array()
    {
        $mockRegistry = Mockery::mock(McpPromptRegistry::class);
        $mockRegistry->shouldReceive('getPrompts')
            ->with(null)
            ->once()
            ->andReturn([
                'prompts' => [
                    [
                        'name' => 'wizlights_listOperations',
                        'description' => 'Guidance for discovering wizlights tools',
                        'arguments' => [['name' => 'command', 'description' => 'User command', 'required' => false]],
                    ],
                ],
                'nextCursor' => null,
            ]);

        $this->app->instance(McpPromptRegistry::class, $mockRegistry);

        $request = $this->makeJsonRpcRequest('prompts/list', [], 2, $this->sessionId);
        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('prompts', $result['result']);
        $this->assertCount(1, $result['result']['prompts']);
        $this->assertEquals('wizlights_listOperations', $result['result']['prompts'][0]['name']);
        $this->assertArrayHasKey('nextCursor', $result['result']);
    }

    #[Test]
    public function prompts_get_dispatch_returns_jsonrpc_response_with_description_and_messages()
    {
        $mockRegistry = Mockery::mock(McpPromptRegistry::class);
        $mockRegistry->shouldReceive('getPrompt')
            ->with('wizlights_listOperations', ['command' => 'turn on lights'])
            ->once()
            ->andReturn([
                'description' => 'Guidance for discovering wizlights tools',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => "To adjust the lighting...\n\nUser command: turn on lights",
                        ],
                    ],
                ],
            ]);

        $this->app->instance(McpPromptRegistry::class, $mockRegistry);

        $request = $this->makeJsonRpcRequest('prompts/get', [
            'name' => 'wizlights_listOperations',
            'arguments' => ['command' => 'turn on lights'],
        ], 3, $this->sessionId);
        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('description', $result['result']);
        $this->assertArrayHasKey('messages', $result['result']);
        $this->assertCount(1, $result['result']['messages']);
        $this->assertEquals('user', $result['result']['messages'][0]['role']);
    }

    #[Test]
    public function prompts_get_dispatch_returns_32602_error_for_invalid_prompt_name()
    {
        $mockRegistry = Mockery::mock(McpPromptRegistry::class);
        $mockRegistry->shouldReceive('getPrompt')
            ->with('nonexistent_prompt', [])
            ->once()
            ->andReturn(null);

        $this->app->instance(McpPromptRegistry::class, $mockRegistry);

        $request = $this->makeJsonRpcRequest('prompts/get', [
            'name' => 'nonexistent_prompt',
        ], 4, $this->sessionId);
        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(-32602, $result['error']['code']);
        $this->assertStringContainsString('Prompt not found', $result['error']['message']);
    }

    #[Test]
    public function resources_list_dispatch_returns_jsonrpc_response_with_resources_array()
    {
        $mockHandler = Mockery::mock(McpResourceHandler::class);
        $mockHandler->shouldReceive('listResources')
            ->once()
            ->andReturn([
                'resources' => [
                    [
                        'uri' => 'conversation://test-uuid',
                        'name' => 'Test Conversation',
                        'description' => 'Conversation with 5 messages',
                        'mimeType' => 'application/json',
                    ],
                ],
                'nextCursor' => null,
            ]);

        $this->app->instance(McpResourceHandler::class, $mockHandler);

        $request = $this->makeJsonRpcRequest('resources/list', [], 5, $this->sessionId);
        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('resources', $result['result']);
        $this->assertCount(1, $result['result']['resources']);
    }

    #[Test]
    public function resources_read_dispatch_for_conversation_uri_returns_jsonrpc_response_with_contents()
    {
        $mockHandler = Mockery::mock(McpResourceHandler::class);
        $mockHandler->shouldReceive('readResource')
            ->once()
            ->andReturn([
                'contents' => [
                    [
                        'uri' => 'conversation://test-uuid',
                        'mimeType' => 'application/json',
                        'text' => '{"conversation":{},"messages":[],"pagination":{}}',
                    ],
                ],
            ]);

        $this->app->instance(McpResourceHandler::class, $mockHandler);

        $request = $this->makeJsonRpcRequest('resources/read', [
            'uri' => 'conversation://test-uuid',
        ], 6, $this->sessionId);
        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('contents', $result['result']);
        $this->assertCount(1, $result['result']['contents']);
    }

    #[Test]
    public function resources_templates_list_dispatch_returns_jsonrpc_response_with_resource_templates_array()
    {
        $mockHandler = Mockery::mock(McpResourceHandler::class);
        $mockHandler->shouldReceive('listResourceTemplates')
            ->with(null)
            ->once()
            ->andReturn([
                'resourceTemplates' => [
                    [
                        'uriTemplate' => 'page://{url}',
                        'name' => 'Web Page Text',
                        'description' => 'Fetch and extract text content from a web page URL',
                        'mimeType' => 'text/plain',
                    ],
                ],
                'nextCursor' => null,
            ]);

        $this->app->instance(McpResourceHandler::class, $mockHandler);

        $request = $this->makeJsonRpcRequest('resources/templates/list', [], 7, $this->sessionId);
        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('resourceTemplates', $result['result']);
        $this->assertCount(1, $result['result']['resourceTemplates']);
    }

    private function makeJsonRpcRequest(string $method, array $params = [], ?int $id = null, ?string $sessionId = null): Request
    {
        $body = ['jsonrpc' => '2.0', 'method' => $method];
        if ($id !== null) {
            $body['id'] = $id;
        }
        if (!empty($params)) {
            $body['params'] = $params;
        }

        $headers = ['CONTENT_TYPE' => 'application/json'];

        $request = Request::create('/api/mcp', 'POST', [], [], [], $headers, json_encode($body));

        if ($sessionId) {
            $request->headers->set('Mcp-Session-Id', $sessionId);
        }

        return $request;
    }
}
