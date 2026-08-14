<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * Assigning a candidate helper to a would-be parent would nest the helper
 * more levels below its root ancestor than the configured
 * `llm-client.helpers.max_depth` allows (097-subagent-model, data-model.md
 * §3, research.md D5). Raised by AgentHelperService::assign() and rendered
 * as a 422 naming both the computed depth and the configured maximum
 * (contracts/subagent-model-api.md §1).
 */
final class HelperDepthLimitExceededException extends \RuntimeException
{
    public function __construct(
        public readonly string $parentAgentId,
        public readonly string $helperAgentId,
        public readonly int $computedDepth,
        public readonly int $maxDepth,
    ) {
        parent::__construct(sprintf(
            "Assigning helper agent '%s' to parent agent '%s' would nest %d levels deep, beyond the configured limit of %d.",
            $helperAgentId,
            $parentAgentId,
            $computedDepth,
            $maxDepth,
        ));
    }
}
