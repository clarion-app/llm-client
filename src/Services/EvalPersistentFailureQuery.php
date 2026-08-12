<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\EvalCase;
use ClarionApp\LlmClient\Models\EvalSuite;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Most persistently failing cases" — a per-case, SQL-LIMIT-capped UNION
 * ALL query, deliberately not EvalCaseHistoryQuery::historiesFor()'s own
 * uncapped-fetch shape: this query scans an agent's entire run history
 * with no exclusion bound, so an uncapped initial fetch would grow with
 * total history size, exactly what a bounded read must avoid. Bounded by
 * design: total row volume is at most (agent's live case count) x
 * (persistent_failure_lookback) — both config-capped, small numbers —
 * never by total run history length.
 */
class EvalPersistentFailureQuery
{
    /**
     * Resolves the agent's own live case ids first, issues one
     * per-case, ORDER BY created_at DESC LIMIT lookback subquery for
     * each, combines them into a single round trip via unionAll(), then
     * computes fail_count/total_count in PHP over that already-capped row
     * set before ranking by fail_count descending, then total_count
     * descending — never total_count alone.
     *
     * @return array<int, array{eval_case_id: string, fail_count: int, total_count: int, fail_rate: float}>
     */
    public function rankedFailures(string $agentLabel, int $limit): array
    {
        $caseIds = EvalCase::whereIn(
            'suite_id',
            EvalSuite::where('agent_identifier', $agentLabel)->pluck('id'),
        )->pluck('id')->all();

        if ($caseIds === []) {
            return [];
        }

        $lookback = (int) config('llm-client.eval_dashboard.persistent_failure_lookback', 20);

        $grouped = [];

        foreach ($this->fetchCappedResults($agentLabel, $caseIds, $lookback) as $row) {
            $caseId = $row->eval_case_id;
            $effective = $row->outcome_override ?? $row->outcome;

            if (!isset($grouped[$caseId])) {
                $grouped[$caseId] = ['fail_count' => 0, 'total_count' => 0];
            }

            $grouped[$caseId]['total_count']++;

            if ($effective === 'fail') {
                $grouped[$caseId]['fail_count']++;
            }
        }

        $ranked = [];

        foreach ($grouped as $caseId => $counts) {
            $ranked[] = [
                'eval_case_id' => $caseId,
                'fail_count' => $counts['fail_count'],
                'total_count' => $counts['total_count'],
                'fail_rate' => $counts['total_count'] > 0
                    ? round($counts['fail_count'] / $counts['total_count'], 4)
                    : 0.0,
            ];
        }

        usort($ranked, function (array $a, array $b) {
            if ($a['fail_count'] !== $b['fail_count']) {
                return $b['fail_count'] <=> $a['fail_count'];
            }

            return $b['total_count'] <=> $a['total_count'];
        });

        return array_slice($ranked, 0, $limit);
    }

    /**
     * One portable, SQL-level-capped subquery per case id — ORDER BY
     * created_at DESC LIMIT $lookback — combined into one round trip via
     * unionAll(). Each subquery's own cost is bounded by its LIMIT, never
     * by that case's total historical row count.
     *
     * @param  array<int, string>  $caseIds
     */
    private function fetchCappedResults(string $agentLabel, array $caseIds, int $lookback): Collection
    {
        $queries = [];

        foreach ($caseIds as $caseId) {
            $queries[] = DB::table('eval_case_results')
                ->join('eval_runs', 'eval_runs.id', '=', 'eval_case_results.run_id')
                ->where('eval_runs.agent_label', $agentLabel)
                ->where('eval_case_results.eval_case_id', $caseId)
                ->orderByDesc('eval_case_results.created_at')
                ->limit($lookback)
                ->select(
                    'eval_case_results.eval_case_id',
                    'eval_case_results.outcome',
                    'eval_case_results.outcome_override',
                );
        }

        $base = array_shift($queries);

        foreach ($queries as $query) {
            $base->unionAll($query);
        }

        return $base->get();
    }
}
