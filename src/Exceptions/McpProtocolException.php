<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * A third-party MCP server responded, but the response itself was not
 * valid MCP -- malformed JSON-RPC, or a tools/list payload whose entries
 * do not match their own declared inputSchema. Distinguishes a
 * misbehaving server from one that is simply unreachable
 * (McpTransportException) or slow (McpTransportTimeoutException).
 */
class McpProtocolException extends \Exception
{
}
