<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * A transport-level failure reaching or communicating with a third-party
 * MCP server -- connection refused, DNS failure, a stdio subprocess that
 * failed to spawn or exited unexpectedly, or a credential the server
 * rejected. Caught at the tool-executor boundary and converted into the
 * same content-envelope failure shape a failed built-in call already
 * produces; never allowed to reach AgentLoopService directly.
 */
class McpTransportException extends \Exception
{
}
