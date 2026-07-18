<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Value object capturing context management results for a single request.
 *
 * Populated by ContextWindowBudgeter::trim() and ConversationCondenser::condenseOrTrim()
 * via an optional by-reference out-parameter, then read by AgentLoopService::applyContextWindowTrim()
 * at the single recording site.
 */
final class ContextManagementOutcome
{
    /** @var list<ContextManagementStep> */
    private array $steps = [];

    public function __construct(
        public readonly int $contextCapacity,
        public readonly int $historyBudget,
        public readonly int $tokensBefore,
        public readonly int $tokensAfter,
        public readonly ?string $model,
        public readonly ?string $providerType,
    ) {}

    /**
     * Append a mechanism step to this outcome.
     */
    public function addStep(ContextManagementStep $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * Get all mechanism steps.
     *
     * @return list<ContextManagementStep>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * Check if no mechanisms fired (empty steps list).
     */
    public function isNone(): bool
    {
        return empty($this->steps);
    }

    /**
     * Create an outcome representing "no action needed" (messages fit the budget).
     */
    public static function none(
        int $contextCapacity,
        int $historyBudget,
        int $tokensBefore,
        ?string $model = null,
        ?string $providerType = null,
    ): self {
        return new self(
            contextCapacity: $contextCapacity,
            historyBudget: $historyBudget,
            tokensBefore: $tokensBefore,
            tokensAfter: $tokensBefore,
            model: $model,
            providerType: $providerType,
        );
    }
}
