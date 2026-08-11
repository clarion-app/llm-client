<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\EvalCaseJudge;
use ClarionApp\LlmClient\ValueObjects\EvalCaseOutcome;
use ClarionApp\LlmClient\ValueObjects\ExpectationKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * data-model.md §3's judging table, for all five ExpectationKinds (077),
 * plus the aggregate outcome derivation. EvalCaseJudge::judge() is a pure
 * function — no model call, no randomness (research.md D13) — so every
 * case here is a plain input/output assertion.
 */
class EvalCaseJudgeTest extends TestCase
{
    private function judge(array $expectations, ?string $producedResponse, array $attemptedActions = []): array
    {
        return app(EvalCaseJudge::class)->judge($expectations, $producedResponse, $attemptedActions);
    }

    // ---------------------------------------------------------------
    // text_match — normalized (trim + collapse whitespace) exact match
    // ---------------------------------------------------------------

    #[Test]
    public function text_match_is_met_when_the_normalized_response_exactly_equals_the_expected_text(): void
    {
        $result = $this->judge(
            [['kind' => 'text_match', 'expected_text' => 'The answer is 4']],
            "  The   answer \n is    4  ",
        );

        $this->assertTrue($result['expectation_results'][0]['met']);
        $this->assertSame('text_match', $result['expectation_results'][0]['kind']);
    }

    #[Test]
    public function text_match_is_not_met_when_the_normalized_response_does_not_exactly_equal_the_expected_text(): void
    {
        $result = $this->judge(
            [['kind' => 'text_match', 'expected_text' => 'The answer is 4']],
            'The answer is 5',
        );

        $this->assertFalse($result['expectation_results'][0]['met']);
    }

    // ---------------------------------------------------------------
    // information_present — normalized substring containment
    // ---------------------------------------------------------------

    #[Test]
    public function information_present_is_met_when_the_expected_info_appears_anywhere_in_the_normalized_response(): void
    {
        $result = $this->judge(
            [['kind' => 'information_present', 'expected_info' => 'the phone number 555-0100']],
            "Sure — I found Alice's record.\n  The   phone  number   555-0100  is on file.",
        );

        $this->assertTrue($result['expectation_results'][0]['met']);
    }

    #[Test]
    public function information_present_is_not_met_when_the_expected_info_is_absent(): void
    {
        $result = $this->judge(
            [['kind' => 'information_present', 'expected_info' => 'the phone number 555-0100']],
            "I wasn't able to find a contact matching that name.",
        );

        $this->assertFalse($result['expectation_results'][0]['met']);
    }

    // ---------------------------------------------------------------
    // action_taken — exact tool-name membership in attempted_actions
    // ---------------------------------------------------------------

    #[Test]
    public function action_taken_is_met_when_the_named_action_is_present_in_attempted_actions(): void
    {
        $result = $this->judge(
            [['kind' => 'action_taken', 'action' => 'contacts.create']],
            'Done.',
            [['tool' => 'contacts.create', 'arguments' => ['name' => 'Alice']]],
        );

        $this->assertTrue($result['expectation_results'][0]['met']);
    }

    #[Test]
    public function action_taken_is_not_met_when_the_named_action_is_absent_from_attempted_actions(): void
    {
        $result = $this->judge(
            [['kind' => 'action_taken', 'action' => 'contacts.create']],
            'Done.',
            [['tool' => 'contacts.search', 'arguments' => ['query' => 'Alice']]],
        );

        $this->assertFalse($result['expectation_results'][0]['met']);
    }

    /**
     * spec.md's own edge case: an action_taken expectation naming an
     * action the agent had no way to attempt in this run's environment
     * (never a reachable tool at all) is recorded as not met — not
     * silently skipped. Structurally identical to the ordinary-absence
     * case above (an unreachable action can never appear in
     * attempted_actions either), but named as its own scenario because
     * data-model.md §3 calls it out explicitly (mutation-checklist row 14).
     */
    #[Test]
    public function action_taken_is_not_met_when_the_named_action_was_never_a_reachable_tool_at_all(): void
    {
        $result = $this->judge(
            [['kind' => 'action_taken', 'action' => 'nonexistent.operation']],
            'Done.',
            [],
        );

        $this->assertFalse($result['expectation_results'][0]['met']);
    }

    // ---------------------------------------------------------------
    // action_not_taken — absent from attempted_actions ⇒ met, including
    // the unreachable-action rule
    // ---------------------------------------------------------------

    #[Test]
    public function action_not_taken_is_met_when_the_named_action_is_absent_from_attempted_actions(): void
    {
        $result = $this->judge(
            [['kind' => 'action_not_taken', 'action' => 'contacts.create']],
            'Hello! Nothing was created.',
            [],
        );

        $this->assertTrue($result['expectation_results'][0]['met']);
    }

    #[Test]
    public function action_not_taken_is_not_met_when_the_named_action_was_actually_attempted(): void
    {
        $result = $this->judge(
            [['kind' => 'action_not_taken', 'action' => 'contacts.create']],
            'Done.',
            [['tool' => 'contacts.create', 'arguments' => ['name' => 'Alice']]],
        );

        $this->assertFalse($result['expectation_results'][0]['met']);
    }

    /**
     * The unreachable-action rule, read the way data-model.md §3 spells it
     * out: an action_not_taken expectation naming an action that was never
     * a reachable tool in this run's environment at all is trivially met —
     * it certainly wasn't taken. Not skipped, not omitted from the results.
     */
    #[Test]
    public function action_not_taken_is_met_when_the_named_action_was_never_a_reachable_tool_at_all(): void
    {
        $result = $this->judge(
            [['kind' => 'action_not_taken', 'action' => 'nonexistent.operation']],
            'Hello!',
            [],
        );

        $this->assertTrue($result['expectation_results'][0]['met']);
    }

    // ---------------------------------------------------------------
    // human_judgment — never programmatically evaluated
    // ---------------------------------------------------------------

    #[Test]
    public function human_judgment_is_never_evaluated_and_always_contributes_needs_human_review_even_when_every_other_expectation_passed(): void
    {
        $result = $this->judge(
            [
                ['kind' => 'text_match', 'expected_text' => '4'],
                ['kind' => 'human_judgment', 'note' => 'Check the tone is friendly.'],
            ],
            '4',
        );

        $this->assertTrue($result['expectation_results'][0]['met'], 'The checkable expectation alongside a human_judgment one must still be judged and shown');
        $this->assertSame('human_judgment', $result['expectation_results'][1]['kind']);
        $this->assertSame(EvalCaseOutcome::NeedsHumanReview, $result['outcome']);
    }

    // ---------------------------------------------------------------
    // Aggregate outcome derivation
    // ---------------------------------------------------------------

    #[Test]
    public function outcome_is_pass_when_every_checkable_expectation_is_met_and_there_is_no_human_judgment_expectation(): void
    {
        $result = $this->judge(
            [
                ['kind' => 'text_match', 'expected_text' => 'The answer is 4'],
                ['kind' => 'information_present', 'expected_info' => 'answer'],
            ],
            'The answer is 4',
        );

        $this->assertSame(EvalCaseOutcome::Pass, $result['outcome']);
    }

    #[Test]
    public function outcome_is_fail_when_any_checkable_expectation_is_not_met_and_there_is_no_human_judgment_expectation(): void
    {
        $result = $this->judge(
            [
                ['kind' => 'text_match', 'expected_text' => '4'],
                ['kind' => 'action_taken', 'action' => 'contacts.create'],
            ],
            '4',
            [],
        );

        $this->assertSame(EvalCaseOutcome::Fail, $result['outcome']);
    }

    #[Test]
    public function outcome_is_needs_human_review_when_any_expectation_is_human_judgment_regardless_of_checkable_results(): void
    {
        $result = $this->judge(
            [
                ['kind' => 'action_taken', 'action' => 'contacts.create'],
                ['kind' => 'human_judgment'],
            ],
            'Done.',
            [], // the checkable expectation fails too — human_judgment must still win
        );

        $this->assertSame(EvalCaseOutcome::NeedsHumanReview, $result['outcome']);
    }

    #[Test]
    public function outcome_is_pass_for_a_single_case_with_only_one_checkable_expectation_that_is_met(): void
    {
        $result = $this->judge(
            [['kind' => 'information_present', 'expected_info' => 'sunny']],
            "It's sunny outside today.",
        );

        $this->assertSame(EvalCaseOutcome::Pass, $result['outcome']);
    }

    // ---------------------------------------------------------------
    // rubric_judgment passthrough — never evaluated here (that is
    // RubricJudge's own job); this class must simply leave it alone and
    // not misclassify it as an unmet checkable expectation
    // ---------------------------------------------------------------

    #[Test]
    public function rubric_judgment_is_left_with_a_null_met_value_and_does_not_flip_the_outcome_to_fail_on_its_own(): void
    {
        $result = $this->judge(
            [
                ['kind' => 'text_match', 'expected_text' => '4'],
                ['kind' => ExpectationKind::RubricJudgment->value, 'criteria' => 'Must be polite.'],
            ],
            '4',
        );

        $this->assertTrue($result['expectation_results'][0]['met']);
        $this->assertNull($result['expectation_results'][1]['met']);
        $this->assertSame(ExpectationKind::RubricJudgment->value, $result['expectation_results'][1]['kind']);
        $this->assertSame(EvalCaseOutcome::Pass, $result['outcome']);
    }
}
