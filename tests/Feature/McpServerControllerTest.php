<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Models\McpConfirmationToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Mockery;

class McpServerControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->mockToolDiscovery();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function full_mcp_lifecycle()
    {
        Passport::actingAs($this->user);

        // Step 1: Initialize
        $response = $this->postJson('/api/clarion-app/llm-client/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'integration-test', 'version' => '1.0'],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Mcp-Session-Id');
        $sessionId = $response->headers->get('Mcp-Session-Id');
        $body = $response->json();
        $this->assertEquals('2025-03-26', $body['result']['protocolVersion']);
        $this->assertEquals('clarion-mcp-server', $body['result']['serverInfo']['name']);

        // Step 2: Send notifications/initialized
        $response = $this->postJson('/api/clarion-app/llm-client/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], ['Mcp-Session-Id' => $sessionId]);

        $response->assertStatus(204);

        // Step 3: tools/list
        $response = $this->postJson('/api/clarion-app/llm-client/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ], ['Mcp-Session-Id' => $sessionId]);

        $response->assertStatus(200);
        $tools = $response->json('result.tools');
        $this->assertIsArray($tools);
        $this->assertNotEmpty($tools);

        // Step 4: tools/call safe tool (GET — returns mocked success)
        $response = $this->postJson('/api/clarion-app/llm-client/mcp', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'contacts.listContacts',
                'arguments' => [],
            ],
        ], ['Mcp-Session-Id' => $sessionId]);

        $response->assertStatus(200);
        $result = $response->json('result');
        $this->assertFalse($result['isError']);

        // Step 5: tools/call destructive tool (DELETE — returns confirmation_required)
        $response = $this->postJson('/api/clarion-app/llm-client/mcp', [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'contacts.deleteContact',
                'arguments' => ['path' => ['contact' => 'abc-123']],
            ],
        ], ['Mcp-Session-Id' => $sessionId]);

        $response->assertStatus(200);
        $result = $response->json('result');
        $this->assertFalse($result['isError']);
        $content = json_decode($result['content'][0]['text'], true);
        $this->assertTrue($content['confirmation_required']);
        $confirmationToken = $content['confirmation_token'];

        // Step 6: Resubmit with confirmation token (returns success)
        $response = $this->postJson('/api/clarion-app/llm-client/mcp', [
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'contacts.deleteContact',
                'arguments' => [
                    'path' => ['contact' => 'abc-123'],
                    '_confirmation_token' => $confirmationToken,
                ],
            ],
        ], ['Mcp-Session-Id' => $sessionId]);

        $response->assertStatus(200);
        $result = $response->json('result');
        $this->assertFalse($result['isError']);
    }

    /** @test */
    public function rejects_unauthenticated_request()
    {
        $response = $this->postJson('/api/clarion-app/llm-client/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ]);

        $response->assertStatus(401);
    }

    private function mockToolDiscovery(): void
    {
        // Mock McpToolRegistry — resolves via app() in McpProtocolHandler.
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('getTools')
            ->andReturn([
                'tools' => [
                    [
                        'name' => 'contacts.listContacts',
                        'description' => 'List all contacts',
                        'inputSchema' => [],
                    ],
                    [
                        'name' => 'contacts.deleteContact',
                        'description' => 'Delete a contact',
                        'inputSchema' => [],
                    ],
                ],
                'nextCursor' => null,
            ]);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.listContacts')
            ->andReturn([
                'name' => 'contacts.listContacts',
                'description' => 'List all contacts',
                'inputSchema' => [],
                '_meta' => [
                    'operationId' => 'listContacts',
                    'method' => 'get',
                    'path' => '/api/clarion-app/contacts/contact',
                ],
            ]);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.deleteContact')
            ->andReturn([
                'name' => 'contacts.deleteContact',
                'description' => 'Delete a contact',
                'inputSchema' => [],
                '_meta' => [
                    'operationId' => 'deleteContact',
                    'method' => 'delete',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        // Mock McpToolExecutor — resolves via app() in McpProtocolHandler.
        // We need it to handle 3 scenarios:
        // 1. GET call (listContacts) → success
        // 2. DELETE call without confirmation_token → confirmation_required
        // 3. DELETE call with confirmation_token → success
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $executorMock->shouldReceive('executeTool')
            ->with('contacts.listContacts', Mockery::type('array'), Mockery::type('object'))
            ->andReturn([
                'content' => [['type' => 'text', 'mimeType' => 'application/json', 'text' => json_encode(['items' => []])]],
                'isError' => false,
            ]);

        // First call for deleteContact (no confirmation token) → returns confirmation
        $executorMock->shouldReceive('executeTool')
            ->with('contacts.deleteContact', Mockery::on(function ($args) {
                return empty($args['_confirmation_token'] ?? null);
            }), Mockery::type('object'))
            ->andReturnUsing(function ($args) {
                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'mimeType' => 'application/json',
                            'text' => json_encode([
                                'confirmation_required' => true,
                                'confirmation_token' => 'test-token-' . bin2hex(random_bytes(8)),
                                'tool_name' => 'contacts.deleteContact',
                                'message' => 'This is a destructive operation.',
                                'expires_in_seconds' => 300,
                            ]),
                        ],
                    ],
                    'isError' => false,
                ];
            });

        // Second call for deleteContact (with confirmation token) → success
        $executorMock->shouldReceive('executeTool')
            ->with('contacts.deleteContact', Mockery::on(function ($args) {
                return !empty($args['_confirmation_token'] ?? null);
            }), Mockery::type('object'))
            ->andReturn([
                'content' => [['type' => 'text', 'mimeType' => 'application/json', 'text' => json_encode(['status' => 'deleted'])]],
                'isError' => false,
            ]);

        $this->instance(McpToolRegistry::class, $registryMock);
        $this->instance(McpToolExecutor::class, $executorMock);
    }
}
