<?php

namespace ClarionApp\LlmClient\Tests\Unit\Support;

use Tests\TestCase;
use ClarionApp\LlmClient\Support\CalendarPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for CalendarPeriod's two additive helpers:
 *
 *   containing(string $type, ?CarbonInterface $at = null): array  // [from, to]
 *   resetsAt(string $type, string $date): CarbonImmutable
 *
 * containing() answers "which period contains this instant" — the form
 * enforcement actually needs, as opposed to resolve()'s "which period
 * contains this date string". resetsAt() answers "when does that period
 * end", as an exclusive upper bound (the day after `to`, at midnight UTC)
 * rather than 23:59:59 of `to`, so a user watching the clock never sees a
 * minute of apparent limbo between the reported reset time and the actual
 * one.
 *
 * The load-bearing property at the bottom of this file is that containing()
 * and resolve() agree exactly for the same anchor: a budget period and the
 * cost_summaries.period_date buckets summed over it are then the same
 * period by construction, not by two implementations happening to match.
 */
class CalendarPeriodResetTest extends TestCase
{
    // ---------------------------------------------------------------
    // containing()
    // ---------------------------------------------------------------

    #[Test]
    public function containing_a_day_returns_that_instants_own_utc_calendar_day_for_both_bounds(): void
    {
        [$from, $to] = CalendarPeriod::containing('day', Carbon::parse('2026-08-07 13:45:12', 'UTC'));

        $this->assertSame('2026-08-07', $from);
        $this->assertSame('2026-08-07', $to);
    }

    #[Test]
    public function containing_defaults_to_now_when_no_instant_is_given(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 23:12:00', 'UTC'));

        try {
            $this->assertSame(['2026-08-07', '2026-08-07'], CalendarPeriod::containing('day'));
            $this->assertSame(['2026-08-03', '2026-08-09'], CalendarPeriod::containing('week'));
            $this->assertSame(['2026-08-01', '2026-08-31'], CalendarPeriod::containing('month'));
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function containing_a_week_returns_monday_through_sunday_of_that_iso_week(): void
    {
        // 2026-08-07 is a Friday; its ISO week runs Mon 2026-08-03 to Sun 2026-08-09.
        $this->assertSame(
            ['2026-08-03', '2026-08-09'],
            CalendarPeriod::containing('week', Carbon::parse('2026-08-07 13:45:12', 'UTC'))
        );

        // A Monday and a Sunday in the same ISO week give the identical range.
        $this->assertSame(
            ['2026-08-03', '2026-08-09'],
            CalendarPeriod::containing('week', Carbon::parse('2026-08-03 00:00:01', 'UTC'))
        );
        $this->assertSame(
            ['2026-08-03', '2026-08-09'],
            CalendarPeriod::containing('week', Carbon::parse('2026-08-09 23:59:59', 'UTC'))
        );
    }

    #[Test]
    public function containing_a_week_stays_pinned_to_monday_when_the_ambient_locale_starts_weeks_on_sunday(): void
    {
        // Carbon's ambient "first day of week" is derived from the active
        // locale's translation data — 'en' says Monday (1), 'en_US' says
        // Sunday (0). There is no setWeekStartsAt() static in Carbon 3.x, so
        // switching the locale is the way to move the ambient default. If
        // containing() ever stops passing the boundary explicitly (or stops
        // routing through resolve(), which does), this turns red.
        $originalLocale = Carbon::getLocale();

        Carbon::setLocale('en_US');

        try {
            // Precondition: the locale switch really did move the ambient
            // default off Monday, otherwise this test passes vacuously.
            $this->assertSame(
                0,
                Carbon::parse('2026-08-07')->utc()->firstWeekDay,
                'Precondition: the ambient locale week-start must actually differ from Monday'
            );

            [$from, $to] = CalendarPeriod::containing('week', Carbon::parse('2026-08-07 13:45:12', 'UTC'));

            $this->assertSame('2026-08-03', $from, 'from must still be the Monday');
            $this->assertSame('2026-08-09', $to, 'to must still be the Sunday');
        } finally {
            Carbon::setLocale($originalLocale);
        }
    }

    #[Test]
    public function containing_a_month_returns_the_first_and_last_day_of_that_utc_calendar_month(): void
    {
        $this->assertSame(
            ['2026-08-01', '2026-08-31'],
            CalendarPeriod::containing('month', Carbon::parse('2026-08-07 13:45:12', 'UTC'))
        );

        // A 28-day month, and a leap February, so the last day is genuinely
        // computed rather than assumed to be the 30th/31st.
        $this->assertSame(
            ['2026-02-01', '2026-02-28'],
            CalendarPeriod::containing('month', Carbon::parse('2026-02-14 06:00:00', 'UTC'))
        );
        $this->assertSame(
            ['2028-02-01', '2028-02-29'],
            CalendarPeriod::containing('month', Carbon::parse('2028-02-14 06:00:00', 'UTC'))
        );
    }

    #[Test]
    public function containing_normalises_a_non_utc_instant_to_its_utc_calendar_period(): void
    {
        // 2026-08-08 01:30 in Asia/Tokyo (UTC+9) is 2026-08-07 16:30 UTC, so
        // the containing UTC day is the 7th, not the 8th.
        $tokyo = Carbon::parse('2026-08-08 01:30:00', 'Asia/Tokyo');

        $this->assertSame(['2026-08-07', '2026-08-07'], CalendarPeriod::containing('day', $tokyo));
    }

    #[Test]
    public function containing_rejects_an_unknown_period_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarPeriod::containing('quarter', Carbon::parse('2026-08-07', 'UTC'));
    }

    // ---------------------------------------------------------------
    // resetsAt()
    // ---------------------------------------------------------------

    #[Test]
    public function resets_at_a_day_is_the_following_midnight_utc_not_the_last_second_of_the_day(): void
    {
        $resetsAt = CalendarPeriod::resetsAt('day', '2026-08-07');

        $this->assertInstanceOf(CarbonImmutable::class, $resetsAt);
        $this->assertSame('2026-08-08T00:00:00+00:00', $resetsAt->toIso8601String());
        $this->assertSame('UTC', $resetsAt->timezoneName);

        // The off-by-one this design exists to avoid: never 23:59:59 of the
        // period's own last day.
        $this->assertNotSame('2026-08-07T23:59:59+00:00', $resetsAt->toIso8601String());
    }

    #[Test]
    public function resets_at_a_week_is_the_monday_after_the_periods_sunday(): void
    {
        // The week containing Fri 2026-08-07 ends Sun 2026-08-09, so it
        // resets at the start of Mon 2026-08-10.
        $resetsAt = CalendarPeriod::resetsAt('week', '2026-08-07');

        $this->assertSame('2026-08-10T00:00:00+00:00', $resetsAt->toIso8601String());

        // Anchoring anywhere in the same week gives the same reset instant.
        $this->assertSame(
            '2026-08-10T00:00:00+00:00',
            CalendarPeriod::resetsAt('week', '2026-08-09')->toIso8601String()
        );
    }

    #[Test]
    public function resets_at_a_month_is_the_first_of_the_following_month(): void
    {
        $this->assertSame(
            '2026-09-01T00:00:00+00:00',
            CalendarPeriod::resetsAt('month', '2026-08-07')->toIso8601String()
        );

        // Across a year boundary.
        $this->assertSame(
            '2027-01-01T00:00:00+00:00',
            CalendarPeriod::resetsAt('month', '2026-12-25')->toIso8601String()
        );
    }

    #[Test]
    public function resets_at_is_exactly_one_day_after_the_periods_inclusive_upper_bound_for_every_type(): void
    {
        foreach (CalendarPeriod::TYPES as $type) {
            $resolved = CalendarPeriod::resolve($type, '2026-08-07');
            $expected = CarbonImmutable::parse($resolved['to'], 'UTC')->addDay()->startOfDay();

            $this->assertSame(
                $expected->toIso8601String(),
                CalendarPeriod::resetsAt($type, '2026-08-07')->toIso8601String(),
                "resetsAt('{$type}') must be the day after the period's inclusive 'to', at midnight UTC"
            );
        }
    }

    #[Test]
    public function resets_at_rejects_an_unknown_period_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarPeriod::resetsAt('quarter', '2026-08-07');
    }

    // ---------------------------------------------------------------
    // The agreement property
    // ---------------------------------------------------------------

    #[Test]
    public function containing_and_resolve_agree_exactly_for_the_same_anchor(): void
    {
        $anchors = ['2026-08-07', '2026-08-03', '2026-08-09', '2026-02-28', '2028-02-29', '2026-12-31', '2027-01-01'];

        foreach ($anchors as $anchor) {
            foreach (CalendarPeriod::TYPES as $type) {
                $resolved = CalendarPeriod::resolve($type, $anchor);
                $contained = CalendarPeriod::containing($type, Carbon::parse($anchor.' 12:00:00', 'UTC'));

                $this->assertSame(
                    [$resolved['from'], $resolved['to']],
                    $contained,
                    "containing('{$type}') must equal resolve('{$type}') for anchor {$anchor}"
                );
            }
        }
    }
}
