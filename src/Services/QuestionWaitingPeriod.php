<?php

namespace ClarionApp\LlmClient\Services;

/**
 * How long a question stays answerable: the period the agent asked for, or
 * the installation default when it asked for none, always brought within
 * the installation's minimum and maximum — never refused.
 *
 * Every configured value is first capped at CEILING_MINUTES, so no
 * configuration can overflow the stored period or push a deadline past
 * what a MySQL TIMESTAMP holds. The minimum is at least one minute, and a
 * maximum configured below the minimum yields to the minimum, so a
 * misconfiguration can never make a question expire sooner than the floor.
 *
 * Configuration is read on every call; a question keeps the period it was
 * given when it was asked.
 */
class QuestionWaitingPeriod
{
    /** One year of minutes. */
    public const CEILING_MINUTES = 525600;

    /** The effective period, in minutes, for a requested one (null: none requested). */
    public function effectiveMinutes(?int $requested): int
    {
        $bounds = $this->bounds();

        if ($requested === null) {
            return $bounds['default'];
        }

        return min($bounds['max'], max($bounds['min'], $requested));
    }

    /** @return array{default: int, min: int, max: int} the bounds in force, after capping and clamping */
    public function bounds(): array
    {
        $min = max(1, self::capped(config('llm-client.agent_questions.min_expiry_minutes', 60)));
        $max = max($min, self::capped(config('llm-client.agent_questions.max_expiry_minutes', 10080)));
        $default = min($max, max($min, self::capped(config('llm-client.agent_questions.expiry_minutes', 1440))));

        return ['default' => $default, 'min' => $min, 'max' => $max];
    }

    private static function capped(mixed $minutes): int
    {
        if (!is_numeric($minutes)) {
            return 0;
        }

        return (int) max(-self::CEILING_MINUTES, min(self::CEILING_MINUTES, (float) $minutes));
    }
}
