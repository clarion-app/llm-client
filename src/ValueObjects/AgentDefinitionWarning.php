<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Spec's own "Warning" Key Entity: a specific, named category of risk
 * that does not prevent saving. Deliberately not a \Throwable —
 * nothing in this feature ever throws a warning, since a warning by
 * definition never aborts anything (contrast
 * AgentDefinitionParseException/AgentDefinitionResolutionException,
 * which are both thrown and collected).
 *
 * A plain, unvalidated readonly value object, matching AgentDefinition's
 * own established precedent (086 data-model.md §1).
 */
final readonly class AgentDefinitionWarning
{
    public function __construct(
        public AgentDefinitionWarningKind $kind,
        public string $operationId,
        public string $method,
        public string $message,
    ) {
    }
}
