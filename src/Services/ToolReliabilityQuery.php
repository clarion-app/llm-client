<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\ToolReliabilitySummary;
use ClarionApp\LlmClient\Support\CalendarPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Role-scoped reads over tool_reliability_summaries (075-tool-reliability-
 * rates, User Story 1), mirroring CostRollupQuery's per-scope show/list
 * shape.
 *
 * Every toolSummary() call resolves a caller-chosen period type + reference
 * date into a calendar-aligned [from, to] range via CalendarPeriod::resolve()
 * and sums the in-range tool_reliability_summaries rows for a single tool —
 * never a scan of tool_invocation_records, and never more than the bounded
 * (<=31) day-granularity rows the resolved range spans.
 *
 * $callerId/$isOperator mirror CostRollupQuery's authorization pattern
 * exactly: an operator's read is unrestricted; a non-operator's read is
 * scoped to rows where user_id = $callerId. This class never throws or 403s
 * for a scoping mismatch — it simply narrows, or returns the zero-value/
 * no_activity shape when nothing matches.
 */
class ToolReliabilityQuery
{
    /**
     * Sum a single tool's in-range tool_reliability_summaries rows.
     * $agentId narrows to one bucket — a real agent id, the explicit
     * UNATTRIBUTED_AGENT_BUCKET sentinel, or null for the all-agents
     * aggregate (User Story 1's original behavior, unchanged). Resolving the
     * API's `unattributed` literal into the sentinel is the controller's
     * job, not this method's. Returns the zero-value/no_activity shape
     * (never null, never an exception) when nothing matches, including when
     * the tool name has never appeared at all.
     */
    public function toolSummary(string $toolName, string $periodType, string $date, ?string $agentId, ?string $callerId, bool $isOperator): array
    {
        $period = CalendarPeriod::resolve($periodType, $date);

        $query = $this->periodQuery($period['from'], $period['to'])->where('tool_name', $toolName);

        if ($agentId !== null) {
            $query->where('agent_id', $agentId);
        }

        if (!$isOperator) {
            $query->where('user_id', $callerId);
        }

        $row = $query->selectRaw($this->aggregateSelect())->first();

        return $this->shape($toolName, $agentId, $period, $row);
    }

    /**
     * One entry per tool with any in-range, in-scope activity, ordered by
     * failure_count DESC then invocation_count DESC (contracts/tool-
     * reliability-api.md §2). A tool with zero activity in the period never
     * appears as a row.
     */
    public function toolList(string $periodType, string $date, ?string $callerId, bool $isOperator): array
    {
        $period = CalendarPeriod::resolve($periodType, $date);

        $toolNames = $this->distinctToolNames($period['from'], $period['to'], $isOperator ? null : $callerId);

        $rows = array_map(
            fn (string $toolName) => $this->toolSummary(
                toolName: $toolName,
                periodType: $periodType,
                date: $date,
                agentId: null,
                callerId: $callerId,
                isOperator: $isOperator,
            ),
            $toolNames
        );

        usort($rows, function (array $a, array $b) {
            return $b['failure_count'] <=> $a['failure_count']
                ?: $b['invocation_count'] <=> $a['invocation_count'];
        });

        return array_values($rows);
    }

    /**
     * One entry per agent (including an explicit Unattributed entry
     * whenever at least one in-scope invocation carried no agent, FR-013)
     * that used this tool in the period, ordered by failure_count DESC
     * (contracts/tool-reliability-api.md §3).
     */
    public function toolAgentBreakdown(string $toolName, string $periodType, string $date, ?string $callerId, bool $isOperator): array
    {
        $period = CalendarPeriod::resolve($periodType, $date);

        $agentIds = $this->distinctAgentIds($toolName, $period['from'], $period['to'], $isOperator ? null : $callerId);

        $rows = array_map(
            fn (string $agentId) => $this->toolSummary(
                toolName: $toolName,
                periodType: $periodType,
                date: $date,
                agentId: $agentId,
                callerId: $callerId,
                isOperator: $isOperator,
            ),
            $agentIds
        );

        usort($rows, fn (array $a, array $b) => $b['failure_count'] <=> $a['failure_count']);

        return array_values($rows);
    }

    /**
     * Single agent, cross-tool, lifetime-range aggregate (095-agent-summary-
     * cards, data-model.md §6, research.md D2). Unlike toolSummary(), there
     * is no $toolName predicate at all — every tool_reliability_summaries
     * row for this agent, across every tool it used, is summed together.
     * Returns the zero-value/no_activity shape (never null) when the agent
     * has no in-range, in-scope activity at all.
     */
    public function agentSummary(string $agentId, string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        $query = $this->periodQuery($from, $to)->where('agent_id', $agentId);

        if (!$isOperator) {
            $query->where('user_id', $callerId);
        }

        $row = $query->selectRaw($this->aggregateSelect())->first();

        return $this->shapeAgent($agentId, $row);
    }

    /**
     * One entry per agent with any in-range, in-scope activity, summed
     * across every tool_name (095-agent-summary-cards, data-model.md §6,
     * research.md D2/D6) — mirroring CostRollupQuery::agentList()'s own
     * grouped shape. An agent with zero rows in range is simply absent,
     * never a zero-value row; AgentSummaryQuery supplies the default shape
     * for an absent key, not this method.
     */
    public function agentList(string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        $query = $this->periodQuery($from, $to);

        if (!$isOperator) {
            $query->where('user_id', $callerId);
        }

        $rows = $query->selectRaw('agent_id, '.$this->aggregateSelect())
            ->groupBy('agent_id')
            ->get();

        return $rows->map(fn ($row) => $this->shapeAgent($row->agent_id, $row))->all();
    }

    private function distinctAgentIds(string $toolName, string $from, string $to, ?string $userIdFilter): array
    {
        $query = $this->periodQuery($from, $to)->where('tool_name', $toolName);

        if ($userIdFilter !== null) {
            $query->where('user_id', $userIdFilter);
        }

        return $query->distinct()->pluck('agent_id')->all();
    }

    private function distinctToolNames(string $from, string $to, ?string $userIdFilter): array
    {
        $query = $this->periodQuery($from, $to);

        if ($userIdFilter !== null) {
            $query->where('user_id', $userIdFilter);
        }

        return $query->distinct()->pluck('tool_name')->all();
    }

    private function periodQuery(string $from, string $to)
    {
        return DB::table('tool_reliability_summaries')
            ->whereBetween('period_date', [$from, $to]);
    }

    private function aggregateSelect(): string
    {
        return
            'COALESCE(SUM(invocation_count), 0) as invocation_count, '.
            'COALESCE(SUM(success_count), 0) as success_count, '.
            'COALESCE(SUM(failure_count), 0) as failure_count, '.
            'COALESCE(SUM(failure_timeout_count), 0) as failure_timeout_count, '.
            'COALESCE(SUM(failure_connection_failure_count), 0) as failure_connection_failure_count, '.
            'COALESCE(SUM(failure_authentication_failure_count), 0) as failure_authentication_failure_count, '.
            'COALESCE(SUM(failure_invalid_input_count), 0) as failure_invalid_input_count, '.
            'COALESCE(SUM(failure_server_error_count), 0) as failure_server_error_count, '.
            'COALESCE(SUM(failure_other_count), 0) as failure_other_count, '.
            'COALESCE(SUM(failure_uncategorized_count), 0) as failure_uncategorized_count';
    }

    /**
     * low_sample/no_activity are computed here, after summation — the only
     * point at which a multi-day period's true total is known (research.md
     * D6/D7).
     */
    private function shape(string $toolName, ?string $agentId, array $period, ?object $row): array
    {
        $invocationCount = (int) ($row->invocation_count ?? 0);

        return [
            'tool_name' => $toolName,
            'agent_id' => $agentId,
            'period' => $period,
            'invocation_count' => $invocationCount,
            'success_count' => (int) ($row->success_count ?? 0),
            'failure_count' => (int) ($row->failure_count ?? 0),
            'failure_breakdown' => [
                'timeout' => (int) ($row->failure_timeout_count ?? 0),
                'connection_failure' => (int) ($row->failure_connection_failure_count ?? 0),
                'authentication_failure' => (int) ($row->failure_authentication_failure_count ?? 0),
                'invalid_input' => (int) ($row->failure_invalid_input_count ?? 0),
                'server_error' => (int) ($row->failure_server_error_count ?? 0),
                'other' => (int) ($row->failure_other_count ?? 0),
                'uncategorized' => (int) ($row->failure_uncategorized_count ?? 0),
            ],
            'low_sample' => $invocationCount < ToolReliabilitySummary::LOW_SAMPLE_THRESHOLD,
            'no_activity' => $invocationCount === 0,
        ];
    }

    /**
     * The cross-tool sibling of shape() (095-agent-summary-cards, data-
     * model.md §6) — no `period`/`tool_name`/`failure_breakdown` keys,
     * since this aggregate has no tool or period dimension by construction.
     * low_sample/no_activity use shape()'s identical post-summation
     * formula.
     */
    private function shapeAgent(string $agentId, ?object $row): array
    {
        $invocationCount = (int) ($row->invocation_count ?? 0);

        return [
            'agent_id' => $agentId,
            'invocation_count' => $invocationCount,
            'success_count' => (int) ($row->success_count ?? 0),
            'failure_count' => (int) ($row->failure_count ?? 0),
            'low_sample' => $invocationCount < ToolReliabilitySummary::LOW_SAMPLE_THRESHOLD,
            'no_activity' => $invocationCount === 0,
        ];
    }
}
