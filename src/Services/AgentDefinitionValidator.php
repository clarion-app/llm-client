<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\AgentDefinitionValidationResult;

/**
 * Checks a definition on demand, before it is saved
 * (088-agent-definition-validator, contracts/agent-definition-validator-api.md
 * §4). A thin wrapper over AgentDefinitionParser::collect() — the sole
 * implementation of the 11-step rule set (research.md D0) — called exactly
 * once per check(). `warnings` is a stub empty list in this phase; a later
 * phase adds the real DestructiveOperationWithoutConfirmation computation
 * (research.md D2/D3), reusing this same collect() result's catalog rather
 * than reading the operation catalog a second time (research.md D7).
 *
 * check($rawYaml)->valid is true iff AgentDefinitionParser::parse($rawYaml)
 * would return successfully for the identical input and installation state
 * — both route through collect() (research.md D0). Idempotent and
 * side-effect-free: no write of any kind, no accumulated state across calls.
 */
final class AgentDefinitionValidator
{
    public function __construct(
        private readonly AgentDefinitionParser $parser,
    ) {}

    /**
     * Any \Throwable other than the two domain exception types
     * (AgentDefinitionParseException/AgentDefinitionResolutionException) is
     * never caught here — there is no try/catch in this method at all,
     * since collect() itself already narrows its own catches to just those
     * two types (research.md D6). A live-state failure (e.g. a database
     * error resolving a stated model) propagates out of check() uncaught,
     * never converted into a completed-check result describing it as a
     * "problem."
     */
    public function check(string $rawYaml): AgentDefinitionValidationResult
    {
        $result = $this->parser->collect($rawYaml);

        return new AgentDefinitionValidationResult(
            valid: $result->problems === [],
            problems: $result->problems,
            warnings: [],
        );
    }
}
