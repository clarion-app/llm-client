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
            $this->structuralEffectiveBound($parent, $catalog),
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
            $this->structuralEffectiveBound($parent, $catalog),
        ));
    }

    /**
     * The recursive structural bound of a single agent (100-subagent-tool-
     * restrictions, data-model.md §2, research.md D3): the agent's own
     * currently permitted operations, intersected with the
     * structuralEffectiveBound() of each of its own currently-active
     * parents. An agent with zero active parents (a root) returns its own
     * permittedOperationIds() unchanged — the pre-existing one-level
     * behavior, preserved exactly for the common case.
     *
     * $visited is passed *by value*, mirroring depthOf()'s own posture
     * (Grounding note item 1) rather than dfsForTarget()'s shared-by-
     * reference one: a node reachable via more than one parent must remain
     * visitable again on a sibling branch. Only a revisit of a node already
     * on the *current* path — a pre-existing data cycle the real API
     * refuses to ever create, but which this traversal must still defend
     * against — degrades to an empty set rather than recursing forever.
     *
     * @param list<array{operationId: string, method: string}> $catalog
     * @param array<string, bool> $visited
     * @return list<string>
     */
    public function structuralEffectiveBound(Agent $agent, array $catalog, array $visited = []): array
    {
        if (isset($visited[$agent->id])) {
            return [];
        }

        $visited[$agent->id] = true;

        $bound = $this->permittedOperationIds($agent, $catalog);
        $parentIds = $this->activeParentIdsOf($agent->id);

        foreach ($parentIds as $parentId) {
            $parent = Agent::withTrashed()->find($parentId);

            if ($parent === null) {
                continue;
            }

            $bound = array_values(array_intersect(
                $bound,
                $this->structuralEffectiveBound($parent, $catalog, $visited),
            ));
        }

        return $bound;
    }

    /**
     * Every currently-active helper of an agent the caller owns
     * (data-model.md §4). Null uniformly for "doesn't exist"/"not yours,"
     * matching findAgent()'s own contract.
     *
     * Each row's helper is resolved via a trash-inclusive lookup
     * (Agent::withTrashed()->find(), mirroring
     * AgentQuery::findAgentIncludingTrashed()'s own precedent) rather than
     * the plain `helper()` relation, so a row is never omitted merely
     * because its helper has since been soft-deleted (097-subagent-model
     * Phase 5/US4, fixing Phase 3's own disclosed, temporary limitation) —
     * the row is annotated `helper_status: 'gone'` instead.
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

        $assignments = AgentHelperAssignment::where('parent_agent_id', $parentAgentId)->get();

        return $assignments
            ->map(fn (AgentHelperAssignment $assignment) => $this->annotateRow(
                $assignment,
                Agent::withTrashed()->find($assignment->helper_agent_id),
                $parent,
                $catalog,
            ))
            ->values();
    }

    /**
     * Whether assigning the candidate helper under the given would-be
     * parent would close a cycle, direct or transitive (research.md D2) —
     * a DFS walking outward from the candidate helper along its own active
     * "parent of" edges (every agent the candidate helper is itself,
     * transitively, currently a parent to), until either the would-be
     * parent is found (a cycle would form) or the reachable set is
     * exhausted. Guarded by a visited-set so a pre-existing cycle in the
     * data — which the real API must never allow once this check is wired
     * into AgentHelperService::assign(), but which the traversal itself
     * must still defend against regardless — cannot cause infinite
     * recursion.
     *
     * @return list<string>|null the ordered cycle path, starting at the
     *   candidate helper and ending at the would-be parent, or null if no
     *   cycle would form.
     */
    public function wouldCreateCycle(string $parentAgentId, string $helperAgentId): ?array
    {
        $path = [];
        $visited = [];

        if ($this->dfsForTarget($helperAgentId, $parentAgentId, $path, $visited)) {
            return $path;
        }

        return null;
    }

    /**
     * How many levels below its root ancestor the candidate helper would
     * land if assigned under the given would-be parent (research.md D5) —
     * the would-be parent's own depth (0 for a root, i.e. an agent that is
     * not itself currently anyone's active helper) plus one. The candidate
     * helper's own id plays no part in the arithmetic (only where it would
     * be attached matters), but is kept in the signature to mirror
     * wouldCreateCycle()'s own (parentAgentId, helperAgentId) argument
     * order.
     */
    public function computeDepth(string $parentAgentId, string $helperAgentId): int
    {
        return $this->depthOf($parentAgentId, []) + 1;
    }

    /**
     * The full descendant graph beneath a given agent the caller owns
     * (FR-007, data-model.md §4) — a flattened, depth-first list of every
     * currently-active helper reachable through any number of hops, not
     * only the immediate ones helpersFor() returns. Reuses the same
     * active-edge adjacency lookup wouldCreateCycle()'s own traversal is
     * built on, and the identical cycle-guard posture (a would-be revisit
     * of an agent already on the current path is skipped rather than
     * looped forever). Bounded by config('llm-client.helpers.max_depth')
     * (research.md D5) — a defensive cap on traversal size/response
     * latency, never a promise that deeper structure could exist
     * (assign()'s own depth check already prevents that).
     *
     * Null uniformly for "doesn't exist"/"not yours," matching
     * helpersFor()'s own contract.
     *
     * @return array{data: list<array{agent_id: string, name: string, depth: int, path: list<string>, helper_status: string, within_bounds: bool, effective_operation_count: int}>, truncated: bool}|null
     */
    public function hierarchyFor(string $callerUserId, string $rootAgentId): ?array
    {
        $root = $this->query->findAgent($callerUserId, $rootAgentId);

        if ($root === null) {
            return null;
        }

        $catalog = $this->catalog();
        $maxDepth = (int) config('llm-client.helpers.max_depth', 10);

        $entries = [];
        $truncated = false;

        $this->walkHierarchy($root, [$root->id], $maxDepth, $catalog, $entries, $truncated);

        return [
            'data' => $entries,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param list<string> $path the chain of agent ids from the root down
     *   to (and including) $parent.
     * @param list<array{operationId: string, method: string}> $catalog
     * @param list<array{agent_id: string, name: string, depth: int, path: list<string>, helper_status: string, within_bounds: bool, effective_operation_count: int}> $entries
     */
    private function walkHierarchy(Agent $parent, array $path, int $maxDepth, array $catalog, array &$entries, bool &$truncated): void
    {
        $depth = count($path);

        $assignments = AgentHelperAssignment::where('parent_agent_id', $parent->id)->get();

        foreach ($assignments as $assignment) {
            // Trash-inclusive, mirroring helpersFor()'s own Phase 5 fix
            // (Agent::withTrashed()->find(), AgentQuery::findAgentIncludingTrashed()'s
            // precedent) -- a soft-deleted helper mid-chain must not
            // silently drop its own still-active sub-tree from the
            // hierarchy (research.md D4: retiring/removing an agent never
            // cascades to the assignment graph beneath it, so FR-007's
            // "full helper hierarchy... not only its immediate helpers"
            // still applies beneath a gone node).
            $helper = Agent::withTrashed()->find($assignment->helper_agent_id);

            if ($helper === null || in_array($assignment->helper_agent_id, $path, true)) {
                // Genuinely unresolvable (never happens through the real
                // API, since an assignment always names two agents that
                // existed at assignment time) or a would-be revisit of an
                // agent already on this path -- a pre-existing cycle in
                // the data the traversal defends against rather than
                // looping forever, exactly like wouldCreateCycle()'s own
                // visited-set guard.
                continue;
            }

            if ($depth > $maxDepth) {
                $truncated = true;

                continue;
            }

            $childPath = [...$path, $helper->id];
            $isGone = $helper->trashed();

            $entries[] = [
                'agent_id' => $helper->id,
                'name' => $helper->name,
                'depth' => $depth,
                'path' => $childPath,
                'helper_status' => $isGone ? 'gone' : ($helper->is_active ? 'active' : 'deactivated'),
                'within_bounds' => $isGone ? false : $this->isWithinParentBounds($helper, $parent, $catalog),
                'effective_operation_count' => $isGone ? 0 : count($this->effectiveOperationIds($helper, $parent, $catalog)),
            ];

            // Recurse regardless of whether $helper itself is gone -- the
            // assignment graph beneath it is an independent structural
            // fact (D4's "no cascade" posture), so a retired/removed node
            // mid-chain must not truncate the traceable hierarchy beneath
            // it.
            $this->walkHierarchy($helper, $childPath, $maxDepth, $catalog, $entries, $truncated);
        }
    }

    /**
     * DFS from $currentAgentId outward along active "parent of" edges
     * (every agent $currentAgentId is itself currently a parent to),
     * looking for $targetAgentId. Shared, by-reference $visited across the
     * whole traversal is correct (not merely convenient) here: whether
     * $targetAgentId is reachable beneath a given node is a property of
     * that node alone, independent of which path led to it, so a node
     * already fully explored never needs re-exploring.
     *
     * @param list<string> $path
     * @param array<string, bool> $visited
     */
    private function dfsForTarget(string $currentAgentId, string $targetAgentId, array &$path, array &$visited): bool
    {
        if (isset($visited[$currentAgentId])) {
            return false;
        }

        $visited[$currentAgentId] = true;
        $path[] = $currentAgentId;

        if ($currentAgentId === $targetAgentId) {
            return true;
        }

        foreach ($this->activeHelperIdsOf($currentAgentId) as $childAgentId) {
            if ($this->dfsForTarget($childAgentId, $targetAgentId, $path, $visited)) {
                return true;
            }
        }

        array_pop($path);

        return false;
    }

    /**
     * How deep $agentId itself already sits below its own root ancestor
     * (0 for a root). Unlike dfsForTarget()'s shared visited-set, $visited
     * is intentionally passed *by value* here: depth is the max over
     * every valid path from a root, so a node reachable via more than one
     * parent (research.md D1) must remain visitable again on a sibling
     * branch — only revisiting a node already on the *current* path (a
     * pre-existing cycle) must be guarded against.
     *
     * @param array<string, bool> $visited
     */
    private function depthOf(string $agentId, array $visited): int
    {
        if (isset($visited[$agentId])) {
            return 0;
        }

        $visited[$agentId] = true;

        $parentIds = $this->activeParentIdsOf($agentId);

        if ($parentIds === []) {
            return 0;
        }

        $maxParentDepth = 0;

        foreach ($parentIds as $parentId) {
            $maxParentDepth = max($maxParentDepth, $this->depthOf($parentId, $visited));
        }

        return $maxParentDepth + 1;
    }

    /**
     * @return list<string>
     */
    private function activeHelperIdsOf(string $parentAgentId): array
    {
        return AgentHelperAssignment::where('parent_agent_id', $parentAgentId)
            ->pluck('helper_agent_id')
            ->all();
    }

    /**
     * @return list<string>
     */
    private function activeParentIdsOf(string $helperAgentId): array
    {
        return AgentHelperAssignment::where('helper_agent_id', $helperAgentId)
            ->pluck('parent_agent_id')
            ->all();
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

    /**
     * $helper is nullable to cover the trash-inclusive lookup in
     * helpersFor() still coming back empty (the helper agent id no longer
     * resolves at all, even including trashed rows) — treated identically
     * to a resolved-but-soft-deleted helper: 'gone', never omitted or
     * thrown for.
     */
    private function annotateRow(AgentHelperAssignment $assignment, ?Agent $helper, Agent $parent, array $catalog): object
    {
        if ($helper === null || $helper->trashed()) {
            $name = $helper->name ?? null;

            return (object) [
                'id' => $assignment->id,
                'parent_agent_id' => $assignment->parent_agent_id,
                'helper_agent_id' => $assignment->helper_agent_id,
                'helper_name' => $name,
                'helper_purpose' => null,
                'helper_status' => 'gone',
                'within_bounds' => false,
                'effective_operation_count' => 0,
                'created_at' => $assignment->created_at,
                'updated_at' => $assignment->updated_at,
            ];
        }

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
