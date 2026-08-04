<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\ServerStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Feature tests for GET /server-status endpoint.
 *
 * Verifies that the endpoint returns one entry for every server
 * including those with no status row, and that in_flight is
 * server-computed (not cached).
 */
class ServerStatusEndpointTest extends TestCase
{
    private User $user;
    private Server $serverWithStatus;
    private Server $serverWithoutStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->serverWithStatus = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Server With Status',
            'server_url' => 'http://localhost:8081',
            'token' => 'test-token',
            'provider_type' => 'openai',
        ]);

        $this->serverWithoutStatus = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Server Without Status',
            'server_url' => 'http://localhost:8082',
            'token' => 'test-token',
            'provider_type' => 'openai',
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('llm_server_statuses')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * GET /server-status returns one entry for every server,
     * including those with no status row.
     */
    #[Test]
    public function returns_all_servers_including_no_status_rows(): void
    {
        // Create a status row for one server only.
        ServerStatus::create([
            'server_id' => $this->serverWithStatus->id,
            'connection_status' => 'reachable',
            'last_outcome' => 'models_updated',
            'model_count' => 5,
            'refresh_started_at' => now()->subSeconds(10),
            'refresh_finished_at' => now()->subSeconds(5),
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/server-status');

        $response->assertStatus(200);
        $data = $response->json();

        // Should have two entries (one per server).
        $this->assertCount(2, $data);

        // Find entries by server_id.
        $withStatus = collect($data)->firstWhere('server_id', $this->serverWithStatus->id);
        $withoutStatus = collect($data)->firstWhere('server_id', $this->serverWithoutStatus->id);

        $this->assertNotNull($withStatus);
        $this->assertNotNull($withoutStatus);

        // Server with status should have the correct values.
        $this->assertEquals('reachable', $withStatus['connection_status']);
        $this->assertEquals('models_updated', $withStatus['last_outcome']);
        $this->assertEquals(5, $withStatus['model_count']);

        // Server without status should project as never_checked.
        $this->assertEquals('never_checked', $withoutStatus['connection_status']);
        $this->assertNull($withoutStatus['last_outcome']);
        $this->assertEquals(0, $withoutStatus['model_count']);
    }

    /**
     * in_flight is server-computed (not cached).
     */
    #[Test]
    public function in_flight_is_server_computed(): void
    {
        // Create a status row with refresh in progress.
        ServerStatus::create([
            'server_id' => $this->serverWithStatus->id,
            'connection_status' => 'reachable',
            'refresh_started_at' => now()->subSeconds(5),
            'triggered_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/server-status');
        $data = $response->json();

        $withStatus = collect($data)->firstWhere('server_id', $this->serverWithStatus->id);

        $this->assertTrue($withStatus['in_flight']);
    }

    /**
     * Endpoint is inside auth:api — unauthenticated requests are rejected.
     */
    #[Test]
    public function endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/clarion-app/llm-client/server-status');

        $response->assertStatus(401);
    }

    /**
     * Each entry contains server_id and the projection fields.
     */
    #[Test]
    public function each_entry_contains_required_fields(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/server-status');
        $data = $response->json();

        foreach ($data as $entry) {
            $this->assertArrayHasKey('server_id', $entry);
            $this->assertArrayHasKey('connection_status', $entry);
            $this->assertArrayHasKey('last_outcome', $entry);
            $this->assertArrayHasKey('model_count', $entry);
            $this->assertArrayHasKey('in_flight', $entry);
        }
    }
}
