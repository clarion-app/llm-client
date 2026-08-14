<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * Assigning a candidate helper to a would-be parent would close a cycle,
 * direct or transitive — the would-be parent already appears, directly or
 * transitively, among the candidate helper's own descendants
 * (097-subagent-model, data-model.md §3, research.md D2, FR-006). Raised by
 * AgentHelperService::assign() and rendered as a 422 naming the ordered
 * cycle path (contracts/subagent-model-api.md §1).
 */
final class HelperAssignmentCycleException extends \RuntimeException
{
    /**
     * @param list<string> $cyclePath ordered agent ids forming the cycle
     */
    public function __construct(
        public readonly string $parentAgentId,
        public readonly string $helperAgentId,
        public readonly array $cyclePath,
    ) {
        parent::__construct(sprintf(
            "Assigning helper agent '%s' to parent agent '%s' would create a loop: %s.",
            $helperAgentId,
            $parentAgentId,
            implode(' -> ', $cyclePath),
        ));
    }
}
