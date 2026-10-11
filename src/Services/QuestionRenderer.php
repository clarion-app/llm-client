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

    /**
     * @param  string|null  $preamble  assistant text that accompanied the
     *   question; blank is the same as none
     */
    public function render(AgentQuestion $question, ?string $preamble = null): string
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
                $lines[] = ($index + 1).'. '.$choice;
            }
            $blocks[] = implode("\n", $lines);
            $blocks[] = $question->allow_multiple ? self::MULTI_SELECT_INSTRUCTION : self::SINGLE_SELECT_INSTRUCTION;
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
