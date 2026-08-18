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
     * The fixed sentinel method every synthesized external-tool path
     * carries (McpClientToolCatalogMerger's own "method" field) -- never
     * a real HTTP verb, so a bare-verb pattern in tools.allow (e.g. "GET")
     * can never accidentally permit an external tool; only a glob pattern
     * written against an "mcp:"-prefixed operationId can.
     */
    private const EXTERNAL_TOOL_METHOD = 'MCP_EXTERNAL';

    /**
     * @param array<string, bool> $memory keyed by MemoryKind::value
     * @param list<ReducibleTool> $capabilities
     * @param list<string> $toolsAllow raw, unexpanded patterns
     * @param list<string> $toolsDeny raw, unexpanded patterns
     * @param list<string> $safetyConfirmationRequired raw, unexpanded patterns/verbs
     * @param list<string> $safetyDenylist raw, unexpanded patterns/verbs
     * @param list<string> $unattendedAuthorized raw, unexpanded patterns/verbs
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
        public readonly array $unattendedAuthorized = [],
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

        // An external-tool operationId is never a member of ApiManager's
        // own catalog (it names a per-user/per-server tool, not an
        // installation-wide route), so resolveCatalog()'s enumerate-then-
        // check-membership approach can never match one, regardless of
        // what tools.allow/tools.deny say -- every external tool would
        // otherwise be permanently unreachable no matter how a definition
        // is configured. Matched directly against the raw patterns
        // instead, the same per-pattern predicate resolve() itself calls
        // internally, just without requiring catalog pre-enumeration.
        if ($this->isExternalToolOperationId($operationId)) {
            if ($this->matchesAnyPattern($operationId, self::EXTERNAL_TOOL_METHOD, $this->toolsDeny)) {
                return false;
            }

            return $this->matchesAnyPattern($operationId, self::EXTERNAL_TOOL_METHOD, $this->toolsAllow);
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
     * Whether this operation was explicitly pre-authorized, as part of this
     * definition's own setup, to proceed without a live confirmation when
     * no user is present to answer one (the scheduler-agent family's own
     * "advance authorization" axis — see scheduler.yaml). Resolved via the
     * same OperationGroupPattern::resolve() every other pattern list on
     * this class already uses. Unlike isConfirmationRequired(), there is no
     * installation-wide ceiling to union with here: pre-authorization is
     * granted only by this definition's own setup, never by an
     * installation-wide default.
     *
     * Consulted only from the unattended execution path — an interactive
     * turn never reads this method, so a definition that omits the
     * declaring key (resolving to an empty list) behaves identically to
     * one written before this method existed.
     */
    public function isUnattendedAuthorized(string $operationId): bool
    {
        if ($this->isExternalToolOperationId($operationId)) {
            return $this->matchesAnyPattern($operationId, self::EXTERNAL_TOOL_METHOD, $this->unattendedAuthorized);
        }

        $catalog = $this->resolveCatalog();

        return in_array(
            $operationId,
            OperationGroupPattern::resolve($this->unattendedAuthorized, $catalog),
            true,
        );
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
     * A synthesized external-tool operationId, distinguished from a real
     * built-in one by its own reserved "mcp:" prefix
     * (McpClientToolCatalogMerger's own operationId shape).
     */
    private function isExternalToolOperationId(string $operationId): bool
    {
        return str_starts_with($operationId, 'mcp:');
    }

    /**
     * Whether any of the given raw patterns matches this exact
     * operationId/method pair — OperationGroupPattern::matches()'s own
     * per-pattern predicate, called directly rather than through
     * resolve()'s catalog-enumeration wrapper, since an external tool's
     * operationId is never a member of any catalog to enumerate.
     *
     * @param list<string> $patterns
     */
    private function matchesAnyPattern(string $operationId, string $method, array $patterns): bool
    {
        foreach ($patterns as $raw) {
            if ((new OperationGroupPattern($raw))->matches($operationId, $method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds the live [{operationId, method}, ...] catalog OperationGroupPattern
     * resolves patterns against — ApiManager::getOperations() for the full
     * operationId set, plus one getOperationDetails() lookup per candidate
     * for its method (research.md D8). Never cached on this object: called
     * fresh on every isOperationPermitted()/isConfirmationRequired() call.
     * Never consulted for an external-tool operationId — see
     * isExternalToolOperationId()'s own callers above.
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
