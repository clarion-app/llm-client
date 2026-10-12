<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\QuestionRefusal;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;

/**
 * Checks the raw `ask_user` arguments before anything is written, and
 * normalizes an acceptable question (question and choices trimmed, the
 * policy for an unanswered question resolved, an assumed answer put in the
 * shape a user's answer has).
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

    public const MAX_ASSUMED_TEXT_CHARS = 4000;

    /** A requested waiting period is kept within ± this many minutes, so it always fits its column. */
    public const MAX_WAIT_MINUTES = 1_000_000_000;

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

        return $this->resolvePolicy($arguments, $question, $choices, $allowMultiple);
    }

    /**
     * Resolve what happens if nobody answers in time, refusing every
     * malformed, contradictory or unsafe combination. Checked after the
     * question and its choices, in a fixed order; the first failure is the
     * one reported. An argument that is absent or null is not given.
     *
     * @param  list<string>  $choices
     */
    private function resolvePolicy(array $arguments, string $question, array $choices, bool $allowMultiple): AskUserRequest|QuestionRefusal
    {
        $policyGiven = ($arguments['if_unanswered'] ?? null) !== null;
        $policy = null;
        if ($policyGiven) {
            $policy = is_string($arguments['if_unanswered']) ? UnansweredPolicy::tryFrom($arguments['if_unanswered']) : null;

            if ($policy === null) {
                return QuestionRefusal::invalid('if_unanswered must be "proceed_on_assumption" or "stop"');
            }
        }

        $consequential = false;
        if (($arguments['consequential'] ?? null) !== null) {
            $consequential = self::boolean($arguments['consequential']);

            if ($consequential === null) {
                return QuestionRefusal::invalid('consequential must be true or false');
            }
        }

        $requestedWaitMinutes = null;
        if (($arguments['wait_minutes'] ?? null) !== null) {
            $requestedWaitMinutes = self::wholeMinutes($arguments['wait_minutes']);

            if ($requestedWaitMinutes === null) {
                return QuestionRefusal::invalid('wait_minutes must be a whole number of minutes');
            }
        }

        $choicesGiven = ($arguments['assumed_choices'] ?? null) !== null;
        $textGiven = ($arguments['assumed_text'] ?? null) !== null;
        $assumptionGiven = $choicesGiven || $textGiven;

        if ($choicesGiven && $textGiven) {
            return QuestionRefusal::invalid('give the assumed answer as assumed_choices or assumed_text, not both');
        }

        if ($consequential && ($policy === UnansweredPolicy::ProceedOnAssumption || $assumptionGiven)) {
            return QuestionRefusal::invalid('a consequential question cannot proceed on an assumed answer; it always stops until the user answers');
        }

        if ($policy === UnansweredPolicy::Stop && $assumptionGiven) {
            return QuestionRefusal::invalid('if_unanswered is "stop" but an assumed answer was given; a question that stops when unanswered cannot also assume an answer');
        }

        if ($policy === UnansweredPolicy::ProceedOnAssumption && !$assumptionGiven) {
            return QuestionRefusal::invalid('if_unanswered is "proceed_on_assumption" but no assumed answer was given');
        }

        $assumedAnswer = null;

        if ($choicesGiven) {
            $assumedAnswer = $this->assumedSelection($arguments['assumed_choices'], $choices, $allowMultiple);

            if ($assumedAnswer instanceof QuestionRefusal) {
                return $assumedAnswer;
            }
        }

        if ($textGiven) {
            $text = $arguments['assumed_text'];

            if (!is_string($text) || trim($text) === '') {
                return QuestionRefusal::invalid('assumed_text must be non-empty text');
            }

            if (mb_strlen($text) > self::MAX_ASSUMED_TEXT_CHARS) {
                return QuestionRefusal::invalid('assumed_text exceeds '.self::MAX_ASSUMED_TEXT_CHARS.' characters');
            }

            // Read exactly as the same typed reply would be.
            $assumedAnswer = (new AnswerParser())->parseAgainst($text, $choices, $allowMultiple);
        }

        return new AskUserRequest(
            $question,
            $choices,
            $allowMultiple,
            policy: $assumedAnswer !== null ? UnansweredPolicy::ProceedOnAssumption : UnansweredPolicy::Stop,
            assumedAnswer: $assumedAnswer,
            consequential: $consequential,
            requestedWaitMinutes: $requestedWaitMinutes,
        );
    }

    /**
     * An assumed selection, checked strictly, in the shape a typed reply
     * selecting the same choices has: the numbers in order, comma
     * separated, and the choices they select.
     *
     * @param  list<string>  $choices
     */
    private function assumedSelection(mixed $numbers, array $choices, bool $allowMultiple): ParsedAnswer|QuestionRefusal
    {
        if (!is_array($numbers) || !array_is_list($numbers)) {
            return QuestionRefusal::invalid('assumed_choices must be a list of choice numbers');
        }

        foreach ($numbers as $number) {
            if (!is_int($number)) {
                return QuestionRefusal::invalid('assumed_choices must be a list of choice numbers');
            }
        }

        if ($choices === []) {
            return QuestionRefusal::invalid('assumed_choices requires choices');
        }

        if ($numbers === []) {
            return QuestionRefusal::invalid('assumed_choices is empty');
        }

        $count = count($choices);
        foreach ($numbers as $number) {
            if ($number < 1 || $number > $count) {
                return QuestionRefusal::invalid("assumed choice {$number} does not exist (the question has {$count} choices)");
            }
        }

        $seen = [];
        foreach ($numbers as $number) {
            if (isset($seen[$number])) {
                return QuestionRefusal::invalid("assumed choice {$number} is repeated");
            }

            $seen[$number] = true;
        }

        if (!$allowMultiple && count($numbers) > 1) {
            return QuestionRefusal::invalid('a single-selection question can assume only one choice');
        }

        sort($numbers);

        return new ParsedAnswer(
            implode(', ', $numbers),
            true,
            array_map(fn (int $number) => ['number' => $number, 'text' => $choices[$number - 1]], $numbers),
        );
    }

    /** true / false, or the strings "true" / "false"; null for anything else. */
    private static function boolean(mixed $value): ?bool
    {
        return match ($value) {
            true, 'true' => true,
            false, 'false' => false,
            default => null,
        };
    }

    /**
     * A whole number of minutes — an integer, an integral float or an
     * integral numeric string — kept within the storage limit; null when
     * the value is not one.
     */
    private static function wholeMinutes(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);

            if (!is_numeric($value)) {
                return null;
            }

            $value = $value + 0;
        }

        if (is_int($value)) {
            return max(-self::MAX_WAIT_MINUTES, min(self::MAX_WAIT_MINUTES, $value));
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) max(-self::MAX_WAIT_MINUTES, min(self::MAX_WAIT_MINUTES, $value));
        }

        return null;
    }
}
