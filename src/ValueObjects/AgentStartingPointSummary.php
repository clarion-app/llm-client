<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;

/**
 * What AgentStartingPointCatalog::list() returns -- one entry per
 * registered starting point, live-installation-aware. Never persisted;
 * recomputed on every call.
 */
final readonly class AgentStartingPointSummary
{
    /**
     * @param list<AgentDefinitionParseException|AgentDefinitionResolutionException> $problems verbatim from AgentDefinitionValidationResult::$problems when $requirementsSatisfied is false; empty otherwise
     */
    public function __construct(
        public string $slug,
        public string $description,
        public bool $requirementsSatisfied,
        public array $problems,
    ) {
    }
}
