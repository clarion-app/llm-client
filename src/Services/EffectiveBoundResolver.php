<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;

/**
 * Runtime (chain) bound (100-subagent-tool-restrictions, Phase 4/US2,
 * data-model.md §3, research.md D2/D8).
 *
 * Walks `agent_delegations` upward from the acting conversation, nearest
 * ancestor first: the row whose `helper_conversation_id` equals the
 * conversation under test names the next ancestor (`parent_agent_id`) and
 * the next hop to repeat the lookup from (`parent_conversation_id`). The
 * first ancestor whose CURRENT `AgentHelperQuery::permittedOperationIds()`
 * excludes the operation under test is reported as the blocker.
 *
 * A conversation never named as a `helper_conversation_id` (the
 * overwhelmingly common, non-delegated case) is allowed immediately, at
 * the cost of exactly one query and no `Agent`/`AgentVersion` fetch
 * (research.md D8) — this is deliberately the fast path.
 *
 * The walk is bounded both by `config('llm-client.delegation.max_chain_depth')`
 * (already enforced write-time by DelegationService, defensive here) and by
 * a visited-set guard against a data-level cycle in `agent_delegations` —
 * that table is written strictly forward by DelegationService::delegate()
 * and should never actually contain one, but this walk does not trust that
 * blindly (mirroring AgentHelperQuery::structuralEffectiveBound()'s own
 * cycle-safety posture, research.md D2/D3).
 *
 * 109-agent-as-capability (Phase 5/US3, data-model.md §7, research.md D3):
 * a SECOND, independent visited-set additionally tracks visited AGENT ids
 * (`$delegation->parent_agent_id` at each hop) — not merely conversation
 * ids. Introducing a second edge type (capability offerings) that a live
 * chain can traverse interchangeably with helper-assignment hops means the
 * config-time union-graph DFS (AgentHelperQuery::wouldCreateCycle()/
 * wouldOfferingCreateCycle()) is the PRIMARY defense, not the only one — a
 * data anomaly or race bypassing it must still be caught here, at the
 * moment it would actually matter, rather than merely capped by depth. If
 * the specific agent about to be checked as an ancestor has already
 * appeared earlier in this same walk, the walk stops and reports that
 * agent as the blocker using the SAME return shape a permission-bound
 * rejection already uses — no new result shape is introduced for a
 * cycle-shaped refusal at this layer.
 */
final class EffectiveBoundResolver
{
    public function __construct(private readonly AgentHelperQuery $helperQuery) {}

    /**
     * @return array{allowed: bool, blocking_agent_id: ?string, blocking_agent_name: ?string, levels_up: ?int}
     */
    public function check(Conversation $conversation, string $operationId): array
    {
        $maxChainDepth = (int) config('llm-client.delegation.max_chain_depth', 5);

        $catalog = null;
        $visited = [];
        $visitedAgentIds = [];
        $currentConversationId = $conversation->id;
        $levelsUp = 0;

        while (true) {
            if (isset($visited[$currentConversationId])) {
                // A data-level cycle — every ancestor actually reachable
                // has already been checked once by the time a revisit is
                // detected, so this is the same "reached the top of what
                // can safely be walked" posture as the depth cap below.
                break;
            }
            $visited[$currentConversationId] = true;

            $delegation = Delegation::where('helper_conversation_id', $currentConversationId)->first();
            if ($delegation === null) {
                break;
            }

            $levelsUp++;
            if ($levelsUp > $maxChainDepth) {
                break;
            }

            // 109-agent-as-capability (Phase 5/US3, data-model.md §7):
            // the agent-identity backstop — checked BEFORE the permission
            // bound below, so a revisited agent is refused as a cycle
            // even when its own current bound would otherwise permit the
            // operation (proving this is genuinely identity-based, not a
            // side effect of the permission check).
            $ancestorAgentId = $delegation->parent_agent_id;
            if (isset($visitedAgentIds[$ancestorAgentId])) {
                $ancestorAgent = Agent::find($ancestorAgentId);

                return [
                    'allowed' => false,
                    'blocking_agent_id' => $ancestorAgentId,
                    'blocking_agent_name' => $ancestorAgent?->name,
                    'levels_up' => $levelsUp,
                ];
            }
            $visitedAgentIds[$ancestorAgentId] = true;

            $catalog ??= $this->helperQuery->catalog();

            $ancestorAgent = Agent::find($ancestorAgentId);
            if ($ancestorAgent !== null) {
                $permitted = $this->helperQuery->permittedOperationIds($ancestorAgent, $catalog);
                if (!in_array($operationId, $permitted, true)) {
                    return [
                        'allowed' => false,
                        'blocking_agent_id' => $ancestorAgent->id,
                        'blocking_agent_name' => $ancestorAgent->name,
                        'levels_up' => $levelsUp,
                    ];
                }
            }

            $currentConversationId = $delegation->parent_conversation_id;
        }

        return [
            'allowed' => true,
            'blocking_agent_id' => null,
            'blocking_agent_name' => null,
            'levels_up' => null,
        ];
    }
}
