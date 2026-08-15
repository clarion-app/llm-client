<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The outcome of RouterService::route() — which agent (if any) a
 * conversation should be bound to, and why (data-model.md's own
 * "New value object" section). Plain, unvalidated, mirroring this
 * package's own RoleResolution/DegradationDecision precedent.
 */
final class RouterDecision
{
    public function __construct(
        public readonly ?string $agentId,
        public readonly ?string $agentVersionId,
        public readonly string $reason, // 'automatic' | 'default' | 'none'
    ) {}

    public function hasAgent(): bool
    {
        return $this->agentId !== null;
    }
}
