<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\Support\Decimal;

/**
 * A point-in-time view of one task's consumption against its ceilings.
 * Spend figures are decimal strings, never floats. Whether a paused task is
 * awaiting a decision or stopped is derived from its prompt's state, never
 * stored. toArray() is the shape every API response uses.
 */
final readonly class TaskBudgetStanding
{
    public function __construct(
        public string $taskBudgetId,
        public string $conversationId,
        public TaskBudgetState $state,
        public ?QuestionState $promptState,
        public ?string $promptQuestionId,
        public int $stepsUsed,
        public int $maxSteps,
        public CeilingOrigin $stepsOrigin,
        public int $maximumSteps,
        public bool $stepsApproachNoticed,
        public string $spendUsed,
        public string $maxSpend,
        public string $currency,
        public CeilingOrigin $spendOrigin,
        public string $maximumSpend,
        public bool $approximate,
        public int $unpricedRequestCount,
        public bool $spendApproachNoticed,
        public string $approachThreshold,
    ) {
    }

    /** The same standing with a different step count. */
    public function withStepsUsed(int $stepsUsed): self
    {
        return new self(...array_merge(get_object_vars($this), ['stepsUsed' => $stepsUsed]));
    }

    public function subState(): ?string
    {
        if ($this->state !== TaskBudgetState::Paused) {
            return null;
        }

        return match ($this->promptState) {
            QuestionState::Pending => 'awaiting_decision',
            QuestionState::Stopped => 'stopped',
            default => null,
        };
    }

    public function incomplete(): bool
    {
        return $this->unpricedRequestCount > 0;
    }

    /** @return list<string> */
    public function reached(): array
    {
        $reached = [];
        if ($this->stepsUsed >= $this->maxSteps) {
            $reached[] = 'steps';
        }
        if (bccomp(Decimal::toPlainNotation($this->spendUsed), Decimal::toPlainNotation($this->maxSpend), 10) >= 0) {
            $reached[] = 'spend';
        }

        return $reached;
    }

    public function label(): string
    {
        return (new TaskBudgetText())->standing($this);
    }

    public function toArray(): array
    {
        return [
            'task_budget_id' => $this->taskBudgetId,
            'conversation_id' => $this->conversationId,
            'state' => $this->state->value,
            'sub_state' => $this->subState(),
            'steps' => [
                'used' => $this->stepsUsed,
                'ceiling' => $this->maxSteps,
                'origin' => $this->stepsOrigin->value,
                'maximum' => $this->maximumSteps,
                'approach_noticed' => $this->stepsApproachNoticed,
            ],
            'spend' => [
                'used' => Decimal::round($this->spendUsed, 10),
                'ceiling' => Decimal::round($this->maxSpend, 10),
                'currency' => $this->currency,
                'origin' => $this->spendOrigin->value,
                'maximum' => Decimal::round($this->maximumSpend, 10),
                'approximate' => $this->approximate,
                'incomplete' => $this->incomplete(),
                'unpriced_request_count' => $this->unpricedRequestCount,
                'approach_noticed' => $this->spendApproachNoticed,
            ],
            'approach_threshold' => Decimal::round($this->approachThreshold, 4),
            'reached' => $this->reached(),
            'prompt_question_id' => $this->promptQuestionId,
            'label' => $this->label(),
        ];
    }
}
