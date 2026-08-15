<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * 103-manager-agent (data-model.md §6). Owner-scoped read path over the
 * ManagedTask rows ManagerService writes -- mirrors DelegationQuery::
 * findDelegation()'s exact "null collapses both absent and not-the-caller's"
 * contract.
 *
 * Phase 3 (US1) adds findManagedTask(). Phase 5 (US3) adds partsForTask().
 * Phase 9 (US7) adds costForTask().
 */
class ManagedTaskQuery
{
    /** Column scale usage_records.total_cost is written/read at (matches DelegationQuery::COST_SCALE). */
    private const COST_SCALE = 10;
    /**
     * @return ManagedTask|null Null when absent or owned by another user.
     */
    public function findManagedTask(string $callerUserId, string $managedTaskId): ?ManagedTask
    {
        return ManagedTask::where('id', $managedTaskId)
            ->where('owner_user_id', $callerUserId)
            ->first();
    }

    /**
     * 103-manager-agent (US3, contracts/manager-agent-api.md §3, data-model.md
     * §6, tasks.md T043). Every ManagedTaskPart for the task, ordered by
     * sequence, ownership-checked via findManagedTask() first -- available
     * while the task is still in progress (FR-008/US3 AC2, Edge Cases'
     * "shows the state of each part as of that moment"), not only once
     * terminal.
     *
     * assigned_helper_agent_id/_name reflect current_delegation_id
     * (outstanding/most recent attempt -- overwritten on every
     * assign_part call, so a reassigned part shows the NEW helper, not
     * the one that failed) or, once a part is accepted and
     * current_delegation_id is no longer touched, accepted_delegation_id
     * (contracts §3).
     *
     * @return array<int, array<string, mixed>>|null Null when the task is
     *   absent or owned by another user.
     */
    public function partsForTask(string $callerUserId, string $managedTaskId): ?array
    {
        $task = $this->findManagedTask($callerUserId, $managedTaskId);
        if ($task === null) {
            return null;
        }

        $parts = ManagedTaskPart::where('managed_task_id', $managedTaskId)
            ->orderBy('sequence')
            ->get();

        $delegationIds = $parts
            ->map(fn (ManagedTaskPart $part) => $part->current_delegation_id ?? $part->accepted_delegation_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $delegations = empty($delegationIds)
            ? collect()
            : Delegation::whereIn('id', $delegationIds)->get()->keyBy('id');

        $agentIds = $delegations->pluck('helper_agent_id')->filter()->unique()->values()->all();
        $names = empty($agentIds) ? [] : Agent::whereIn('id', $agentIds)->pluck('name', 'id')->all();

        return $parts->map(function (ManagedTaskPart $part) use ($delegations, $names) {
            $delegationId = $part->current_delegation_id ?? $part->accepted_delegation_id;
            $delegation = $delegationId !== null ? ($delegations[$delegationId] ?? null) : null;

            return [
                'part_id' => $part->id,
                'sequence' => $part->sequence,
                'description' => $part->description,
                'state' => $part->state,
                'assigned_helper_agent_id' => $delegation?->helper_agent_id,
                'assigned_helper_agent_name' => $delegation !== null ? ($names[$delegation->helper_agent_id] ?? null) : null,
                'assignment_count' => $part->assignment_count,
                'accepted_summary' => $part->accepted_summary,
                'shortfall_reason' => $part->shortfall_reason,
            ];
        })->all();
    }

    /**
     * 103-manager-agent (US7, contracts/manager-agent-api.md §4,
     * data-model.md §6, research.md D10, tasks.md T068). Tree-wide cost
     * attribution: sums usage_records scoped to (a) conversation_id =
     * $task->conversation_id (the manager's own reasoning cost, across
     * however many RunManagedTaskStepJob invocations it took) and (b)
     * every helper_run_id among agent_delegations rows sharing this
     * managed_task_id -- collected via ONE indexed WHERE managed_task_id
     * = ? query, never a recursive graph walk, since DelegationService
     * (T067) already tags managed_task_id at write time on every
     * delegation anywhere in the tree, however many nested hops deep.
     *
     * A delegation made directly via assign_part carries its own part_id;
     * a nested delegation a helper makes on its own initiative carries
     * null part_id (T067) but IS still attributed to its ancestor part --
     * resolved in-memory via resolvePartId()'s own walk up the
     * parent_run_id -> helper_run_id chain, entirely within the one
     * already-collected row set (every ancestor up to and including the
     * part-tagged root shares this same managed_task_id, so it is always
     * present in that set).
     *
     * total_cost is computed by construction as manager_cost plus the
     * running sum of every by_part[].total_cost in the SAME pass that
     * builds by_part -- SC-010's "the breakdown sums exactly to the
     * reported total" holds structurally, never as two independently
     * maintained numbers.
     *
     * @return array{managed_task_id: string, total_cost: string, total_tokens: int, rounds_used: int, round_ceiling: int, manager_cost: string, by_part: array<int, array{part_id: string, sequence: int, total_cost: string, total_tokens: int, rounds: array<int, array{delegation_id: string, helper_agent_id: ?string, result_status: ?string, cost: string, tokens: int}>}>}|null
     *   Null when the task is absent or owned by another user.
     */
    public function costForTask(string $callerUserId, string $managedTaskId): ?array
    {
        $task = $this->findManagedTask($callerUserId, $managedTaskId);
        if ($task === null) {
            return null;
        }

        $managerUsage = UsageRecord::where('conversation_id', $task->conversation_id)
            ->selectRaw('SUM(total_tokens) as tokens, SUM(total_cost) as cost')
            ->first();

        $managerCost = Decimal::round(Decimal::fromNumeric($managerUsage->cost ?? '0'), self::COST_SCALE);
        $managerTokens = (int) ($managerUsage->tokens ?? 0);

        $delegations = Delegation::where('managed_task_id', $managedTaskId)
            ->orderBy('started_at')
            ->get();

        $byHelperRunId = $delegations
            ->filter(fn (Delegation $d) => $d->helper_run_id !== null)
            ->keyBy('helper_run_id');

        $runIds = $delegations->pluck('helper_run_id')->filter()->unique()->values()->all();

        $usageByRun = [];
        if (!empty($runIds)) {
            $rows = UsageRecord::whereIn('run_id', $runIds)
                ->selectRaw('run_id, SUM(total_tokens) as tokens, SUM(total_cost) as cost')
                ->groupBy('run_id')
                ->get();

            foreach ($rows as $row) {
                $usageByRun[$row->run_id] = [
                    'cost' => Decimal::round(Decimal::fromNumeric($row->cost ?? '0'), self::COST_SCALE),
                    'tokens' => (int) ($row->tokens ?? 0),
                ];
            }
        }

        $roundsByPart = [];
        foreach ($delegations as $delegation) {
            $partId = $this->resolvePartId($delegation, $byHelperRunId);
            if ($partId === null) {
                continue;
            }

            $usage = $delegation->helper_run_id !== null
                ? ($usageByRun[$delegation->helper_run_id] ?? null)
                : null;
            $usage ??= ['cost' => Decimal::round('0', self::COST_SCALE), 'tokens' => 0];

            $roundsByPart[$partId][] = [
                'delegation_id' => $delegation->id,
                'helper_agent_id' => $delegation->helper_agent_id,
                'result_status' => $delegation->result_status,
                'cost' => $usage['cost'],
                'tokens' => $usage['tokens'],
            ];
        }

        $parts = ManagedTaskPart::where('managed_task_id', $managedTaskId)
            ->orderBy('sequence')
            ->get();

        $byPart = [];
        $totalCost = $managerCost;
        $totalTokens = $managerTokens;

        foreach ($parts as $part) {
            $rounds = $roundsByPart[$part->id] ?? [];

            $partCost = Decimal::round('0', self::COST_SCALE);
            $partTokens = 0;
            foreach ($rounds as $round) {
                $partCost = bcadd($partCost, $round['cost'], self::COST_SCALE);
                $partTokens += $round['tokens'];
            }

            $byPart[] = [
                'part_id' => $part->id,
                'sequence' => $part->sequence,
                'total_cost' => $partCost,
                'total_tokens' => $partTokens,
                'rounds' => $rounds,
            ];

            $totalCost = bcadd($totalCost, $partCost, self::COST_SCALE);
            $totalTokens += $partTokens;
        }

        return [
            'managed_task_id' => $task->id,
            'total_cost' => $totalCost,
            'total_tokens' => $totalTokens,
            'rounds_used' => $task->rounds_used,
            'round_ceiling' => $task->round_ceiling,
            'manager_cost' => $managerCost,
            'by_part' => $byPart,
        ];
    }

    /**
     * Resolves the part a delegation's cost is attributable to: itself if
     * it carries a part_id directly (a direct assign_part round), or --
     * for a nested delegation a helper makes on its own initiative, whose
     * own part_id is always null (T067) -- climbs the parent_run_id ->
     * helper_run_id chain to find the ancestor delegation that DOES carry
     * one. $byHelperRunId is keyed from the SAME already-collected
     * managed_task_id-scoped row set costForTask() built -- every
     * ancestor up to and including the part-tagged root is guaranteed
     * present in it, since managed_task_id itself only ever propagates
     * from an already-tagged enclosing delegation (T067), so no further
     * query is ever needed here. $depth guards against a pathological
     * cycle; ordinary chains are bounded by delegation.max_chain_depth.
     */
    private function resolvePartId(Delegation $delegation, Collection $byHelperRunId, int $depth = 0): ?string
    {
        if ($delegation->part_id !== null) {
            return $delegation->part_id;
        }

        if ($depth > 20 || $delegation->parent_run_id === null) {
            return null;
        }

        $parent = $byHelperRunId->get($delegation->parent_run_id);
        if ($parent === null) {
            return null;
        }

        return $this->resolvePartId($parent, $byHelperRunId, $depth + 1);
    }
}
