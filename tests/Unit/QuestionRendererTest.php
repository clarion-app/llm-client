<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Services\QuestionRenderer;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * QuestionRenderer is the only renderer of a question and of its follow-up
 * notices, and it is channel-agnostic: the strings asserted here are exactly
 * what the web screen and every messaging channel show. They are plain text
 * — numbered choice lines, blank-line separated blocks, no markup.
 */
class QuestionRendererTest extends TestCase
{
    private function question(array $choices = ['Backend', 'Frontend', 'Docs'], bool $allowMultiple = false, array $extra = []): AgentQuestion
    {
        return (new AgentQuestion())->forceFill([
            'question' => 'Which part should I change first?',
            'choices' => $choices,
            'allow_multiple' => $allowMultiple,
        ] + $extra);
    }

    private function renderer(): QuestionRenderer
    {
        return new QuestionRenderer();
    }

    #[Test]
    public function single_select_question_renders_numbered_choices_and_the_number_instruction(): void
    {
        $expected = "Which part should I change first?\n"
            ."\n"
            ."1. Backend\n"
            ."2. Frontend\n"
            ."3. Docs\n"
            ."\n"
            .'Reply with the number of your choice, or answer in your own words.';

        $this->assertSame($expected, $this->renderer()->render($this->question(), null));
    }

    #[Test]
    public function multi_select_question_uses_the_several_numbers_instruction(): void
    {
        $expected = "Which part should I change first?\n"
            ."\n"
            ."1. Backend\n"
            ."2. Frontend\n"
            ."3. Docs\n"
            ."\n"
            .'Reply with one or more numbers separated by commas or spaces, or answer in your own words.';

        $this->assertSame($expected, $this->renderer()->render($this->question(allowMultiple: true), null));
    }

    #[Test]
    public function free_text_question_has_no_choice_lines(): void
    {
        $expected = "Which part should I change first?\n"
            ."\n"
            .'Reply with your answer.';

        $this->assertSame($expected, $this->renderer()->render($this->question(choices: []), null));
    }

    #[Test]
    public function preamble_precedes_the_question_as_its_own_block(): void
    {
        $expected = "I found three areas that need work.\n"
            ."\n"
            ."Which part should I change first?\n"
            ."\n"
            ."1. Backend\n"
            ."2. Frontend\n"
            ."3. Docs\n"
            ."\n"
            .'Reply with the number of your choice, or answer in your own words.';

        $this->assertSame(
            $expected,
            $this->renderer()->render($this->question(), 'I found three areas that need work.'),
        );
    }

    #[Test]
    public function an_empty_preamble_is_the_same_as_none(): void
    {
        $this->assertSame(
            $this->renderer()->render($this->question(), null),
            $this->renderer()->render($this->question(), "  \n"),
        );
    }

    #[Test]
    public function choice_text_is_rendered_verbatim_without_markup_interpretation(): void
    {
        $rendered = $this->renderer()->render($this->question(choices: ['<b>bold</b>', '*stars*', 'a & b']), null);

        $this->assertStringContainsString("1. <b>bold</b>\n2. *stars*\n3. a & b\n", $rendered);
    }

    #[Test]
    public function expired_notice(): void
    {
        $question = $this->question(extra: ['state' => QuestionState::Expired]);

        $this->assertSame(
            'The question "Which part should I change first?" expired without an answer. '
            .'The agent will continue without it; replies sent now are treated as new messages.',
            $this->renderer()->renderNotice($question),
        );
    }

    #[Test]
    public function withdrawn_notice(): void
    {
        $question = $this->question(extra: ['state' => QuestionState::Withdrawn]);

        $this->assertSame(
            'The question "Which part should I change first?" was withdrawn because the task was stopped. '
            .'It no longer needs a reply.',
            $this->renderer()->renderNotice($question),
        );
    }

    #[Test]
    public function undeliverable_notice_names_the_failure_reason(): void
    {
        $question = $this->question(extra: [
            'state' => QuestionState::Undeliverable,
            'delivery_failure_reason' => 'telegram account not linked',
        ]);

        $this->assertSame(
            'The question "Which part should I change first?" could not be delivered (telegram account not linked). '
            .'The agent was told and will continue without an answer.',
            $this->renderer()->renderNotice($question),
        );
    }
}
