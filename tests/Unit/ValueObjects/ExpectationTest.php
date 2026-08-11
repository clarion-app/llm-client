<?php

namespace ClarionApp\LlmClient\Tests\Unit\ValueObjects;

use ClarionApp\LlmClient\ValueObjects\Expectation;
use ClarionApp\LlmClient\ValueObjects\ExpectationKind;
use Tests\TestCase;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for Expectation — the one place FR-004/FR-009/FR-010's shape
 * rules for a single expectation entry are enforced (data-model.md §4).
 * Both EvalCaseService (authoring) and EvalSuiteImporter (import) call
 * Expectation::validate() directly; nothing else re-implements these rules.
 */
class ExpectationTest extends TestCase
{
    // --- validate(): each of the five kinds with its own required field ---

    #[Test]
    public function validate_accepts_a_well_formed_text_match(): void
    {
        Expectation::validate([
            'kind' => 'text_match',
            'expected_text' => 'The confirmation number is 12345.',
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validate_accepts_a_well_formed_information_present(): void
    {
        Expectation::validate([
            'kind' => 'information_present',
            'expected_info' => 'the phone number 555-0100',
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validate_accepts_a_well_formed_action_taken(): void
    {
        Expectation::validate([
            'kind' => 'action_taken',
            'action' => 'contacts.create',
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validate_accepts_a_well_formed_action_not_taken(): void
    {
        Expectation::validate([
            'kind' => 'action_not_taken',
            'action' => 'billing.charge',
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validate_accepts_human_judgment_with_a_note(): void
    {
        Expectation::validate([
            'kind' => 'human_judgment',
            'note' => 'Judge whether the tone reads as natural.',
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validate_accepts_human_judgment_with_note_omitted(): void
    {
        Expectation::validate([
            'kind' => 'human_judgment',
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validate_accepts_human_judgment_with_an_empty_note(): void
    {
        Expectation::validate([
            'kind' => 'human_judgment',
            'note' => '',
        ]);

        $this->addToAssertionCount(1);
    }

    // --- unrecognized kind, never coerced ---

    #[Test]
    public function validate_rejects_an_unrecognized_kind(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expectation::validate([
            'kind' => 'vibes_check',
            'expected_text' => 'anything',
        ]);
    }

    // --- action_taken / action_not_taken: missing or empty action (FR-010) ---

    #[Test]
    public function validate_rejects_action_taken_with_missing_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expectation::validate([
            'kind' => 'action_taken',
        ]);
    }

    #[Test]
    public function validate_rejects_action_taken_with_empty_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expectation::validate([
            'kind' => 'action_taken',
            'action' => '   ',
        ]);
    }

    #[Test]
    public function validate_rejects_action_not_taken_with_missing_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expectation::validate([
            'kind' => 'action_not_taken',
        ]);
    }

    #[Test]
    public function validate_rejects_action_not_taken_with_empty_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expectation::validate([
            'kind' => 'action_not_taken',
            'action' => '',
        ]);
    }

    // --- other kinds: missing or empty required field ---

    #[Test]
    public function validate_rejects_text_match_with_missing_expected_text(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expectation::validate([
            'kind' => 'text_match',
        ]);
    }

    #[Test]
    public function validate_rejects_text_match_with_empty_after_trim_expected_text(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expectation::validate([
            'kind' => 'text_match',
            'expected_text' => '   ',
        ]);
    }

    #[Test]
    public function validate_rejects_information_present_with_missing_expected_info(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expectation::validate([
            'kind' => 'information_present',
        ]);
    }

    #[Test]
    public function validate_rejects_information_present_with_empty_expected_info(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expectation::validate([
            'kind' => 'information_present',
            'expected_info' => '',
        ]);
    }

    // --- length bound ---

    #[Test]
    public function validate_rejects_a_field_exceeding_max_text_length(): void
    {
        $tooLong = str_repeat('a', config('llm-client.eval_suites.max_text_length') + 1);

        $this->expectException(\InvalidArgumentException::class);

        Expectation::validate([
            'kind' => 'text_match',
            'expected_text' => $tooLong,
        ]);
    }

    #[Test]
    public function validate_accepts_a_field_exactly_at_max_text_length(): void
    {
        $atLimit = str_repeat('a', config('llm-client.eval_suites.max_text_length'));

        Expectation::validate([
            'kind' => 'text_match',
            'expected_text' => $atLimit,
        ]);

        $this->addToAssertionCount(1);
    }

    // --- action_taken / action_not_taken are recorded distinctly (US1 scenario 5) ---

    #[Test]
    public function action_taken_and_action_not_taken_are_distinct_kinds(): void
    {
        $taken = Expectation::fromArray([
            'kind' => 'action_taken',
            'action' => 'contacts.create',
        ]);

        $notTaken = Expectation::fromArray([
            'kind' => 'action_not_taken',
            'action' => 'contacts.create',
        ]);

        $this->assertSame(ExpectationKind::ActionTaken, $taken->kind);
        $this->assertSame(ExpectationKind::ActionNotTaken, $notTaken->kind);
        $this->assertNotEquals($taken->kind, $notTaken->kind);

        // A case recording only action_taken for X carries no
        // action_not_taken entry at all — there is no third state.
        $this->assertNotEquals($taken->toArray(), $notTaken->toArray());
    }

    // --- fromArray()/toArray() round-trip for all five kinds ---

    #[Test]
    public function text_match_round_trips_through_from_array_and_to_array(): void
    {
        $data = ['kind' => 'text_match', 'expected_text' => 'exact phrase'];

        $expectation = Expectation::fromArray($data);

        $this->assertSame($data, $expectation->toArray());
    }

    #[Test]
    public function information_present_round_trips_through_from_array_and_to_array(): void
    {
        $data = ['kind' => 'information_present', 'expected_info' => 'the account balance'];

        $expectation = Expectation::fromArray($data);

        $this->assertSame($data, $expectation->toArray());
    }

    #[Test]
    public function action_taken_round_trips_through_from_array_and_to_array(): void
    {
        $data = ['kind' => 'action_taken', 'action' => 'contacts.create'];

        $expectation = Expectation::fromArray($data);

        $this->assertSame($data, $expectation->toArray());
    }

    #[Test]
    public function action_not_taken_round_trips_through_from_array_and_to_array(): void
    {
        $data = ['kind' => 'action_not_taken', 'action' => 'billing.charge'];

        $expectation = Expectation::fromArray($data);

        $this->assertSame($data, $expectation->toArray());
    }

    #[Test]
    public function human_judgment_round_trips_through_from_array_and_to_array(): void
    {
        $data = ['kind' => 'human_judgment', 'note' => 'Judge the tone.'];

        $expectation = Expectation::fromArray($data);

        $this->assertSame($data, $expectation->toArray());
    }

    #[Test]
    public function human_judgment_with_no_note_round_trips_without_inventing_one(): void
    {
        $data = ['kind' => 'human_judgment'];

        $expectation = Expectation::fromArray($data);

        $this->assertSame($data, $expectation->toArray());
    }

    // --- fromArray() throws with a specific reason, before constructing anything ---

    #[Test]
    public function from_array_throws_invalid_argument_exception_on_an_invalid_shape(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expectation::fromArray(['kind' => 'action_taken', 'action' => '']);
    }

    // --- rubric_judgment: the sixth kind, required non-empty bounded criteria ---

    #[Test]
    public function validate_accepts_a_well_formed_rubric_judgment(): void
    {
        Expectation::validate([
            'kind' => 'rubric_judgment',
            'criteria' => "The response must acknowledge the customer's frustration before offering a solution.",
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validate_rejects_rubric_judgment_with_missing_criteria(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expectation::validate([
            'kind' => 'rubric_judgment',
        ]);
    }

    #[Test]
    public function validate_rejects_rubric_judgment_with_empty_after_trim_criteria(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expectation::validate([
            'kind' => 'rubric_judgment',
            'criteria' => '   ',
        ]);
    }

    #[Test]
    public function validate_rejects_rubric_judgment_criteria_exceeding_max_text_length(): void
    {
        $tooLong = str_repeat('a', config('llm-client.eval_suites.max_text_length') + 1);

        $this->expectException(\InvalidArgumentException::class);

        Expectation::validate([
            'kind' => 'rubric_judgment',
            'criteria' => $tooLong,
        ]);
    }

    #[Test]
    public function validate_accepts_rubric_judgment_criteria_exactly_at_max_text_length(): void
    {
        $atLimit = str_repeat('a', config('llm-client.eval_suites.max_text_length'));

        Expectation::validate([
            'kind' => 'rubric_judgment',
            'criteria' => $atLimit,
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rubric_judgment_round_trips_through_from_array_and_to_array(): void
    {
        $data = ['kind' => 'rubric_judgment', 'criteria' => 'Must acknowledge the customer\'s frustration.'];

        $expectation = Expectation::fromArray($data);

        $this->assertSame($data, $expectation->toArray());
    }
}
