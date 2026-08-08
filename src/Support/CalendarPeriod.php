<?php

namespace ClarionApp\LlmClient\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Turns a caller's period type (day/week/month) and reference date into the
 * inclusive [from, to] UTC calendar-date range ToolReliabilityQuery sums
 * over (data-model.md §4.3).
 *
 * The week boundary is always Monday-Sunday, pinned explicitly rather than
 * read from Carbon's ambient "first day of week" default (which is derived
 * from the active locale's translation data and can differ, e.g. Sunday
 * under the 'en_US' locale) — every caller gets the same week alignment
 * regardless of what locale happens to be active.
 */
final class CalendarPeriod
{
    public const TYPES = ['day', 'week', 'month'];

    /**
     * @return array{type: string, from: string, to: string} 'from'/'to' are Y-m-d strings.
     */
    public static function resolve(string $type, string $date): array
    {
        $anchor = Carbon::parse($date)->utc();

        [$from, $to] = match ($type) {
            'day' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
            'week' => [
                $anchor->copy()->startOfWeek(Carbon::MONDAY),
                $anchor->copy()->endOfWeek(Carbon::SUNDAY),
            ],
            'month' => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
            default => throw new \InvalidArgumentException("Invalid period type: {$type}"),
        };

        return ['type' => $type, 'from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /**
     * The period *containing* an instant, defaulting to now — the form
     * budget enforcement needs, as opposed to resolve()'s date-string
     * anchor.
     *
     * This deliberately delegates to resolve() rather than repeating the
     * boundary arithmetic, so a budget period and the cost_summaries
     * period_date buckets summed over it are the same period by
     * construction. The instant is normalised to UTC before its calendar
     * date is taken, so an instant expressed in another timezone lands in
     * the UTC period that actually contains it.
     *
     * @return array{0: string, 1: string} The inclusive [from, to] Y-m-d pair.
     */
    public static function containing(string $type, ?CarbonInterface $at = null): array
    {
        $anchor = ($at ? Carbon::instance($at) : Carbon::now())->utc();

        $resolved = self::resolve($type, $anchor->toDateString());

        return [$resolved['from'], $resolved['to']];
    }

    /**
     * The UTC instant at which the period containing $date ends, as an
     * *exclusive* upper bound: the day after the period's inclusive 'to',
     * at 00:00:00Z.
     *
     * Exclusive rather than "23:59:59 of the last day" on purpose — the
     * latter is the classic off-by-one that has a user watching the clock
     * see a minute of apparent limbo between the reset time they were
     * quoted and the reset actually taking effect.
     */
    public static function resetsAt(string $type, string $date): CarbonImmutable
    {
        $resolved = self::resolve($type, $date);

        return CarbonImmutable::parse($resolved['to'], 'UTC')->addDay()->startOfDay();
    }
}
