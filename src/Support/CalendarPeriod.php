<?php

namespace ClarionApp\LlmClient\Support;

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
}
