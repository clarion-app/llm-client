<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CapabilityOffering;
use Illuminate\Support\Collection;

/**
 * Read path for `agent_capability_offerings` (109-agent-as-capability,
 * Phase 2/Foundational, data-model.md §3).
 *
 * offeringsFor() is owner-only, mirroring AgentQuery::findAgent()'s own
 * null-for-"doesn't exist"-or-"not yours" contract -- it never calls
 * AgentQuery::findAccessibleAgent(), so a mere `use`/`use_and_edit`
 * recipient of a shared agent can never see what it offers.
 *
 * eligibleFor() is deliberately unscoped by user id -- it is called from
 * inside an already-authenticated turn's own loop (the conversation's own
 * bound agent is already ownership-resolved), mirroring
 * AgentHelperQuery::catalog()'s own unauthenticated-by-user-id shape for
 * the identical reason.
 */
class CapabilityOfferingQuery
{
    public function __construct(
        private readonly AgentQuery $query,
    ) {}

    /**
     * Every currently-active offering *made by* the given agent the caller
     * owns (who it is offered to). Null uniformly for "doesn't exist"/"not
     * yours," matching AgentHelperQuery::helpersFor()'s own contract.
     *
     * @return Collection<int, object>|null
     */
    public function offeringsFor(string $callerUserId, string $offeredAgentId): ?Collection
    {
        $offered = $this->query->findAgent($callerUserId, $offeredAgentId);

        if ($offered === null) {
            return null;
        }

        $offerings = CapabilityOffering::where('offered_agent_id', $offeredAgentId)->get();

        return $offerings
            ->map(fn (CapabilityOffering $offering) => $this->annotateRow(
                $offering,
                $offered,
                Agent::withTrashed()->find($offering->caller_agent_id),
            ))
            ->values();
    }

    /**
     * Every currently-active offering visible *to* the given caller agent
     * (who may invoke what) -- the query CapabilityCatalogMerger and the
     * execute_operation branch both actually use at runtime (Phase 3/US1).
     * No user-id scoping parameter, mirroring AgentHelperQuery::catalog()'s
     * own unauthenticated-by-user-id shape.
     *
     * @return Collection<int, CapabilityOffering>
     */
    public function eligibleFor(string $callerAgentId): Collection
    {
        return CapabilityOffering::where('caller_agent_id', $callerAgentId)->get();
    }

    /**
     * $offered/$caller are nullable to cover a trash-inclusive lookup still
     * coming back empty (the agent id no longer resolves at all, even
     * including trashed rows) -- treated identically to a resolved-but-
     * soft-deleted agent: name null, never omitted or thrown for.
     */
    private function annotateRow(CapabilityOffering $offering, ?Agent $offered, ?Agent $caller): object
    {
        return (object) [
            'id' => $offering->id,
            'offered_agent_id' => $offering->offered_agent_id,
            'offered_agent_name' => $offered->name ?? null,
            'caller_agent_id' => $offering->caller_agent_id,
            'caller_agent_name' => $caller->name ?? null,
            'capability_name' => $offering->capability_name,
            'capability_description' => $offering->capability_description,
            'input_description' => $offering->input_description,
            'created_at' => $offering->created_at,
            'updated_at' => $offering->updated_at,
        ];
    }
}
