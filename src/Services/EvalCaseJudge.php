<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\EvalCaseOutcome;
use ClarionApp\LlmClient\ValueObjects\ExpectationKind;

/**
 * Pure, deterministic case judging (research.md D13, data-model.md §3) —
 * no model call, no randomness. Given a pinned case version's
 * expectations, the agent's produced response text, and the actions it
 * attempted during the case's turn, computes one expectation_results
 * entry per expectation plus the case's aggregate outcome.
 */
class EvalCaseJudge
{
    /**
     * @param  array<int, array<string, mixed>>  $expectations  077's Expectation
     *   shape, reused verbatim.
     * @param  array<int, array<string, mixed>>  $attemptedActions  each shaped
     *   ['tool' => string, 'arguments' => array] (research.md D6).
     * @return array{expectation_results: array<int, array<string, mixed>>, outcome: EvalCaseOutcome}
     */
    public function judge(array $expectations, ?string $producedResponse, array $attemptedActions = []): array
    {
        $attemptedToolNames = array_map(
            fn (array $action) => $action['tool'] ?? null,
            $attemptedActions,
        );

        $hasHumanJudgment = false;
        $hasUnmetCheckable = false;
        $expectationResults = [];

        foreach ($expectations as $expectation) {
            $kind = $expectation['kind'] ?? null;

            $met = match ($kind) {
                ExpectationKind::TextMatch->value => $this->normalize((string) $producedResponse)
                    === $this->normalize((string) ($expectation['expected_text'] ?? '')),
                ExpectationKind::InformationPresent->value => str_contains(
                    $this->normalize((string) $producedResponse),
                    $this->normalize((string) ($expectation['expected_info'] ?? '')),
                ),
                ExpectationKind::ActionTaken->value => in_array(
                    $expectation['action'] ?? null,
                    $attemptedToolNames,
                    true,
                ),
                // Also true (not skipped) when the named action was never a
                // reachable tool at all — it certainly wasn't taken either
                // way, so this branch covers both cases identically
                // (data-model.md §3's unreachable-action rule).
                ExpectationKind::ActionNotTaken->value => !in_array(
                    $expectation['action'] ?? null,
                    $attemptedToolNames,
                    true,
                ),
                // Never programmatically evaluated (data-model.md §3).
                ExpectationKind::HumanJudgment->value => null,
                default => null,
            };

            if ($kind === ExpectationKind::HumanJudgment->value) {
                $hasHumanJudgment = true;
            } elseif ($met === false) {
                $hasUnmetCheckable = true;
            }

            $expectationResults[] = $expectation + ['met' => $met];
        }

        $outcome = match (true) {
            $hasHumanJudgment => EvalCaseOutcome::NeedsHumanReview,
            $hasUnmetCheckable => EvalCaseOutcome::Fail,
            default => EvalCaseOutcome::Pass,
        };

        return [
            'expectation_results' => $expectationResults,
            'outcome' => $outcome,
        ];
    }

    /**
     * Trim + collapse internal whitespace + fold case — data-model.md
     * §3's judging table normalization for text_match/information_present.
     * Case-folding keeps a checkable expectation from failing on
     * capitalization the agent's own wording is under no obligation to
     * match exactly.
     */
    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }
}
