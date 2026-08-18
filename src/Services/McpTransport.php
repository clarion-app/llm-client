<?php

namespace ClarionApp\LlmClient\Services;

/**
 * A live connection to one third-party MCP server, able to perform the
 * three calls a client makes: the initialize handshake, listing the
 * tools currently offered, and invoking one of them.
 *
 * Two implementations exist -- StreamableHttpMcpTransport and
 * StdioMcpTransport -- both stateless from the caller's own perspective:
 * a fresh instance is built per call site by McpTransportFactory, never
 * held open or reused across separate invocations, since a queue-worker
 * process is reused across many subsequent, unrelated jobs.
 */
interface McpTransport
{
    /**
     * Perform the MCP initialize handshake. Throws McpTransportException
     * (or its McpTransportTimeoutException subtype) on failure; never
     * returns a partial or otherwise ambiguous success.
     */
    public function initialize(): void;

    /**
     * The server's current tool list, one entry per offered tool:
     * array<int, array{name: string, description: ?string, inputSchema: array, annotations: ?array}>
     */
    public function listTools(): array;

    /**
     * Invoke one tool by name. Returns the raw MCP content envelope on
     * success. Throws on transport/protocol failure -- never returns a
     * malformed or partial envelope silently.
     */
    public function callTool(string $name, array $arguments): array;
}
