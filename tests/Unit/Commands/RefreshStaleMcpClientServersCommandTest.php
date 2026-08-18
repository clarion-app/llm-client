<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\LlmClient\Jobs\RefreshMcpClientServerToolsJob;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientServerStatus;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `llm-client:refresh-external-mcp-tools` -- dispatches
 * RefreshMcpClientServerToolsJob for every McpClientServer whose latest
 * status row is stale (older than mcp_client.tool_cache_ttl_minutes) or
 * has none yet, and skips a server refreshed within that window.
 *
 * Written before RefreshStaleMcpClientServersCommand exists -- expected
 * to FAIL red (command not found) until it is created.
 */
class RefreshStaleMcpClientServersCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.mcp_client.tool_cache_ttl_minutes' => 15]);
    }

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
    public function a_server_with_no_status_row_at_all_is_dispatched(): void
    {
        $server = $this->makeServer();

        Queue::fake();
        $exitCode = Artisan::call('llm-client:refresh-external-mcp-tools');

        $this->assertSame(0, $exitCode);
        Queue::assertPushed(RefreshMcpClientServerToolsJob::class, function (RefreshMcpClientServerToolsJob $job) use ($server) {
            return $job->serverId === $server->id;
        });
    }

    #[Test]
    public function a_server_whose_last_refresh_is_older_than_the_ttl_is_dispatched(): void
    {
        $server = $this->makeServer();
        McpClientServerStatus::create([
            'server_id' => $server->id,
            'connection_status' => 'reachable',
            'tool_count' => 3,
            'refresh_finished_at' => now()->subMinutes(20),
        ]);

        Queue::fake();
        Artisan::call('llm-client:refresh-external-mcp-tools');

        Queue::assertPushed(RefreshMcpClientServerToolsJob::class, function (RefreshMcpClientServerToolsJob $job) use ($server) {
            return $job->serverId === $server->id;
        });
    }

    #[Test]
    public function a_server_refreshed_within_the_ttl_is_skipped(): void
    {
        $server = $this->makeServer();
        McpClientServerStatus::create([
            'server_id' => $server->id,
            'connection_status' => 'reachable',
            'tool_count' => 3,
            'refresh_finished_at' => now()->subMinutes(5),
        ]);

        Queue::fake();
        Artisan::call('llm-client:refresh-external-mcp-tools');

        Queue::assertNotPushed(RefreshMcpClientServerToolsJob::class);
    }

    #[Test]
    public function a_soft_deleted_server_is_never_dispatched(): void
    {
        $server = $this->makeServer();
        $server->delete();

        Queue::fake();
        Artisan::call('llm-client:refresh-external-mcp-tools');

        Queue::assertNotPushed(RefreshMcpClientServerToolsJob::class);
    }

    #[Test]
    public function dispatched_jobs_are_marked_as_scheduled(): void
    {
        $server = $this->makeServer();

        Queue::fake();
        Artisan::call('llm-client:refresh-external-mcp-tools');

        Queue::assertPushed(RefreshMcpClientServerToolsJob::class, function (RefreshMcpClientServerToolsJob $job) use ($server) {
            return $job->serverId === $server->id && $job->triggeredBy === 'scheduled';
        });
    }
}
