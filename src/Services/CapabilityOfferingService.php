<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\CapabilityOfferingCycleException;
use ClarionApp\LlmClient\Models\CapabilityOffering;

/**
 * Write path for `agent_capability_offerings` (109-agent-as-capability,
 * Phase 2/Foundational, data-model.md §2). Mirrors AgentHelperService's own
 * role as the sole write-path owner for its sibling table.
 *
 * offer() is owner-only: it resolves both the offered agent and the
 * candidate caller via the existing, unmodified, owner-only
 * AgentQuery::findAgent() -- never findAccessibleAgent() -- so a recipient
 * who only holds a `use`/`use_and_edit` grant on a shared agent can never
 * offer it as a capability, regardless of their own permission level.
 *
 * Unlike AgentHelperService::assign(), offer() has no subset-of-parent
 * check -- the offered agent's own permittedOperationIds() need not be a
 * subset of the caller's (data-model.md §1's own deliberate asymmetry: what
 * a specific caller's invocation can actually achieve is bounded live, per
 * call, by EffectiveBoundResolver, not precomputed here). Validation order
 * is therefore: self-offer -> union-graph cycle (via
 * AgentHelperQuery::wouldOfferingCreateCycle(), which walks the same
 * combined agent_helper_assignments/agent_capability_offerings graph
 * AgentHelperService::assign()'s own cycle check now walks, research.md
 * D3) -> upsert. No depth check either -- live chain depth is bounded once,
 * chain-wide, by the shared delegation.max_chain_depth at invocation time
 * (data-model.md §8), not at offering-configuration time.
 */
class CapabilityOfferingService
{
    public function __construct(
        private readonly AgentQuery $query,
        private readonly AgentHelperQuery $helperQuery,
    ) {}

    /**
     * Offers (or re-offers/restores) an agent as a capability to a caller
     * agent, both owned by the caller of this method. Idempotent per
     * (offered_agent_id, caller_agent_id) pair -- a call after a prior
     * withdraw() restores the same lifetime row instead of inserting a
     * second one, updating the capability fields to whatever this call
     * supplies.
     *
     * @throws \RuntimeException when the caller does not own the offered
     *   agent or the candidate caller agent, or when
     *   offeredAgentId === callerAgentId.
     * @throws CapabilityOfferingCycleException when the offering would
     *   close a cycle, direct or transitive, in the union of active
     *   agent_helper_assignments and agent_capability_offerings edges.
     */
    public function offer(
        string $callerUserId,
        string $offeredAgentId,
        string $callerAgentId,
        string $capabilityName,
        string $capabilityDescription,
        string $inputDescription,
    ): CapabilityOffering {
        $offered = $this->query->findAgent($callerUserId, $offeredAgentId);

        if ($offered === null) {
            throw new \RuntimeException('Agent not found or not owned by the caller.');
        }

        $caller = $this->query->findAgent($callerUserId, $callerAgentId);

        if ($caller === null) {
            throw new \RuntimeException('Caller agent not found or not owned by the caller.');
        }

        if ($offeredAgentId === $callerAgentId) {
            throw new \RuntimeException('An agent cannot be offered as a capability to itself.');
        }

        $cyclePath = $this->helperQuery->wouldOfferingCreateCycle($offeredAgentId, $callerAgentId);

        if ($cyclePath !== null) {
            throw new CapabilityOfferingCycleException($offeredAgentId, $callerAgentId, $cyclePath);
        }

        $offering = CapabilityOffering::withTrashed()->firstOrNew([
            'offered_agent_id' => $offeredAgentId,
            'caller_agent_id' => $callerAgentId,
        ]);

        $offering->owner_user_id = $callerUserId;
        $offering->capability_name = $capabilityName;
        $offering->capability_description = $capabilityDescription;
        $offering->input_description = $inputDescription;

        if ($offering->trashed()) {
            // restore() persists the row itself (sets deleted_at = null and
            // calls save()), already carrying every attribute assigned
            // above along with it -- a separate save() call afterward would
            // be a redundant no-op, not a second write.
            $offering->restore();
        } else {
            $offering->save();
        }

        return $offering;
    }

    /**
     * Withdraws a previously offered capability, for an offered agent the
     * caller owns (mirrors AgentHelperService::remove()'s exact idempotency
     * posture). Same ownership resolution as offer() for the offered-agent
     * side -- but, unlike offer(), the caller-agent side does not need to
     * still be owned by the caller, or even still exist, for a withdrawal
     * to succeed.
     *
     * Idempotent: soft-deletes the active (non-trashed) row for the pair if
     * one exists and returns true; returns false, never throws, when no
     * active row exists for the pair.
     *
     * @throws \RuntimeException when the caller does not own the offered
     *   agent.
     */
    public function withdraw(string $callerUserId, string $offeredAgentId, string $callerAgentId): bool
    {
        $offered = $this->query->findAgent($callerUserId, $offeredAgentId);

        if ($offered === null) {
            throw new \RuntimeException('Agent not found or not owned by the caller.');
        }

        $offering = CapabilityOffering::where('offered_agent_id', $offeredAgentId)
            ->where('caller_agent_id', $callerAgentId)
            ->first();

        if ($offering === null) {
            return false;
        }

        $offering->delete();

        return true;
    }
}
