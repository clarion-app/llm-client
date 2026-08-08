<?php

namespace ClarionApp\LlmClient\Tests\Unit\Support;

use Tests\TestCase;
use ClarionApp\LlmClient\Support\CalendarPeriod;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for CalendarPeriod::resolve() — turns a caller's period type
 * and reference date into the inclusive [from, to] UTC calendar-date range
 * ToolReliabilityQuery sums over (data-model.md §4.3, research.md D8).
 */
class CalendarPeriodTest extends TestCase
{
    #[Test]
    public function a_day_period_resolves_to_the_dates_own_utc_calendar_date_for_both_bounds(): void
    {
        $result = CalendarPeriod::resolve('day', '2026-08-07');

        $this->assertSame('day', $result['type']);
        $this->assertSame('2026-08-07', $result['from']);
        $this->assertSame('2026-08-07', $result['to']);
    }

    #[Test]
    public function a_week_period_resolves_to_the_monday_through_sunday_of_that_iso_week_regardless_of_weekday(): void
    {
        // 2026-08-07 is a Friday. The ISO week it falls in runs Monday
        // 2026-08-03 through Sunday 2026-08-09.
        $friday = CalendarPeriod::resolve('week', '2026-08-07');
        $this->assertSame('2026-08-03', $friday['from']);
        $this->assertSame('2026-08-09', $friday['to']);

        // A Monday and a Sunday within the same ISO week must resolve to
        // the identical range.
        $monday = CalendarPeriod::resolve('week', '2026-08-03');
        $this->assertSame('2026-08-03', $monday['from']);
        $this->assertSame('2026-08-09', $monday['to']);

        $sunday = CalendarPeriod::resolve('week', '2026-08-09');
        $this->assertSame('2026-08-03', $sunday['from']);
        $this->assertSame('2026-08-09', $sunday['to']);
    }

    #[Test]
    public function the_week_boundary_is_pinned_to_monday_even_when_the_ambient_locale_week_start_has_been_mutated(): void
    {
        // Carbon's ambient "first day of week" comes from the active
        // locale's translation data — 'en' defines Monday (1), 'en_US'
        // defines Sunday (0). Switching the active locale to 'en_US' proves
        // CalendarPeriod::resolve() still returns a Monday-Sunday range only
        // if the Monday/Sunday boundary is passed explicitly to
        // startOfWeek()/endOfWeek() rather than read from this ambient
        // default (mutation-checklist row 6).
        $originalLocale = Carbon::getLocale();

        Carbon::setLocale('en_US');

        try {
            // Precondition: the locale switch really did change the ambient
            // default away from Monday, otherwise this test would pass
            // vacuously regardless of whether the boundary is pinned.
            $this->assertSame(
                0,
                Carbon::parse('2026-08-07')->utc()->firstWeekDay,
                'Precondition: the ambient locale week-start must actually differ from Monday'
            );

            $result = CalendarPeriod::resolve('week', '2026-08-07');

            $this->assertSame('2026-08-03', $result['from'], 'from must still be the Monday');
            $this->assertSame('2026-08-09', $result['to'], 'to must still be the Sunday');
        } finally {
            Carbon::setLocale($originalLocale);
        }
    }

    #[Test]
    public function a_month_period_resolves_to_the_first_and_last_day_of_the_dates_own_utc_calendar_month(): void
    {
        $midMonth = CalendarPeriod::resolve('month', '2026-08-15');
        $this->assertSame('2026-08-01', $midMonth['from']);
        $this->assertSame('2026-08-31', $midMonth['to']);

        // February in a non-leap year.
        $february = CalendarPeriod::resolve('month', '2026-02-14');
        $this->assertSame('2026-02-01', $february['from']);
        $this->assertSame('2026-02-28', $february['to']);
    }

    #[Test]
    public function an_invalid_period_type_throws_an_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarPeriod::resolve('fortnight', '2026-08-07');
    }

    #[Test]
    public function the_returned_type_key_echoes_the_input_type_verbatim(): void
    {
        $this->assertSame('day', CalendarPeriod::resolve('day', '2026-08-07')['type']);
        $this->assertSame('week', CalendarPeriod::resolve('week', '2026-08-07')['type']);
        $this->assertSame('month', CalendarPeriod::resolve('month', '2026-08-07')['type']);
    }
}
