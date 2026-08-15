<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Support\Decimal;

/**
 * Owner-scoped read path over the Delegation rows DelegationService writes
 * (data-model.md §6). Mirrors RunTraceQuery::findRun()'s exact "null
 * collapses both absent and not-the-caller's" contract for every method
 * here, and EvalRunConsumptionQuery's "collect the related id set, guard
 * empty, whereIn + selectRaw SUM" shape for costForRun().
 */
class DelegationQuery
{
    /** Column scale usage_records.total_cost is written/read at (matches EvalRunConsumptionQuery::COST_SCALE). */
    private const COST_SCALE = 10;

    public function __construct(
        private readonly RunTraceQuery $runTraceQuery,
    ) {}

    /**
     * @return Delegation|null Null when absent or owned by another user.
     */
    public function findDelegation(string $callerUserId, string $delegationId): ?Delegation
    {
        return Delegation::where('id', $delegationId)
            ->where('owner_user_id', $callerUserId)
            ->first();
    }

    /**
     * Every delegation made during a run the caller owns.
     *
     * @return Delegation[]|null Null when the run doesn't exist or isn't
     *                           owned by the caller. Empty array for an
     *                           owned run with zero delegations.
     */
    public function delegationsForRun(string $callerUserId, string $runId): ?array
    {
        $run = $this->runTraceQuery->findRun($callerUserId, $runId);
        if ($run === null) {
            return null;
        }

        return Delegation::where('parent_run_id', $runId)
            ->orderBy('started_at')
            ->get()
            ->all();
    }

    /**
     * Every delegation made from a conversation the caller owns, independent
     * of any one run (FR-012's general "recoverable after the fact"
     * surface).
     *
     * @return Delegation[]|null Null when the conversation doesn't exist or
     *                           isn't owned by the caller.
     */
    public function delegationsForConversation(string $callerUserId, string $conversationId): ?array
    {
        $ownsConversation = Conversation::where('id', $conversationId)
            ->where('user_id', $callerUserId)
            ->exists();

        if (!$ownsConversation) {
            return null;
        }

        return Delegation::where('parent_conversation_id', $conversationId)
            ->orderBy('started_at')
            ->get()
            ->all();
    }

    /**
     * 101-parallel-subagent-execution (FR-012, contracts §3, data-model.md
     * §3): every agent_delegations row sharing one batch_id -- a Concurrent
     * Batch has no row of its own, so "membership" is always this query,
     * never cached or duplicated elsewhere. Owner-scoped via the batch's
     * own rows' owner_user_id, mirroring delegationsForRun()'s exact
     * "null collapses both absent and not-the-caller's" contract.
     *
     * @return Delegation[]|null Null when the batch id is unknown or not
     *                           owned by the caller. Empty array for an
     *                           owned batch with zero members (defensive --
     *                           data-model.md §1 treats this as an anomaly
     *                           that should not occur in practice).
     */
    public function membersForBatch(string $callerUserId, string $batchId): ?array
    {
        $owned = Delegation::where('batch_id', $batchId)
            ->where('owner_user_id', $callerUserId)
            ->exists();

        if (!$owned) {
            return null;
        }

        return Delegation::where('batch_id', $batchId)
            ->where('owner_user_id', $callerUserId)
            ->orderBy('started_at')
            ->get()
            ->all();
    }

    /**
     * Rolled-up delegated cost for a run (research.md D9): every delegation
     * made directly from this run, plus transitively every further
     * delegation made from each of those helper runs in turn (a helper that
     * itself delegated further is included), summed against usage_records
     * scoped by helper_conversation_id. `delegation_count` is the size of
     * the same transitive set the cost sum uses, so the two figures always
     * describe the same set of delegations.
     *
     * @return array{total_cost: string, total_tokens: int, delegation_count: int}
     */
    public function costForRun(string $callerUserId, string $runId): array
    {
        $directDelegations = $this->delegationsForRun($callerUserId, $runId) ?? [];

        $allDelegations = $this->collectTransitiveDelegations($callerUserId, $directDelegations);

        $helperConversationIds = array_values(array_unique(array_filter(
            array_map(fn (Delegation $d) => $d->helper_conversation_id, $allDelegations)
        )));

        if (empty($helperConversationIds)) {
            return [
                'total_cost' => Decimal::round('0', self::COST_SCALE),
                'total_tokens' => 0,
                'delegation_count' => count($allDelegations),
            ];
        }

        $usage = UsageRecord::whereIn('conversation_id', $helperConversationIds)
            ->selectRaw('SUM(total_tokens) as tokens, SUM(total_cost) as cost')
            ->first();

        return [
            'total_cost' => Decimal::round(Decimal::fromNumeric($usage->cost ?? '0'), self::COST_SCALE),
            'total_tokens' => (int) ($usage->tokens ?? 0),
            'delegation_count' => count($allDelegations),
        ];
    }

    /**
     * Breadth-first walk outward from a set of direct delegations, through
     * every further delegation made from each one's own helper run, each
     * visited run id at most once. Nested rows are trusted to belong to the
     * same owner without a further ownership check (DelegationService
     * always stamps owner_user_id with the real delegating user, D9) but the
     * filter is kept anyway as defense-in-depth.
     *
     * @param Delegation[] $directDelegations
     * @return Delegation[]
     */
    private function collectTransitiveDelegations(string $callerUserId, array $directDelegations): array
    {
        $all = $directDelegations;
        $visitedRunIds = [];
        $queue = array_values(array_filter(array_map(fn (Delegation $d) => $d->helper_run_id, $directDelegations)));

        while (!empty($queue)) {
            $runId = array_shift($queue);
            if ($runId === null || in_array($runId, $visitedRunIds, true)) {
                continue;
            }
            $visitedRunIds[] = $runId;

            $nested = Delegation::where('parent_run_id', $runId)
                ->where('owner_user_id', $callerUserId)
                ->orderBy('started_at')
                ->get();

            foreach ($nested as $delegation) {
                $all[] = $delegation;
                if ($delegation->helper_run_id !== null) {
                    $queue[] = $delegation->helper_run_id;
                }
            }
        }

        return $all;
    }
}
