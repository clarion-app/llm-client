<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Support\Decimal;
use ClarionApp\LlmClient\ValueObjects\CeilingOrigin;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;

/**
 * Resolves the installation's task-budget defaults, maxima, approach
 * threshold, prompt wait and retention from configuration, read on every
 * call. An invalid configured value resolves to the shipped value (a zero
 * maximum never means unlimited), hard caps bound every maximum, and a
 * default above its maximum is reduced to it. All spend values are decimal
 * strings at scale 10.
 */
class TaskBudgetLimits
{
    public const HARD_MAX_STEPS = 1000000;
    public const HARD_MAX_SPEND = '1000000.00';

    private const SHIPPED_DEFAULT_STEPS = 20;
    private const SHIPPED_DEFAULT_SPEND = '1.00';
    private const SHIPPED_MAXIMUM_STEPS = 200;
    private const SHIPPED_MAXIMUM_SPEND = '25.00';
    private const SHIPPED_THRESHOLD = '0.80';

    public function maximumSteps(): int
    {
        $value = $this->positiveInt(config('llm-client.task_budget.maximum_steps')) ?? self::SHIPPED_MAXIMUM_STEPS;

        return min($value, self::HARD_MAX_STEPS);
    }

    public function maximumSpend(): string
    {
        $value = $this->positiveDecimal(config('llm-client.task_budget.maximum_spend')) ?? self::SHIPPED_MAXIMUM_SPEND;

        return $this->min($value, self::HARD_MAX_SPEND);
    }

    public function defaultSteps(): int
    {
        $value = $this->positiveInt(config('llm-client.agent_loop.max_iterations')) ?? self::SHIPPED_DEFAULT_STEPS;

        return min($value, $this->maximumSteps());
    }

    public function defaultSpend(): string
    {
        $value = $this->positiveDecimal(config('llm-client.task_budget.default_max_spend')) ?? self::SHIPPED_DEFAULT_SPEND;

        return $this->min($value, $this->maximumSpend());
    }

    public function approachThreshold(): string
    {
        foreach ([config('llm-client.task_budget.approach_threshold'), config('llm-client.budget.default_approach_threshold'), self::SHIPPED_THRESHOLD] as $candidate) {
            $value = $this->decimal($candidate);
            if ($value !== null && bccomp($value, '0', 10) > 0 && bccomp($value, '1', 10) < 0) {
                return Decimal::round($value, 4);
            }
        }

        return '0.8000';
    }

    public function promptWaitMinutes(): int
    {
        $requested = config('llm-client.task_budget.prompt_wait_minutes');
        $requested = is_numeric($requested) ? (int) $requested : null;

        return app(QuestionWaitingPeriod::class)->effectiveMinutes($requested);
    }

    public function retentionDays(): int
    {
        return $this->positiveInt(config('llm-client.task_budget.retention_days'))
            ?? $this->positiveInt(config('llm-client.run_trace.retention_days'))
            ?? 90;
    }

    public function clamp(?StatedCeilings $stated): array
    {
        $steps = $this->defaultSteps();
        $stepsOrigin = CeilingOrigin::Default;
        $stepsReduced = false;
        $statedSteps = $stated?->maxSteps;
        if ($statedSteps !== null && $statedSteps >= 1) {
            $max = $this->maximumSteps();
            $stepsReduced = $statedSteps > $max;
            $steps = min($statedSteps, $max);
            $stepsOrigin = $stepsReduced ? CeilingOrigin::UserReducedToMaximum : CeilingOrigin::User;
        }

        $spend = $this->defaultSpend();
        $spendOrigin = CeilingOrigin::Default;
        $spendReduced = false;
        $statedSpend = $this->positiveDecimal($stated?->maxSpend);
        if ($statedSpend !== null) {
            $max = $this->maximumSpend();
            $spendReduced = bccomp($statedSpend, $max, 10) > 0;
            $spend = $this->min($statedSpend, $max);
            $spendOrigin = $spendReduced ? CeilingOrigin::UserReducedToMaximum : CeilingOrigin::User;
        }

        return [
            'steps' => $steps,
            'spend' => $spend,
            'steps_origin' => $stepsOrigin,
            'spend_origin' => $spendOrigin,
            'steps_reduced' => $stepsReduced,
            'spend_reduced' => $spendReduced,
        ];
    }

    public function clampAllowance(int $currentSteps, ?int $addSteps, string $currentSpend, ?string $addSpend): array
    {
        $current = Decimal::round(Decimal::toPlainNotation($currentSpend), 10);

        $grantSteps = null;
        $steps = $currentSteps;
        $stepsReduced = false;
        if ($addSteps !== null) {
            $room = max(0, $this->maximumSteps() - $currentSteps);
            $grantSteps = max(0, min($addSteps, $room));
            $stepsReduced = $grantSteps < $addSteps;
            $steps = $currentSteps + $grantSteps;
        }

        $grantSpend = null;
        $spend = $current;
        $spendReduced = false;
        if ($addSpend !== null) {
            $add = Decimal::round(Decimal::toPlainNotation($addSpend), 10);
            $room = bcsub($this->maximumSpend(), $current, 10);
            if (bccomp($room, '0', 10) < 0) {
                $room = '0.0000000000';
            }
            $grantSpend = bccomp($add, $room, 10) > 0 ? $room : $add;
            $spendReduced = bccomp($grantSpend, $add, 10) < 0;
            $spend = bcadd($current, $grantSpend, 10);
        }

        return [
            'add_steps' => $grantSteps,
            'steps' => $steps,
            'add_spend' => $grantSpend,
            'spend' => $spend,
            'steps_reduced' => $stepsReduced,
            'spend_reduced' => $spendReduced,
        ];
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $float = (float) $value;
        if ($float < 1) {
            return null;
        }

        return $float >= self::HARD_MAX_STEPS ? self::HARD_MAX_STEPS : (int) $value;
    }

    private function decimal(mixed $value): ?string
    {
        if (is_bool($value) || $value === null || $value === '') {
            return null;
        }
        $plain = Decimal::toPlainNotation(is_float($value) ? Decimal::fromNumeric($value) : trim((string) $value));
        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $plain)) {
            return null;
        }

        return Decimal::round($plain, 10);
    }

    private function positiveDecimal(mixed $value): ?string
    {
        $decimal = $this->decimal($value);

        return ($decimal !== null && bccomp($decimal, '0', 10) > 0) ? $decimal : null;
    }

    private function min(string $a, string $b): string
    {
        $a = Decimal::round($a, 10);
        $b = Decimal::round($b, 10);

        return bccomp($a, $b, 10) <= 0 ? $a : $b;
    }
}
