<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * Thrown when an unattended run reaches an action it cannot proceed with:
 * an operation not permitted by the bound agent's own permission rules, or
 * a destructive action that was not pre-authorized in advance (see
 * AgentDefinition::isUnattendedAuthorized()). This is not an ordinary
 * tool-result error — the run loop catches it, closes the run, and stops
 * entirely, exactly the same "not merely another return value" role
 * AgentDefinitionParseException/AgentDefinitionResolutionException already
 * play for a document-level problem in this same package.
 *
 * Never allowed to propagate out of AgentLoopService::run() itself — its
 * own top-level boundary catches this, closes the run, and returns a
 * structured stopped_unauthorized result instead of re-throwing.
 */
final class UnattendedActionRefusedException extends \RuntimeException
{
    public function __construct(
        public readonly string $operationId,
        string $reason,
    ) {
        parent::__construct($reason);
    }
}
