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

    /**
     * US1 Acceptance Scenarios 1/3, FR-001, Grounding note 1 — index()
     * currently returns only id/name/transport/scope with no status data
     * at all. Two servers, each with its own status row (one reachable,
     * one unreachable), must each carry its own connection_status/
     * last_reachable_at/tool_count in the list response, independent of
     * the other's values.
     */
    #[Test]
    public function index_lists_each_servers_own_status_independently_of_the_others(): void
    {
        $reachableServer = McpClientServer::create([
            'name' => 'Reachable server',
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/reachable',
            'user_id' => $this->user->id,
        ]);
        // SQLite's datetime storage truncates to whole seconds, so the
        // fixture's own timestamp is pre-truncated too -- otherwise the
        // round trip through the database would never match byte-for-byte.
        $reachableLastReachableAt = now()->subMinutes(5)->startOfSecond();
        McpClientServerStatus::create([
            'server_id' => $reachableServer->id,
            'connection_status' => 'reachable',
            'tool_count' => 4,
            'refresh_started_at' => $reachableLastReachableAt,
            'refresh_finished_at' => $reachableLastReachableAt,
            'last_reachable_at' => $reachableLastReachableAt,
            'triggered_by' => 'create',
        ]);

        $unreachableServer = McpClientServer::create([
            'name' => 'Unreachable server',
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/unreachable',
            'user_id' => $this->user->id,
        ]);
        McpClientServerStatus::create([
            'server_id' => $unreachableServer->id,
            'connection_status' => 'unreachable',
            'last_error' => 'Connection refused',
            'tool_count' => 0,
            'refresh_started_at' => now(),
            'refresh_finished_at' => now(),
            'last_reachable_at' => null,
            'triggered_by' => 'create',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/mcp-client-server');

        $response->assertStatus(200);
        $data = collect($response->json())->keyBy('id');

        $reachableData = $data[$reachableServer->id];
        $this->assertSame('reachable', $reachableData['connection_status']);
        $this->assertSame(4, $reachableData['tool_count']);
        $this->assertNotNull($reachableData['last_reachable_at']);
        $this->assertSame(
            $reachableLastReachableAt->toISOString(),
            \Carbon\Carbon::parse($reachableData['last_reachable_at'])->toISOString(),
        );

        $unreachableData = $data[$unreachableServer->id];
        $this->assertSame('unreachable', $unreachableData['connection_status']);
        $this->assertSame(0, $unreachableData['tool_count']);
        $this->assertNull($unreachableData['last_reachable_at']);

        // Independence: the unreachable server's failure must not leak
        // into the reachable server's own reported status.
        $this->assertNotSame($reachableData['connection_status'], $unreachableData['connection_status']);
    }

    /**
     * US1 Acceptance Scenario 2, Grounding note 2 — a status row where a
     * later *failed* discover() advanced refresh_finished_at but left
     * last_reachable_at at its earlier, correct success time must show
     * that distinction in both show() and index(), not collapse the two
     * timestamps together.
     */
    #[Test]
    public function show_and_index_distinguish_last_reachable_at_from_a_later_failed_refresh_finished_at(): void
    {
        $server = McpClientServer::create([
            'name' => 'Flaky server',
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/flaky',
            'user_id' => $this->user->id,
        ]);

        // SQLite's datetime storage truncates to whole seconds, so the
        // fixture's own timestamps are pre-truncated too -- otherwise the
        // round trip through the database would never match byte-for-byte.
        $earlierSuccess = now()->subHours(2)->startOfSecond();
        $laterFailedAttempt = now()->startOfSecond();

        McpClientServerStatus::create([
            'server_id' => $server->id,
            'connection_status' => 'unreachable',
            'last_error' => 'Timed out',
            'tool_count' => 2,
            'refresh_started_at' => $laterFailedAttempt,
            'refresh_finished_at' => $laterFailedAttempt,
            'last_reachable_at' => $earlierSuccess,
            'triggered_by' => 'schedule',
        ]);

        $showResponse = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/{$server->id}");
        $showResponse->assertStatus(200);
        $showStatus = $showResponse->json('status');
        $this->assertNotNull($showStatus['last_reachable_at']);
        $this->assertNotSame($showStatus['last_reachable_at'], $showStatus['refresh_finished_at']);
        $this->assertSame(
            $earlierSuccess->toISOString(),
            \Carbon\Carbon::parse($showStatus['last_reachable_at'])->toISOString(),
        );

        $indexResponse = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/mcp-client-server');
        $indexResponse->assertStatus(200);
        $indexData = collect($indexResponse->json())->keyBy('id')[$server->id];
        $this->assertSame(
            $earlierSuccess->toISOString(),
            \Carbon\Carbon::parse($indexData['last_reachable_at'])->toISOString(),
        );
        $this->assertSame('unreachable', $indexData['connection_status']);
    }
}
