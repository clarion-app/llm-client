<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\QuestionRefusal;

/**
 * Checks the raw `ask_user` arguments before anything is written, and
 * normalizes an acceptable question (question and choices trimmed).
 *
 * The limits keep a question readable as plain text on a phone; they are
 * constants, not configuration. Lengths are counted in characters, not
 * bytes.
 */
class AgentQuestionValidator
{
    public const MAX_CHOICES = 10;

    public const MAX_QUESTION_CHARS = 4000;

    public const MAX_CHOICE_CHARS = 500;

    public function validate(array $arguments): AskUserRequest|QuestionRefusal
    {
        $question = $arguments['question'] ?? null;
        $question = is_string($question) ? trim($question) : '';

        if ($question === '') {
            return QuestionRefusal::invalid('question text is empty');
        }

        if (mb_strlen($question) > self::MAX_QUESTION_CHARS) {
            return QuestionRefusal::invalid('question text exceeds '.self::MAX_QUESTION_CHARS.' characters');
        }

        $rawChoices = $arguments['choices'] ?? [];

        if (!is_array($rawChoices) || !array_is_list($rawChoices)) {
            return QuestionRefusal::invalid('choices must be a list of strings');
        }

        foreach ($rawChoices as $choice) {
            if (!is_string($choice)) {
                return QuestionRefusal::invalid('choices must be a list of strings');
            }
        }

        if (count($rawChoices) > self::MAX_CHOICES) {
            return QuestionRefusal::invalid('more than '.self::MAX_CHOICES.' choices');
        }

        $choices = [];
        foreach ($rawChoices as $index => $choice) {
            $number = $index + 1;
            $choice = trim($choice);

            if ($choice === '') {
                return QuestionRefusal::invalid("choice {$number} is empty");
            }

            if (mb_strlen($choice) > self::MAX_CHOICE_CHARS) {
                return QuestionRefusal::invalid("choice {$number} exceeds ".self::MAX_CHOICE_CHARS.' characters');
            }

            $choices[] = $choice;
        }

        $seen = [];
        foreach ($choices as $index => $choice) {
            $folded = mb_strtolower($choice);

            if (isset($seen[$folded])) {
                $first = $seen[$folded] + 1;
                $second = $index + 1;

                return QuestionRefusal::invalid("choices {$first} and {$second} are duplicates");
            }

            $seen[$folded] = $index;
        }

        $allowMultiple = (bool) ($arguments['allow_multiple'] ?? false);

        if ($allowMultiple && $choices === []) {
            return QuestionRefusal::invalid('allow_multiple requires at least one choice');
        }

        return new AskUserRequest($question, $choices, $allowMultiple);
    }
}
