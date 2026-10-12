<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Services\QuestionRenderer;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * QuestionRenderer stays the only producer of question text and choice
 * numbering. A channel that shows the choices as controls gets a body that
 * differs from the plain-text one only in its instruction line, so the
 * numbered list is byte-identical everywhere; option labels and the
 * resolved-outcome summary come from the same class.
 */
class QuestionRendererOptionsTest extends TestCase
{
    private const QUESTION = 'Which part should I change first?';

    private const OPTIONS_SINGLE = 'Choose an option below, or reply in your own words.';

    private const OPTIONS_MULTI = 'Select one or more options below, then tap Submit — or reply in your own words.';

    private function question(array $choices = ['Backend', 'Frontend', 'Docs'], bool $allowMultiple = false, array $extra = []): AgentQuestion
    {
        return (new AgentQuestion())->forceFill([
            'id' => '6f1c2a40-0000-4000-8000-000000000001',
            'question' => self::QUESTION,
            'choices' => $choices,
            'allow_multiple' => $allowMultiple,
        ] + $extra);
    }

    private function renderer(): QuestionRenderer
    {
        return new QuestionRenderer();
    }

    /** @return list<string> the `n. choice` lines of a rendered body */
    private function numberedLines(string $rendered): array
    {
        return array_values(array_filter(
            explode("\n", $rendered),
            fn (string $line) => preg_match('/^\d+\. /', $line) === 1,
        ));
    }

    // ---------------------------------------------------------------
    // renderWithOptions()
    // ---------------------------------------------------------------

    #[Test]
    public function single_select_body_lists_every_choice_and_points_at_the_options(): void
    {
        $expected = self::QUESTION."\n"
            ."\n"
            ."1. Backend\n"
            ."2. Frontend\n"
            ."3. Docs\n"
            ."\n"
            .self::OPTIONS_SINGLE;

        $this->assertSame($expected, $this->renderer()->renderWithOptions($this->question(), null));
    }

    #[Test]
    public function multi_select_body_points_at_the_options_and_the_submit_control(): void
    {
        $expected = self::QUESTION."\n"
            ."\n"
            ."1. Backend\n"
            ."2. Frontend\n"
            ."3. Docs\n"
            ."\n"
            .self::OPTIONS_MULTI;

        $this->assertSame($expected, $this->renderer()->renderWithOptions($this->question(allowMultiple: true), null));
    }

    #[Test]
    public function it_differs_from_render_only_in_the_instruction_line(): void
    {
        $renderer = $this->renderer();

        foreach ([false, true] as $allowMultiple) {
            foreach ([null, 'I found three areas that need work.'] as $preamble) {
                $question = $this->question(allowMultiple: $allowMultiple);
                $plain = $renderer->render($question, $preamble);
                $withOptions = $renderer->renderWithOptions($question, $preamble);

                $plainInstruction = $allowMultiple ? QuestionRenderer::MULTI_SELECT_INSTRUCTION : QuestionRenderer::SINGLE_SELECT_INSTRUCTION;
                $optionsInstruction = $allowMultiple ? self::OPTIONS_MULTI : self::OPTIONS_SINGLE;

                $this->assertStringEndsWith("\n\n".$optionsInstruction, $withOptions);
                $this->assertSame(
                    $plain,
                    substr($withOptions, 0, -strlen($optionsInstruction)).$plainInstruction,
                    'everything above the instruction line is identical to the plain-text rendering',
                );
            }
        }
    }

    #[Test]
    public function the_preamble_precedes_the_question_as_in_the_plain_rendering(): void
    {
        $rendered = $this->renderer()->renderWithOptions($this->question(), 'I found three areas that need work.');

        $this->assertStringStartsWith("I found three areas that need work.\n\n".self::QUESTION."\n\n1. Backend\n", $rendered);
    }

    #[Test]
    public function a_blank_preamble_is_the_same_as_none(): void
    {
        $this->assertSame(
            $this->renderer()->renderWithOptions($this->question(), null),
            $this->renderer()->renderWithOptions($this->question(), "  \n"),
        );
    }

    #[Test]
    public function a_question_without_choices_renders_exactly_as_in_plain_text(): void
    {
        $question = $this->question(choices: []);

        $this->assertSame(
            self::QUESTION."\n\n".QuestionRenderer::FREE_TEXT_INSTRUCTION,
            $this->renderer()->renderWithOptions($question, null),
        );
        $this->assertSame(
            $this->renderer()->render($question, 'Before I start:'),
            $this->renderer()->renderWithOptions($question, 'Before I start:'),
        );
    }

    #[Test]
    public function the_numbered_lines_are_byte_identical_to_the_plain_renderings(): void
    {
        $choices = ['<b>bold</b>', '*stars* & _underscores_', 'Café — naïve façade', '日本語の選択肢', str_repeat('long ', 80)];

        foreach ([false, true] as $allowMultiple) {
            $question = $this->question(choices: $choices, allowMultiple: $allowMultiple);
            $plainLines = $this->numberedLines($this->renderer()->render($question, null));

            $this->assertCount(5, $plainLines, 'precondition: every choice is a numbered line');
            $this->assertSame($plainLines, $this->numberedLines($this->renderer()->renderWithOptions($question, null)));
        }
    }

    // ---------------------------------------------------------------
    // optionLabel()
    // ---------------------------------------------------------------

    #[Test]
    public function an_option_label_is_the_number_and_the_full_text(): void
    {
        $this->assertSame('2. Use the cache', $this->renderer()->optionLabel(2, 'Use the cache', null));
        $this->assertSame('10. Ten', $this->renderer()->optionLabel(10, 'Ten', null));
    }

    #[Test]
    public function a_label_within_the_limit_is_not_shortened(): void
    {
        $this->assertSame('2. Use the cache', $this->renderer()->optionLabel(2, 'Use the cache', 40));
        // Exactly at the limit (16 characters).
        $this->assertSame('2. Use the cache', $this->renderer()->optionLabel(2, 'Use the cache', 16));
    }

    #[Test]
    public function a_long_label_is_cut_to_the_limit_keeping_its_number_and_ending_in_an_ellipsis(): void
    {
        $label = $this->renderer()->optionLabel(3, 'Rewrite the persistence layer to use event sourcing throughout', 20);

        $this->assertSame(20, mb_strlen($label), 'limit minus one character, plus the ellipsis');
        $this->assertStringStartsWith('3. ', $label);
        $this->assertStringEndsWith('…', $label);
        $this->assertSame('3. Rewrite the pers…', $label);
    }

    #[Test]
    public function shortening_is_multibyte_safe(): void
    {
        $label = $this->renderer()->optionLabel(7, str_repeat('é', 60).str_repeat('語', 60), 25);

        $this->assertTrue(mb_check_encoding($label, 'UTF-8'), 'no character is split');
        $this->assertSame(25, mb_strlen($label));
        $this->assertStringStartsWith('7. éé', $label);
        $this->assertStringEndsWith('…', $label);
    }

    #[Test]
    public function the_number_prefix_survives_a_very_small_limit(): void
    {
        $label = $this->renderer()->optionLabel(4, 'Something rather long', 6);

        $this->assertStringStartsWith('4. ', $label, 'the number is what identifies the choice');
        $this->assertLessThanOrEqual(6, mb_strlen($label));
    }

    // ---------------------------------------------------------------
    // renderOutcome()
    // ---------------------------------------------------------------

    #[Test]
    public function an_answered_single_selection_names_the_chosen_option(): void
    {
        $question = $this->question(choices: ['Skip the cache', 'Use the cache'], extra: [
            'state' => QuestionState::Answered,
            'answer_raw' => '2',
            'answer_selected' => [['number' => 2, 'text' => 'Use the cache']],
        ]);

        $this->assertSame('Answered: 2. Use the cache', $this->renderer()->renderOutcome($question));
    }

    #[Test]
    public function an_answered_multi_selection_names_every_chosen_option_in_order(): void
    {
        $question = $this->question(allowMultiple: true, extra: [
            'state' => QuestionState::Answered,
            'answer_raw' => '3 1',
            'answer_selected' => [['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']],
        ]);

        $this->assertSame('Answered: 1. Backend, 3. Docs', $this->renderer()->renderOutcome($question));
    }

    #[Test]
    public function the_outcome_uses_the_full_choice_text_never_a_shortened_label(): void
    {
        $long = str_repeat('a very long choice ', 20);
        $question = $this->question(choices: ['Short', $long], extra: [
            'state' => QuestionState::Answered,
            'answer_raw' => '2',
            'answer_selected' => [['number' => 2, 'text' => $long]],
        ]);

        $this->assertSame('Answered: 2. '.$long, $this->renderer()->renderOutcome($question));
    }

    #[Test]
    public function an_answer_in_free_text_does_not_repeat_the_text(): void
    {
        $question = $this->question(extra: [
            'state' => QuestionState::Answered,
            'answer_raw' => 'none of these — use a fourth approach',
            'answer_selected' => [],
        ]);

        $this->assertSame('Answered in your own words.', $this->renderer()->renderOutcome($question));
    }

    #[Test]
    public function an_expired_question(): void
    {
        $question = $this->question(extra: ['state' => QuestionState::Expired]);

        $this->assertSame('This question expired and is no longer awaiting a reply.', $this->renderer()->renderOutcome($question));
    }

    #[Test]
    public function a_withdrawn_question(): void
    {
        $question = $this->question(extra: ['state' => QuestionState::Withdrawn]);

        $this->assertSame('This question was withdrawn and is no longer awaiting a reply.', $this->renderer()->renderOutcome($question));
    }

    #[Test]
    public function an_undeliverable_question(): void
    {
        $question = $this->question(extra: [
            'state' => QuestionState::Undeliverable,
            'delivery_failure_reason' => 'telegram account not linked',
        ]);

        $this->assertSame('This question could not be delivered.', $this->renderer()->renderOutcome($question));
    }

    #[Test]
    public function a_pending_question_has_no_outcome(): void
    {
        $this->expectException(\LogicException::class);

        $this->renderer()->renderOutcome($this->question(extra: ['state' => QuestionState::Pending]));
    }
}
