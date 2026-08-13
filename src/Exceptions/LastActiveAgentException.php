<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * Deactivating this agent would leave the caller with no active agents,
 * and the caller did not pass confirm to proceed anyway (data-model.md
 * §3, research.md D6).
 *
 * A single, simple failure mode — like AgentNameAlreadyInUseException, no
 * `$kind` enum is needed here.
 */
final class LastActiveAgentException extends \RuntimeException
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $agentName,
    ) {
        parent::__construct(sprintf(
            "Deactivating '%s' would leave you with no active agents. Pass confirm to proceed anyway.",
            $agentName
        ));
    }
}
