<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\ConsensusRequest;

/**
 * Owner-scoped read path over the ConsensusRequest rows ConsensusService
 * writes (104-multi-agent-consensus, data-model.md §4). Mirrors
 * DelegationQuery::findDelegation()'s exact "null collapses both absent
 * and not-the-caller's" contract.
 *
 * Phase 3/US1 adds only findRequest() -- contributorsForRequest()/
 * costForRequest() are added in Phase 6/US4 and Phase 4/US2 respectively.
 */
class ConsensusQuery
{
    /**
     * @return ConsensusRequest|null Null when absent or owned by another user.
     */
    public function findRequest(string $callerUserId, string $requestId): ?ConsensusRequest
    {
        return ConsensusRequest::where('id', $requestId)
            ->where('owner_user_id', $callerUserId)
            ->first();
    }
}
