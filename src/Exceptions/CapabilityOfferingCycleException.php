<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * Offering a candidate agent as a capability to a would-be caller would
 * close a cycle, direct or transitive, in the union of active
 * agent_capability_offerings and agent_helper_assignments edges
 * (109-agent-as-capability, data-model.md §9, research.md D3, FR-011).
 * Raised by CapabilityOfferingService::offer() and rendered as a 422 naming
 * the ordered cycle path (contracts/capability-offering-api.md).
 *
 * Mirrors HelperAssignmentCycleException's exact constructor/message shape.
 */
final class CapabilityOfferingCycleException extends \RuntimeException
{
    /**
     * @param list<string> $cyclePath ordered agent ids forming the cycle
     */
    public function __construct(
        public readonly string $offeredAgentId,
        public readonly string $callerAgentId,
        public readonly array $cyclePath,
    ) {
        parent::__construct(sprintf(
            "Offering agent '%s' as a capability to caller agent '%s' would create a loop: %s.",
            $offeredAgentId,
            $callerAgentId,
            implode(' -> ', $cyclePath),
        ));
    }
}
