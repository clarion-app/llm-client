<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\McpClientServer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Server visibility follows McpClientServer::eligibleFor() -- the same
 * personal-or-installation-scope predicate RoleAssignment resolution
 * already applies for role scoping (src/Models/RoleAssignment.php) -- and
 * `scope` in a create request is always translated server-side into
 * `user_id`, never accepted as a raw field from the client.
 */
class McpClientServerControllerScopeTest extends TestCase
{
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create(['password' => Hash::make('password')]);
        $this->userB = User::factory()->create(['password' => Hash::make('password')]);
    }

    #[Test]
    public function a_personal_scope_server_is_invisible_to_a_different_user_via_index(): void
    {
        McpClientServer::create([
            'name' => "A's private server",
            'transport' => 'stdio',
            'command' => 'npx',
            'user_id' => $this->userA->id,
        ]);

        $response = $this->actingAs($this->userB)->getJson('/api/clarion-app/llm-client/mcp-client-server');

        $response->assertStatus(200);
        $names = collect($response->json())->pluck('name');
        $this->assertNotContains("A's private server", $names);
    }

    #[Test]
    public function a_personal_scope_server_is_invisible_to_a_different_user_via_show(): void
    {
        $server = McpClientServer::create([
            'name' => "A's private server",
            'transport' => 'stdio',
            'command' => 'npx',
            'user_id' => $this->userA->id,
        ]);

        $response = $this->actingAs($this->userB)->getJson("/api/clarion-app/llm-client/mcp-client-server/{$server->id}");

        $response->assertStatus(404);
    }

    #[Test]
    public function a_project_scope_server_is_visible_to_every_authenticated_user(): void
    {
        $server = McpClientServer::create([
            'name' => 'Team web-search server',
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/mcp',
            'user_id' => McpClientServer::INSTALLATION_SCOPE_ID,
        ]);

        foreach ([$this->userA, $this->userB] as $user) {
            $indexResponse = $this->actingAs($user)->getJson('/api/clarion-app/llm-client/mcp-client-server');
            $indexResponse->assertStatus(200);
            $this->assertContains($server->id, collect($indexResponse->json())->pluck('id'));

            $showResponse = $this->actingAs($user)->getJson("/api/clarion-app/llm-client/mcp-client-server/{$server->id}");
            $showResponse->assertStatus(200);
        }
    }

    #[Test]
    public function scope_personal_in_the_request_body_is_translated_to_the_callers_own_user_id(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->userA)->postJson('/api/clarion-app/llm-client/mcp-client-server', [
            'name' => 'Personal server',
            'transport' => 'stdio',
            'command' => 'npx',
            'scope' => 'personal',
        ]);

        $response->assertStatus(201);
        $server = McpClientServer::find($response->json('id'));
        $this->assertSame($this->userA->id, $server->user_id);
    }

    #[Test]
    public function scope_project_in_the_request_body_is_translated_to_the_installation_sentinel(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->userA)->postJson('/api/clarion-app/llm-client/mcp-client-server', [
            'name' => 'Shared server',
            'transport' => 'stdio',
            'command' => 'npx',
            'scope' => 'project',
        ]);

        $response->assertStatus(201);
        $server = McpClientServer::find($response->json('id'));
        $this->assertSame(McpClientServer::INSTALLATION_SCOPE_ID, $server->user_id);
    }

    #[Test]
    public function a_raw_user_id_field_in_the_request_body_is_never_accepted(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->userA)->postJson('/api/clarion-app/llm-client/mcp-client-server', [
            'name' => 'Spoof attempt',
            'transport' => 'stdio',
            'command' => 'npx',
            'scope' => 'personal',
            'user_id' => $this->userB->id,
        ]);

        $response->assertStatus(201);
        $server = McpClientServer::find($response->json('id'));
        $this->assertSame($this->userA->id, $server->user_id);
        $this->assertNotSame($this->userB->id, $server->user_id);
    }
}
