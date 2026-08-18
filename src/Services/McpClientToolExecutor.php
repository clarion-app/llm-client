<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;

/**
 * Invokes one cached external tool: resolves the server's own transport
 * (McpTransportFactory, which already bounds every call it builds by
 * config('llm-client.mcp_client.call_timeout_seconds')), performs the
 * initialize handshake, then the one tools/call the caller asked for, and
 * returns the raw MCP content envelope on success -- unchanged, including
 * a server-reported tool-level isError: true, since that is a legitimate
 * response from the tool itself, not a transport failure.
 *
 * Every transport-level failure (unreachable, timed out, malformed/
 * misbehaving response) or any other unexpected throwable is caught here
 * and converted into the same {content: [{type: "text", text: "Error:
 * ..."}], isError: true} shape McpToolExecutor::errorResult() already
 * produces for a failed built-in call -- never allowed to propagate out
 * of execute() uncaught, so one hostile or hung third-party server can
 * never crash or stall the turn that called it.
 */
class McpClientToolExecutor
{
    public function __construct(
        private readonly McpTransportFactory $transportFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{content: array<int, array{type: string, text: string, mimeType?: string}>, isError: bool}
     */
    public function execute(McpClientServer $server, McpClientTool $tool, array $arguments): array
    {
        try {
            $transport = $this->transportFactory->for($server);
            $transport->initialize();

            return $transport->callTool($tool->name, $arguments);
        } catch (\Throwable $e) {
            return $this->errorResult($e->getMessage());
        }
    }

    /**
     * The identical shape McpToolExecutor::errorResult() already produces
     * for a failed built-in call, so nothing downstream (condensation,
     * trace recording, the model's own parsing) needs to know a failure
     * came from a third-party server rather than Clarion's own API.
     */
    private function errorResult(string $message): array
    {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => "Error: {$message}",
                ],
            ],
            'isError' => true,
        ];
    }
}
