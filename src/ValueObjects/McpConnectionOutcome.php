<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * What a single MCP connection attempt meant -- the shared result shape
 * McpClientConnectionOutcomeClassifier::classify() returns, consumed by
 * both McpClientToolDiscoveryService::discover() (ongoing status display)
 * and TestMcpClientConnectionJob (test-before-save), so the two call
 * sites can never disagree about a given exception's category.
 */
readonly class McpConnectionOutcome
{
    public function __construct(
        public string $category,
        public string $message,
    ) {
    }
}
