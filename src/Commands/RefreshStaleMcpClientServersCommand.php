<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Jobs\RefreshMcpClientServerToolsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled sweep dispatching a fresh RefreshMcpClientServerToolsJob for
 * every McpClientServer whose cached tool list is stale (its latest
 * status row's refresh_finished_at is older than
 * mcp_client.tool_cache_ttl_minutes) or has no status row at all yet --
 * the out-of-band refresh trigger that keeps a server's tools current
 * across sessions without any per-conversation staleness check.
 *
 * A left join against mcp_client_server_statuses rather than a query per
 * server: that table carries at most one row per server_id (unique
 * indexed), so the join can never duplicate a candidate.
 */
class RefreshStaleMcpClientServersCommand extends Command
{
    protected $signature = 'llm-client:refresh-external-mcp-tools';

    protected $description = 'Refresh the cached tool list for every configured MCP client server whose cache is stale or missing';

    public function handle(): int
    {
        $ttlMinutes = (int) config('llm-client.mcp_client.tool_cache_ttl_minutes', 15);
        $cutoff = now()->subMinutes($ttlMinutes);

        $staleServerIds = DB::table('mcp_client_servers')
            ->leftJoin('mcp_client_server_statuses', 'mcp_client_server_statuses.server_id', '=', 'mcp_client_servers.id')
            ->whereNull('mcp_client_servers.deleted_at')
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('mcp_client_server_statuses.refresh_finished_at')
                    ->orWhere('mcp_client_server_statuses.refresh_finished_at', '<', $cutoff);
            })
            ->pluck('mcp_client_servers.id');

        foreach ($staleServerIds as $serverId) {
            RefreshMcpClientServerToolsJob::dispatch($serverId, 'scheduled');
        }

        $this->info("Dispatched refresh for {$staleServerIds->count()} stale MCP client server(s).");

        return self::SUCCESS;
    }
}
