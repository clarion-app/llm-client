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

    public const NO_USER_REASON = 'This task has no user to ask.';
    public const UNATTENDED_REASON = 'This is an unattended run; no user is present to answer.';
    public const HELPER_REASON = 'Delegated helpers cannot pause to ask the user; return the open question to your caller in your result.';

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

    /**
     * The refusal carried by a decoded `ask_user` tool result, or null when
     * the result is anything else.
     */
    public static function fromToolResult(mixed $decoded): ?self
    {
        if (!is_array($decoded) || ($decoded['outcome'] ?? null) !== 'refused' || !is_string($decoded['code'] ?? null)) {
            return null;
        }

        return new self($decoded['code'], (string) ($decoded['reason'] ?? ''));
    }

    /** The failure reason recorded on the refused call's `ask_user` action. */
    public function actionFailureReason(): string
    {
        return 'ask refused: '.$this->code;
    }

    /**
     * The run-record snapshot of the refused ask. No question row exists,
     * so `question_id` is null; the question and choices are recorded as
     * the agent submitted them, and there is no expiry, wait or answer.
     *
     * @param  array<string, mixed>  $arguments  the call's arguments as given
     */
    public function snapshot(array $arguments, \DateTimeInterface $at): string
    {
        $time = \DateTimeImmutable::createFromInterface($at)->format('Y-m-d\\TH:i:s.vP');

        return json_encode([
            'question_id' => null,
            'question' => $arguments['question'] ?? null,
            'choices' => $arguments['choices'] ?? [],
            'allow_multiple' => (bool) ($arguments['allow_multiple'] ?? false),
            'asked_at' => $time,
            'expires_at' => null,
            'resolution' => 'refused:'.$this->code,
            'resolved_at' => $time,
            'waited_ms' => null,
            'answer' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
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
