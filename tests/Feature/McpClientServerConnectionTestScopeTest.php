<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\McpClientConnectionTest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-011 applied to the connection-test subsystem, mirroring
 * McpClientServerControllerScopeTest's own "a raw user_id field in the
 * request body is never accepted" precedent: user_id is always stamped
 * server-side from Auth::user()->id, and a foreign-owned test id 404s the
 * same uniform way an absent one does -- never a distinguishing 403,
 * never a 200.
 */
class McpClientServerConnectionTestScopeTest extends TestCase
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
    public function user_id_is_stamped_server_side_and_a_raw_user_id_in_the_body_is_never_accepted(): void
    {
        $response = $this->actingAs($this->userA)->postJson('/api/clarion-app/llm-client/mcp-client-server/test-connection', [
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/mcp',
            'credential' => null,
            'user_id' => $this->userB->id,
        ]);

        $response->assertStatus(202);
        $testId = $response->json('id');

        $row = McpClientConnectionTest::find($testId);
        $this->assertNotNull($row);
        $this->assertSame($this->userA->id, $row->user_id, 'user_id must be stamped from the caller, never accepted from the request body');
    }

    #[Test]
    public function a_second_users_test_id_is_uniformly_404_never_403_never_200(): void
    {
        $ownedByA = McpClientConnectionTest::create([
            'user_id' => $this->userA->id,
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/mcp',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->userB)->getJson("/api/clarion-app/llm-client/mcp-client-server/test-connection/{$ownedByA->id}");

        $response->assertStatus(404);
        $response->assertJson([
            'error' => 'MCP client server not found',
            'code' => 'mcp_client_server_not_found',
        ]);
    }

    #[Test]
    public function an_id_that_never_existed_is_also_a_uniform_404(): void
    {
        $response = $this->actingAs($this->userA)->getJson('/api/clarion-app/llm-client/mcp-client-server/test-connection/' . (string) Str::uuid());

        $response->assertStatus(404);
        $response->assertJson([
            'error' => 'MCP client server not found',
            'code' => 'mcp_client_server_not_found',
        ]);
    }
}
