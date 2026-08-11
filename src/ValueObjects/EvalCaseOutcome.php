<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The durable outcome recorded once per case per run (FR-003/FR-005).
 * `Errored` is not a judging outcome at all — it is set instead of
 * calling EvalCaseJudge, when the case's agent call itself threw, timed
 * out, or was refused by the budget gate. `Unjudged` covers the case
 * where every checkable expectation passed (or there were none) but at
 * least one rubric_judgment expectation could not be scored.
 */
enum EvalCaseOutcome: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case NeedsHumanReview = 'needs_human_review';
    case Errored = 'errored';
    case Unjudged = 'unjudged';

    /**
     * The single aggregate-derivation rule — called both at
     * original-judgment time and whenever an override changes one
     * expectation's met value. One rule, not two.
     *
     * @param  array<int, array<string, mixed>>  $expectationResults
     */
    public static function aggregate(array $expectationResults): self
    {
        $hasUnmetCheckable = false;
        $hasUnjudgedRubric = false;
        $hasHumanJudgment = false;

        foreach ($expectationResults as $result) {
            $kind = $result['kind'] ?? null;

            if ($kind === ExpectationKind::HumanJudgment->value) {
                $hasHumanJudgment = true;
            } elseif ($kind === ExpectationKind::RubricJudgment->value
                && ($result['status'] ?? null) === 'unjudged') {
                $hasUnjudgedRubric = true;
            } elseif (($result['met'] ?? null) === false) {
                $hasUnmetCheckable = true;
            }
        }

        return match (true) {
            // Checked first — preserves the existing, already-tested rule
            // that a human_judgment expectation wins regardless of
            // checkable results. Reordering this below Fail would
            // silently flip already-shipped behavior.
            $hasHumanJudgment => self::NeedsHumanReview,
            $hasUnmetCheckable => self::Fail,
            $hasUnjudgedRubric => self::Unjudged,
            default => self::Pass,
        };
    }
}
