<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The gate's answer before a model call: ALLOW (a step was claimed) or STOP
 * with the reason — a ceiling reached (steps, spend or both), the task no
 * longer running, or the budget unavailable (the gate fails closed).
 */
final readonly class TaskBudgetDecision
{
    public const ALLOW = 'allow';
    public const STOP = 'stop';

    public const REASON_STEPS = 'steps';
    public const REASON_SPEND = 'spend';
    public const REASON_BOTH = 'both';
    public const REASON_NOT_RUNNING = 'not_running';
    public const REASON_UNAVAILABLE = 'unavailable';

    private const REASONS = [self::REASON_STEPS, self::REASON_SPEND, self::REASON_BOTH, self::REASON_NOT_RUNNING, self::REASON_UNAVAILABLE];

    private function __construct(
        public string $verdict,
        public ?string $reason,
        public ?TaskBudgetStanding $standing,
    ) {
    }

    public static function allow(?TaskBudgetStanding $standing = null): self
    {
        return new self(self::ALLOW, null, $standing);
    }

    public static function stop(string $reason, ?TaskBudgetStanding $standing = null): self
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException("Unknown task budget stop reason: {$reason}");
        }

        return new self(self::STOP, $reason, $standing);
    }

    public static function unavailable(): self
    {
        return self::stop(self::REASON_UNAVAILABLE);
    }

    public function isStop(): bool
    {
        return $this->verdict === self::STOP;
    }

    public function isAllow(): bool
    {
        return $this->verdict === self::ALLOW;
    }
}
