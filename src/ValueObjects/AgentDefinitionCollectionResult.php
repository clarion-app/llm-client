<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;

/**
 * AgentDefinitionParser::collect()'s own return shape — the single
 * implementation of the 11-step rule set in collecting form
 * (research.md D0). Not itself part of this feature's public HTTP
 * contract; consumed by parse() (086, unchanged signature) and by
 * AgentDefinitionValidator::check().
 *
 * When $problems is not empty, $definition's fields corresponding to a
 * failed step hold a harmless placeholder (e.g. an unresolved model
 * stays null, an invalid capability entry is simply omitted from the
 * resolved list) and MUST NOT be treated as authoritative by any caller
 * outside this feature's own internal use (constructing best-effort
 * inputs for the warning check, research.md D2/D3).
 */
final readonly class AgentDefinitionCollectionResult
{
    /**
     * @param list<AgentDefinitionParseException|AgentDefinitionResolutionException> $problems every blocking problem found, in the fixed check order (086 D11), in file order within a step (research.md D4); empty when the document is fully valid; each element is a real, constructed exception instance — never thrown from inside collect() itself
     * @param list<array{operationId: string, method: string}> $catalog the live operation catalog collect() resolved exactly once (086's existing resolveCatalog(), unmodified), reused by every pattern check collect() itself performs and by AgentDefinitionValidator's warning computation (research.md D7) — never re-fetched
     */
    public function __construct(
        public AgentDefinition $definition,
        public array $problems,
        public array $catalog,
    ) {
    }
}
