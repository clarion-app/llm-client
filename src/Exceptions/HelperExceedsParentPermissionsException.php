<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * A candidate helper's own permitted operations exceed the operations its
 * would-be parent agent itself permits (097-subagent-model, data-model.md
 * §3, research.md D3, FR-005). Raised by AgentHelperService::assign() and
 * rendered as a 422 naming the exact excess (contracts/subagent-model-api.md
 * §1) — never a bare "not allowed."
 */
final class HelperExceedsParentPermissionsException extends \RuntimeException
{
    /**
     * @param list<string> $excessOperationIds
     */
    public function __construct(
        public readonly string $parentAgentId,
        public readonly string $helperAgentId,
        public readonly array $excessOperationIds,
    ) {
        parent::__construct(sprintf(
            "Helper agent '%s' exceeds the permitted operations of parent agent '%s': %s.",
            $helperAgentId,
            $parentAgentId,
            implode(', ', $excessOperationIds),
        ));
    }
}
