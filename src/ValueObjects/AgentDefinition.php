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
 * Phase 4 (086-agent-yaml-schema / US3) added the installation-ceiling
 * union: isOperationPermitted()/isConfirmationRequired() now also consult
 * config('llm-client.api_denylist')/config('llm-client.confirm_methods')
 * so the installation's own safety rules always govern, whether the
 * definition tries to widen past them explicitly or merely omits a
 * setting the installation itself restricts (tasks.md Grounding note 1).
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
     * Deny wins over allow. Unions the definition's own toolsAllow/toolsDeny
     * with the installation's own config('llm-client.api_denylist') ceiling
     * (086-agent-yaml-schema Phase 4/US3) — a path matching the installation
     * denylist is never permitted, regardless of what the definition itself
     * states.
     */
    public function isOperationPermitted(string $operationId): bool
    {
        if ($this->isDeniedByInstallation($operationId)) {
            return false;
        }

        $catalog = $this->resolveCatalog();

        if (in_array($operationId, OperationGroupPattern::resolve($this->toolsDeny, $catalog), true)) {
            return false;
        }

        return in_array($operationId, OperationGroupPattern::resolve($this->toolsAllow, $catalog), true);
    }

    /**
     * The exact set of catalog operations this definition currently
     * permits — deny wins over allow, unioned with the installation
     * denylist ceiling, identical policy to isOperationPermitted() but
     * computed for the whole catalog at once instead of one operationId at
     * a time (095-agent-summary-cards, data-model.md §5, research.md D4).
     *
     * $catalog is caller-supplied rather than resolved internally
     * (resolveCatalog()) so a list render resolves it once and reuses the
     * same array across every agent's own call, instead of each agent
     * re-resolving it independently.
     *
     * @param list<array{operationId: string, method: string}> $catalog
     * @return list<string>
     */
    public function permittedOperationIds(array $catalog): array
    {
        $permitted = array_diff(
            OperationGroupPattern::resolve($this->toolsAllow, $catalog),
            OperationGroupPattern::resolve($this->toolsDeny, $catalog),
        );

        return array_values(array_filter(
            $permitted,
            fn (string $operationId): bool => !$this->isDeniedByInstallation($operationId),
        ));
    }

    /**
     * Unions the definition's own safetyConfirmationRequired with the
     * installation's own config('llm-client.confirm_methods') ceiling
     * (086-agent-yaml-schema Phase 4/US3) — an operation whose HTTP method
     * the installation requires confirmation for always requires
     * confirmation, whether or not the definition itself names it.
     * Independent of, and never mutually exclusive with,
     * isOperationPermitted().
     */
    public function isConfirmationRequired(string $operationId): bool
    {
        $catalog = $this->resolveCatalog();

        if (in_array($operationId, OperationGroupPattern::resolve($this->safetyConfirmationRequired, $catalog), true)) {
            return true;
        }

        return $this->isConfirmationRequiredByInstallation($operationId);
    }

    /**
     * Reapplies ApiCallValidator::validate()'s own exact path-denylist
     * normalization (tasks.md Grounding note 1) — never
     * OperationGroupPattern/operationId matching, since api_denylist
     * entries are path globs, not operationId globs.
     */
    private function isDeniedByInstallation(string $operationId): bool
    {
        $details = (array) ApiManager::getOperationDetails($operationId);

        if (!isset($details['path'])) {
            return false;
        }

        $normalizedPath = '/' . ltrim($details['path'], '/');

        foreach (config('llm-client.api_denylist', []) as $pattern) {
            if (fnmatch($pattern, $normalizedPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A plain verb-set membership test against the operation's resolved
     * HTTP method (uppercased, matching AgentLoopService.php's own
     * strtoupper($details['method'] ?? 'GET') convention) — no fnmatch()
     * involved on this side at all.
     */
    private function isConfirmationRequiredByInstallation(string $operationId): bool
    {
        $details = (array) ApiManager::getOperationDetails($operationId);

        if (!isset($details['method'])) {
            return false;
        }

        return in_array(strtoupper($details['method']), config('llm-client.confirm_methods', ['DELETE']), true);
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
