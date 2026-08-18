<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\RefreshMcpClientServerToolsJob;
use ClarionApp\LlmClient\Models\McpClientServer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * PATCH mcp-client-server/{id}/credential (contracts/credential-replace-api.md,
 * D7): a narrow, single-field endpoint that replaces only the credential
 * column, structurally accepts no other field at all, dispatches
 * RefreshMcpClientServerToolsJob so the next check picks up the new
 * value with no further user action (SC-003), and is scoped through the
 * same findEligible() every other id-scoped endpoint on this controller
 * already uses (FR-011).
 */
class McpClientServerCredentialReplaceTest extends TestCase
{
    private User $user;

    private ?ReferenceMcpServer $referenceServer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['password' => Hash::make('password')]);
    }

    protected function tearDown(): void
    {
        $this->referenceServer?->stopHttp();
        $this->referenceServer = null;

        parent::tearDown();
    }

    #[Test]
    public function replacing_the_credential_leaves_every_other_column_byte_for_byte_unchanged(): void
    {
        $server = McpClientServer::create([
            'name' => 'Team web-search server',
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/mcp',
            'command' => null,
            'args' => ['--flag', 'value'],
            'credential' => 'old-token',
            'user_id' => $this->user->id,
        ]);

        $before = [
            'name' => $server->name,
            'transport' => $server->transport->value,
            'url' => $server->url,
            'command' => $server->command,
            'args' => $server->args,
            'user_id' => $server->user_id,
        ];

        $response = $this->actingAs($this->user)->patchJson(
            "/api/clarion-app/llm-client/mcp-client-server/{$server->id}/credential",
            ['credential' => 'new-token']
        );

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayNotHasKey('credential', $data);
        $this->assertSame($server->id, $data['id']);
        $this->assertSame($server->name, $data['name']);
        $this->assertSame($server->transport->value, $data['transport']);
        $this->assertSame('personal', $data['scope']);

        $fresh = $server->fresh();
        $this->assertSame($before['name'], $fresh->name);
        $this->assertSame($before['transport'], $fresh->transport->value);
        $this->assertSame($before['url'], $fresh->url);
        $this->assertSame($before['command'], $fresh->command);
        $this->assertSame($before['args'], $fresh->args);
        $this->assertSame($before['user_id'], $fresh->user_id);
        $this->assertSame('new-token', $fresh->credential);
    }

    #[Test]
    public function replacing_the_credential_dispatches_a_refresh_job_for_this_server(): void
    {
        Bus::fake();

        $server = McpClientServer::create([
            'name' => 'Team web-search server',
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/mcp',
            'credential' => 'old-token',
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->patchJson(
            "/api/clarion-app/llm-client/mcp-client-server/{$server->id}/credential",
            ['credential' => 'new-token']
        );

        $response->assertStatus(200);

        Bus::assertDispatched(
            RefreshMcpClientServerToolsJob::class,
            fn (RefreshMcpClientServerToolsJob $job) => $job->serverId === $server->id
                && $job->triggeredBy === 'credential_replace'
        );
    }

    #[Test]
    public function a_server_failing_on_an_old_credential_becomes_reachable_once_the_dispatched_job_runs(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH, ['expected_token' => 'the-real-token']);

        // Created with the wrong credential -- the create()-time refresh
        // (queued synchronously under this suite's queue connection, the
        // same mechanism McpClientServerCredentialHiddenTest relies on)
        // leaves the server auth_failed.
        $storeResponse = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server', [
            'name' => 'Credentialed server',
            'transport' => 'streamable_http',
            'url' => $url,
            'credential' => 'the-wrong-token',
            'scope' => 'personal',
        ]);
        $serverId = $storeResponse->json('id');

        $beforeShow = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/{$serverId}");
        $this->assertSame('auth_failed', $beforeShow->json('status.connection_status'));

        $replaceResponse = $this->actingAs($this->user)->patchJson(
            "/api/clarion-app/llm-client/mcp-client-server/{$serverId}/credential",
            ['credential' => 'the-real-token']
        );
        $replaceResponse->assertStatus(200);

        $afterShow = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/{$serverId}");
        $afterData = $afterShow->json();
        $this->assertSame('reachable', $afterData['status']['connection_status']);
        $this->assertGreaterThan(0, $afterData['status']['tool_count']);
    }

    #[Test]
    public function a_missing_credential_returns_422(): void
    {
        $server = McpClientServer::create([
            'name' => 'Team web-search server',
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/mcp',
            'credential' => 'old-token',
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->patchJson(
            "/api/clarion-app/llm-client/mcp-client-server/{$server->id}/credential",
            []
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['credential']);
    }

    #[Test]
    public function a_non_string_credential_returns_422(): void
    {
        $server = McpClientServer::create([
            'name' => 'Team web-search server',
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/mcp',
            'credential' => 'old-token',
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->patchJson(
            "/api/clarion-app/llm-client/mcp-client-server/{$server->id}/credential",
            ['credential' => ['not', 'a', 'string']]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['credential']);
    }

    #[Test]
    public function a_request_body_also_attempting_to_set_name_silently_ignores_the_name_field(): void
    {
        $server = McpClientServer::create([
            'name' => 'Original name',
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/mcp',
            'credential' => 'old-token',
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->patchJson(
            "/api/clarion-app/llm-client/mcp-client-server/{$server->id}/credential",
            ['credential' => 'new-token', 'name' => 'Attempted rename']
        );

        $response->assertStatus(200);
        $this->assertSame('Original name', $response->json('name'));
        $this->assertSame('Original name', $server->fresh()->name);
    }

    #[Test]
    public function replacing_a_credential_on_another_users_server_returns_a_uniform_404(): void
    {
        $owner = User::factory()->create(['password' => Hash::make('password')]);
        $stranger = User::factory()->create(['password' => Hash::make('password')]);

        $server = McpClientServer::create([
            'name' => "Owner's private server",
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/mcp',
            'credential' => 'old-token',
            'user_id' => $owner->id,
        ]);

        $response = $this->actingAs($stranger)->patchJson(
            "/api/clarion-app/llm-client/mcp-client-server/{$server->id}/credential",
            ['credential' => 'new-token']
        );

        $response->assertStatus(404);
        $response->assertJson(['code' => 'mcp_client_server_not_found']);

        $this->assertSame('old-token', $server->fresh()->credential);
    }

    #[Test]
    public function replacing_a_credential_on_a_nonexistent_server_returns_the_same_uniform_404(): void
    {
        $response = $this->actingAs($this->user)->patchJson(
            '/api/clarion-app/llm-client/mcp-client-server/00000000-0000-0000-0000-000000000099/credential',
            ['credential' => 'new-token']
        );

        $response->assertStatus(404);
        $response->assertJson(['code' => 'mcp_client_server_not_found']);
    }

    #[Test]
    public function replacing_the_credential_on_a_project_scoped_server_is_allowed_for_any_user_at_the_installation(): void
    {
        Bus::fake();

        $server = McpClientServer::create([
            'name' => 'Shared team server',
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/mcp',
            'credential' => 'old-token',
            'user_id' => McpClientServer::INSTALLATION_SCOPE_ID,
        ]);

        $anotherUser = User::factory()->create(['password' => Hash::make('password')]);

        $response = $this->actingAs($anotherUser)->patchJson(
            "/api/clarion-app/llm-client/mcp-client-server/{$server->id}/credential",
            ['credential' => 'new-token']
        );

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('credential', $response->json());
        $this->assertSame('Shared team server', $response->json('name'));
        $this->assertSame('new-token', $server->fresh()->credential);

        Bus::assertDispatched(
            RefreshMcpClientServerToolsJob::class,
            fn (RefreshMcpClientServerToolsJob $job) => $job->serverId === $server->id
                && $job->triggeredBy === 'credential_replace'
        );
    }
}
