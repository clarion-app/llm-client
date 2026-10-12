<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * An answer given through controls rather than typed: either a selection
 * of choice numbers or free text, never both. It is turned into the reply
 * the user could have typed for the same meaning (toReplyText()), so it is
 * interpreted by the same parser as a typed reply.
 */
final readonly class InteractiveAnswer
{
    /**
     * @param  list<int>|null  $selected  the numbers exactly as given
     */
    private function __construct(
        public ?array $selected,
        public ?string $text,
    ) {
    }

    /** @param list<int> $numbers */
    public static function selection(array $numbers): self
    {
        return new self(array_values($numbers), null);
    }

    public static function freeText(string $text): self
    {
        return new self(null, $text);
    }

    /** True for an empty selection or blank text. */
    public function isEmpty(): bool
    {
        if ($this->selected !== null) {
            return $this->selected === [];
        }

        return trim((string) $this->text) === '';
    }

    /**
     * The equivalent typed reply: the sorted, de-duplicated numbers joined
     * by ", " (e.g. "1, 3"), or the text exactly as given.
     */
    public function toReplyText(): string
    {
        if ($this->selected === null) {
            return (string) $this->text;
        }

        $numbers = array_values(array_unique(array_map('intval', $this->selected)));
        sort($numbers);

        return implode(', ', $numbers);
    }
}
