<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * A validated, normalized `ask_user` call: the question and every choice
 * trimmed, choices in the order the agent gave them.
 */
final readonly class AskUserRequest
{
    /**
     * @param  list<string>  $choices
     */
    public function __construct(
        public string $question,
        public array $choices = [],
        public bool $allowMultiple = false,
    ) {
    }

    /** @return array{question: string, choices: list<string>, allow_multiple: bool} */
    public function toArray(): array
    {
        return [
            'question' => $this->question,
            'choices' => $this->choices,
            'allow_multiple' => $this->allowMultiple,
        ];
    }
}
