<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * What a channel can present when it shows a question's choices as
 * controls: whether it can at all, how many options at most (null: no
 * limit), and the longest option label before it is shortened (null: no
 * limit).
 */
final readonly class ChannelQuestionCapabilities
{
    public function __construct(
        public bool $supportsOptions,
        public ?int $maxOptions = null,
        public ?int $maxOptionLabelChars = null,
    ) {
    }

    /** A channel that cannot present options; it always gets plain text. */
    public static function none(): self
    {
        return new self(false);
    }

    /** A channel that can present any number of options with full labels. */
    public static function unlimited(): self
    {
        return new self(true);
    }
}
