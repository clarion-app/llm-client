<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Services\AgentQuestionValidator;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\QuestionRefusal;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What happens to a question nobody answers is decided by the agent when
 * it asks, through five optional `ask_user` arguments: `if_unanswered`,
 * `assumed_choices`, `assumed_text`, `consequential` and `wait_minutes`.
 *
 * The validator resolves the effective policy (stop unless an assumed
 * answer is given) and refuses every malformed, contradictory or unsafe
 * combination with an exact reason, checked in a fixed order after the
 * question and choices themselves are checked. A refused ask writes and
 * shows nothing.
 *
 * An argument that is absent and the same argument sent as JSON null mean
 * the same thing: not given. An empty `assumed_choices` list is given.
 *
 * The assumed answer is normalized to the shape a user's answer has: the
 * reply text a user would have typed for that meaning, and the choices it
 * selects. An assumed selection is checked strictly; an assumed free text
 * is read exactly as the same typed reply would be.
 *
 * A requested waiting period is never refused for being out of range — it
 * is brought within the installation's bounds when the question opens —
 * but it is kept within ±1 000 000 000 so no number overflows its column.
 */
class AgentQuestionValidatorPolicyTest extends TestCase
{
    private const CHOICES = ['PHPUnit', 'Pest', 'Codeception'];

    private function validate(array $arguments): AskUserRequest|QuestionRefusal
    {
        return (new AgentQuestionValidator())->validate($arguments);
    }

    /** A three-choice single-selection question with the given extra arguments. */
    private function ask(array $extra = []): array
    {
        return $extra + [
            'question' => 'Which test framework should I use?',
            'choices' => self::CHOICES,
        ];
    }

    private function accepted(array $arguments): AskUserRequest
    {
        $result = $this->validate($arguments);

        $this->assertInstanceOf(
            AskUserRequest::class,
            $result,
            'expected acceptance for '.json_encode($arguments)
                .($result instanceof QuestionRefusal ? ', refused: '.$result->reason : ''),
        );

        return $result;
    }

    private function assertRefused(array $arguments, string $reason): void
    {
        $result = $this->validate($arguments);

        $this->assertInstanceOf(QuestionRefusal::class, $result, 'expected a refusal for '.json_encode($arguments));
        $this->assertSame(QuestionRefusal::INVALID_QUESTION, $result->code);
        $this->assertSame($reason, $result->reason, 'for '.json_encode($arguments));
    }

    private function selection(int ...$numbers): array
    {
        return array_map(fn (int $n) => ['number' => $n, 'text' => self::CHOICES[$n - 1]], $numbers);
    }

    // ---------------------------------------------------------------
    // Refusals: every row of the table, with its exact reason
    // ---------------------------------------------------------------

    private const R1 = 'if_unanswered must be "proceed_on_assumption" or "stop"';
    private const R2 = 'consequential must be true or false';
    private const R3 = 'wait_minutes must be a whole number of minutes';
    private const R4 = 'give the assumed answer as assumed_choices or assumed_text, not both';
    private const R5 = 'a consequential question cannot proceed on an assumed answer; it always stops until the user answers';
    private const R6 = 'if_unanswered is "stop" but an assumed answer was given; a question that stops when unanswered cannot also assume an answer';
    private const R7 = 'if_unanswered is "proceed_on_assumption" but no assumed answer was given';
    private const R8 = 'assumed_choices must be a list of choice numbers';
    private const R9 = 'assumed_choices requires choices';
    private const R10 = 'assumed_choices is empty';
    private const R12_FORMAT = 'assumed choice %d is repeated';
    private const R13 = 'a single-selection question can assume only one choice';
    private const R14 = 'assumed_text must be non-empty text';
    private const R15 = 'assumed_text exceeds 4000 characters';

    private static function r11(int $n, int $count): string
    {
        return "assumed choice {$n} does not exist (the question has {$count} choices)";
    }

    /** @return array<string, array{0: array, 1: string}> extra arguments on a three-choice single-select question => reason */
    public static function refusals(): array
    {
        $multi = ['allow_multiple' => true];

        return [
            // 1
            'row 1: unknown policy' => [['if_unanswered' => 'ask_again'], self::R1],
            'row 1: empty policy' => [['if_unanswered' => ''], self::R1],
            'row 1: policy not a string' => [['if_unanswered' => 1], self::R1],
            'row 1: policy in another case' => [['if_unanswered' => 'STOP'], self::R1],
            // 2
            'row 2: consequential "yes"' => [['consequential' => 'yes'], self::R2],
            'row 2: consequential 1' => [['consequential' => 1], self::R2],
            'row 2: consequential a list' => [['consequential' => []], self::R2],
            // 3
            'row 3: wait_minutes a word' => [['wait_minutes' => 'ninety'], self::R3],
            'row 3: wait_minutes a fraction' => [['wait_minutes' => 90.5], self::R3],
            'row 3: wait_minutes a fractional string' => [['wait_minutes' => '90.5'], self::R3],
            'row 3: wait_minutes a boolean' => [['wait_minutes' => true], self::R3],
            'row 3: wait_minutes a list' => [['wait_minutes' => [90]], self::R3],
            // 4
            'row 4: both assumptions' => [['assumed_choices' => [1], 'assumed_text' => 'PHPUnit'], self::R4],
            // 5
            'row 5: consequential and proceed' => [['consequential' => true, 'if_unanswered' => 'proceed_on_assumption'], self::R5],
            'row 5: consequential and an assumed choice' => [['consequential' => true, 'assumed_choices' => [1]], self::R5],
            'row 5: consequential and an assumed text' => [['consequential' => 'true', 'assumed_text' => 'PHPUnit'], self::R5],
            'row 5: consequential, stop and an assumption' => [['consequential' => true, 'if_unanswered' => 'stop', 'assumed_choices' => [1]], self::R5],
            'row 5: consequential, proceed and an assumption' => [['consequential' => true, 'if_unanswered' => 'proceed_on_assumption', 'assumed_choices' => [1]], self::R5],
            // 6
            'row 6: stop and an assumed choice' => [['if_unanswered' => 'stop', 'assumed_choices' => [1]], self::R6],
            'row 6: stop and an assumed text' => [['if_unanswered' => 'stop', 'assumed_text' => 'PHPUnit'], self::R6],
            'row 6: stop and an empty assumed choices list' => [['if_unanswered' => 'stop', 'assumed_choices' => []], self::R6],
            // 7
            'row 7: proceed without an assumption' => [['if_unanswered' => 'proceed_on_assumption'], self::R7],
            // 8
            'row 8: assumed_choices a number' => [['assumed_choices' => 1], self::R8],
            'row 8: assumed_choices a string' => [['assumed_choices' => '1'], self::R8],
            'row 8: assumed_choices of words' => [['assumed_choices' => ['PHPUnit']], self::R8],
            'row 8: assumed_choices of fractions' => [['assumed_choices' => [1.5]], self::R8],
            'row 8: assumed_choices of lists' => [['assumed_choices' => [[1]]], self::R8],
            'row 8: assumed_choices keyed' => [['assumed_choices' => ['a' => 1]], self::R8],
            // 10
            'row 10: empty assumed choices' => [['assumed_choices' => []], self::R10],
            'row 10: empty assumed choices with proceed' => [['if_unanswered' => 'proceed_on_assumption', 'assumed_choices' => []], self::R10],
            // 11
            'row 11: above the choices' => [['assumed_choices' => [4]], self::r11(4, 3)],
            'row 11: zero' => [['assumed_choices' => [0]], self::r11(0, 3)],
            'row 11: negative' => [['assumed_choices' => [-1]], self::r11(-1, 3)],
            'row 11: above the choices on multi-select' => [$multi + ['assumed_choices' => [1, 9]], self::r11(9, 3)],
            // 12
            'row 12: repeated on multi-select' => [$multi + ['assumed_choices' => [2, 2]], sprintf(self::R12_FORMAT, 2)],
            // 13
            'row 13: two on single-select' => [['assumed_choices' => [1, 2]], self::R13],
            // 14
            'row 14: blank text' => [['assumed_text' => "  \n\t "], self::R14],
            'row 14: empty text' => [['assumed_text' => ''], self::R14],
            'row 14: text not a string' => [['assumed_text' => 5], self::R14],
            'row 14: text a list' => [['assumed_text' => ['PHPUnit']], self::R14],
            // 15
            'row 15: text over 4000 characters' => [['assumed_text' => str_repeat('a', 4001)], self::R15],
            'row 15: multibyte text over 4000 characters' => [['assumed_text' => str_repeat('é', 4001)], self::R15],
        ];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function each_malformed_policy_is_refused_with_its_exact_reason(array $extra, string $reason): void
    {
        $this->assertRefused($this->ask($extra), $reason);
    }

    #[Test]
    public function assumed_choices_on_a_question_without_choices_is_refused(): void
    {
        $this->assertRefused(['question' => 'What should the release be called?', 'assumed_choices' => [1]], self::R9);
    }

    // ---------------------------------------------------------------
    // Order: the question's own checks first, then the table in order
    // ---------------------------------------------------------------

    #[Test]
    public function the_question_and_choice_checks_run_before_the_policy_checks(): void
    {
        $this->assertRefused(
            ['question' => '  ', 'choices' => self::CHOICES, 'if_unanswered' => 'ask_again'],
            'question text is empty',
        );
        $this->assertRefused(
            [
                'question' => 'Which one?',
                'choices' => array_map(fn (int $i) => "Option {$i}", range(1, 11)),
                'consequential' => true,
                'assumed_choices' => [1],
            ],
            'more than 10 choices',
        );
        $this->assertRefused(
            ['question' => 'Which ones?', 'allow_multiple' => true, 'if_unanswered' => 'proceed_on_assumption'],
            'allow_multiple requires at least one choice',
        );
        $this->assertRefused(
            ['question' => 'Which one?', 'choices' => ['Same', 'same'], 'consequential' => true, 'assumed_choices' => [1]],
            'choices 1 and 2 are duplicates',
        );
    }

    /** @return array<string, array{0: array, 1: string}> an ask failing two rows => the earlier row's reason */
    public static function twoFailures(): array
    {
        return [
            'rows 1 and 2' => [['if_unanswered' => 'ask_again', 'consequential' => 'yes'], self::R1],
            'rows 2 and 3' => [['consequential' => 'yes', 'wait_minutes' => 'ninety'], self::R2],
            'rows 3 and 4' => [['wait_minutes' => 'ninety', 'assumed_choices' => [1], 'assumed_text' => 'x'], self::R3],
            'rows 4 and 5' => [['consequential' => true, 'assumed_choices' => [1], 'assumed_text' => 'x'], self::R4],
            'rows 5 and 6' => [['consequential' => true, 'if_unanswered' => 'stop', 'assumed_text' => 'x'], self::R5],
            'rows 5 and 8' => [['consequential' => true, 'assumed_choices' => 'abc'], self::R5],
            'rows 6 and 11' => [['if_unanswered' => 'stop', 'assumed_choices' => [9]], self::R6],
            'rows 7 and 3' => [['if_unanswered' => 'proceed_on_assumption', 'wait_minutes' => 'soon'], self::R3],
            'rows 11 and 13' => [['assumed_choices' => [1, 5]], self::r11(5, 3)],
            'rows 12 and 13' => [['assumed_choices' => [2, 2]], sprintf(self::R12_FORMAT, 2)],
        ];
    }

    #[Test]
    #[DataProvider('twoFailures')]
    public function the_first_failing_row_is_the_one_reported(array $extra, string $reason): void
    {
        $this->assertRefused($this->ask($extra), $reason);
    }

    #[Test]
    public function rows_8_and_9_report_the_malformed_list_first(): void
    {
        $this->assertRefused(['question' => 'What should it be called?', 'assumed_choices' => 'abc'], self::R8);
        $this->assertRefused(['question' => 'What should it be called?', 'assumed_choices' => []], self::R9);
    }

    // ---------------------------------------------------------------
    // Resolution of an accepted ask
    // ---------------------------------------------------------------

    #[Test]
    public function an_ask_naming_nothing_stops(): void
    {
        $request = $this->accepted($this->ask());

        $this->assertSame(UnansweredPolicy::Stop, $request->policy);
        $this->assertNull($request->assumedAnswer);
        $this->assertFalse($request->consequential);
        $this->assertNull($request->requestedWaitMinutes);
        $this->assertSame('Which test framework should I use?', $request->question);
        $this->assertSame(self::CHOICES, $request->choices);
        $this->assertFalse($request->allowMultiple);
    }

    #[Test]
    public function a_question_without_choices_naming_nothing_stops(): void
    {
        $request = $this->accepted(['question' => 'What should the release be called?']);

        $this->assertSame(UnansweredPolicy::Stop, $request->policy);
        $this->assertNull($request->assumedAnswer);
    }

    #[Test]
    public function an_explicit_stop_stops(): void
    {
        $request = $this->accepted($this->ask(['if_unanswered' => 'stop']));

        $this->assertSame(UnansweredPolicy::Stop, $request->policy);
        $this->assertNull($request->assumedAnswer);
        $this->assertFalse($request->consequential);
    }

    #[Test]
    public function a_consequential_question_alone_stops(): void
    {
        $request = $this->accepted($this->ask(['consequential' => true]));

        $this->assertSame(UnansweredPolicy::Stop, $request->policy);
        $this->assertNull($request->assumedAnswer);
        $this->assertTrue($request->consequential);

        $explicit = $this->accepted($this->ask(['consequential' => true, 'if_unanswered' => 'stop']));
        $this->assertSame(UnansweredPolicy::Stop, $explicit->policy);
        $this->assertTrue($explicit->consequential);
    }

    #[Test]
    public function consequential_accepts_the_strings_true_and_false(): void
    {
        $this->assertTrue($this->accepted($this->ask(['consequential' => 'true']))->consequential);
        $this->assertFalse($this->accepted($this->ask(['consequential' => 'false']))->consequential);
        $this->assertFalse($this->accepted($this->ask(['consequential' => false]))->consequential);
    }

    #[Test]
    public function a_non_consequential_question_may_assume(): void
    {
        $request = $this->accepted($this->ask(['consequential' => false, 'assumed_choices' => [1]]));
        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $request->policy);
        $this->assertFalse($request->consequential);

        $request = $this->accepted($this->ask(['consequential' => 'false', 'if_unanswered' => 'proceed_on_assumption', 'assumed_text' => 'PHPUnit']));
        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $request->policy);
        $this->assertFalse($request->consequential);
    }

    #[Test]
    public function an_assumption_without_a_policy_proceeds(): void
    {
        $request = $this->accepted($this->ask(['assumed_choices' => [1]]));

        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $request->policy);
        $this->assertEquals(new ParsedAnswer('1', true, $this->selection(1)), $request->assumedAnswer);
        $this->assertFalse($request->consequential);
    }

    #[Test]
    public function an_explicit_proceed_with_an_assumption_proceeds(): void
    {
        $request = $this->accepted($this->ask(['if_unanswered' => 'proceed_on_assumption', 'assumed_choices' => [2]]));

        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $request->policy);
        $this->assertEquals(new ParsedAnswer('2', true, $this->selection(2)), $request->assumedAnswer);
    }

    // ---------------------------------------------------------------
    // Normalization of the assumed answer
    // ---------------------------------------------------------------

    #[Test]
    public function assumed_choices_on_multi_select_are_sorted_into_the_typed_reply_shape(): void
    {
        $request = $this->accepted($this->ask(['allow_multiple' => true, 'assumed_choices' => [3, 1]]));

        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $request->policy);
        $this->assertSame('1, 3', $request->assumedAnswer?->rawReply);
        $this->assertTrue($request->assumedAnswer?->isSelection);
        $this->assertSame($this->selection(1, 3), $request->assumedAnswer?->selected);
    }

    #[Test]
    public function every_choice_may_be_assumed_on_multi_select(): void
    {
        $request = $this->accepted($this->ask(['allow_multiple' => true, 'assumed_choices' => [2, 3, 1]]));

        $this->assertEquals(new ParsedAnswer('1, 2, 3', true, $this->selection(1, 2, 3)), $request->assumedAnswer);
    }

    #[Test]
    public function an_assumed_text_naming_a_choice_number_is_that_selection(): void
    {
        $request = $this->accepted($this->ask(['assumed_text' => '2']));

        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $request->policy);
        $this->assertEquals(new ParsedAnswer('2', true, $this->selection(2)), $request->assumedAnswer);
        $this->assertEquals(
            (new AnswerParser())->parseAgainst('2', self::CHOICES, false),
            $request->assumedAnswer,
            'read exactly as the same typed reply',
        );
    }

    #[Test]
    public function an_assumed_text_naming_choice_numbers_on_multi_select_is_that_selection(): void
    {
        $request = $this->accepted($this->ask(['allow_multiple' => true, 'assumed_text' => '3, 1']));

        $this->assertEquals(new ParsedAnswer('3, 1', true, $this->selection(1, 3)), $request->assumedAnswer);
    }

    #[Test]
    public function an_assumed_text_naming_a_missing_choice_is_free_text(): void
    {
        $request = $this->accepted($this->ask(['assumed_text' => '7']));

        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $request->policy);
        $this->assertEquals(new ParsedAnswer('7', false, []), $request->assumedAnswer);
    }

    #[Test]
    public function an_assumed_free_text_is_kept_verbatim(): void
    {
        $request = $this->accepted([
            'question' => 'What should the release be called?',
            'assumed_text' => 'Use the date, like 2026.10',
        ]);

        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $request->policy);
        $this->assertEquals(new ParsedAnswer('Use the date, like 2026.10', false, []), $request->assumedAnswer);
    }

    #[Test]
    public function an_assumed_text_of_exactly_4000_characters_is_accepted(): void
    {
        $ascii = str_repeat('a', 4000);
        $this->assertSame($ascii, $this->accepted($this->ask(['assumed_text' => $ascii]))->assumedAnswer?->rawReply);

        $multibyte = str_repeat('é', 4000);
        $this->assertSame(
            $multibyte,
            $this->accepted($this->ask(['assumed_text' => $multibyte]))->assumedAnswer?->rawReply,
            'length is counted in characters, not bytes',
        );
    }

    // ---------------------------------------------------------------
    // The requested waiting period
    // ---------------------------------------------------------------

    /** @return array<string, array{0: mixed, 1: int}> given => requestedWaitMinutes */
    public static function waits(): array
    {
        return [
            'an integer' => [90, 90],
            'an integral float' => [90.0, 90],
            'a numeric string' => ['90', 90],
            'zero (clamped later, never refused)' => [0, 0],
            'negative (clamped later, never refused)' => [-5, -5],
            'very large (clamped later, never refused)' => [99_999_999, 99_999_999],
            'at the storage limit' => [1_000_000_000, 1_000_000_000],
            'beyond the storage limit' => [5_000_000_000, 1_000_000_000],
            'beyond the negative storage limit' => [-5_000_000_000, -1_000_000_000],
            'a huge integral float' => [1.0e10, 1_000_000_000],
        ];
    }

    #[Test]
    #[DataProvider('waits')]
    public function a_whole_number_of_minutes_is_accepted_as_given_within_storage_limits(mixed $given, int $expected): void
    {
        $request = $this->accepted($this->ask(['wait_minutes' => $given]));

        $this->assertSame($expected, $request->requestedWaitMinutes);
        $this->assertSame(UnansweredPolicy::Stop, $request->policy, 'the period alone does not change the policy');
    }

    // ---------------------------------------------------------------
    // Absent and null are the same
    // ---------------------------------------------------------------

    #[Test]
    public function every_new_argument_sent_as_null_is_not_given(): void
    {
        $absent = $this->accepted($this->ask());
        $nulls = $this->accepted($this->ask([
            'if_unanswered' => null,
            'assumed_choices' => null,
            'assumed_text' => null,
            'consequential' => null,
            'wait_minutes' => null,
        ]));

        $this->assertEquals($absent, $nulls);
        $this->assertSame(UnansweredPolicy::Stop, $nulls->policy);
        $this->assertNull($nulls->assumedAnswer);
        $this->assertFalse($nulls->consequential);
        $this->assertNull($nulls->requestedWaitMinutes);
    }

    #[Test]
    public function a_null_alongside_a_given_assumption_is_not_a_second_assumption(): void
    {
        $text = $this->accepted($this->ask(['assumed_choices' => null, 'assumed_text' => 'PHPUnit']));
        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $text->policy);
        $this->assertEquals(new ParsedAnswer('PHPUnit', false, []), $text->assumedAnswer);

        $choice = $this->accepted($this->ask(['assumed_choices' => [1], 'assumed_text' => null]));
        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $choice->policy);
        $this->assertEquals(new ParsedAnswer('1', true, $this->selection(1)), $choice->assumedAnswer);
    }

    #[Test]
    public function null_arguments_resolve_exactly_like_absent_ones(): void
    {
        $this->assertEquals(
            $this->accepted($this->ask(['assumed_choices' => [1]])),
            $this->accepted($this->ask(['assumed_choices' => [1], 'if_unanswered' => null, 'consequential' => null, 'wait_minutes' => null])),
        );
        $this->assertRefused($this->ask(['if_unanswered' => 'proceed_on_assumption', 'assumed_choices' => null]), self::R7);
        $this->assertRefused($this->ask(['if_unanswered' => 'proceed_on_assumption', 'assumed_text' => null]), self::R7);
        $this->assertSame(
            UnansweredPolicy::Stop,
            $this->accepted($this->ask(['if_unanswered' => 'stop', 'assumed_choices' => null, 'assumed_text' => null]))->policy,
        );
    }
}
