<?php

namespace ClarionApp\LlmClient\Exceptions;

use ClarionApp\LlmClient\ValueObjects\InvalidAgentVersionComparisonKind;

/**
 * Two version ids that AgentVersionComparer::compare() refuses to compare
 * (data-model.md §6) — thrown before either version is parsed, so a
 * refusal on these grounds never touches AgentDefinitionParser at all.
 *
 * Caught by AgentVersionComparisonController::compare() into a 422, in the
 * same {error, message, kind} shape StoredAgentController::
 * definitionErrorResponse() already produces for 086/087's own
 * definition-error exceptions.
 */
final class InvalidAgentVersionComparisonException extends \RuntimeException
{
    public function __construct(
        public readonly InvalidAgentVersionComparisonKind $kind,
        public readonly string $leftVersionId,
        public readonly string $rightVersionId,
    ) {
        parent::__construct($this->composeMessage($kind));
    }

    private function composeMessage(InvalidAgentVersionComparisonKind $kind): string
    {
        return match ($kind) {
            InvalidAgentVersionComparisonKind::SameVersion =>
                'Cannot compare a version against itself.',
            InvalidAgentVersionComparisonKind::DifferentAgents =>
                'Cannot compare versions belonging to different agents.',
        };
    }
}
