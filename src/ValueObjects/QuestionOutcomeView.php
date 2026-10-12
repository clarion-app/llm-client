<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Services\QuestionRenderer;

/**
 * What a channel needs to show that a question is resolved, without
 * rendering any text itself: the final state, the chosen choices (empty for
 * a free-text answer and every unanswered state), how it was answered, and
 * the one-line summary to display.
 */
final readonly class QuestionOutcomeView
{
    /**
     * @param  list<array{number: int, text: string}>  $selected
     */
    public function __construct(
        public string $questionId,
        public QuestionState $state,
        public array $selected,
        public bool $isSelection,
        public ?AnswerMethod $method,
        public string $summary,
    ) {
    }

    /** Built from a resolved question row. */
    public static function fromQuestion(AgentQuestion $question): self
    {
        $state = $question->state instanceof QuestionState ? $question->state : QuestionState::from((string) $question->state);
        $answered = $state === QuestionState::Answered;

        $selected = $answered ? array_values($question->answer_selected ?? []) : [];
        $method = $answered && $question->answer_method instanceof AnswerMethod ? $question->answer_method : null;

        return new self(
            questionId: (string) $question->id,
            state: $state,
            selected: $selected,
            isSelection: $selected !== [],
            method: $method,
            summary: app(QuestionRenderer::class)->renderOutcome($question),
        );
    }
}
