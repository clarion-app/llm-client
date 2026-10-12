<?php

namespace ClarionApp\LlmClient\Contracts;

use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerOutcome;
use ClarionApp\LlmClient\ValueObjects\QuestionState;

/**
 * How another package (a messaging channel) applies an answer given through
 * its own controls to a specific question.
 */
interface InteractiveQuestionAnswers
{
    /** The question's state, or null when it does not exist or $userId does not own it (indistinguishable). */
    public function stateFor(string $questionId, string $userId): ?QuestionState;

    /**
     * Record an interactive answer to exactly this question and, when it was applied, queue the resume
     * (ResumeAfterQuestionJob → ResumeCaller::Background). Returns quickly; never runs the agent inline.
     */
    public function submit(
        string $questionId,
        string $userId,
        InteractiveAnswer $answer,
        AnswerMethod $method,
    ): InteractiveAnswerOutcome;

    /**
     * Plain-text description of a question that no longer accepts answers
     * (QuestionRenderer::renderOutcome()), or null when it does not exist,
     * is not $userId's, or still accepts answers. Absent and foreign are
     * identical, so the result reveals nothing about another user's question.
     */
    public function describeState(string $questionId, string $userId): ?string;
}
