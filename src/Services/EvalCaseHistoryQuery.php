<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\ValueObjects\ExpectationKind;

/**
 * One batched, capped query gathering each matched case's own historical
 * evidence for CaseVarianceAnalyzer — never one query per case (the
 * EvalRunConsumptionQuery::summarizeJudging() batching shape applied
 * here).
 */
class EvalCaseHistoryQuery
{
    /**
     * @param  array<int, array{eval_case_id: string, eval_case_version_id: string}>  $caseVersionPairs
     *   One entry per matched case in the comparison being built — never
     *   called for added/removed/edited cases, which have no single
     *   (case, version) pair shared by both runs to gather history for.
     * @param  array<int, string>  $excludeRunIds  The reference run's id
     *   and the compared run's id — their own results must never count
     *   as their own prior history.
     * @return array<string, array{outcomes: array<int, string>, scores_by_expectation_index: array<int, array<int, int>>}>
     *   Keyed by eval_case_id. Every requested pair is present in the
     *   return value, even one with no history at all (empty arrays).
     */
    public function historiesFor(string $agentLabel, array $caseVersionPairs, array $excludeRunIds, int $limitPerCase): array
    {
        $histories = [];
        foreach ($caseVersionPairs as $pair) {
            $histories[$pair['eval_case_id']] = [
                'outcomes' => [],
                'scores_by_expectation_index' => [],
            ];
        }

        if (empty($caseVersionPairs)) {
            return $histories;
        }

        $rows = EvalCaseResult::query()
            ->join('eval_runs', 'eval_runs.id', '=', 'eval_case_results.run_id')
            ->where('eval_runs.agent_label', $agentLabel)
            ->whereNotIn('eval_case_results.run_id', $excludeRunIds)
            ->where(function ($query) use ($caseVersionPairs) {
                foreach ($caseVersionPairs as $pair) {
                    $query->orWhere(function ($pairQuery) use ($pair) {
                        $pairQuery
                            ->where('eval_case_results.eval_case_id', $pair['eval_case_id'])
                            ->where('eval_case_results.eval_case_version_id', $pair['eval_case_version_id']);
                    });
                }
            })
            ->orderByDesc('eval_case_results.created_at')
            ->select('eval_case_results.*')
            ->get();

        // Grouped in PHP, not SQL — a portable per-group LIMIT is not
        // expressible without a window-function dialect this package
        // otherwise avoids. Rows arrive newest-first already, so simple
        // append-order grouping preserves that order per case.
        $rowsByCase = [];
        foreach ($rows as $row) {
            $rowsByCase[$row->eval_case_id][] = $row;
        }

        foreach ($rowsByCase as $evalCaseId => $caseRows) {
            $outcomes = [];
            $scoresByIndex = [];

            foreach ($caseRows as $row) {
                $effectiveOutcome = $row->outcome_override ?? $row->outcome->value;

                // Restricted to genuinely comparable pass/fail values —
                // an unjudged/needs_human_review/errored prior result is
                // an absence of evidence, not evidence of either
                // verdict, and must never pad the sample past
                // min_history_for_variance. The cap is applied after
                // this filter, not before.
                if (in_array($effectiveOutcome, ['pass', 'fail'], true) && count($outcomes) < $limitPerCase) {
                    $outcomes[] = $effectiveOutcome;
                }

                foreach (($row->expectation_results ?? []) as $index => $expectation) {
                    if (($expectation['kind'] ?? null) !== ExpectationKind::RubricJudgment->value) {
                        continue;
                    }

                    if (($expectation['status'] ?? null) !== 'judged') {
                        continue;
                    }

                    $score = $expectation['score'] ?? null;

                    if ($score === null) {
                        continue;
                    }

                    $scoresByIndex[$index] ??= [];

                    if (count($scoresByIndex[$index]) < $limitPerCase) {
                        $scoresByIndex[$index][] = $score;
                    }
                }
            }

            $histories[$evalCaseId] = [
                'outcomes' => $outcomes,
                'scores_by_expectation_index' => $scoresByIndex,
            ];
        }

        return $histories;
    }
}
