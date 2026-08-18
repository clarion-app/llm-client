<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\Services\McpClientToolCatalogMerger;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * McpClientToolCatalogMerger::formatTool() -- the shared formatting
 * recipe turning one cached McpClientTool into the same {operationId,
 * summary, method, path, paramSchema} shape every other search_operations
 * result already carries, mirroring
 * CapabilityCatalogMerger::formatOffering()'s own field-for-field
 * precedent.
 *
 * Written before McpClientToolCatalogMerger exists -- expected to FAIL
 * red (class not found) until it is created.
 */
class McpClientToolCatalogMergerTest extends TestCase
{
    private function makeServer(): McpClientServer
    {
        return McpClientServer::create([
            'name' => 'Team web-search server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => 'https://example.test/mcp',
            'user_id' => (string) Str::uuid(),
        ]);
    }

    #[Test]
    public function format_tool_produces_the_exact_contract_shape(): void
    {
        $server = $this->makeServer();
        $tool = McpClientTool::create([
            'server_id' => $server->id,
            'synthetic_operation_id' => "mcp:{$server->id}:search",
            'name' => 'search',
            'description' => '[External tool via Team web-search server] Search the web for a query and return snippets.',
            'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            'last_seen_at' => now(),
        ]);

        $entry = McpClientToolCatalogMerger::formatTool($tool, $server);

        $this->assertSame("mcp:{$server->id}:search", $entry['operationId']);
        $this->assertSame($tool->description, $entry['summary']);
        $this->assertSame('MCP_EXTERNAL', $entry['method']);
        $this->assertSame("/mcp-client/{$server->id}/search", $entry['path']);
        $this->assertSame($tool->input_schema, $entry['paramSchema']);
    }

    #[Test]
    public function the_path_is_the_same_synthesized_path_the_denylist_checks_against(): void
    {
        // Two servers, identically-named tool -- the paths must differ,
        // since the denylist is checked per-path and a same-named tool on
        // a different server must be independently denyable.
        $serverOne = $this->makeServer();
        $serverTwo = $this->makeServer();

        $toolOne = McpClientTool::create([
            'server_id' => $serverOne->id,
            'synthetic_operation_id' => "mcp:{$serverOne->id}:delete_file",
            'name' => 'delete_file',
            'input_schema' => ['type' => 'object'],
            'last_seen_at' => now(),
        ]);
        $toolTwo = McpClientTool::create([
            'server_id' => $serverTwo->id,
            'synthetic_operation_id' => "mcp:{$serverTwo->id}:delete_file",
            'name' => 'delete_file',
            'input_schema' => ['type' => 'object'],
            'last_seen_at' => now(),
        ]);

        $entryOne = McpClientToolCatalogMerger::formatTool($toolOne, $serverOne);
        $entryTwo = McpClientToolCatalogMerger::formatTool($toolTwo, $serverTwo);

        $this->assertNotSame($entryOne['path'], $entryTwo['path']);
        $this->assertNotSame($entryOne['operationId'], $entryTwo['operationId']);
    }

    #[Test]
    public function param_schema_is_the_tools_own_input_schema_verbatim(): void
    {
        $server = $this->makeServer();
        $schema = ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']];
        $tool = McpClientTool::create([
            'server_id' => $server->id,
            'synthetic_operation_id' => "mcp:{$server->id}:read_file",
            'name' => 'read_file',
            'input_schema' => $schema,
            'last_seen_at' => now(),
        ]);

        $entry = McpClientToolCatalogMerger::formatTool($tool, $server);

        $this->assertSame($schema, $entry['paramSchema']);
    }
}
