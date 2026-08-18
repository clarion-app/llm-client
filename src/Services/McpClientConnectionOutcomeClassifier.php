<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\McpAuthenticationException;
use ClarionApp\LlmClient\Exceptions\McpProtocolException;
use ClarionApp\LlmClient\ValueObjects\McpConnectionOutcome;

/**
 * The single, shared definition of what a given MCP connection outcome
 * means -- used by both McpClientToolDiscoveryService::discover() (the
 * ongoing status path) and TestMcpClientConnectionJob (the new
 * test-before-save path), so a given exception's reported category can
 * never drift apart between the two call sites (FR-010's "both in test
 * results and in ongoing status display" requirement, structurally).
 *
 * Four categories: `reachable` (no exception), `auth_failed`
 * (McpAuthenticationException), `protocol_error` (McpProtocolException --
 * a server that responded, but not validly), and `unreachable` (every
 * other \Throwable, including McpTransportException and
 * McpTransportTimeoutException).
 */
class McpClientConnectionOutcomeClassifier
{
    public function classify(?\Throwable $e): McpConnectionOutcome
    {
        if ($e === null) {
            return new McpConnectionOutcome('reachable', '');
        }

        if ($e instanceof McpAuthenticationException) {
            return new McpConnectionOutcome('auth_failed', $e->getMessage());
        }

        if ($e instanceof McpProtocolException) {
            return new McpConnectionOutcome('protocol_error', $e->getMessage());
        }

        return new McpConnectionOutcome('unreachable', $e->getMessage());
    }
}
