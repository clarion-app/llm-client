<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use Illuminate\Support\Collection;

/**
 * Read path and live-computation primitives for `agent_helper_assignments`
 * (097-subagent-model, data-model.md §4).
 *
 * isWithinParentBounds()/effectiveOperationIds() (research.md D3) are the
 * two primitives every other piece of this feature builds on: both
 * AgentHelperService::assign()'s own write-time subset refusal and
 * helpersFor()'s read-time `within_bounds`/`effective_operation_count`
 * annotation call these same two methods, so the write-time refusal and
 * the read-time display flag are provably the same rule, never two
 * implementations that happen to agree today.
 *
 * helpersFor() is owner-only, mirroring AgentQuery::findAgent()'s own
 * null-for-"doesn't exist"-or-"not yours" contract — it never calls
 * AgentQuery::findAccessibleAgent(), so a mere `use`/`use_and_edit`
 * recipient of a shared agent can never see who its helpers are.
 */
class AgentHelperQuery
{
    public function __construct(
        private readonly AgentQuery $query,
        private readonly AgentDefinitionParser $parser,
    ) {}

    /**
     * Whether every operation the helper's own current definition permits
     * is also permitted by the parent's own current definition
     * (research.md D3) — a pure subset test, computed fresh against one
     * shared $catalog, never a stored value.
     *
     * @param list<array{operationId: string, method: string}> $catalog
     */
    public function isWithinParentBounds(Agent $helper, Agent $parent, array $catalog): bool
    {
        $excess = array_diff(
            $this->permittedOperationIds($helper, $catalog),
            $this->permittedOperationIds($parent, $catalog),
        );

        return $excess === [];
    }

    /**
     * The operations the helper actually gets to exercise once bounded by
     * its parent — always the current, live intersection of both agents'
     * own permitted operations, never a pass-through of the helper's own
     * full set (research.md D3's own explicit requirement).
     *
     * @param list<array{operationId: string, method: string}> $catalog
     * @return list<string>
     */
    public function effectiveOperationIds(Agent $helper, Agent $parent, array $catalog): array
    {
        return array_values(array_intersect(
            $this->permittedOperationIds($helper, $catalog),
            $this->permittedOperationIds($parent, $catalog),
        ));
    }

    /**
     * Every currently-active helper of an agent the caller owns
     * (data-model.md §4). Null uniformly for "doesn't exist"/"not yours,"
     * matching findAgent()'s own contract.
     *
     * A soft-deleted helper's row is, for now, defensively omitted from
     * the result rather than resolved via a trash-inclusive lookup — a
     * known, disclosed, temporary limitation fixed in a later phase (US4),
     * not something this method attempts to handle yet.
     *
     * One shared $catalog is resolved once per call and reused across
     * every row — never re-resolved inside the loop.
     *
     * @return Collection<int, object>|null
     */
    public function helpersFor(string $callerUserId, string $parentAgentId): ?Collection
    {
        $parent = $this->query->findAgent($callerUserId, $parentAgentId);

        if ($parent === null) {
            return null;
        }

        $catalog = $this->catalog();

        $assignments = AgentHelperAssignment::where('parent_agent_id', $parentAgentId)
            ->with('helper')
            ->get();

        return $assignments
            ->filter(fn (AgentHelperAssignment $assignment): bool => $assignment->helper !== null)
            ->map(fn (AgentHelperAssignment $assignment) => $this->annotateRow($assignment, $assignment->helper, $parent, $catalog))
            ->values();
    }

    /**
     * The identical per-row annotation helpersFor() produces, for exactly
     * one already-known assignment — used by AgentHelperController::assign()
     * to render its own 201/200 response without re-listing and searching
     * for the row it just wrote. Null if either side of the assignment no
     * longer resolves via the plain (non-trash-inclusive) relations.
     */
    public function annotate(AgentHelperAssignment $assignment): ?object
    {
        $parent = $assignment->parent;
        $helper = $assignment->helper;

        if ($parent === null || $helper === null) {
            return null;
        }

        return $this->annotateRow($assignment, $helper, $parent, $this->catalog());
    }

    private function annotateRow(AgentHelperAssignment $assignment, Agent $helper, Agent $parent, array $catalog): object
    {
        [$name, $purpose] = $this->helperNameAndPurpose($helper);

        return (object) [
            'id' => $assignment->id,
            'parent_agent_id' => $assignment->parent_agent_id,
            'helper_agent_id' => $assignment->helper_agent_id,
            'helper_name' => $name,
            'helper_purpose' => $purpose,
            'helper_status' => $helper->is_active ? 'active' : 'deactivated',
            'within_bounds' => $this->isWithinParentBounds($helper, $parent, $catalog),
            'effective_operation_count' => count($this->effectiveOperationIds($helper, $parent, $catalog)),
            'created_at' => $assignment->created_at,
            'updated_at' => $assignment->updated_at,
        ];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function helperNameAndPurpose(Agent $helper): array
    {
        try {
            $definition = $this->parser->parse($helper->currentVersion->raw_definition);

            return [$helper->name, $definition->instructions];
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException) {
            // Degrade this one row's purpose to null rather than aborting
            // the whole list (mirrors AgentQuery::searchForUser()'s and
            // AgentSummaryQuery's own established per-agent degrade
            // pattern).
            return [$helper->name, null];
        }
    }

    /**
     * The full set of operation ids a single agent's own current
     * definition permits (research.md D3's underlying primitive). Exposed
     * publicly so AgentHelperService::assign() can name the exact excess
     * on a rejection without re-implementing the parse-and-degrade
     * machinery a second time.
     *
     * @param list<array{operationId: string, method: string}> $catalog
     * @return list<string>
     */
    public function permittedOperationIds(Agent $agent, array $catalog): array
    {
        try {
            return $this->parser->parse($agent->currentVersion->raw_definition)->permittedOperationIds($catalog);
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException) {
            return [];
        }
    }

    /**
     * The shared operation catalog every permittedOperationIds()/
     * isWithinParentBounds()/effectiveOperationIds() call is resolved
     * against — resolved once per request and reused, never re-resolved
     * per agent (AgentSummaryQuery's own established precedent). Exposed
     * publicly so AgentHelperService::assign() reuses the identical
     * resolution rather than building its own.
     *
     * @return list<array{operationId: string, method: string}>
     */
    public function catalog(): array
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
