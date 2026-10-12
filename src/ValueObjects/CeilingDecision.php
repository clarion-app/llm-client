<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * A user's parsed reply to a task-budget ceiling prompt: continue (with an
 * optional additional allowance of steps and/or spend), accept what has been
 * done, stop, or a reply that is none of these — which carries the guidance
 * text to show instead of acting on it.
 */
final readonly class CeilingDecision
{
    public const CONTINUE = 'continue';
    public const ACCEPT = 'accept';
    public const STOP = 'stop';
    public const INVALID = 'invalid';

    private const KINDS = [self::CONTINUE, self::ACCEPT, self::STOP, self::INVALID];

    public function __construct(
        public string $kind,
        public ?int $addSteps = null,
        public ?string $addSpend = null,
        public ?string $guidance = null,
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown ceiling decision: {$kind}");
        }
    }

    public static function continue(?int $addSteps = null, ?string $addSpend = null): self
    {
        return new self(self::CONTINUE, $addSteps, $addSpend);
    }

    public static function accept(): self
    {
        return new self(self::ACCEPT);
    }

    public static function stop(): self
    {
        return new self(self::STOP);
    }

    public static function invalid(string $guidance): self
    {
        return new self(self::INVALID, guidance: $guidance);
    }

    public function isInvalid(): bool
    {
        return $this->kind === self::INVALID;
    }
}
