<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\ValueObjects\QuestionState;

/**
 * The only renderer of a question and of its follow-up notices. The output
 * is plain text and channel-agnostic: every channel receives exactly these
 * strings, so a question reads the same on the web screen and in a chat.
 */
class QuestionRenderer
{
    public const SINGLE_SELECT_INSTRUCTION = 'Reply with the number of your choice, or answer in your own words.';

    public const MULTI_SELECT_INSTRUCTION = 'Reply with one or more numbers separated by commas or spaces, or answer in your own words.';

    public const FREE_TEXT_INSTRUCTION = 'Reply with your answer.';

    /** Instruction under a single-select question whose choices are shown as controls. */
    public const SINGLE_SELECT_OPTIONS_INSTRUCTION = 'Choose an option below, or reply in your own words.';

    /** Instruction under a multi-select question whose choices are shown as controls. */
    public const MULTI_SELECT_OPTIONS_INSTRUCTION = 'Select one or more options below, then tap Submit — or reply in your own words.';

    /**
     * The plain-text question, answered by typing.
     *
     * @param  string|null  $preamble  assistant text that accompanied the
     *   question; blank is the same as none
     */
    public function render(AgentQuestion $question, ?string $preamble = null): string
    {
        return $this->compose($question, $preamble, self::SINGLE_SELECT_INSTRUCTION, self::MULTI_SELECT_INSTRUCTION);
    }

    /**
     * The question as shown alongside its choices as controls. Identical to
     * render() except for the instruction line under a list of choices, so
     * the numbered list reads the same on every channel. A question without
     * choices has no controls and renders exactly as render() does.
     */
    public function renderWithOptions(AgentQuestion $question, ?string $preamble = null): string
    {
        return $this->compose($question, $preamble, self::SINGLE_SELECT_OPTIONS_INSTRUCTION, self::MULTI_SELECT_OPTIONS_INSTRUCTION);
    }

    /**
     * The label of one choice: "{n}. {text}". With $maxChars, a longer
     * label is cut to exactly $maxChars characters ending in "…"; the
     * "{n}. " prefix that identifies the choice is always kept.
     */
    public function optionLabel(int $number, string $text, ?int $maxChars): string
    {
        $prefix = $number.'. ';
        $label = $prefix.$text;

        if ($maxChars === null || mb_strlen($label) <= $maxChars) {
            return $label;
        }

        $keep = $maxChars - 1;

        if ($keep < mb_strlen($prefix)) {
            // Too small a limit for any text: the number alone still
            // identifies the choice.
            $bare = rtrim($prefix);

            return mb_strlen($bare) <= $maxChars ? $bare : $prefix;
        }

        return mb_substr($label, 0, $keep).'…';
    }

    /**
     * One line describing how a resolved question was resolved, e.g.
     * "Answered: 2. Use the cache". A selection names every chosen choice
     * by its full text; a free-text answer is not repeated.
     */
    public function renderOutcome(AgentQuestion $question): string
    {
        $state = $question->state instanceof QuestionState ? $question->state : QuestionState::tryFrom((string) $question->state);

        return match ($state) {
            QuestionState::Answered => $this->renderAnswered($question),
            QuestionState::Expired => 'This question expired and is no longer awaiting a reply.',
            QuestionState::Withdrawn => 'This question was withdrawn and is no longer awaiting a reply.',
            QuestionState::Undeliverable => 'This question could not be delivered.',
            default => throw new \LogicException("No outcome exists for a question in state {$state?->value}."),
        };
    }

    private function renderAnswered(AgentQuestion $question): string
    {
        $selected = array_values($question->answer_selected ?? []);

        if ($selected === []) {
            return 'Answered in your own words.';
        }

        $labels = array_map(
            fn (array $choice) => $this->optionLabel((int) $choice['number'], (string) $choice['text'], null),
            $selected,
        );

        return 'Answered: '.implode(', ', $labels);
    }

    private function compose(AgentQuestion $question, ?string $preamble, string $singleInstruction, string $multiInstruction): string
    {
        $blocks = [];

        if ($preamble !== null && trim($preamble) !== '') {
            $blocks[] = trim($preamble);
        }

        $blocks[] = (string) $question->question;

        $choices = $question->choiceList();

        if ($choices !== []) {
            $lines = [];
            foreach ($choices as $index => $choice) {
                $lines[] = $this->optionLabel($index + 1, $choice, null);
            }
            $blocks[] = implode("\n", $lines);
            $blocks[] = $question->allow_multiple ? $multiInstruction : $singleInstruction;
        } else {
            $blocks[] = self::FREE_TEXT_INSTRUCTION;
        }

        return implode("\n\n", $blocks);
    }

    /** The notice shown when a question is resolved without an answer. */
    public function renderNotice(AgentQuestion $question): string
    {
        $quoted = '"'.$question->question.'"';

        return match ($question->state) {
            QuestionState::Expired => "The question {$quoted} expired without an answer. "
                .'The agent will continue without it; replies sent now are treated as new messages.',
            QuestionState::Withdrawn => "The question {$quoted} was withdrawn because the task was stopped. "
                .'It no longer needs a reply.',
            QuestionState::Undeliverable => "The question {$quoted} could not be delivered ("
                .($question->delivery_failure_reason ?? 'unknown reason').'). '
                .'The agent was told and will continue without an answer.',
            default => throw new \LogicException("No notice exists for a question in state {$question->state?->value}."),
        };
    }
}
