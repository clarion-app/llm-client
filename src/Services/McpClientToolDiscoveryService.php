<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\McpAuthenticationException;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientServerStatus;
use ClarionApp\LlmClient\Models\McpClientTool;

/**
 * Runs the initialize -> tools/list handshake against one McpClientServer
 * and reconciles the result into two local tables: mcp_client_tools (one
 * row per currently-offered tool, stamped with the moment this refresh
 * ran) and mcp_client_server_statuses (one row per server, upserted in
 * place) -- mirroring RefreshServerModelsJob's own create/reconcile/
 * soft-delete shape for LanguageModel rows.
 *
 * "Soft removal" here works differently from that job's outright
 * ->delete(), because mcp_client_tools has no deleted_at column at all: a
 * tool the server no longer offers simply isn't touched by this refresh,
 * so its last_seen_at falls behind the new refresh_finished_at and
 * McpClientTool::scopeActive() excludes it from then on, while the row
 * itself -- and any invocation history attributed to it -- stays intact.
 *
 * Every outcome lands on exactly one of the four connection_status values
 * a status row can hold (reachable/unreachable/auth_failed/unknown) --
 * never an uncaught exception escaping to the caller, the same failure-
 * isolation discipline McpClientToolExecutor applies one layer down for a
 * single tool invocation.
 */
class McpClientToolDiscoveryService
{
    public function __construct(
        private readonly McpTransportFactory $transportFactory,
        private readonly McpClientTextSanitizer $sanitizer,
    ) {
    }

    public function discover(McpClientServer $server): McpClientServerStatus
    {
        $existingStatus = McpClientServerStatus::where('server_id', $server->id)->first();

        $status = McpClientServerStatus::updateOrCreate(
            ['server_id' => $server->id],
            [
                'connection_status' => $existingStatus?->connection_status ?? 'unknown',
                'refresh_started_at' => now(),
                'refresh_finished_at' => null,
            ]
        );

        try {
            $transport = $this->transportFactory->for($server);
            $transport->initialize();
            $tools = $transport->listTools();
        } catch (McpAuthenticationException $e) {
            $status->update([
                'connection_status' => 'auth_failed',
                'last_error' => $e->getMessage(),
                'tool_count' => 0,
                'refresh_finished_at' => now(),
            ]);

            return $status->fresh();
        } catch (\Throwable $e) {
            // Every other transport-level failure -- unreachable, timed
            // out, or a malformed/misbehaving response -- is reported as
            // "unreachable": the status row's own connection_status
            // vocabulary has no separate timeout/protocol-error value,
            // and the property it exists to preserve is "could not be
            // used at all" vs. "has no tools", not a full taxonomy of
            // every possible transport failure.
            $status->update([
                'connection_status' => 'unreachable',
                'last_error' => $e->getMessage(),
                'tool_count' => 0,
                'refresh_finished_at' => now(),
            ]);

            return $status->fresh();
        }

        // One shared instant, stamped onto every tool this refresh finds
        // *and* onto the status row's own refresh_finished_at, so
        // McpClientTool::scopeActive()'s "last_seen_at >= refresh_finished_at"
        // check holds for every tool this refresh actually touched and
        // fails for any it did not -- the entire soft-removal mechanism
        // rests on both values being identical, not merely close.
        $refreshTimestamp = now();
        $toolCount = 0;

        foreach ($tools as $tool) {
            $name = is_string($tool['name'] ?? null) ? $tool['name'] : null;
            if ($name === null || $name === '') {
                continue;
            }

            $inputSchema = is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [];
            $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : null;
            $description = $this->sanitizer->sanitize($tool['description'] ?? null, $server->name);

            McpClientTool::updateOrCreate(
                ['synthetic_operation_id' => "mcp:{$server->id}:{$name}"],
                [
                    'server_id' => $server->id,
                    'name' => $name,
                    'description' => $description,
                    'input_schema' => $inputSchema,
                    'annotations' => $annotations,
                    'last_seen_at' => $refreshTimestamp,
                ]
            );
            $toolCount++;
        }

        $status->update([
            'connection_status' => 'reachable',
            'last_error' => null,
            'tool_count' => $toolCount,
            'refresh_finished_at' => $refreshTimestamp,
        ]);

        return $status->fresh();
    }
}
