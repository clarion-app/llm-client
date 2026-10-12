<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Support\Decimal;
use ClarionApp\LlmClient\ValueObjects\CeilingDecision;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetStanding;

/**
 * Reads a reply to a task-budget ceiling prompt: "1"/"continue" (optionally
 * followed by "steps=N" and/or "spend=X"), "2"/"accept" or "3"/"stop", in any
 * case. Given the task's standing and the offered allowance, a continue is
 * checked against the task: a reached ceiling with no stated allowance gets
 * the offered one, each addition is cut to the installation maximum, and an
 * allowance that would not permit one more step is refused with guidance.
 */
class CeilingDecisionParser
{
    public function __construct(private readonly TaskBudgetText $text = new TaskBudgetText())
    {
    }

    public function parse(string $raw, ?TaskBudgetStanding $against = null, ?int $offeredSteps = null, ?string $offeredSpend = null): CeilingDecision
    {
        $trimmed = trim($raw);
        $tokens = $trimmed === '' ? [] : preg_split('/\s+/', $trimmed);
        if ($tokens === []) {
            return $this->invalid(TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => '']);
        }
        $kind = match (strtolower($tokens[0])) {
            '1', 'continue' => CeilingDecision::CONTINUE,
            '2', 'accept' => CeilingDecision::ACCEPT,
            '3', 'stop' => CeilingDecision::STOP,
            default => null,
        };
        if ($kind === null) {
            return $this->invalid(TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => $trimmed]);
        }
        $pairs = [];
        foreach (array_slice($tokens, 1) as $token) {
            if (preg_match('/^(steps|spend)=(.*)$/i', $token, $m) !== 1) {
                return $this->invalid(TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => $trimmed]);
            }
            $pairs[] = [strtolower($m[1]), $m[2]];
        }
        if ($kind !== CeilingDecision::CONTINUE) {
            if ($pairs !== []) {
                return $this->invalid(TaskBudgetText::PROBLEM_ALLOWANCE_WITHOUT_CONTINUE);
            }

            return $kind === CeilingDecision::ACCEPT ? CeilingDecision::accept() : CeilingDecision::stop();
        }
        $steps = null;
        $spend = null;
        $givenSteps = null;
        $givenSpend = null;
        $seen = [];
        foreach ($pairs as [$key, $value]) {
            if (isset($seen[$key])) {
                return $this->invalid(TaskBudgetText::PROBLEM_REPEATED_KEY, ['key' => $key]);
            }
            $seen[$key] = true;
            if ($key === 'steps') {
                if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
                    return $this->invalid(TaskBudgetText::PROBLEM_INVALID_STEPS, ['value' => $value]);
                }
                $steps = (int) $value;
                $givenSteps = $value;
            } else {
                if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1 || bccomp($value, '0', 10) <= 0) {
                    return $this->invalid(TaskBudgetText::PROBLEM_INVALID_SPEND, ['value' => $value]);
                }
                $spend = Decimal::round($value, 10);
                $givenSpend = $value;
            }
        }
        if ($against === null) {
            return CeilingDecision::continue($steps, $spend);
        }

        $reached = $against->reached();
        $stepsReduced = false;
        $spendReduced = false;
        $addSteps = $steps ?? (in_array('steps', $reached, true) ? (int) ($offeredSteps ?? 0) : null);
        if ($addSteps !== null) {
            $room = max(0, $against->maximumSteps - $against->maxSteps);
            $grant = max(0, min($addSteps, $room));
            $stepsReduced = $grant < $addSteps;
            if (in_array('steps', $reached, true) && $against->maxSteps + $grant <= $against->stepsUsed) {
                return $room === 0
                    ? $this->invalid(TaskBudgetText::PROBLEM_AT_MAXIMUM)
                    : $this->invalid(TaskBudgetText::PROBLEM_NO_ROOM_STEPS, ['given' => $givenSteps !== null ? (int) $givenSteps : $addSteps, 'used' => $against->stepsUsed]);
            }
            $addSteps = $grant;
        }
        $addSpend = $spend ?? (in_array('spend', $reached, true) ? Decimal::round(Decimal::toPlainNotation($offeredSpend ?? '0'), 10) : null);
        if ($addSpend !== null) {
            $max = Decimal::round(Decimal::toPlainNotation($against->maxSpend), 10);
            $room = bcsub(Decimal::round(Decimal::toPlainNotation($against->maximumSpend), 10), $max, 10);
            if (bccomp($room, '0', 10) < 0) {
                $room = '0.0000000000';
            }
            $grant = bccomp($addSpend, $room, 10) > 0 ? $room : $addSpend;
            $spendReduced = bccomp($grant, $addSpend, 10) < 0;
            if (in_array('spend', $reached, true) && bccomp(bcadd($max, $grant, 10), Decimal::round(Decimal::toPlainNotation($against->spendUsed), 10), 10) <= 0) {
                return bccomp($room, '0', 10) === 0
                    ? $this->invalid(TaskBudgetText::PROBLEM_AT_MAXIMUM)
                    : $this->invalid(TaskBudgetText::PROBLEM_NO_ROOM_SPEND, ['given' => $givenSpend ?? Decimal::round($addSpend, 2), 'used' => $against->spendUsed, 'currency' => $against->currency]);
            }
            $addSpend = $grant;
        }

        return CeilingDecision::continue($addSteps, $addSpend, $stepsReduced, $spendReduced);
    }

    private function invalid(string $problem, array $params = []): CeilingDecision
    {
        return CeilingDecision::invalid($this->text->decisionGuidance($problem, $params));
    }
}
