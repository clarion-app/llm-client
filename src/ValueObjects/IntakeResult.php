<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Message;

/**
 * What answer intake did with an inbound reply. Intake only records an
 * answer; the calling endpoint performs the resume.
 */
final readonly class IntakeResult
{
    public const ANSWERED = 'answered';
    public const ANSWER_REQUIRED = 'answer_required';
    public const NOT_AN_ANSWER = 'not_an_answer';

    public function __construct(
        public string $kind,
        public ?AgentQuestion $question = null,
        public ?Message $message = null,
    ) {
    }

    public static function answered(AgentQuestion $question, Message $message): self
    {
        return new self(self::ANSWERED, $question, $message);
    }

    public static function answerRequired(AgentQuestion $question): self
    {
        return new self(self::ANSWER_REQUIRED, $question);
    }

    public static function notAnAnswer(): self
    {
        return new self(self::NOT_AN_ANSWER);
    }
}
