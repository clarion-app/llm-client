<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\VarianceConfidence;

/**
 * Pure, deterministic decision rules — no I/O, no database, no model
 * call. Given a case's own historical evidence (already resolved and
 * filtered elsewhere), decides whether a new regression-shaped
 * difference looks like this agent's ordinary run-to-run variation or a
 * genuine first-ever occurrence, on two independent axes: a boolean
 * pass/fail transition, and a numeric rubric-score drop.
 *
 * Below a configured minimum sample size the answer is always
 * insufficient_history regardless of what the (too-small) sample would
 * otherwise suggest — a small sample is never allowed to masquerade as
 * either a confident "ordinary" or a confident "regression" verdict.
 */
class CaseVarianceAnalyzer
{
    /**
     * A case that just flipped pass -> fail. $priorOutcomes is the
     * case's own history, arriving pre-filtered to only 'pass'/'fail'
     * values (an unjudged/needs_human_review/errored prior attempt is
     * excluded upstream, never counted here).
     *
     * @param  array<int, string>  $priorOutcomes
     */
    public function classifyBooleanTransition(array $priorOutcomes): VarianceConfidence
    {
        if (count($priorOutcomes) < $this->minHistoryForVariance()) {
            return VarianceConfidence::InsufficientHistory;
        }

        return in_array('fail', $priorOutcomes, true)
            ? VarianceConfidence::OrdinaryVariation
            : VarianceConfidence::LikelyRegression;
    }

    /**
     * A case whose rubric score just dropped materially while still
     * passing. $priorScores is the case's own history of prior judged
     * scores at the specific expectation index in play (an unjudged
     * prior attempt contributes no entry, never counted here).
     *
     * @param  array<int, int>  $priorScores
     */
    public function classifyNumericDrift(array $priorScores, int $newScore): VarianceConfidence
    {
        if (count($priorScores) < $this->minHistoryForVariance()) {
            return VarianceConfidence::InsufficientHistory;
        }

        return $newScore >= min($priorScores)
            ? VarianceConfidence::OrdinaryVariation
            : VarianceConfidence::LikelyRegression;
    }

    private function minHistoryForVariance(): int
    {
        return (int) config('llm-client.eval_regression.min_history_for_variance', 5);
    }
}
