<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;

/**
 * Resolves which McpTransport implementation one McpClientServer's own
 * configured transport calls for, and builds it -- carrying the server's
 * decrypted credential the way the interface's own contract requires: an
 * Authorization header for Streamable HTTP (the same Bearer-token shape
 * RefreshServerModelsJob/McpToolExecutor::executeHttpCall() already use),
 * or an environment variable -- never a command-line argument, so it can
 * never appear in `ps` output on a shared host -- for stdio, mirroring
 * GitDefinitionFileReader's own array-argument Process construction to
 * avoid shell interpolation of a stored, reusable string. Every call
 * builds a fresh instance; nothing is cached or reused across calls, per
 * McpTransport's own stateless-per-call-site contract.
 */
class McpTransportFactory
{
    public function for(McpClientServer $server): McpTransport
    {
        $callTimeoutSeconds = (int) config('llm-client.mcp_client.call_timeout_seconds', 30);
        $discoveryTimeoutSeconds = (int) config('llm-client.mcp_client.discovery_timeout_seconds', 15);

        if ($server->transport === McpTransportKind::Stdio) {
            $args = is_array($server->args) ? $server->args : [];

            $env = [];
            if ($server->credential !== null && $server->credential !== '') {
                $env['MCP_CLIENT_CREDENTIAL'] = $server->credential;
            }

            return new StdioMcpTransport(
                command: array_values(array_merge([(string) $server->command], $args)),
                env: $env,
                callTimeoutSeconds: $callTimeoutSeconds,
                handshakeTimeoutSeconds: $discoveryTimeoutSeconds,
            );
        }

        return new StreamableHttpMcpTransport(
            url: (string) $server->url,
            credential: $server->credential,
            callTimeoutSeconds: $callTimeoutSeconds,
            handshakeTimeoutSeconds: $discoveryTimeoutSeconds,
        );
    }
}
