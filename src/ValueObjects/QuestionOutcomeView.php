<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Services\QuestionRenderer;

/**
 * What a channel needs to show a question's outcome, without rendering any
 * text itself: the state, the chosen choices (empty for a free-text answer
 * and every unanswered state), how it was answered, the one-line summary to
 * display, and whether the question still takes an answer (a question whose
 * work stopped at its deadline does, so its options stay on offer).
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
        public bool $answerable = false,
    ) {
    }

    /** Built from a question row that has stopped or been resolved. */
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
            answerable: $state->acceptsAnswers(),
        );
    }
}
