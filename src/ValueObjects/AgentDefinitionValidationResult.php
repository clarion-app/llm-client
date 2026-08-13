<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;

/**
 * The FR-006 "same terms" contract — the one shape both POST
 * /agents/check and the rewritten store()/update() 422 response
 * serialize (research.md D8/D9). Spec's own "Validation Result" Key
 * Entity.
 *
 * $valid is fully derived from $problems (problems === []) — never an
 * independently-set flag that could disagree with the list it
 * describes; deriving it is the constructing caller's responsibility,
 * not this object's own.
 */
final readonly class AgentDefinitionValidationResult
{
    /**
     * @param list<AgentDefinitionParseException|AgentDefinitionResolutionException> $problems verbatim from AgentDefinitionCollectionResult::$problems (research.md D0) — never re-derived or re-ordered
     * @param list<AgentDefinitionWarning> $warnings every DestructiveOperationWithoutConfirmation warning found (research.md D2), computed regardless of whether $problems is empty — US3 AC3
     */
    public function __construct(
        public bool $valid,
        public array $problems,
        public array $warnings,
    ) {
    }
}
