<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * A reply interpreted against the question it answers. `rawReply` is the
 * reply exactly as received; `selected` lists the chosen choices (empty for
 * a free-text answer).
 */
final readonly class ParsedAnswer
{
    /**
     * @param  list<array{number: int, text: string}>  $selected
     */
    public function __construct(
        public string $rawReply,
        public bool $isSelection,
        public array $selected = [],
    ) {
    }

    public function isBlank(): bool
    {
        return trim($this->rawReply) === '';
    }

    /** @return array{raw_reply: string, is_selection: bool, selected: list<array{number: int, text: string}>} */
    public function toArray(): array
    {
        return [
            'raw_reply' => $this->rawReply,
            'is_selection' => $this->isSelection,
            'selected' => $this->selected,
        ];
    }
}
