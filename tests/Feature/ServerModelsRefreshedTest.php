<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\ServerModelsRefreshed;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\ServerStatus;
use ClarionApp\LlmClient\Services\ServerStatusProjector;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Feature tests for ServerModelsRefreshed event.
 *
 * Verifies channel name, payload identity with REST projection,
 * and null-triggered silence (event not dispatched when triggered_by is null).
 */
class ServerModelsRefreshedTest extends TestCase
{
    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test Server',
            'server_url' => 'http://localhost:8081',
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
     * Event broadcasts on PrivateChannel('User.{triggeredBy}').
     */
    #[Test]
    public function event_broadcasts_on_correct_private_channel(): void
    {
        $event = new ServerModelsRefreshed(
            $this->server->id,
            $this->user->id,
        );

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals("private-User.{$this->user->id}", $channels[0]->name);
    }

    /**
     * Event with null triggered_by broadcasts on no channel.
     */
    #[Test]
    public function event_with_null_triggered_by_broadcasts_on_no_channel(): void
    {
        $event = new ServerModelsRefreshed(
            $this->server->id,
            null,
        );

        $channels = $event->broadcastOn();
        $this->assertEmpty($channels);
    }

    /**
     * broadcastWith() returns the same ServerStatusProjector output
     * as GET /server-status would return for this server.
     */
    #[Test]
    public function broadcast_payload_matches_projector_output(): void
    {
        // Create a status row with a completed refresh.
        ServerStatus::create([
            'server_id' => $this->server->id,
            'connection_status' => 'reachable',
            'last_outcome' => 'models_updated',
            'model_count' => 5,
            'triggered_by' => $this->user->id,
            'refresh_started_at' => now()->subSeconds(10),
            'refresh_finished_at' => now()->subSeconds(5),
        ]);

        $event = new ServerModelsRefreshed(
            $this->server->id,
            $this->user->id,
        );

        $payload = $event->broadcastWith();

        // Compare against the projector output.
        $projector = app(ServerStatusProjector::class);
        $projected = $projector->project($this->server);

        $this->assertArrayHasKey('server_id', $payload);
        $this->assertEquals($this->server->id, $payload['server_id']);
        $this->assertEquals($projected['connection_status'], $payload['connection_status']);
        $this->assertEquals($projected['last_outcome'], $payload['last_outcome']);
        $this->assertEquals($projected['model_count'], $payload['model_count']);
        $this->assertEquals($projected['in_flight'], $payload['in_flight']);
    }

    /**
     * Payload for a server with no status row matches the projector's
     * never_checked projection.
     */
    #[Test]
    public function broadcast_payload_for_missing_status_row(): void
    {
        // No status row created.
        $event = new ServerModelsRefreshed(
            $this->server->id,
            $this->user->id,
        );

        $payload = $event->broadcastWith();

        $projector = app(ServerStatusProjector::class);
        $projected = $projector->project($this->server);

        $this->assertEquals('never_checked', $payload['connection_status']);
        $this->assertNull($payload['last_outcome']);
        $this->assertEquals(0, $payload['model_count']);
        $this->assertFalse($payload['in_flight']);
    }

    /**
     * Payload includes in_flight: true when refresh is in progress.
     */
    #[Test]
    public function broadcast_payload_includes_in_flight_state(): void
    {
        ServerStatus::create([
            'server_id' => $this->server->id,
            'connection_status' => 'reachable',
            'refresh_started_at' => now()->subSeconds(5),
            'triggered_by' => $this->user->id,
        ]);

        $event = new ServerModelsRefreshed(
            $this->server->id,
            $this->user->id,
        );

        $payload = $event->broadcastWith();

        $this->assertTrue($payload['in_flight']);
    }
}
