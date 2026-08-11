<?php

namespace ClarionApp\LlmClient\Tests\Unit\ValueObjects;

use ClarionApp\LlmClient\ValueObjects\EvalCaseOutcome;
use ClarionApp\LlmClient\ValueObjects\ExpectationKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EvalCaseOutcome::aggregate() is the single shared rule for deriving a
 * case's overall outcome from its expectation_results array — called both
 * at original-judgment time and whenever an override changes one
 * expectation's met value. Precedence, highest to lowest:
 *
 *   any human_judgment entry            -> NeedsHumanReview
 *   any unmet checkable entry           -> Fail
 *   any unjudged rubric_judgment entry  -> Unjudged
 *   otherwise                           -> Pass
 *
 * NeedsHumanReview must outrank everything else, including an unmet
 * checkable expectation in the same set — this preserves a rule
 * EvalCaseJudge already shipped and already tested; a naive re-ordering
 * that checked the unmet-checkable condition first would get this
 * boundary case backwards.
 */
class EvalCaseOutcomeAggregateTest extends TestCase
{
    #[Test]
    public function human_judgment_outranks_both_a_failed_checkable_and_an_unjudged_rubric_entry_in_the_same_set(): void
    {
        $outcome = EvalCaseOutcome::aggregate([
            ['kind' => ExpectationKind::TextMatch->value, 'met' => false],
            ['kind' => ExpectationKind::RubricJudgment->value, 'status' => 'unjudged'],
            ['kind' => ExpectationKind::HumanJudgment->value, 'met' => null],
        ]);

        $this->assertSame(EvalCaseOutcome::NeedsHumanReview, $outcome);
    }

    #[Test]
    public function a_failed_checkable_outranks_an_unjudged_rubric_entry_when_no_human_judgment_is_present(): void
    {
        $outcome = EvalCaseOutcome::aggregate([
            ['kind' => ExpectationKind::TextMatch->value, 'met' => false],
            ['kind' => ExpectationKind::RubricJudgment->value, 'status' => 'unjudged'],
        ]);

        $this->assertSame(EvalCaseOutcome::Fail, $outcome);
    }

    #[Test]
    public function an_unjudged_rubric_entry_yields_unjudged_when_nothing_failed_and_no_human_judgment_is_present(): void
    {
        $outcome = EvalCaseOutcome::aggregate([
            ['kind' => ExpectationKind::ActionTaken->value, 'met' => true],
            ['kind' => ExpectationKind::RubricJudgment->value, 'status' => 'unjudged'],
        ]);

        $this->assertSame(EvalCaseOutcome::Unjudged, $outcome);
    }

    #[Test]
    public function outcome_is_pass_when_nothing_failed_nothing_is_unjudged_and_no_human_judgment_is_present(): void
    {
        $outcome = EvalCaseOutcome::aggregate([
            ['kind' => ExpectationKind::TextMatch->value, 'met' => true],
            ['kind' => ExpectationKind::ActionNotTaken->value, 'met' => true],
        ]);

        $this->assertSame(EvalCaseOutcome::Pass, $outcome);
    }

    #[Test]
    public function an_empty_expectation_results_array_is_vacuously_pass(): void
    {
        $outcome = EvalCaseOutcome::aggregate([]);

        $this->assertSame(EvalCaseOutcome::Pass, $outcome);
    }

    #[Test]
    public function a_judged_rubric_entry_with_a_passing_met_value_behaves_like_a_met_checkable_entry(): void
    {
        $outcome = EvalCaseOutcome::aggregate([
            ['kind' => ExpectationKind::RubricJudgment->value, 'status' => 'judged', 'met' => true],
        ]);

        $this->assertSame(EvalCaseOutcome::Pass, $outcome);
    }
}
