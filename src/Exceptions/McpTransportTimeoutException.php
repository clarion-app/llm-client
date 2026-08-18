<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * A single MCP call -- the initialize handshake, a tools/list request, or
 * a tool invocation -- exceeded its configured time bound. A distinct
 * subtype of McpTransportException so a bounded-but-cut-off call can be
 * reported distinctly from a server that was simply unreachable from the
 * first attempt.
 */
class McpTransportTimeoutException extends McpTransportException
{
}
