<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Everything a channel needs to present a question with its choices as
 * controls. `options` lists every choice in order. `bodyText` is the text
 * to show alongside the controls; `plainText` is the plain-text question,
 * used if the rich presentation fails.
 */
final readonly class QuestionPresentation
{
    /**
     * @param  list<QuestionOption>  $options
     */
    public function __construct(
        public string $questionId,
        public string $question,
        public ?string $preamble,
        public array $options,
        public bool $allowMultiple,
        public string $bodyText,
        public string $plainText,
    ) {
    }
}
