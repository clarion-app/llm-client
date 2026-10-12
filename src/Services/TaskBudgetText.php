<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Support\Decimal;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetStanding;

/**
 * The only producer of user-facing task-budget text. Every message names
 * "this task's budget" so it cannot be mistaken for an account spending
 * ceiling. Amounts are formatted from decimal strings at two places, never
 * through a float.
 */
class TaskBudgetText
{
    public const PROBLEM_UNRECOGNISED = 'unrecognised';
    public const PROBLEM_ALLOWANCE_WITHOUT_CONTINUE = 'allowance_without_continue';
    public const PROBLEM_REPEATED_KEY = 'repeated_key';
    public const PROBLEM_INVALID_STEPS = 'invalid_steps';
    public const PROBLEM_INVALID_SPEND = 'invalid_spend';
    public const PROBLEM_NO_ROOM_STEPS = 'no_room_steps';
    public const PROBLEM_NO_ROOM_SPEND = 'no_room_spend';

    public function standing(TaskBudgetStanding $s): string
    {
        return "This task's budget: ".$this->figures($s);
    }

    public function approachNotice(TaskBudgetStanding $s, array $ceilings): string
    {
        $percent = rtrim(rtrim(bcmul(Decimal::toPlainNotation($s->approachThreshold), '100', 2), '0'), '.');
        $parts = [];
        if (in_array('steps', $ceilings, true)) {
            $parts[] = 'steps='.($s->maxSteps * 2);
        }
        if (in_array('spend', $ceilings, true)) {
            $parts[] = 'spend='.$this->amount(bcmul(Decimal::toPlainNotation($s->maxSpend), '2', 10));
        }

        return "Heads up — this task's budget is {$percent}% used: ".$this->figures($s).'. It will pause for your decision at the ceiling. '
            ."You can raise it now with '/budget ".implode(' ', $parts)."' or from the conversation's limits.";
    }

    public function promptQuestion(TaskBudgetStanding $s): string
    {
        return "This task's budget ceiling was reached. What should happen next?";
    }

    public function choices(?int $offeredSteps, ?string $offeredSpend, string $currency): array
    {
        $parts = [];
        if ($offeredSteps !== null && $offeredSteps > 0) {
            $parts[] = '+'.$offeredSteps.($offeredSteps === 1 ? ' step' : ' steps');
        }
        if ($offeredSpend !== null && bccomp(Decimal::toPlainNotation($offeredSpend), '0', 10) > 0) {
            $parts[] = '+'.$this->amount($offeredSpend).' '.$currency;
        }
        $continue = $parts === []
            ? 'Continue with more allowance (not available — this task is at the installation maximum)'
            : 'Continue with more allowance ('.implode(', ', $parts).')';

        return [$continue, 'Accept what has been done', 'Stop'];
    }

    public function stoppedByChoice(): string
    {
        return "Stopped at this task's budget ceiling, as you asked. Nothing more will run until you decide: reply 'continue' to resume with more allowance or 'accept' to close the task with what has been done.";
    }

    public function expiredStopped(): string
    {
        return "No answer arrived to this task's budget prompt, so work has stopped at the ceiling until you decide. Reply 'continue' to resume with more allowance, 'accept' to close the task with what has been done, or 'stop' to leave it stopped.";
    }

    public function alreadyStopped(): string
    {
        return "Already stopped at this task's budget ceiling. Reply 'continue' to resume it or 'accept' to close it.";
    }

    public function decisionGuidance(?string $problem = null, array $params = []): string
    {
        $base = "This task's budget prompt accepts: 1 or 'continue' (optionally 'steps=N' and/or 'spend=X' for the additional allowance), 2 or 'accept', 3 or 'stop'. To start something new, accept or stop this task first.";
        if ($problem === null) {
            return $base;
        }

        $detail = match ($problem) {
            self::PROBLEM_UNRECOGNISED => ($params['reply'] ?? '') === '' ? 'the reply was empty' : "'{$params['reply']}' is not one of the choices",
            self::PROBLEM_ALLOWANCE_WITHOUT_CONTINUE => "an additional allowance can only be given with 'continue'",
            self::PROBLEM_REPEATED_KEY => "'{$params['key']}' was given more than once",
            self::PROBLEM_INVALID_STEPS => "steps={$params['value']} is not a whole number of at least 1",
            self::PROBLEM_INVALID_SPEND => "spend={$params['value']} is not an amount greater than 0",
            self::PROBLEM_NO_ROOM_STEPS => "steps={$params['given']} would not allow another step: {$params['used']} already used",
            self::PROBLEM_NO_ROOM_SPEND => "spend={$params['given']} would not allow another step: ".$this->amount((string) $params['used'])." {$params['currency']} already spent",
            default => throw new \InvalidArgumentException("Unknown problem: {$problem}"),
        };

        return $base.' This reply was not accepted: '.$detail.'.';
    }

    public function reducedToMaximum(?int $statedSteps, ?int $maximumSteps, ?string $statedSpend, ?string $maximumSpend, string $currency): string
    {
        $stated = [];
        $max = [];
        if ($statedSteps !== null) {
            $stated[] = "{$statedSteps} steps";
            $max[] = "{$maximumSteps} steps";
        }
        if ($statedSpend !== null) {
            $stated[] = $this->amount($statedSpend).' '.$currency;
            $max[] = $this->amount((string) $maximumSpend).' '.$currency;
        }

        return "The ceiling you stated for this task's budget (".implode(', ', $stated).') was reduced to the installation maximum ('.implode(', ', $max).').';
    }

    public function helperStopped(): string
    {
        return "Stopped by this task's budget before finishing.";
    }

    public function managedShortfall(?TaskBudgetStanding $standing = null): string
    {
        $text = "This task's budget ceiling was reached before this part could be completed.";

        return $standing === null ? $text : $text.' '.$this->standing($standing).'.';
    }

    public function sequenceStopped(): string
    {
        return "This task's budget ceiling was reached, so this sequence stopped before this stage.";
    }

    public function consensusStopped(): string
    {
        return "Stopped by this task's budget before answering.";
    }

    public function unavailable(): string
    {
        return "This task's budget could not be checked, so no further work was started.";
    }

    public function noTaskInProgress(): string
    {
        return "No task is running or paused in this conversation, so this task's budget cannot be shown or changed. To set it for your next request, start that request with '/budget steps=50 spend=2.00' on its first line.";
    }

    public function budgetUpdated(TaskBudgetStanding $s, ?string $reducedLine = null): string
    {
        $text = "This task's budget was updated: ".$this->figures($s).'.';

        return $reducedLine === null ? $text : $text.' '.$reducedLine;
    }

    public function directiveMalformed(): string
    {
        return "That '/budget' line was not understood, so this task's budget was not changed. Use the form '/budget steps=50 spend=2.00' (either part may be left out).";
    }

    public function halted(): string
    {
        return "This task's budget ceiling was reached and nobody could be asked, so the task stopped. The work above is kept.";
    }

    public function concludedReason(): string
    {
        return "Concluded at this task's budget ceiling (accepted)";
    }

    public function ceilingReachedReason(): string
    {
        return 'Task budget ceiling reached';
    }

    public function reportHeadline(TaskBudgetStanding $s): string
    {
        $reached = $s->reached();
        if ($reached === []) {
            return $this->unavailable();
        }

        $parts = [];
        if (in_array('steps', $reached, true)) {
            $parts[] = $s->stepsUsed > $s->maxSteps
                ? "the ceiling was lowered to {$s->maxSteps} while {$s->stepsUsed} steps were already used"
                : "steps ({$s->stepsUsed} of {$s->maxSteps})";
        }
        if (in_array('spend', $reached, true)) {
            $over = bcsub(Decimal::toPlainNotation($s->spendUsed), Decimal::toPlainNotation($s->maxSpend), 10);
            $spend = 'spend ('.$this->amount($s->spendUsed).' of '.$this->amount($s->maxSpend).' '.$s->currency;
            if (bccomp($over, '0', 10) > 0) {
                $spend .= ' — '.$this->amount($over).' over, from the step that was already running';
            }
            $parts[] = $spend.')';
        }

        return "This task's budget ceiling was reached: ".implode(' and ', $parts).'.';
    }

    public function reportConsumption(TaskBudgetStanding $s): string
    {
        $text = "Used under this task's budget: {$s->stepsUsed} of {$s->maxSteps} steps, ".$this->amount($s->spendUsed).' of '.$this->amount($s->maxSpend).' '.$s->currency.'.';
        if ($s->approximate) {
            $text .= ' Spend is approximate (some usage was estimated).';
        }
        if ($s->unpricedRequestCount > 0) {
            $text .= ' Spend is incomplete ('.$s->unpricedRequestCount.($s->unpricedRequestCount === 1 ? ' request' : ' requests').' had no configured price).';
        }

        return $text;
    }

    /** @param array<string, int> $toolCounts completed tool calls by name, in first-seen order */
    public function reportDone(array $toolCounts): string
    {
        if ($toolCounts === []) {
            return "Nothing was completed under this task's budget yet.";
        }

        $parts = [];
        foreach ($toolCounts as $name => $count) {
            $parts[] = "{$name} ({$count})";
        }

        return "Completed under this task's budget: ".implode(', ', $parts).'.';
    }

    public function reportLatestProgress(string $progress): string
    {
        return "Latest progress under this task's budget: {$progress}";
    }

    /**
     * @param list<array{name: string, outcome: string, summary: string}> $helpers
     *   outcome: completed|partial|stopped|failed|in_progress
     */
    public function reportHelpers(array $helpers): string
    {
        $words = [
            'completed' => 'completed',
            'partial' => 'partial',
            'stopped' => "stopped by this task's budget",
            'failed' => 'failed',
            'in_progress' => 'still in progress',
        ];
        $parts = [];
        foreach ($helpers as $helper) {
            $line = $helper['name'].': '.($words[$helper['outcome']] ?? $helper['outcome']);
            if ($helper['summary'] !== '') {
                $line .= ' — '.mb_substr($helper['summary'], 0, 200);
            }
            $parts[] = $line;
        }

        return $this->sentence("Helpers under this task's budget: ".implode('; ', $parts));
    }

    /**
     * @param list<string> $descriptions the descriptions of a managed task's
     *   parts that were not accepted, in part order
     */
    public function reportPartsNotAccepted(array $descriptions): string
    {
        $parts = array_map(fn (string $description) => mb_substr($description, 0, 200), $descriptions);

        return $this->sentence("Parts not accepted under this task's budget: ".implode('; ', $parts));
    }

    public function reportNothingOutstanding(): string
    {
        return "Anything the agent had not started yet under this task's budget is not recorded.";
    }

    public function reportNext(string $occasion): string
    {
        return match ($occasion) {
            'paused' => "This task's budget needs your decision: continue with more allowance, accept what has been done, or stop.",
            'accepted' => "This task is closed at this task's budget ceiling; its completed work above is the result.",
            'stopped', 'expired' => "Work has stopped at this task's budget ceiling. Reply 'continue' to resume with more allowance or 'accept' to close the task with what has been done.",
            'halted' => "Nobody could be asked about this task's budget, so the task stopped. The work above is kept.",
            default => throw new \InvalidArgumentException("Unknown report occasion: {$occasion}"),
        };
    }

    /** Ends a sentence with a full stop unless it already ends with one. */
    private function sentence(string $text): string
    {
        return preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
    }

    private function figures(TaskBudgetStanding $s): string
    {
        $text = "{$s->stepsUsed} of {$s->maxSteps} steps, ".$this->amount($s->spendUsed).' of '.$this->amount($s->maxSpend).' '.$s->currency;
        $notes = [];
        if ($s->approximate) {
            $notes[] = 'approximate: some usage was estimated';
        }
        if ($s->unpricedRequestCount > 0) {
            $notes[] = 'incomplete: '.$s->unpricedRequestCount.($s->unpricedRequestCount === 1 ? ' request' : ' requests').' had no price';
        }

        return $notes === [] ? $text : $text.' ('.implode('; ', $notes).')';
    }

    private function amount(string $decimal): string
    {
        return Decimal::round(Decimal::toPlainNotation($decimal), 2);
    }
}
