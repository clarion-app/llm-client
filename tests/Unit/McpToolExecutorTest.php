<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\CallValidatorInterface;
use ClarionApp\LlmClient\Models\McpSession;
use ClarionApp\LlmClient\Models\McpConfirmationToken;
use ClarionApp\Backend\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class McpToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    private McpSession $session;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->session = McpSession::create([
            'user_id' => $this->user->id,
            'protocol_version' => '2025-03-26',
        ]);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function extracts_path_params_into_url()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.show')
            ->andReturn([
                'name' => 'contacts.show',
                '_meta' => [
                    'operationId' => 'showContact',
                    'method' => 'GET',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['data' => ['id' => 'abc', 'name' => 'Alice']], 200),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.show', ['path' => ['contact' => 'abc']], $this->session);

        $this->assertFalse($result['isError']);
        $this->assertEquals('text', $result['content'][0]['type']);
        $this->assertEquals('application/json', $result['content'][0]['mimeType']);
    }

    #[Test]
    public function extracts_query_params_into_query_string()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.index')
            ->andReturn([
                'name' => 'contacts.index',
                '_meta' => [
                    'operationId' => 'listContacts',
                    'method' => 'GET',
                    'path' => '/api/clarion-app/contacts/contact',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['data' => []], 200),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.index', ['query' => ['page' => 2]], $this->session);

        $this->assertFalse($result['isError']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'page=2');
        });
    }

    #[Test]
    public function extracts_body_params_into_request_body()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.store')
            ->andReturn([
                'name' => 'contacts.store',
                '_meta' => [
                    'operationId' => 'createContact',
                    'method' => 'POST',
                    'path' => '/api/clarion-app/contacts/contact',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['data' => ['id' => 'new', 'name' => 'Bob']], 201),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.store', [
            'body' => ['name' => 'Bob', 'email' => 'bob@example.com'],
        ], $this->session);

        $this->assertFalse($result['isError']);
        Http::assertSent(function ($request) {
            $body = $request->data();
            return ($body['name'] ?? null) === 'Bob' && ($body['email'] ?? null) === 'bob@example.com';
        });
    }

    #[Test]
    public function executes_safe_get_tool_and_returns_json_content()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.index')
            ->andReturn([
                'name' => 'contacts.index',
                '_meta' => [
                    'operationId' => 'listContacts',
                    'method' => 'GET',
                    'path' => '/api/clarion-app/contacts/contact',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['data' => [['id' => '1', 'name' => 'Alice']]], 200),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.index', [], $this->session);

        $this->assertFalse($result['isError']);
        $this->assertEquals('text', $result['content'][0]['type']);
        $this->assertEquals('application/json', $result['content'][0]['mimeType']);
        $this->assertStringContainsString('Alice', $result['content'][0]['text']);
    }

    #[Test]
    public function rejects_denylisted_tool_with_is_error()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('llm-client.listConversations')
            ->andReturn([
                'name' => 'llm-client.listConversations',
                '_meta' => [
                    'operationId' => 'listConversations',
                    'method' => 'GET',
                    'path' => '/api/clarion-app/llm-client/conversation',
                ],
            ]);

        $validatorMock = $this->mockValidator('reject', 'Path is denylisted');

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('llm-client.listConversations', [], $this->session);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('denylisted', $result['content'][0]['text']);
    }

    #[Test]
    public function rejects_path_traversal_with_is_error()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.show')
            ->andReturn([
                'name' => 'contacts.show',
                '_meta' => [
                    'operationId' => 'showContact',
                    'method' => 'GET',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        $validatorMock = $this->mockValidator('reject', 'Path traversal detected');

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.show', ['path' => ['contact' => '../../../etc/passwd']], $this->session);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('traversal', $result['content'][0]['text']);
    }

    #[Test]
    public function blocks_destructive_call_and_returns_confirmation_token()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => [
                    'operationId' => 'deleteContact',
                    'method' => 'DELETE',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        $validatorMock = $this->mockValidator('confirm', 'DELETE requests require user confirmation');

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.destroy', ['path' => ['contact' => 'abc']], $this->session);

        $this->assertFalse($result['isError']);
        $content = json_decode($result['content'][0]['text'], true);
        $this->assertTrue($content['confirmation_required']);
        $this->assertNotEmpty($content['confirmation_token']);
        $this->assertEquals('contacts.destroy', $content['tool_name']);
    }

    #[Test]
    public function validates_and_consumes_confirmation_token_on_resubmit()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => [
                    'operationId' => 'deleteContact',
                    'method' => 'DELETE',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        $arguments = ['path' => ['contact' => 'abc']];
        $argumentsHash = hash('sha256', json_encode($arguments));

        $token = McpConfirmationToken::create([
            'session_id' => $this->session->id,
            'tool_name' => 'contacts.destroy',
            'arguments_hash' => $argumentsHash,
            'arguments_snapshot' => $arguments,
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);

        $validatorMock = Mockery::mock(CallValidatorInterface::class);

        Http::fake([
            '*' => Http::response(null, 204),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.destroy', array_merge($arguments, [
            '_confirmation_token' => $token->id,
        ]), $this->session);

        $this->assertFalse($result['isError']);

        $token->refresh();
        $this->assertNotNull($token->used_at);
    }

    #[Test]
    public function rejects_expired_confirmation_token()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => [
                    'operationId' => 'deleteContact',
                    'method' => 'DELETE',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        $arguments = ['path' => ['contact' => 'abc']];
        $argumentsHash = hash('sha256', json_encode($arguments));

        $token = McpConfirmationToken::create([
            'session_id' => $this->session->id,
            'tool_name' => 'contacts.destroy',
            'arguments_hash' => $argumentsHash,
            'arguments_snapshot' => $arguments,
            'expires_at' => Carbon::now()->subMinutes(1),
        ]);

        $validatorMock = Mockery::mock(CallValidatorInterface::class);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.destroy', array_merge($arguments, [
            '_confirmation_token' => $token->id,
        ]), $this->session);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('expired', strtolower($result['content'][0]['text']));
    }

    #[Test]
    public function rejects_token_with_wrong_arguments_hash()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => [
                    'operationId' => 'deleteContact',
                    'method' => 'DELETE',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        $token = McpConfirmationToken::create([
            'session_id' => $this->session->id,
            'tool_name' => 'contacts.destroy',
            'arguments_hash' => hash('sha256', 'different-args'),
            'arguments_snapshot' => ['path' => ['contact' => 'different']],
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);

        $validatorMock = Mockery::mock(CallValidatorInterface::class);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.destroy', [
            'path' => ['contact' => 'abc'],
            '_confirmation_token' => $token->id,
        ], $this->session);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('invalid', strtolower($result['content'][0]['text']));
    }

    #[Test]
    public function rejects_token_from_different_session()
    {
        $otherSession = McpSession::create([
            'user_id' => (string) Str::uuid(),
            'protocol_version' => '2025-03-26',
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => [
                    'operationId' => 'deleteContact',
                    'method' => 'DELETE',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        $arguments = ['path' => ['contact' => 'abc']];
        $argumentsHash = hash('sha256', json_encode($arguments));

        $token = McpConfirmationToken::create([
            'session_id' => $otherSession->id,
            'tool_name' => 'contacts.destroy',
            'arguments_hash' => $argumentsHash,
            'arguments_snapshot' => $arguments,
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);

        $validatorMock = Mockery::mock(CallValidatorInterface::class);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.destroy', array_merge($arguments, [
            '_confirmation_token' => $token->id,
        ]), $this->session);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('invalid', strtolower($result['content'][0]['text']));
    }

    #[Test]
    public function wraps_backend_error_as_is_error()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.show')
            ->andReturn([
                'name' => 'contacts.show',
                '_meta' => [
                    'operationId' => 'showContact',
                    'method' => 'GET',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['message' => 'Contact not found'], 404),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.show', ['path' => ['contact' => 'nonexistent']], $this->session);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('not found', strtolower($result['content'][0]['text']));
    }

    #[Test]
    public function extracts_structured_path_params_into_url()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.show')
            ->andReturn([
                'name' => 'contacts.show',
                '_meta' => [
                    'operationId' => 'showContact',
                    'method' => 'GET',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['data' => ['id' => 'abc', 'name' => 'Alice']], 200),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.show', ['path' => ['contact' => 'abc']], $this->session);

        $this->assertFalse($result['isError']);
        $this->assertEquals('text', $result['content'][0]['type']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/contact/abc');
        });
    }

    #[Test]
    public function extracts_structured_query_params_into_query_string()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.index')
            ->andReturn([
                'name' => 'contacts.index',
                '_meta' => [
                    'operationId' => 'listContacts',
                    'method' => 'GET',
                    'path' => '/api/clarion-app/contacts/contact',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['data' => []], 200),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.index', ['query' => ['page' => '2']], $this->session);

        $this->assertFalse($result['isError']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'page=2');
        });
    }

    #[Test]
    public function extracts_structured_body_params_into_request_body()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.store')
            ->andReturn([
                'name' => 'contacts.store',
                '_meta' => [
                    'operationId' => 'createContact',
                    'method' => 'POST',
                    'path' => '/api/clarion-app/contacts/contact',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['data' => ['id' => 'new', 'name' => 'Bob']], 201),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.store', [
            'body' => ['name' => 'Bob', 'email' => 'bob@example.com'],
        ], $this->session);

        $this->assertFalse($result['isError']);
        Http::assertSent(function ($request) {
            $body = $request->data();
            return ($body['name'] ?? null) === 'Bob' && ($body['email'] ?? null) === 'bob@example.com';
        });
    }

    #[Test]
    public function extracts_combined_all_three_param_types()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.update')
            ->andReturn([
                'name' => 'contacts.update',
                '_meta' => [
                    'operationId' => 'updateContact',
                    'method' => 'PUT',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['data' => ['id' => 'abc', 'name' => 'Updated']], 200),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.update', [
            'path' => ['contact' => 'abc'],
            'query' => ['include' => 'groups'],
            'body' => ['name' => 'Updated'],
        ], $this->session);

        $this->assertFalse($result['isError']);
        Http::assertSent(function ($request) {
            $url = $request->url();
            $body = $request->data();
            return str_contains($url, '/contact/abc')
                && str_contains($url, 'include=groups')
                && ($body['name'] ?? null) === 'Updated';
        });
    }

    #[Test]
    public function e2e_mcp_structured_path_with_all_param_types()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.show')
            ->andReturn([
                'name' => 'contacts.show',
                '_meta' => [
                    'operationId' => 'showContact',
                    'method' => 'GET',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['data' => ['id' => 'abc', 'name' => 'Alice']], 200),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.show', [
            'path' => ['contact' => 'abc-123'],
            'query' => ['expand' => 'profile'],
            'body' => ['_source' => 'full'],
        ], $this->session);

        $this->assertFalse($result['isError']);
        Http::assertSent(function ($request) {
            $url = $request->url();
            return str_contains($url, '/contact/abc-123') && str_contains($url, 'expand=profile');
        });
    }

    #[Test]
    public function handles_path_only_params_no_query_or_body()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.show')
            ->andReturn([
                'name' => 'contacts.show',
                '_meta' => [
                    'operationId' => 'showContact',
                    'method' => 'GET',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['data' => ['id' => 'abc']], 200),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.show', ['path' => ['contact' => 'abc']], $this->session);

        $this->assertFalse($result['isError']);
    }

    #[Test]
    public function handles_empty_params_gracefully()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.index')
            ->andReturn([
                'name' => 'contacts.index',
                '_meta' => [
                    'operationId' => 'listContacts',
                    'method' => 'GET',
                    'path' => '/api/clarion-app/contacts/contact',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['data' => []], 200),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.index', [], $this->session);

        $this->assertFalse($result['isError']);
    }

    #[Test]
    public function skips_null_values_in_structured_params()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.show')
            ->andReturn([
                'name' => 'contacts.show',
                '_meta' => [
                    'operationId' => 'showContact',
                    'method' => 'GET',
                    'path' => '/api/clarion-app/contacts/contact/{contact}',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['data' => ['id' => 'abc']], 200),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        // null value for path should be skipped (not substituted)
        $result = $executor->executeTool('contacts.show', [
            'path' => ['contact' => null],
            'query' => ['page' => null, 'search' => 'john'],
        ], $this->session);

        $this->assertFalse($result['isError']);
        Http::assertSent(function ($request) {
            // null contact should leave {contact} unsubstituted
            return str_contains($request->url(), '{contact}')
                && !str_contains($request->url(), 'page=')
                && str_contains($request->url(), 'search=john');
        });
    }

    #[Test]
    public function handles_body_only_params()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.store')
            ->andReturn([
                'name' => 'contacts.store',
                '_meta' => [
                    'operationId' => 'createContact',
                    'method' => 'POST',
                    'path' => '/api/clarion-app/contacts/contact',
                ],
            ]);

        $validatorMock = $this->mockValidator('allow');

        Http::fake([
            '*' => Http::response(['data' => ['id' => 'new']], 201),
        ]);

        $executor = new McpToolExecutor($registryMock, $validatorMock);
        $result = $executor->executeTool('contacts.store', [
            'body' => ['name' => 'New Contact'],
        ], $this->session);

        $this->assertFalse($result['isError']);
        Http::assertSent(function ($request) {
            $body = $request->data();
            return ($body['name'] ?? null) === 'New Contact';
        });
    }

    private function mockValidator(string $status, ?string $reason = null): Mockery\MockInterface
    {
        $mock = Mockery::mock(CallValidatorInterface::class);
        $result = ['status' => $status];
        if ($reason) {
            $result['reason'] = $reason;
        }
        $mock->shouldReceive('validate')->andReturn($result);
        return $mock;
    }
}
