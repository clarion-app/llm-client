<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\LlmClient\Services\ApiCallValidator;
use App\Models\User;
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

        // Step 4: tools/call safe tool
        $this->mockApiCallValidator('allow');

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

        // Step 5: tools/call destructive tool (blocked)
        $this->mockApiCallValidator('confirm', 'DELETE requests require confirmation');

        $response = $this->postJson('/api/clarion-app/llm-client/mcp', [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'contacts.deleteContact',
                'arguments' => ['path_contact' => 'abc-123'],
            ],
        ], ['Mcp-Session-Id' => $sessionId]);

        $response->assertStatus(200);
        $result = $response->json('result');
        $this->assertFalse($result['isError']);
        $content = json_decode($result['content'][0]['text'], true);
        $this->assertTrue($content['confirmation_required']);
        $confirmationToken = $content['confirmation_token'];

        // Step 6: Resubmit with confirmation token
        $this->mockApiCallValidator('allow');

        $response = $this->postJson('/api/clarion-app/llm-client/mcp', [
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'contacts.deleteContact',
                'arguments' => [
                    'path_contact' => 'abc-123',
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
        $mock = Mockery::mock('alias:' . ClarionPackageServiceProvider::class);
        $mock->shouldReceive('getPackageDescriptions')
            ->andReturn([
                '@clarion-app/contacts' => ['description' => 'Contacts package'],
            ]);
        $mock->shouldReceive('getPackageOperations')
            ->with('@clarion-app/contacts')
            ->andReturn([
                ['operationId' => 'listContacts', 'summary' => 'List all contacts'],
                ['operationId' => 'deleteContact', 'summary' => 'Delete a contact'],
            ]);

        $apiMock = Mockery::mock('alias:' . ApiManager::class);
        $apiMock->shouldReceive('getOperationDetails')
            ->with('listContacts')
            ->andReturn([
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'get',
                'details' => [
                    'operationId' => 'listContacts',
                    'summary' => 'List all contacts',
                ],
            ]);
        $apiMock->shouldReceive('getOperationDetails')
            ->with('deleteContact')
            ->andReturn([
                'path' => '/api/clarion-app/contacts/contact/{contact}',
                'method' => 'delete',
                'details' => [
                    'operationId' => 'deleteContact',
                    'summary' => 'Delete a contact',
                ],
            ]);
    }

    private function mockApiCallValidator(string $status, ?string $reason = null): void
    {
        $result = ['status' => $status];
        if ($reason) {
            $result['reason'] = $reason;
        }

        $mock = Mockery::mock('alias:' . ApiCallValidator::class);
        $mock->shouldReceive('validate')->andReturn($result);
    }
}
