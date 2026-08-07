<?php

namespace ClarionApp\LlmClient\Tests\Unit\Support;

use ClarionApp\LlmClient\Support\Decimal;
use InvalidArgumentException;
use Tests\TestCase;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for Decimal::round() — the bcmath-only, half-up-away-from-zero
 * rounding primitive generalized from life-log-backend's Decimal4 (research.md D1).
 */
class DecimalTest extends TestCase
{
    #[Test]
    public function it_rounds_half_up_away_from_zero_at_a_boundary_ending_in_05_at_the_target_scale()
    {
        // At scale 10, a value ending in ...05 at the 11th fractional digit
        // rounds the 10th digit up.
        $result = Decimal::round('1.00000000005', 10);

        $this->assertSame('1.0000000001', $result);
    }

    #[Test]
    public function a_value_already_exact_at_the_target_scale_is_unchanged()
    {
        $result = Decimal::round('12.3400000000', 10);

        $this->assertSame('12.3400000000', $result);
    }

    #[Test]
    public function a_negative_value_rounds_away_from_zero_in_its_own_direction()
    {
        $result = Decimal::round('-1.00000000005', 10);

        $this->assertSame('-1.0000000001', $result);
    }

    #[Test]
    public function zero_rounds_to_a_positive_zero_string_never_negative_zero()
    {
        $this->assertSame('0.0000000000', Decimal::round('0', 10));
        $this->assertSame('0.0000000000', Decimal::round('-0', 10));
        $this->assertSame('0.0000000000', Decimal::round('-0.00000000001', 10));
    }

    #[Test]
    public function a_non_numeric_string_throws()
    {
        $this->expectException(InvalidArgumentException::class);

        Decimal::round('not-a-number', 10);
    }

    /**
     * Reproduces the exact production bug found in the Phase 7 reconciliation
     * test: SQLite's NUMERIC storage affinity for a decimal(20,10) column
     * returns a genuine PHP float rather than a string, and PHP's own
     * `(string)` cast of a small-magnitude float renders scientific
     * notation. Passing that string straight through used to fail the plain
     * decimal regex; it must now normalize and round correctly instead.
     */
    #[Test]
    public function a_scientific_notation_string_from_a_stringified_small_float_normalizes_and_rounds_correctly()
    {
        // (string) 1 reused token x $0.30/million rate = 0.0000003, exactly
        // the shape reported in the bug: PHP renders this as "3.0E-7".
        $scientific = (string) 3.0E-7;
        $this->assertSame('3.0E-7', $scientific, 'Precondition: PHP must actually render this in scientific notation');

        $this->assertSame('0.0000003000', Decimal::round($scientific, 10));
    }

    #[Test]
    public function a_scientific_notation_string_at_a_magnitude_that_does_not_round_cleanly_still_rounds_correctly()
    {
        // (string) cast of a float known to stringify to scientific
        // notation, per the task's exact reproduction case.
        $scientific = (string) 1.83E-5;
        $this->assertSame('1.83E-5', $scientific, 'Precondition: PHP must actually render this in scientific notation');

        $this->assertSame('0.0000183000', Decimal::round($scientific, 10));
    }

    #[Test]
    public function a_negative_scientific_notation_string_rounds_away_from_zero_in_its_own_direction()
    {
        $result = Decimal::round((string) -1.83E-5, 10);

        $this->assertSame('-0.0000183000', $result);
    }

    #[Test]
    public function a_plain_decimal_string_is_never_routed_through_a_float_round_trip()
    {
        // A value with more significant digits than a double can represent
        // exactly — if this were routed through (float) casting it would
        // corrupt, since it already matches the plain-notation fast path and
        // must be returned untouched.
        $this->assertSame(
            '123456789012345678.9000000001',
            Decimal::round('123456789012345678.90000000005', 10)
        );
    }

    #[Test]
    public function to_plain_notation_leaves_a_non_numeric_string_unchanged_for_the_caller_to_reject()
    {
        $this->assertSame('not-a-number', Decimal::toPlainNotation('not-a-number'));
    }
}
