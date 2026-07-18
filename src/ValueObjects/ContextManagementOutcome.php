<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Value object capturing context management results for a single request.
 *
 * Populated by ContextWindowBudgeter::trim() and ConversationCondenser::condenseOrTrim()
 * via an optional by-reference out-parameter, then read by AgentLoopService::applyContextWindowTrim()
 * at the single recording site.
 *
 * The outcome is *mutable and accumulating*: mechanisms call recordContext() to contribute the
 * request-level fields and addStep() to append what they did. Callers must never replace the
 * object, because a later mechanism (e.g. the budgeter running after smart trimming) would
 * discard the steps recorded by earlier ones.
 *
 * `tokensBefore` is first-writer-wins so it always reflects the tokens entering the *request*,
 * not the tokens entering the last mechanism to run. `tokensAfter` is last-writer-wins so it
 * reflects the state leaving the pipeline.
 */
final class ContextManagementOutcome
{
    /** @var list<ContextManagementStep> */
    private array $steps = [];

    /** Tracks whether tokensBefore has been claimed by the first mechanism to report. */
    private bool $tokensBeforeSet = false;

    public function __construct(
        public int $contextCapacity = 0,
        public int $historyBudget = 0,
        public int $tokensBefore = 0,
        public int $tokensAfter = 0,
        public ?string $model = null,
        public ?string $providerType = null,
    ) {}

    /**
     * Contribute the request-level context fields.
     *
     * `tokensBefore` is recorded only once (first writer wins) so that a mechanism running
     * later in the pipeline cannot overwrite the true request-level figure with its own
     * post-upstream-mechanism input. Every other field is last-writer-wins, since later
     * mechanisms resolve them more precisely.
     */
    public function recordContext(
        int $contextCapacity,
        int $historyBudget,
        int $tokensBefore,
        int $tokensAfter,
        ?string $model = null,
        ?string $providerType = null,
    ): void {
        $this->contextCapacity = $contextCapacity;
        $this->historyBudget = $historyBudget;

        if (!$this->tokensBeforeSet) {
            $this->tokensBefore = $tokensBefore;
            $this->tokensBeforeSet = true;
        }

        $this->tokensAfter = $tokensAfter;
        $this->model = $model;
        $this->providerType = $providerType;
    }

    /**
     * Claim the request-level `tokensBefore` before any mechanism runs.
     *
     * Used by the condenser so the recorded utilization numerator is the tokens entering the
     * request, even when smart trimming shrinks the payload before the budgeter ever sees it.
     */
    public function recordRequestTokensBefore(int $tokensBefore): void
    {
        if (!$this->tokensBeforeSet) {
            $this->tokensBefore = $tokensBefore;
            $this->tokensBeforeSet = true;
        }
    }

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
        $outcome = new self(
            contextCapacity: $contextCapacity,
            historyBudget: $historyBudget,
            tokensBefore: $tokensBefore,
            tokensAfter: $tokensBefore,
            model: $model,
            providerType: $providerType,
        );
        $outcome->tokensBeforeSet = true;

        return $outcome;
    }
}
