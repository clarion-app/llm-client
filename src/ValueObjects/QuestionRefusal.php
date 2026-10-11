<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * An `ask_user` call refused before any pause: nothing is written and
 * nothing reaches the user; the agent receives toToolResult() as the
 * call's result in the same iteration.
 */
final readonly class QuestionRefusal
{
    public const INVALID_QUESTION = 'invalid_question';
    public const QUESTION_ALREADY_PENDING = 'question_already_pending';
    public const NO_REACHABLE_USER = 'no_reachable_user';

    public const ALREADY_PENDING_REASON = "A question is already waiting for the user's answer in this conversation. Wait for it to be resolved before asking another.";

    public function __construct(
        public string $code,
        public string $reason,
    ) {
    }

    public static function invalid(string $reason): self
    {
        return new self(self::INVALID_QUESTION, $reason);
    }

    public static function alreadyPending(): self
    {
        return new self(self::QUESTION_ALREADY_PENDING, self::ALREADY_PENDING_REASON);
    }

    public static function noReachableUser(string $reason): self
    {
        return new self(self::NO_REACHABLE_USER, $reason);
    }

    public function toToolResult(): string
    {
        return json_encode([
            'outcome' => 'refused',
            'code' => $this->code,
            'reason' => $this->reason,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
