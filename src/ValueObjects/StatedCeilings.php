<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Support\Decimal;

/**
 * Ceilings a user stated for a task, exactly as given. Nothing is validated
 * here — TaskBudgetLimits::clamp() decides what is usable — but spend is
 * normalized to a plain decimal string so no float travels further.
 */
final readonly class StatedCeilings
{
    public ?string $maxSpend;

    public function __construct(
        public ?int $maxSteps = null,
        string|int|float|null $maxSpend = null,
    ) {
        $this->maxSpend = match (true) {
            $maxSpend === null => null,
            is_float($maxSpend) => rtrim(rtrim(Decimal::fromNumeric($maxSpend), '0'), '.') ?: '0',
            default => trim((string) $maxSpend),
        };
    }

    public function isEmpty(): bool
    {
        return $this->maxSteps === null && $this->maxSpend === null;
    }
}
