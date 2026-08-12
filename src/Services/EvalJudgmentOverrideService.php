<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalJudgment;
use ClarionApp\LlmClient\Models\EvalJudgmentOverride;
use ClarionApp\LlmClient\ValueObjects\EvalCaseOutcome;
use Illuminate\Support\Facades\Log;

/**
 * The sole write path for eval_judgment_overrides. An override is a new,
 * append-only fact about a judgment, never a mutation of it — the
 * judgment's own score/justification stay exactly as originally produced
 * no matter how many times an operator corrects them.
 *
 * The one side effect an override has beyond its own table is
 * recomputing and writing eval_case_results.outcome_override via the
 * same EvalCaseOutcome::aggregate() rule used at original-judgment time
 * — the case result's original outcome/expectation_results columns are
 * never touched, only the additive outcome_override column.
 */
class EvalJudgmentOverrideService
{
    public function __construct(
        private readonly EvalPassRateRollupService $passRateRollup,
    ) {
    }

    public function override(EvalJudgment $judgment, ?int $score, ?string $justification, string $userId): EvalJudgmentOverride
    {
        // Force a fresh load of the overrides relation rather than trusting
        // whatever Eloquent already has cached on this instance — a caller
        // that overrides the same $judgment object twice in a row (e.g. an
        // operator correcting a judgment twice within one request/test)
        // must see the first override's row when computing "current
        // effective" for the second, never a stale, pre-first-override cache.
        $judgment->load('overrides');

        $current = $judgment->effective();

        $override = EvalJudgmentOverride::create([
            'judgment_id' => $judgment->id,
            'user_id' => $userId,
            'score' => $score ?? $current['score'],
            'justification' => $justification ?? $current['justification'],
        ]);

        $this->recomputeCaseOutcome($judgment, $override->score);

        return $override;
    }

    /**
     * Rebuilds the judgment's case result's expectation_results array,
     * substituting only this judgment's own expectation entry's `met`
     * value (derived from the overridden score against the passing
     * threshold), leaving every other expectation entry exactly as it
     * was. The recomputed aggregate outcome is written to the additive
     * eval_case_results.outcome_override column only — the row's
     * original outcome/expectation_results columns are never updated.
     */
    private function recomputeCaseOutcome(EvalJudgment $judgment, int $effectiveScore): void
    {
        if ($judgment->eval_case_result_id === null) {
            return;
        }

        $caseResult = EvalCaseResult::find($judgment->eval_case_result_id);

        if ($caseResult === null) {
            return;
        }

        $passingScore = (int) config('llm-client.eval_judging.passing_score', 7);
        $met = $effectiveScore >= $passingScore;

        // Only this judgment's own expectation entry's `met` is
        // substituted for the aggregate recomputation below — the frozen
        // expectation_results entry itself (its score/status/etc.) is
        // never rewritten, matching data-model.md §4: an override's
        // corrected value lives at the judgment's own effective(), not
        // by editing this already-written-once JSON blob.
        $expectationResults = array_map(
            function (array $result) use ($judgment, $met) {
                if (($result['judgment_id'] ?? null) === $judgment->id) {
                    $result['met'] = $met;
                }

                return $result;
            },
            $caseResult->expectation_results ?? [],
        );

        $outcome = EvalCaseOutcome::aggregate($expectationResults);

        // Captured before the update below, from whatever was effective a
        // moment ago — the original, untouched outcome column when this is
        // the case's first override, or a prior override's value on a
        // second correction.
        $oldEffective = $caseResult->outcome_override ?? $caseResult->outcome->value;

        $caseResult->update(['outcome_override' => $outcome->value]);

        if ($outcome->value !== $oldEffective) {
            try {
                $this->passRateRollup->adjustForOverride($caseResult, $oldEffective, $outcome->value);
            } catch (\Throwable $e) {
                Log::warning('EvalJudgmentOverrideService: failed to adjust pass-rate rollup', [
                    'eval_case_result_id' => $caseResult->id,
                    'old_effective' => $oldEffective,
                    'new_effective' => $outcome->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
