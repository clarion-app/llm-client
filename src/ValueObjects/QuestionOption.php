<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * One choice of a question as a channel presents it: its 1-based number,
 * its full text, and the label to show on the control ("{n}. {text}",
 * possibly shortened to the channel's limit but always keeping the number).
 */
final readonly class QuestionOption
{
    public function __construct(
        public int $number,
        public string $text,
        public string $label,
    ) {
    }
}
