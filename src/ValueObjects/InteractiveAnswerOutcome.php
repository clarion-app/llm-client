<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Message;

/**
 * The result of applying an interactive answer. `state` is the question's
 * state for NotPending and Answered; `question`, `message` (the stored
 * answer) and `selectedSummary` (e.g. "2. Use the cache", or the free text)
 * are set only when it was answered. A NotFound outcome carries nothing,
 * so an absent question and another user's question look identical.
 */
final readonly class InteractiveAnswerOutcome
{
    private function __construct(
        public InteractiveAnswerStatus $status,
        public ?QuestionState $state = null,
        public ?AgentQuestion $question = null,
        public ?Message $message = null,
        public ?string $selectedSummary = null,
    ) {
    }

    public static function answered(AgentQuestion $question, Message $message, string $selectedSummary): self
    {
        return new self(InteractiveAnswerStatus::Answered, QuestionState::Answered, $question, $message, $selectedSummary);
    }

    public static function answerRequired(QuestionState $state): self
    {
        return new self(InteractiveAnswerStatus::AnswerRequired, $state);
    }

    public static function invalidAnswer(QuestionState $state): self
    {
        return new self(InteractiveAnswerStatus::InvalidAnswer, $state);
    }

    public static function notPending(QuestionState $state, ?AgentQuestion $question = null): self
    {
        return new self(InteractiveAnswerStatus::NotPending, $state, $question);
    }

    public static function notFound(): self
    {
        return new self(InteractiveAnswerStatus::NotFound);
    }
}
