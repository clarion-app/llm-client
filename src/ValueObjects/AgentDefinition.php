<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\Backend\ApiManager;

/**
 * The complete, parsed representation of one agent definition
 * (086-agent-yaml-schema, data-model.md §1, contracts §2).
 *
 * A plain, unvalidated readonly value object — every validation rule lives
 * in AgentDefinitionParser::parse(), never here (matching DegradationDecision's
 * own established "plain, unvalidated readonly value object" precedent).
 * There is no public way to mutate an instance once constructed.
 *
 * isOperationPermitted()/isConfirmationRequired() re-evaluate live against
 * ApiManager's own operation catalog on every call — the catalog is never
 * cached on this object, so a pattern that resolved to nothing at
 * construction time can still match an operation registered later
 * (research.md D8, FR-008).
 *
 * Scope note (086-agent-yaml-schema Phase 3 / US1): the two methods below
 * check only this definition's own toolsAllow/toolsDeny/
 * safetyConfirmationRequired — no config('llm-client.api_denylist')/
 * confirm_methods union yet. That installation-ceiling union is Phase
 * 4/US3's own scope (tasks.md Grounding note 3).
 */
final class AgentDefinition
{
    /**
     * @param array<string, bool> $memory keyed by MemoryKind::value
     * @param list<ReducibleTool> $capabilities
     * @param list<string> $toolsAllow raw, unexpanded patterns
     * @param list<string> $toolsDeny raw, unexpanded patterns
     * @param list<string> $safetyConfirmationRequired raw, unexpanded patterns/verbs
     * @param list<string> $safetyDenylist raw, unexpanded patterns/verbs
     */
    public function __construct(
        public readonly string $formatVersion,
        public readonly string $name,
        public readonly ?string $version,
        public readonly string $instructions,
        public readonly ?string $model,
        public readonly array $memory,
        public readonly array $capabilities,
        public readonly array $toolsAllow,
        public readonly array $toolsDeny,
        public readonly array $safetyConfirmationRequired,
        public readonly array $safetyDenylist,
    ) {
    }

    public function memoryEnabled(MemoryKind $kind): bool
    {
        return $this->memory[$kind->value] ?? false;
    }

    public function hasCapability(ReducibleTool $tool): bool
    {
        return in_array($tool, $this->capabilities, true);
    }

    /**
     * Deny wins over allow. Checked against this definition's own
     * toolsAllow/toolsDeny only (Phase 3/US1 scope note above).
     */
    public function isOperationPermitted(string $operationId): bool
    {
        $catalog = $this->resolveCatalog();

        if (in_array($operationId, OperationGroupPattern::resolve($this->toolsDeny, $catalog), true)) {
            return false;
        }

        return in_array($operationId, OperationGroupPattern::resolve($this->toolsAllow, $catalog), true);
    }

    /**
     * Checked against this definition's own safetyConfirmationRequired only
     * (Phase 3/US1 scope note above) — independent of, and never mutually
     * exclusive with, isOperationPermitted().
     */
    public function isConfirmationRequired(string $operationId): bool
    {
        $catalog = $this->resolveCatalog();

        return in_array($operationId, OperationGroupPattern::resolve($this->safetyConfirmationRequired, $catalog), true);
    }

    /**
     * Builds the live [{operationId, method}, ...] catalog OperationGroupPattern
     * resolves patterns against — ApiManager::getOperations() for the full
     * operationId set, plus one getOperationDetails() lookup per candidate
     * for its method (research.md D8). Never cached on this object: called
     * fresh on every isOperationPermitted()/isConfirmationRequired() call.
     *
     * @return list<array{operationId: string, method: string}>
     */
    private function resolveCatalog(): array
    {
        $catalog = [];

        foreach (ApiManager::getOperations() as $operation) {
            $details = (array) ApiManager::getOperationDetails($operation['operationId']);

            if (!isset($details['method'])) {
                continue;
            }

            $catalog[] = [
                'operationId' => $operation['operationId'],
                'method' => $details['method'],
            ];
        }

        return $catalog;
    }
}
