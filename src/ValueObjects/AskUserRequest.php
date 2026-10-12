<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * A validated, normalized `ask_user` call: the question and every choice
 * trimmed, choices in the order the agent gave them, and what happens if
 * nobody answers in time — the policy, the answer assumed under the
 * proceed policy, whether the answer gates a consequential action, and the
 * waiting period the agent asked for (before the installation's bounds).
 *
 * The request travels from the tool handler to the pause site as a JSON
 * marker (toArray()); the pause site rebuilds it with fromMarker(), never
 * field by field, so nothing the agent chose can be dropped on the way.
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
        public UnansweredPolicy $policy = UnansweredPolicy::Stop,
        public ?ParsedAnswer $assumedAnswer = null,
        public bool $consequential = false,
        public ?int $requestedWaitMinutes = null,
    ) {
    }

    /**
     * Rebuild a request from the marker toArray() produced. A key the
     * marker lacks takes its default, so a marker written before the
     * policy existed rebuilds as a stop question with nothing assumed.
     *
     * @param  array<string, mixed>  $marker
     */
    public static function fromMarker(array $marker): self
    {
        $choices = is_array($marker['choices'] ?? null) ? array_values(array_map('strval', $marker['choices'])) : [];

        $assumed = $marker['assumed_answer'] ?? null;
        $assumedAnswer = null;
        if (is_array($assumed) && isset($assumed['raw_reply'])) {
            $assumedAnswer = new ParsedAnswer(
                (string) $assumed['raw_reply'],
                (bool) ($assumed['is_selection'] ?? false),
                array_values(array_map(
                    fn (array $choice) => ['number' => (int) $choice['number'], 'text' => (string) $choice['text']],
                    is_array($assumed['selected'] ?? null) ? $assumed['selected'] : [],
                )),
            );
        }

        $wait = $marker['wait_minutes'] ?? null;

        return new self(
            question: (string) ($marker['question'] ?? ''),
            choices: $choices,
            allowMultiple: (bool) ($marker['allow_multiple'] ?? false),
            policy: UnansweredPolicy::tryFrom((string) ($marker['if_unanswered'] ?? '')) ?? UnansweredPolicy::Stop,
            assumedAnswer: $assumedAnswer,
            consequential: (bool) ($marker['consequential'] ?? false),
            requestedWaitMinutes: is_numeric($wait) ? (int) $wait : null,
        );
    }

    /**
     * @return array{
     *     question: string,
     *     choices: list<string>,
     *     allow_multiple: bool,
     *     if_unanswered: string,
     *     assumed_answer: ?array{raw_reply: string, is_selection: bool, selected: list<array{number: int, text: string}>},
     *     consequential: bool,
     *     wait_minutes: ?int,
     * }
     */
    public function toArray(): array
    {
        return [
            'question' => $this->question,
            'choices' => $this->choices,
            'allow_multiple' => $this->allowMultiple,
            'if_unanswered' => $this->policy->value,
            'assumed_answer' => $this->assumedAnswer?->toArray(),
            'consequential' => $this->consequential,
            'wait_minutes' => $this->requestedWaitMinutes,
        ];
    }
}
