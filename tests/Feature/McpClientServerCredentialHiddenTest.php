<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * A stored credential is never returned by store()'s 201 response or any
 * later show() -- McpClientServer::$hidden's own mechanism (mirroring
 * Server::$hidden = ['token']) -- while still being the credential that
 * was actually threaded through to the configured server, proven here
 * against ReferenceMcpServer's own real loopback-HTTP token check rather
 * than only asserting the response shape in isolation.
 */
class McpClientServerCredentialHiddenTest extends TestCase
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
    public function store_response_never_contains_a_credential_key(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH, ['expected_token' => 'the-real-token']);

        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server', [
            'name' => 'Credentialed server',
            'transport' => 'streamable_http',
            'url' => $url,
            'credential' => 'the-real-token',
            'scope' => 'personal',
        ]);

        $response->assertStatus(201);
        $this->assertArrayNotHasKey('credential', $response->json());
    }

    #[Test]
    public function show_never_contains_a_credential_key_even_immediately_after_supplying_one(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH, ['expected_token' => 'the-real-token']);

        $storeResponse = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server', [
            'name' => 'Credentialed server',
            'transport' => 'streamable_http',
            'url' => $url,
            'credential' => 'the-real-token',
            'scope' => 'personal',
        ]);
        $serverId = $storeResponse->json('id');

        $showResponse = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/{$serverId}");

        $showResponse->assertStatus(200);
        $data = $showResponse->json();
        $this->assertArrayNotHasKey('credential', $data);
        $this->assertArrayNotHasKey('credential', $data['status']);
    }

    #[Test]
    public function discovery_still_succeeds_proving_the_credential_was_actually_used(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH, ['expected_token' => 'the-real-token']);

        $storeResponse = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server', [
            'name' => 'Credentialed server',
            'transport' => 'streamable_http',
            'url' => $url,
            'credential' => 'the-real-token',
            'scope' => 'personal',
        ]);
        $serverId = $storeResponse->json('id');

        $showResponse = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/{$serverId}");

        $showResponse->assertStatus(200);
        $data = $showResponse->json();
        $this->assertSame('reachable', $data['status']['connection_status']);
        $this->assertGreaterThan(0, $data['status']['tool_count']);
        $this->assertNotEmpty($data['tools']);
    }

    #[Test]
    public function a_wrong_credential_is_reported_as_auth_failed_never_leaking_the_attempted_value(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH, ['expected_token' => 'the-real-token']);

        $storeResponse = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server', [
            'name' => 'Credentialed server',
            'transport' => 'streamable_http',
            'url' => $url,
            'credential' => 'the-wrong-token',
            'scope' => 'personal',
        ]);
        $serverId = $storeResponse->json('id');

        $showResponse = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/{$serverId}");

        $data = $showResponse->json();
        $this->assertSame('auth_failed', $data['status']['connection_status']);
        $this->assertStringNotContainsString('the-wrong-token', json_encode($data));
        $this->assertNotEmpty($data['status']['last_error']);
    }
}
