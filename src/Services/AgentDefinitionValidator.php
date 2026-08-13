<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\AgentDefinition;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionValidationResult;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionWarning;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionWarningKind;
use ClarionApp\LlmClient\ValueObjects\OperationGroupPattern;

/**
 * Checks a definition on demand, before it is saved
 * (088-agent-definition-validator, contracts/agent-definition-validator-api.md
 * §4). A thin wrapper over AgentDefinitionParser::collect() — the sole
 * implementation of the 11-step rule set (research.md D0) — called exactly
 * once per check(). `warnings` is the one real computation this feature
 * adds (research.md D2/D3): DestructiveOperationWithoutConfirmation,
 * reusing this same collect() result's catalog rather than reading the
 * operation catalog a second time (research.md D7).
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
            warnings: $this->computeWarnings($result->definition, $result->catalog),
        );
    }

    /**
     * DestructiveOperationWithoutConfirmation (research.md D2): for every
     * catalog entry whose method is in config('llm-client.confirm_methods')
     * and that the document's own tools.allow/tools.deny actually permits
     * (via OperationGroupPattern::resolve() — the exact primitive 086
     * already built, never a second matching implementation) and that the
     * document's own safety.confirmation_required does not already cover,
     * one warning is produced. Deliberately reads
     * $definition->safetyConfirmationRequired directly, never via
     * AgentDefinition::isConfirmationRequired() — that method unions in the
     * installation's own confirm_methods ceiling, which would make this
     * warning permanently unreachable on the default installation config
     * (research.md D2's own "ceiling-union trap"). Computed regardless of
     * whether the collect() call found any blocking problem (US3 AC3).
     *
     * @param list<array{operationId: string, method: string}> $catalog
     * @return list<AgentDefinitionWarning>
     */
    private function computeWarnings(AgentDefinition $definition, array $catalog): array
    {
        $permitted = OperationGroupPattern::resolve($definition->toolsAllow, $catalog);
        $denied = OperationGroupPattern::resolve($definition->toolsDeny, $catalog);
        $confirmed = OperationGroupPattern::resolve($definition->safetyConfirmationRequired, $catalog);
        $confirmMethods = config('llm-client.confirm_methods', ['DELETE']);

        $warnings = [];

        foreach ($catalog as $entry) {
            $operationId = $entry['operationId'];
            $method = strtoupper($entry['method']);

            if (!in_array($method, $confirmMethods, true)) {
                continue;
            }

            if (!in_array($operationId, $permitted, true)) {
                continue;
            }

            if (in_array($operationId, $denied, true)) {
                continue;
            }

            if (in_array($operationId, $confirmed, true)) {
                continue;
            }

            $warnings[] = new AgentDefinitionWarning(
                AgentDefinitionWarningKind::DestructiveOperationWithoutConfirmation,
                $operationId,
                $method,
                sprintf(
                    '"%s" is a %s operation permitted by tools.allow with no matching entry in safety.confirmation_required.',
                    $operationId,
                    $method
                ),
            );
        }

        return $warnings;
    }
}
