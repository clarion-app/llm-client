<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\ConsensusRequest;

/**
 * Owner-scoped read path over the ConsensusRequest rows ConsensusService
 * writes (104-multi-agent-consensus, data-model.md §4). Mirrors
 * DelegationQuery::findDelegation()'s exact "null collapses both absent
 * and not-the-caller's" contract.
 *
 * Phase 3/US1 added findRequest(). Phase 4/US2 (T031) adds costForRequest();
 * contributorsForRequest() is still Phase 6/US4's own job.
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

    /**
     * FR-012/FR-013, data-model.md §4: a plain read of the five cost/quorum
     * fields directly off the stored row -- both cost figures are computed
     * once, by ConsensusService (dispatch()'s estimated_additional_cost and
     * finalize()'s actual_additional_cost respectively), so this method
     * never recomputes either live from usage_records (mutation-checklist
     * row 5: a later, unrelated usage_records row landing on the same
     * helper conversation -- e.g. from a different, later consensus
     * request reusing an assignment -- must never change an earlier
     * request's reported figure).
     *
     * @return array{estimated_additional_cost: ?string, actual_additional_cost: ?string, dispatched_count: int, successful_count: ?int, quorum_required: ?int}|null
     *   Null when the request doesn't exist or isn't owned by the caller,
     *   mirroring findRequest()'s own "null collapses both absent and
     *   not-the-caller's" contract.
     */
    public function costForRequest(string $callerUserId, string $requestId): ?array
    {
        $request = $this->findRequest($callerUserId, $requestId);
        if ($request === null) {
            return null;
        }

        return [
            'estimated_additional_cost' => $request->estimated_additional_cost,
            'actual_additional_cost' => $request->actual_additional_cost,
            'dispatched_count' => $request->dispatched_count,
            'successful_count' => $request->successful_count,
            'quorum_required' => $request->quorum_required,
        ];
    }
}
