<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;

/**
 * Interprets a reply to a question. Deliberately strict: a reply is a
 * selection only when it consists of nothing but valid choice numbers —
 * exactly one on a single-select question, one or more (separated by
 * commas and/or whitespace) on a multi-select question. Anything else,
 * including "2." or "#2" or an out-of-range number, is a free-text answer.
 * Nothing is ever rejected, and the raw reply is passed through untouched.
 */
class AnswerParser
{
    public function parse(string $raw, AgentQuestion $question): ParsedAnswer
    {
        $trimmed = trim($raw);
        $choices = $question->choiceList();

        if ($trimmed === '' || $choices === [] || !preg_match('/^\d+(?:[\s,]+\d+)*$/', $trimmed)) {
            return new ParsedAnswer($raw, false);
        }

        $numbers = array_map('intval', preg_split('/[\s,]+/', $trimmed));

        if (!$question->allow_multiple && count($numbers) !== 1) {
            return new ParsedAnswer($raw, false);
        }

        foreach ($numbers as $number) {
            if ($number < 1 || $number > count($choices)) {
                return new ParsedAnswer($raw, false);
            }
        }

        $numbers = array_values(array_unique($numbers));
        sort($numbers);

        $selected = array_map(
            fn (int $number) => ['number' => $number, 'text' => $choices[$number - 1]],
            $numbers,
        );

        return new ParsedAnswer($raw, true, $selected);
    }
}
