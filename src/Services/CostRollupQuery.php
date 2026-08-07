<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Role-scoped reads over cost_summaries (T037, contracts/cost-api.md §3/§4).
 *
 * Every *Total() method sums the requested entity's cost_summaries rows over
 * an inclusive [$from, $to] period_date range and returns the common
 * single-rollup shape. Every *List() method groups by entity_id over the
 * same range and returns one row per entity with any usage, each shaped as
 * the common shape plus its own id field.
 *
 * $callerId/$isOperator mirror contracts/cost-api.md §4's authorization
 * table: an operator's read is unrestricted; a non-operator's read is
 * scoped to cost_summaries rows where user_id = $callerId (research.md D7 —
 * for a conversation/user row user_id is the owner, for an agent row it is
 * the specific contributing user, so this single filter implements both
 * "own conversations/users only" and "own contribution to a shared agent"
 * from the same underlying rows). This class never 403s or throws for a
 * scoping mismatch — it simply narrows or returns the zero-value shape; the
 * controller (CostRollupController) is responsible for translating a
 * scoping mismatch into an HTTP 403 where the contract requires one
 * (conversations/{id}, users/{id}) versus where it does not (agents/{id},
 * the list endpoints).
 */
class CostRollupQuery
{
    public function conversationTotal(string $conversationId, string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        return $this->aggregate(CostSummary::ENTITY_CONVERSATION, $conversationId, $from, $to, $isOperator ? null : $callerId);
    }

    public function conversationList(string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        return $this->groupedList(CostSummary::ENTITY_CONVERSATION, 'conversation_id', $from, $to, $isOperator ? null : $callerId);
    }

    public function userTotal(string $userId, string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        return $this->aggregate(CostSummary::ENTITY_USER, $userId, $from, $to, $isOperator ? null : $callerId);
    }

    /**
     * Operator: one row per user with usage in the period. Non-operator:
     * always exactly one row — the caller's own — even when they have no
     * usage in the period at all (contracts/cost-api.md §3 — "never an
     * error, never another user's row"). This is deliberately different
     * from agentList()'s non-operator behavior, where an unused agent simply
     * has no row.
     */
    public function userList(string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        if (!$isOperator) {
            $own = $this->userTotal($callerId, $from, $to, $callerId, false);

            return [array_merge(['user_id' => $callerId], $own)];
        }

        return $this->groupedList(CostSummary::ENTITY_USER, 'user_id', $from, $to, null);
    }

    public function agentTotal(string $agentId, string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        return $this->aggregate(CostSummary::ENTITY_AGENT, $agentId, $from, $to, $isOperator ? null : $callerId);
    }

    /**
     * Operator: full cross-user totals per agent, including Unattributed
     * when it has any usage. Non-operator: each row (including Unattributed)
     * restricted to the caller's own contribution; an agent with zero usage
     * from the caller simply does not appear (FR-021/FR-022).
     *
     * The reserved sentinel entity_id is translated to the literal
     * "unattributed" here — the raw sentinel UUID is never returned.
     */
    public function agentList(string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        $rows = $this->groupedList(CostSummary::ENTITY_AGENT, 'agent_id', $from, $to, $isOperator ? null : $callerId);

        return array_map(function (array $row) {
            if ($row['agent_id'] === CostSummary::UNATTRIBUTED_AGENT_BUCKET) {
                $row['agent_id'] = 'unattributed';
            }

            return $row;
        }, $rows);
    }

    /**
     * Sum a single entity's cost_summaries rows over [$from, $to]. Returns
     * the zero-value shape (never null, never an exception) when nothing
     * matches — including when the entity itself does not exist, since this
     * class has no notion of entity existence, only of usage.
     */
    private function aggregate(string $entityType, string $entityId, string $from, string $to, ?string $userIdFilter): array
    {
        $query = DB::table('cost_summaries')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereBetween('period_date', [$from, $to]);

        if ($userIdFilter !== null) {
            $query->where('user_id', $userIdFilter);
        }

        $row = $query->selectRaw(
            'COALESCE(SUM(request_count), 0) as request_count, '.
            'COALESCE(SUM(priced_cost_total), 0) as priced_cost_total, '.
            'COALESCE(SUM(zero_priced_request_count), 0) as zero_priced_request_count, '.
            'COALESCE(SUM(unpriced_request_count), 0) as unpriced_request_count, '.
            'COALESCE(SUM(unpriced_total_tokens), 0) as unpriced_total_tokens, '.
            'COALESCE(SUM(estimated_request_count), 0) as estimated_request_count'
        )->first();

        return $this->shapeRow($row);
    }

    /**
     * Group an entity dimension's cost_summaries rows by entity_id over
     * [$from, $to], summed and shaped per row, ordered by priced_cost_total
     * descending (contracts/cost-api.md §3 — "which entity accounted for the
     * largest share").
     */
    private function groupedList(string $entityType, string $idKey, string $from, string $to, ?string $userIdFilter): array
    {
        $query = DB::table('cost_summaries')
            ->where('entity_type', $entityType)
            ->whereBetween('period_date', [$from, $to]);

        if ($userIdFilter !== null) {
            $query->where('user_id', $userIdFilter);
        }

        $rows = $query->selectRaw(
            'entity_id, '.
            'SUM(request_count) as request_count, '.
            'SUM(priced_cost_total) as priced_cost_total, '.
            'SUM(zero_priced_request_count) as zero_priced_request_count, '.
            'SUM(unpriced_request_count) as unpriced_request_count, '.
            'SUM(unpriced_total_tokens) as unpriced_total_tokens, '.
            'SUM(estimated_request_count) as estimated_request_count'
        )
            ->groupBy('entity_id')
            ->orderByDesc('priced_cost_total')
            ->get();

        return $rows->map(fn ($row) => array_merge([$idKey => $row->entity_id], $this->shapeRow($row)))->all();
    }

    private function shapeRow(?object $row): array
    {
        return [
            'priced_cost_total' => Decimal::round((string) ($row->priced_cost_total ?? '0'), 10),
            'request_count' => (int) ($row->request_count ?? 0),
            'zero_priced_request_count' => (int) ($row->zero_priced_request_count ?? 0),
            'unpriced_request_count' => (int) ($row->unpriced_request_count ?? 0),
            'unpriced_total_tokens' => (int) ($row->unpriced_total_tokens ?? 0),
            'has_estimated_cost' => ((int) ($row->estimated_request_count ?? 0)) > 0,
        ];
    }
}
