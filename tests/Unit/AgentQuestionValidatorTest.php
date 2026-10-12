<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Services\AgentQuestionValidator;
use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\QuestionRefusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AgentQuestionValidator turns the raw `ask_user` arguments an agent sent
 * into a normalized AskUserRequest, or refuses them with an
 * `invalid_question` QuestionRefusal whose reason names the exact problem.
 * Nothing is written and nothing reaches the user for a refused ask.
 */
class AgentQuestionValidatorTest extends TestCase
{
    private function validate(array $arguments): AskUserRequest|QuestionRefusal
    {
        return (new AgentQuestionValidator())->validate($arguments);
    }

    private function assertRefused(array $arguments, string $reason): void
    {
        $result = $this->validate($arguments);

        $this->assertInstanceOf(QuestionRefusal::class, $result, 'expected a refusal for '.json_encode($arguments));
        $this->assertSame('invalid_question', $result->code);
        $this->assertSame($reason, $result->reason);
    }

    #[Test]
    public function limits_are_documented_constants(): void
    {
        $this->assertSame(10, AgentQuestionValidator::MAX_CHOICES);
        $this->assertSame(4000, AgentQuestionValidator::MAX_QUESTION_CHARS);
        $this->assertSame(500, AgentQuestionValidator::MAX_CHOICE_CHARS);
    }

    public static function emptyQuestions(): array
    {
        return [
            'missing' => [[]],
            'empty string' => [['question' => '']],
            'whitespace only' => [['question' => "  \n\t "]],
            'not a string' => [['question' => ['Which?']]],
        ];
    }

    #[Test]
    #[DataProvider('emptyQuestions')]
    public function empty_question_text_is_refused(array $arguments): void
    {
        $this->assertRefused($arguments, 'question text is empty');
    }

    #[Test]
    public function question_over_4000_characters_is_refused(): void
    {
        $this->assertRefused(['question' => str_repeat('é', 4001)], 'question text exceeds 4000 characters');
    }

    #[Test]
    public function question_of_exactly_4000_characters_is_accepted(): void
    {
        $result = $this->validate(['question' => str_repeat('é', 4000)]);

        $this->assertInstanceOf(AskUserRequest::class, $result);
    }

    public static function nonListChoices(): array
    {
        return [
            'a string' => [['question' => 'Which?', 'choices' => 'Backend']],
            'an associative array' => [['question' => 'Which?', 'choices' => ['a' => 'Backend', 'b' => 'Frontend']]],
            'a list with a non-string' => [['question' => 'Which?', 'choices' => ['Backend', 2]]],
            'a list with a nested array' => [['question' => 'Which?', 'choices' => ['Backend', ['Frontend']]]],
        ];
    }

    #[Test]
    #[DataProvider('nonListChoices')]
    public function choices_that_are_not_a_list_of_strings_are_refused(array $arguments): void
    {
        $this->assertRefused($arguments, 'choices must be a list of strings');
    }

    #[Test]
    public function more_than_10_choices_are_refused(): void
    {
        $choices = array_map(fn ($i) => "Option {$i}", range(1, 11));

        $this->assertRefused(['question' => 'Which?', 'choices' => $choices], 'more than 10 choices');
    }

    #[Test]
    public function exactly_10_choices_are_accepted(): void
    {
        $choices = array_map(fn ($i) => "Option {$i}", range(1, 10));

        $result = $this->validate(['question' => 'Which?', 'choices' => $choices]);

        $this->assertInstanceOf(AskUserRequest::class, $result);
        $this->assertSame($choices, $result->choices);
    }

    #[Test]
    public function an_empty_choice_is_refused_naming_its_number(): void
    {
        $this->assertRefused(['question' => 'Which?', 'choices' => ['Backend', '   ', 'Docs']], 'choice 2 is empty');
    }

    #[Test]
    public function a_choice_over_500_characters_is_refused_naming_its_number(): void
    {
        $this->assertRefused(
            ['question' => 'Which?', 'choices' => ['Backend', 'Frontend', str_repeat('x', 501)]],
            'choice 3 exceeds 500 characters',
        );
    }

    #[Test]
    public function a_choice_of_exactly_500_characters_is_accepted(): void
    {
        $result = $this->validate(['question' => 'Which?', 'choices' => [str_repeat('x', 500)]]);

        $this->assertInstanceOf(AskUserRequest::class, $result);
    }

    #[Test]
    public function duplicate_choices_after_trim_and_case_folding_are_refused_naming_both_numbers(): void
    {
        $this->assertRefused(
            ['question' => 'Proceed?', 'choices' => ['Yes', 'No', ' yes ']],
            'choices 1 and 3 are duplicates',
        );
    }

    #[Test]
    public function duplicate_detection_folds_multibyte_case(): void
    {
        $this->assertRefused(
            ['question' => 'Which city?', 'choices' => ['Zürich', 'Bern', 'ZÜRICH']],
            'choices 1 and 3 are duplicates',
        );
    }

    #[Test]
    public function allow_multiple_without_choices_is_refused(): void
    {
        $this->assertRefused(['question' => 'What else?', 'allow_multiple' => true], 'allow_multiple requires at least one choice');
        $this->assertRefused(['question' => 'What else?', 'choices' => [], 'allow_multiple' => true], 'allow_multiple requires at least one choice');
    }

    #[Test]
    public function a_valid_choice_question_is_normalized(): void
    {
        $result = $this->validate([
            'question' => "  Which part should I change first?\n",
            'choices' => [' Backend', 'Frontend ', '  Docs  '],
            'allow_multiple' => false,
        ]);

        $this->assertInstanceOf(AskUserRequest::class, $result);
        $this->assertSame('Which part should I change first?', $result->question);
        $this->assertSame(['Backend', 'Frontend', 'Docs'], $result->choices);
        $this->assertFalse($result->allowMultiple);
    }

    #[Test]
    public function a_multi_select_question_keeps_allow_multiple(): void
    {
        $result = $this->validate([
            'question' => 'Which parts?',
            'choices' => ['Backend', 'Frontend'],
            'allow_multiple' => true,
        ]);

        $this->assertInstanceOf(AskUserRequest::class, $result);
        $this->assertTrue($result->allowMultiple);
    }

    #[Test]
    public function a_free_text_question_has_no_choices_and_single_select(): void
    {
        $result = $this->validate(['question' => 'What should the release be called?']);

        $this->assertInstanceOf(AskUserRequest::class, $result);
        $this->assertSame('What should the release be called?', $result->question);
        $this->assertSame([], $result->choices);
        $this->assertFalse($result->allowMultiple);
    }
}
