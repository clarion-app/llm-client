<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\HelperAssignmentCycleException;
use ClarionApp\LlmClient\Exceptions\HelperDepthLimitExceededException;
use ClarionApp\LlmClient\Exceptions\HelperExceedsParentPermissionsException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\ValueObjects\AgentDefinition;

/**
 * Write path for `agent_helper_assignments` (097-subagent-model,
 * data-model.md §3). Mirrors AgentShareService's own owner/write-path role.
 *
 * assign() is owner-only: it resolves both the parent and the candidate
 * helper via the existing, unmodified, owner-only AgentQuery::findAgent()
 * — never findAccessibleAgent() — so a recipient who only holds a `use` or
 * `use_and_edit` grant on a shared agent can never assign helpers to it,
 * regardless of their own permission level.
 *
 * The subset-of-parent check reuses AgentHelperQuery's own
 * isWithinParentBounds()/permittedOperationIds()/catalog() primitives
 * (research.md D3) rather than re-implementing the parse-and-degrade
 * machinery here, so the write-time refusal and AgentHelperQuery's own
 * read-time within_bounds/effective_operation_count annotation are
 * provably the same rule.
 *
 * Cycle prevention (research.md D2) and the depth-limit bound (research.md
 * D5) are enforced here too (097-subagent-model Phase 4/US3), after the
 * self-assignment/subset-of-parent checks and before the upsert
 * (data-model.md §3's ordered validation list) — both reuse
 * AgentHelperQuery's own wouldCreateCycle()/computeDepth() traversal
 * rather than re-implementing graph-walking here.
 */
class AgentHelperService
{
    public function __construct(
        private readonly AgentQuery $query,
        private readonly AgentHelperQuery $helperQuery,
    ) {}

    /**
     * Assigns (or re-assigns/restores) a helper to a parent agent, both
     * owned by the caller. Idempotent per (parent_agent_id,
     * helper_agent_id) pair — a call after a prior remove() restores the
     * same lifetime row instead of inserting a second one.
     *
     * @throws \RuntimeException when the caller does not own the parent or
     *   the candidate helper, or when parentAgentId === helperAgentId.
     * @throws HelperExceedsParentPermissionsException when the candidate
     *   helper's own permitted operations exceed the parent's.
     * @throws HelperAssignmentCycleException when the assignment would
     *   close a cycle, direct or transitive.
     * @throws HelperDepthLimitExceededException when the assignment would
     *   nest the helper deeper than config('llm-client.helpers.max_depth').
     */
    public function assign(string $callerUserId, string $parentAgentId, string $helperAgentId): AgentHelperAssignment
    {
        $parent = $this->query->findAgent($callerUserId, $parentAgentId);

        if ($parent === null) {
            throw new \RuntimeException('Agent not found or not owned by the caller.');
        }

        $helper = $this->query->findAgent($callerUserId, $helperAgentId);

        if ($helper === null) {
            throw new \RuntimeException('Helper agent not found or not owned by the caller.');
        }

        if ($parentAgentId === $helperAgentId) {
            throw new \RuntimeException('An agent cannot be assigned as its own helper.');
        }

        $catalog = $this->helperQuery->catalog();

        if (!$this->helperQuery->isWithinParentBounds($helper, $parent, $catalog)) {
            $excess = array_values(array_diff(
                $this->helperQuery->permittedOperationIds($helper, $catalog),
                $this->helperQuery->permittedOperationIds($parent, $catalog),
            ));

            throw new HelperExceedsParentPermissionsException($parentAgentId, $helperAgentId, $excess);
        }

        $cyclePath = $this->helperQuery->wouldCreateCycle($parentAgentId, $helperAgentId);

        if ($cyclePath !== null) {
            throw new HelperAssignmentCycleException($parentAgentId, $helperAgentId, $cyclePath);
        }

        $maxDepth = (int) config('llm-client.helpers.max_depth', 10);
        $computedDepth = $this->helperQuery->computeDepth($parentAgentId, $helperAgentId);

        if ($computedDepth > $maxDepth) {
            throw new HelperDepthLimitExceededException($parentAgentId, $helperAgentId, $computedDepth, $maxDepth);
        }

        $assignment = AgentHelperAssignment::withTrashed()->firstOrNew([
            'parent_agent_id' => $parentAgentId,
            'helper_agent_id' => $helperAgentId,
        ]);

        $assignment->owner_user_id = $callerUserId;

        if ($assignment->trashed()) {
            // restore() persists the row itself (sets deleted_at = null and
            // calls save()), already carrying the owner_user_id assignment
            // above along with it — a separate save() call afterward would
            // be a redundant no-op, not a second write.
            $assignment->restore();
        } else {
            $assignment->save();
        }

        return $assignment;
    }

    /**
     * Removes a previously assigned helper from a parent agent the caller
     * owns (097-subagent-model, Phase 5/US4, data-model.md §3). Same
     * ownership resolution as assign() for the parent side — but, unlike
     * assign(), the helper side does not need to still be owned by the
     * caller, or even still exist, for a removal to succeed (removing an
     * assignment to a since-retired or -removed helper must always be
     * possible).
     *
     * Idempotent: soft-deletes the active (non-trashed) row for the pair if
     * one exists and returns true; returns false, never throws, when no
     * active row exists for the pair — mirrors AgentShareService::revoke()'s
     * exact idempotency posture.
     *
     * @throws \RuntimeException when the caller does not own the parent agent.
     */
    public function remove(string $callerUserId, string $parentAgentId, string $helperAgentId): bool
    {
        $parent = $this->query->findAgent($callerUserId, $parentAgentId);

        if ($parent === null) {
            throw new \RuntimeException('Agent not found or not owned by the caller.');
        }

        $assignment = AgentHelperAssignment::where('parent_agent_id', $parentAgentId)
            ->where('helper_agent_id', $helperAgentId)
            ->first();

        if ($assignment === null) {
            return false;
        }

        $assignment->delete();

        return true;
    }

    /**
     * Refuses a candidate definition that would exceed the recursive
     * structural bound of any of $agent's own currently-active parents
     * (100-subagent-tool-restrictions, data-model.md §2, FR-015). Compares
     * against $newDefinition — the *candidate*, not-yet-saved definition —
     * never against $agent's own currently-stored version, so a caller can
     * check a would-be write before committing it. No active parents ⇒
     * returns immediately (the common case, one cheap query).
     *
     * Active parents are checked in AgentHelperAssignment.id order
     * (contracts §1); the first one violated is the one named in the
     * thrown exception.
     */
    public function guardAgainstExceedingActiveParents(Agent $agent, AgentDefinition $newDefinition): void
    {
        $parentIds = AgentHelperAssignment::where('helper_agent_id', $agent->id)
            ->orderBy('id')
            ->pluck('parent_agent_id');

        if ($parentIds->isEmpty()) {
            return;
        }

        $catalog = $this->helperQuery->catalog();
        $candidateOperationIds = $newDefinition->permittedOperationIds($catalog);

        foreach ($parentIds as $parentId) {
            $parent = Agent::withTrashed()->find($parentId);

            if ($parent === null) {
                continue;
            }

            $excess = array_values(array_diff(
                $candidateOperationIds,
                $this->helperQuery->structuralEffectiveBound($parent, $catalog),
            ));

            if ($excess !== []) {
                throw new HelperExceedsParentPermissionsException($parent->id, $agent->id, $excess);
            }
        }
    }
}
