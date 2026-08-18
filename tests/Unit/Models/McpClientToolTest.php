<?php

namespace ClarionApp\LlmClient\Tests\Unit\Models;

use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * McpClientTool::findBySyntheticId() -- the primary lookup a call to an
 * external tool performs against the local cache.
 */
class McpClientToolTest extends TestCase
{
    private function makeServer(): McpClientServer
    {
        return McpClientServer::create([
            'name' => 'A Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => 'https://example.test/mcp',
            'user_id' => (string) Str::uuid(),
        ]);
    }

    #[Test]
    public function find_by_synthetic_id_returns_the_exact_matching_row(): void
    {
        $server = $this->makeServer();
        $syntheticId = "mcp:{$server->id}:search";

        $tool = McpClientTool::create([
            'server_id' => $server->id,
            'synthetic_operation_id' => $syntheticId,
            'name' => 'search',
            'description' => 'Searches things.',
            'input_schema' => ['type' => 'object'],
            'last_seen_at' => now(),
        ]);

        $found = McpClientTool::findBySyntheticId($syntheticId);

        $this->assertNotNull($found);
        $this->assertSame($tool->id, $found->id);
    }

    #[Test]
    public function find_by_synthetic_id_returns_null_for_a_completely_unknown_id(): void
    {
        $this->assertNull(McpClientTool::findBySyntheticId('mcp:does-not-exist:search'));
    }

    #[Test]
    public function find_by_synthetic_id_returns_null_for_a_synthetic_shaped_id_with_no_matching_row(): void
    {
        $server = $this->makeServer();

        McpClientTool::create([
            'server_id' => $server->id,
            'synthetic_operation_id' => "mcp:{$server->id}:search",
            'name' => 'search',
            'input_schema' => ['type' => 'object'],
            'last_seen_at' => now(),
        ]);

        // Looks synthetic (the "mcp:" prefix, a real server id) but no row
        // was ever cached under this exact id.
        $found = McpClientTool::findBySyntheticId("mcp:{$server->id}:some_other_tool");

        $this->assertNull($found);
    }
}
