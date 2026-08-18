<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientServerStatus;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * The show() endpoint's status.connection_status must distinguish "the
 * server was reached but currently offers nothing" from an unusable
 * server (Acceptance Scenario 1.3/Edge Cases), and must distinguish a
 * rejected credential (auth_failed) from a plain network failure
 * (unreachable), never conflating either pair -- mirroring
 * McpClientToolDiscoveryServiceTest's own coverage of the write side of
 * this same distinction, at the read (controller) side instead.
 */
class McpClientServerStatusTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['password' => Hash::make('password')]);
    }

    private function makeServer(): McpClientServer
    {
        return McpClientServer::create([
            'name' => 'Status test server',
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.test/mcp',
            'user_id' => $this->user->id,
        ]);
    }

    #[Test]
    public function a_server_reached_successfully_with_zero_tools_is_reachable_not_auth_failed_or_unreachable(): void
    {
        $server = $this->makeServer();
        McpClientServerStatus::create([
            'server_id' => $server->id,
            'connection_status' => 'reachable',
            'tool_count' => 0,
            'refresh_started_at' => now(),
            'refresh_finished_at' => now(),
            'triggered_by' => 'create',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/{$server->id}");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame('reachable', $data['status']['connection_status']);
        $this->assertSame(0, $data['status']['tool_count']);
        $this->assertNotSame('auth_failed', $data['status']['connection_status']);
        $this->assertNotSame('unreachable', $data['status']['connection_status']);
        $this->assertSame([], $data['tools']);
    }

    #[Test]
    public function a_missing_or_wrong_credential_is_auth_failed_with_a_plain_language_error_distinct_from_unreachable(): void
    {
        $server = $this->makeServer();
        McpClientServerStatus::create([
            'server_id' => $server->id,
            'connection_status' => 'auth_failed',
            'last_error' => 'External server rejected the stored credential.',
            'tool_count' => 0,
            'refresh_started_at' => now(),
            'refresh_finished_at' => now(),
            'triggered_by' => 'create',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/{$server->id}");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame('auth_failed', $data['status']['connection_status']);
        $this->assertNotSame('unreachable', $data['status']['connection_status']);
        $this->assertIsString($data['status']['last_error']);
        $this->assertNotEmpty($data['status']['last_error']);
    }

    #[Test]
    public function a_genuinely_unreachable_server_is_unreachable_distinct_from_auth_failed(): void
    {
        $server = $this->makeServer();
        McpClientServerStatus::create([
            'server_id' => $server->id,
            'connection_status' => 'unreachable',
            'last_error' => 'Could not reach external server.',
            'tool_count' => 0,
            'refresh_started_at' => now(),
            'refresh_finished_at' => now(),
            'triggered_by' => 'create',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/{$server->id}");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame('unreachable', $data['status']['connection_status']);
        $this->assertNotSame('auth_failed', $data['status']['connection_status']);
    }

    #[Test]
    public function live_discovery_against_a_credential_requiring_server_with_no_credential_supplied_is_auth_failed(): void
    {
        $referenceServer = new ReferenceMcpServer();
        $url = $referenceServer->startHttp(Protocol::MODE_HAPPY_PATH, ['expected_token' => 'the-real-token']);

        try {
            $storeResponse = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server', [
                'name' => 'Auth-required server',
                'transport' => 'streamable_http',
                'url' => $url,
                'scope' => 'personal',
                // credential deliberately omitted
            ]);
            $serverId = $storeResponse->json('id');

            $showResponse = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/{$serverId}");
            $data = $showResponse->json();

            $this->assertSame('auth_failed', $data['status']['connection_status']);
            $this->assertNotSame('unreachable', $data['status']['connection_status']);
            $this->assertNotEmpty($data['status']['last_error']);
        } finally {
            $referenceServer->stopHttp();
        }
    }
}
