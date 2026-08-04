<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\ServerStatus;
use ClarionApp\LlmClient\Services\ServerStatusProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for ServerStatusProjector projection rules.
 *
 * The projector derives the "current state" view from the raw ServerStatus row
 * on every READ — no timers, no background processes.
 */
class ServerStatusProjectorTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('llm_server_statuses')->delete();
        DB::table('llm_servers')->delete();
        parent::tearDown();
    }

    /**
     * Missing row → never_checked with everything else null/0.
     */
    #[Test]
    public function missing_row_projects_never_checked(): void
    {
        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Fresh Server',
        ]);

        $projector = app(ServerStatusProjector::class);
        $result = $projector->project($server);

        $this->assertEquals('never_checked', $result['connection_status']);
        $this->assertNull($result['last_outcome']);
        $this->assertNull($result['last_error']);
        $this->assertEquals(0, $result['model_count']);
        $this->assertFalse($result['in_flight']);
        $this->assertNull($result['refresh_started_at']);
        $this->assertNull($result['refresh_finished_at']);
    }

    /**
     * Existing row with no refresh in progress → in_flight false.
     */
    #[Test]
    public function completed_refresh_is_not_in_flight(): void
    {
        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test Server',
        ]);

        ServerStatus::create([
            'server_id' => $server->id,
            'connection_status' => 'reachable',
            'last_outcome' => 'models_updated',
            'model_count' => 5,
            'refresh_started_at' => now()->subMinutes(10),
            'refresh_finished_at' => now()->subMinutes(9),
        ]);

        $projector = app(ServerStatusProjector::class);
        $result = $projector->project($server);

        $this->assertEquals('reachable', $result['connection_status']);
        $this->assertEquals('models_updated', $result['last_outcome']);
        $this->assertEquals(5, $result['model_count']);
        $this->assertFalse($result['in_flight']);
    }

    /**
     * Refresh started < 60s ago and not finished → in_flight true.
     */
    #[Test]
    public function recent_refresh_is_in_flight(): void
    {
        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test Server',
        ]);

        ServerStatus::create([
            'server_id' => $server->id,
            'connection_status' => 'reachable',
            'last_outcome' => 'models_updated',
            'model_count' => 5,
            'refresh_started_at' => now()->subSeconds(30),
            'refresh_finished_at' => null,
        ]);

        $projector = app(ServerStatusProjector::class);
        $result = $projector->project($server);

        $this->assertTrue($result['in_flight']);
        $this->assertEquals('reachable', $result['connection_status']);
        $this->assertEquals('models_updated', $result['last_outcome']);
    }

    /**
     * Refresh started >= 60s ago and not finished → did_not_complete,
     * connection_status unchanged, in_flight false.
     */
    #[Test]
    public function stale_refresh_becomes_did_not_complete(): void
    {
        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test Server',
        ]);

        ServerStatus::create([
            'server_id' => $server->id,
            'connection_status' => 'reachable',
            'last_outcome' => 'models_updated',
            'model_count' => 5,
            'refresh_started_at' => now()->subSeconds(90),
            'refresh_finished_at' => null,
        ]);

        $projector = app(ServerStatusProjector::class);
        $result = $projector->project($server);

        $this->assertFalse($result['in_flight']);
        $this->assertEquals('did_not_complete', $result['last_outcome']);
        // Connection status should be preserved (not overwritten).
        $this->assertEquals('reachable', $result['connection_status']);
    }

    /**
     * Stale refresh with never_checked status → did_not_complete.
     */
    #[Test]
    public function stale_refresh_from_never_checked(): void
    {
        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test Server',
        ]);

        ServerStatus::create([
            'server_id' => $server->id,
            'connection_status' => 'never_checked',
            'last_outcome' => null,
            'model_count' => 0,
            'refresh_started_at' => now()->subSeconds(120),
            'refresh_finished_at' => null,
        ]);

        $projector = app(ServerStatusProjector::class);
        $result = $projector->project($server);

        $this->assertFalse($result['in_flight']);
        $this->assertEquals('did_not_complete', $result['last_outcome']);
        $this->assertEquals('never_checked', $result['connection_status']);
    }

    /**
     * Edge case: exactly at 60s boundary → still in_flight.
     */
    #[Test]
    public function exactly_at_60s_boundary_is_in_flight(): void
    {
        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test Server',
        ]);

        ServerStatus::create([
            'server_id' => $server->id,
            'connection_status' => 'reachable',
            'last_outcome' => null,
            'model_count' => 0,
            'refresh_started_at' => now()->subSeconds(59),
            'refresh_finished_at' => null,
        ]);

        $projector = app(ServerStatusProjector::class);
        $result = $projector->project($server);

        $this->assertTrue($result['in_flight']);
    }
}
