<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\RefreshMcpClientServerToolsJob;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientServerStatus;
use ClarionApp\LlmClient\Models\McpClientTool;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * McpClientServerController's CRUD shape, mirroring ServerController's
 * own established pattern for this package: store() creates a server and
 * immediately queues a tool refresh, show() returns a server's current
 * cached tools and status, destroy() soft-deletes, and an absent id 404s
 * uniformly (RunController::notFoundResponse()'s own precedent -- never
 * a distinguishing 403).
 */
class McpClientServerControllerTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['password' => Hash::make('password')]);
    }

    #[Test]
    public function store_creates_a_server_and_dispatches_a_refresh_job_immediately(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server', [
            'name' => 'Local filesystem server',
            'transport' => 'stdio',
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/data'],
            'scope' => 'personal',
        ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertSame('Local filesystem server', $data['name']);
        $this->assertSame('stdio', $data['transport']);
        $this->assertSame('personal', $data['scope']);
        $this->assertSame('pending', $data['status']);

        $server = McpClientServer::find($data['id']);
        $this->assertNotNull($server);
        $this->assertSame($this->user->id, $server->user_id);
        $this->assertSame(['-y', '@modelcontextprotocol/server-filesystem', '/data'], $server->args);

        Bus::assertDispatched(function (RefreshMcpClientServerToolsJob $job) use ($server) {
            return $job->serverId === $server->id;
        });
    }

    #[Test]
    public function store_returns_422_when_a_required_field_for_the_chosen_transport_is_missing(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server', [
            'name' => 'No url',
            'transport' => 'streamable_http',
            'scope' => 'personal',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('url', $response->json('errors'));
    }

    #[Test]
    public function show_returns_the_servers_current_cached_tools_and_status(): void
    {
        $server = McpClientServer::create([
            'name' => 'Team web-search server',
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/mcp',
            'user_id' => $this->user->id,
        ]);

        McpClientServerStatus::create([
            'server_id' => $server->id,
            'connection_status' => 'reachable',
            'tool_count' => 1,
            'refresh_started_at' => now(),
            'refresh_finished_at' => now(),
            'triggered_by' => 'create',
        ]);

        McpClientTool::create([
            'server_id' => $server->id,
            'synthetic_operation_id' => "mcp:{$server->id}:web_search",
            'name' => 'web_search',
            'description' => 'Searches the web.',
            'input_schema' => ['type' => 'object'],
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/{$server->id}");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame('reachable', $data['status']['connection_status']);
        $this->assertSame(1, $data['status']['tool_count']);
        $this->assertCount(1, $data['tools']);
        $this->assertSame('web_search', $data['tools'][0]['name']);

        $cachedTool = McpClientTool::where('server_id', $server->id)->where('name', 'web_search')->firstOrFail();
        $this->assertSame($cachedTool->synthetic_operation_id, $data['tools'][0]['synthetic_operation_id']);
    }

    #[Test]
    public function destroy_soft_deletes(): void
    {
        $server = McpClientServer::create([
            'name' => 'Doomed server',
            'transport' => 'stdio',
            'command' => 'npx',
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/clarion-app/llm-client/mcp-client-server/{$server->id}");

        $response->assertStatus(204);
        $this->assertNull(McpClientServer::find($server->id));
        $trashed = McpClientServer::withTrashed()->find($server->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
    }

    #[Test]
    public function show_404s_uniformly_for_an_absent_id(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/mcp-client-server/' . (string) Str::uuid());

        $response->assertStatus(404);
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('code', $data);
    }

    #[Test]
    public function destroy_404s_uniformly_for_an_absent_id(): void
    {
        $response = $this->actingAs($this->user)->deleteJson('/api/clarion-app/llm-client/mcp-client-server/' . (string) Str::uuid());

        $response->assertStatus(404);
    }
}
