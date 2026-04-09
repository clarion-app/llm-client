<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\McpProtocolHandler;
use ClarionApp\LlmClient\Services\McpSessionManager;
use ClarionApp\LlmClient\Models\McpSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery;

class McpProtocolHandlerTest extends TestCase
{
    use RefreshDatabase;

    private McpProtocolHandler $handler;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = app(McpProtocolHandler::class);
        $this->userId = (string) Str::uuid();
    }

    /** @test */
    public function returns_parse_error_for_invalid_json()
    {
        $request = Request::create('/api/mcp', 'POST', [], [], [], [], 'not json');
        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertEquals(-32700, $result['error']['code']);
        $this->assertStringContainsString('Parse error', $result['error']['message']);
    }

    /** @test */
    public function returns_invalid_request_for_missing_jsonrpc()
    {
        $request = Request::create('/api/mcp', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['id' => 1, 'method' => 'ping']));

        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertEquals(-32600, $result['error']['code']);
    }

    /** @test */
    public function returns_invalid_request_for_missing_method()
    {
        $request = Request::create('/api/mcp', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['jsonrpc' => '2.0', 'id' => 1]));

        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertEquals(-32600, $result['error']['code']);
    }

    /** @test */
    public function returns_method_not_found_for_unknown_method()
    {
        $request = $this->makeJsonRpcRequest('unknown/method', [], 1);

        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertEquals(-32601, $result['error']['code']);
    }

    /** @test */
    public function handles_initialize_and_returns_session_id()
    {
        $request = $this->makeJsonRpcRequest('initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
        ], 1);

        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('result', $result);
        $this->assertEquals('2025-03-26', $result['result']['protocolVersion']);
        $this->assertArrayHasKey('serverInfo', $result['result']);
        $this->assertEquals('clarion-mcp-server', $result['result']['serverInfo']['name']);
        $this->assertArrayHasKey('capabilities', $result['result']);
        $this->assertArrayHasKey('_sessionId', $result);
    }

    /** @test */
    public function handles_notifications_initialized_returns_204_marker()
    {
        // First initialize to get a session
        $initRequest = $this->makeJsonRpcRequest('initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
        ], 1);
        $initResult = $this->handler->dispatch($initRequest, $this->userId);
        $sessionId = $initResult['_sessionId'];

        // Send initialized notification
        $request = $this->makeJsonRpcRequest('notifications/initialized', [], null, $sessionId);

        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('_noContent', $result);
        $this->assertTrue($result['_noContent']);
    }

    /** @test */
    public function handles_ping_with_empty_result()
    {
        // Initialize first
        $initRequest = $this->makeJsonRpcRequest('initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
        ], 1);
        $initResult = $this->handler->dispatch($initRequest, $this->userId);
        $sessionId = $initResult['_sessionId'];

        // Ping
        $request = $this->makeJsonRpcRequest('ping', [], 2, $sessionId);
        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('result', $result);
        $this->assertEquals(new \stdClass(), $result['result']);
    }

    /** @test */
    public function rejects_non_init_request_without_session()
    {
        $request = $this->makeJsonRpcRequest('tools/list', [], 1);

        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(-32600, $result['error']['code']);
    }

    /** @test */
    public function rejects_non_init_request_with_invalid_session()
    {
        $request = $this->makeJsonRpcRequest('tools/list', [], 1, (string) Str::uuid());

        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(-32600, $result['error']['code']);
    }

    /** @test */
    public function initialize_rejects_unsupported_protocol_version()
    {
        $request = $this->makeJsonRpcRequest('initialize', [
            'protocolVersion' => '1999-01-01',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
        ], 1);

        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(-32602, $result['error']['code']);
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
