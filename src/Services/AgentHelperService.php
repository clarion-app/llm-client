<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\HelperExceedsParentPermissionsException;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;

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
 * Cycle and depth-limit checks are deliberately not wired in here yet
 * (097-subagent-model Phase 4/US3) — assign() currently only enforces
 * self-assignment and the subset-of-parent constraint (US1/US2).
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
}
