<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ServerStatus;
use ClarionApp\LlmClient\Models\Server;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Feature tests for ServerStatus model.
 *
 * Verifies model configuration, relationships, UUID generation, and
 * schema fidelity against the test schema.
 */
class ServerStatusModelTest extends TestCase
{
    #[Test]
    public function model_does_not_use_eloguent_multi_chain_bridge(): void
    {
        $model = new ServerStatus();
        $traits = class_uses($model);

        // Constitution §III: ServerStatus is a local-only model.
        // It must NOT use EloquentMultiChainBridge.
        $this->assertArrayNotHasKey(
            \ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge::class,
            $traits,
            'ServerStatus must not use EloquentMultiChainBridge'
        );
    }

    #[Test]
    public function uuid_generated_on_create(): void
    {
        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test Server',
        ]);

        $status = ServerStatus::create([
            'server_id' => $server->id,
        ]);

        $this->assertNotNull($status->id);
        // Validate UUID format
        $idValue = (string) $status->id;
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $idValue,
            'ID should be a valid UUID'
        );
    }

    #[Test]
    public function belongs_to_server_resolves(): void
    {
        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test Server',
        ]);

        $status = ServerStatus::create([
            'server_id' => $server->id,
        ]);

        $this->assertInstanceOf(Server::class, $status->server);
        $this->assertEquals($server->id, $status->server->id);
    }

    #[Test]
    public function schema_fidelity_assertion(): void
    {
        // Compare model's expected columns against the test schema columns.
        // This ensures the model and schema stay in sync.
        $model = new ServerStatus();
        $table = $model->getTable();

        $schemaColumns = Schema::getColumnListing($table);
        $expectedColumns = [
            'id',
            'server_id',
            'connection_status',
            'last_outcome',
            'last_error',
            'model_count',
            'refresh_started_at',
            'refresh_finished_at',
            'triggered_by',
            'created_at',
            'updated_at',
        ];

        sort($schemaColumns);
        sort($expectedColumns);

        $this->assertEquals(
            $expectedColumns,
            $schemaColumns,
            'ServerStatus model columns should match the test schema'
        );
    }
}
