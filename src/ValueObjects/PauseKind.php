<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Why a model turn paused waiting on a person.
 *
 * A paused assistant message records its kind in `tool_data.pause_kind`;
 * a row written before the kind existed has no such key and is a
 * confirmation.
 */
enum PauseKind: string
{
    case Confirmation = 'confirmation';
    case Question = 'question';

    /** The `tool_data` key that holds this kind's pending payload. */
    public function pendingKey(): string
    {
        return match ($this) {
            self::Confirmation => 'pending_confirmation',
            self::Question => 'pending_question',
        };
    }

    /**
     * The tool result given to every call after the pausing call in the
     * same model turn: those calls were never executed.
     */
    public function notExecutedResult(): string
    {
        return match ($this) {
            self::Question => 'Not executed: this turn paused to ask the user a question. Re-issue this call after the answer if it is still needed.',
            self::Confirmation => 'Not executed: this turn paused to ask the user to confirm an earlier call. Re-issue this call after the confirmation if it is still needed.',
        };
    }

    /** The kind of a stored paused turn; absent means a legacy confirmation. */
    public static function fromToolData(array $toolData): self
    {
        return self::tryFrom((string) ($toolData['pause_kind'] ?? '')) ?? self::Confirmation;
    }
}
