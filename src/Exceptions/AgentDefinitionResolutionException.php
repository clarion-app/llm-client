<?php

namespace ClarionApp\LlmClient\Exceptions;

use ClarionApp\LlmClient\ValueObjects\AgentDefinitionResolutionErrorKind;
use ClarionApp\LlmClient\ValueObjects\ReducibleTool;

/**
 * A semantic problem in an otherwise structurally valid agent definition
 * document — a named model, capability, or operation-group pattern that
 * does not resolve against this installation's current state
 * (data-model.md §5, contracts §3).
 *
 * Extends \RuntimeException deliberately, matching
 * RoleAssignmentFailedException's own precedent (research.md D11).
 */
final class AgentDefinitionResolutionException extends \RuntimeException
{
    public function __construct(
        public readonly AgentDefinitionResolutionErrorKind $kind,
        public readonly string $value,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($this->composeMessage($kind, $value), 0, $previous);
    }

    private function composeMessage(AgentDefinitionResolutionErrorKind $kind, string $value): string
    {
        return match ($kind) {
            AgentDefinitionResolutionErrorKind::UnknownModel => sprintf(
                'The model "%s" is not available on this installation. Configure it in LLM settings, or choose a model already configured.',
                $value
            ),
            AgentDefinitionResolutionErrorKind::UnknownCapability => sprintf(
                'Unrecognized capability "%s". Available capabilities: %s.',
                $value,
                implode(', ', array_map(static fn (ReducibleTool $tool): string => $tool->value, ReducibleTool::cases()))
            ),
            AgentDefinitionResolutionErrorKind::EmptyOperationPattern => sprintf(
                'The operation pattern "%s" does not match any currently available operation.',
                $value
            ),
        };
    }
}
