<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * A third-party MCP server explicitly rejected the stored credential
 * (HTTP 401/403 over Streamable HTTP) -- distinct from a server that was
 * simply unreachable, so a discovery run can report a connection status
 * of "auth_failed" rather than conflating a missing/wrong credential
 * with the server being offline. A subtype of McpTransportException, so
 * every existing catch(McpTransportException) site still catches it.
 */
class McpAuthenticationException extends McpTransportException
{
}
