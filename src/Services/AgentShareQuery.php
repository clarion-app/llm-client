<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentShareGrant;
use Illuminate\Support\Collection;

/**
 * Read path for `agent_share_grants` (096-agent-sharing, data-model.md §5).
 *
 * grantsForAgent() is owner-only: it resolves the target agent via the
 * existing, unmodified, owner-only AgentQuery::findAgent() — never
 * findAccessibleAgent() — so a recipient who only holds a `use` or
 * `use_and_edit` grant on an agent can never see who else has been granted
 * access to it, regardless of their own permission level.
 */
class AgentShareQuery
{
    public function __construct(
        private readonly AgentQuery $query,
    ) {}

    /**
     * The currently-active (non-revoked) grants for an agent the caller
     * owns, each eager-loading `recipient` for display-name resolution.
     * Null uniformly for "doesn't exist" and "not yours," matching
     * findAgent()'s own contract — a revoked grant is excluded by
     * AgentShareGrant's own SoftDeletes default scope, with no explicit
     * whereNull('deleted_at')/withTrashed() needed here.
     *
     * @return Collection<int, AgentShareGrant>|null
     */
    public function grantsForAgent(string $callerUserId, string $agentId): ?Collection
    {
        if ($this->query->findAgent($callerUserId, $agentId) === null) {
            return null;
        }

        return AgentShareGrant::where('agent_id', $agentId)
            ->with('recipient')
            ->get();
    }
}
