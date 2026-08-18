<?php

namespace ClarionApp\LlmClient\Tests\Unit\Models;

use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientServerStatus;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * McpClientTool::scopeActive() -- a row counts as "currently offered" iff
 * its own last_seen_at equals its server's current MAX(last_seen_at), a
 * pure per-server comparison against sibling tool rows. Does not read
 * mcp_client_server_statuses at all -- a server's most recent discover()
 * attempt (success or failure) plays no part in this comparison.
 */
class McpClientToolActiveScopeTest extends TestCase
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

    private function makeTool(McpClientServer $server, string $name, \DateTimeInterface $lastSeenAt): McpClientTool
    {
        return McpClientTool::create([
            'server_id' => $server->id,
            'synthetic_operation_id' => "mcp:{$server->id}:{$name}",
            'name' => $name,
            'input_schema' => ['type' => 'object'],
            'last_seen_at' => $lastSeenAt,
        ]);
    }

    #[Test]
    public function only_the_row_matching_its_servers_current_maximum_last_seen_at_is_active(): void
    {
        $server = $this->makeServer();

        $current = $this->makeTool($server, 'current_tool', now());
        $stale = $this->makeTool($server, 'stale_tool', now()->subHour());

        $activeIds = McpClientTool::where('server_id', $server->id)->active()->pluck('id')->all();

        $this->assertContains($current->id, $activeIds);
        $this->assertNotContains($stale->id, $activeIds);
    }

    #[Test]
    public function every_row_is_active_when_a_servers_rows_share_the_identical_last_seen_at(): void
    {
        $server = $this->makeServer();
        $seenAt = now();

        $first = $this->makeTool($server, 'first_tool', $seenAt);
        $second = $this->makeTool($server, 'second_tool', $seenAt);

        $activeIds = McpClientTool::where('server_id', $server->id)->active()->pluck('id')->all();

        $this->assertContains($first->id, $activeIds);
        $this->assertContains($second->id, $activeIds);
    }

    #[Test]
    public function one_servers_rows_never_affect_another_servers_active_inclusion(): void
    {
        $serverA = $this->makeServer();
        $serverB = $this->makeServer();

        // Server A's own current row is far older than server B's -- if
        // the scope were global rather than per-server, this row would
        // wrongly be excluded by server B's later max().
        $rowA = $this->makeTool($serverA, 'tool_a', now()->subDay());
        $this->makeTool($serverB, 'tool_b', now());

        $activeIds = McpClientTool::where('server_id', $serverA->id)->active()->pluck('id')->all();

        $this->assertContains($rowA->id, $activeIds);
    }

    #[Test]
    public function a_tool_row_is_active_even_when_its_server_has_no_status_row_at_all(): void
    {
        $server = $this->makeServer();
        $tool = $this->makeTool($server, 'never_refreshed_tool', now());

        // Confirm the fixture assumption directly -- no status row exists
        // for this server at all, the common seeding shape most of this
        // package's own tests use, having never gone through a real
        // discover() run.
        $this->assertSame(0, McpClientServerStatus::where('server_id', $server->id)->count());

        $activeIds = McpClientTool::where('server_id', $server->id)->active()->pluck('id')->all();

        $this->assertContains($tool->id, $activeIds);
    }
}
