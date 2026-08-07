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
}
