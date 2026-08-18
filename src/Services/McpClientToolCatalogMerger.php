<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;

/**
 * formatTool() -- the one shared formatting recipe turning one cached
 * McpClientTool into the same {operationId, summary, method, path,
 * paramSchema} catalog-entry shape every other search_operations result
 * already carries, mirroring
 * CapabilityCatalogMerger::formatOffering()'s own field-for-field
 * precedent for a non-OpenAPI-operation source.
 */
class McpClientToolCatalogMerger
{
    /**
     * @return array{operationId: string, summary: string, method: string, path: string, paramSchema: array}
     */
    public static function formatTool(McpClientTool $tool, McpClientServer $server): array
    {
        return [
            'operationId' => $tool->synthetic_operation_id,
            // Already truncated/stripped and provenance-prefixed at
            // cache-write time (McpClientToolDiscoveryService, via
            // McpClientTextSanitizer) -- carried here verbatim, never
            // re-sanitized or re-derived.
            'summary' => (string) $tool->description,
            // Fixed sentinel, never derived from the server -- exists
            // purely so the same $validation machinery this class's
            // entries flow into has *a* method value to carry.
            'method' => 'MCP_EXTERNAL',
            'path' => "/mcp-client/{$server->id}/{$tool->name}",
            // The tool's own declared inputSchema, passed through
            // unchanged -- structurally inert, never executed or
            // interpreted as instructions.
            'paramSchema' => is_array($tool->input_schema) ? $tool->input_schema : [],
        ];
    }
}
