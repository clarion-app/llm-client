<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;

/**
 * Ownership-scoped reads for `agents`/`agent_versions` (contracts §12).
 *
 * Skeleton for Phase 3/US1 — only findAgent() is needed here, used by
 * StoredAgentController::update() to resolve the target agent before
 * handing it to AgentService::update(). versionsForAgent()/findVersion()
 * are added in Phase 4/US2.
 */
class AgentQuery
{
    /**
     * Find an agent by id, filtered by caller's user ownership — verbatim
     * RunTraceQuery::findRun()'s shape (research.md D5).
     *
     * @return Agent|null Null uniformly for "doesn't exist" and "belongs
     *   to someone else," so a caller never has to special-case which.
     */
    public function findAgent(string $callerUserId, string $agentId): ?Agent
    {
        return Agent::where('id', $agentId)
            ->where('user_id', $callerUserId)
            ->first();
    }
}
