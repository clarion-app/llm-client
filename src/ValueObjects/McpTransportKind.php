<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The two transports an MCP client connection may use to reach a
 * third-party server -- the two shapes the current MCP specification
 * itself defines, never the deprecated HTTP+SSE shape.
 */
enum McpTransportKind: string
{
    case StreamableHttp = 'streamable_http';
    case Stdio = 'stdio';
}
