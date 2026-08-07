<?php

namespace ClarionApp\LlmClient\Support;

use InvalidArgumentException;

/**
 * Rounds a numeric string to a caller-chosen scale, half-up away from zero.
 *
 * Pure bcmath: the value goes in as a string and comes out as a string, and no
 * float is ever formed (research.md D1). Generalized from
 * `life-log-backend/src/Support/Decimal4.php`, which is identical except for
 * a hardcoded scale of 4 — this class parameterizes the scale instead.
 *
 * Half-up away from zero is chosen over PHP's default half-to-even because it
 * is the rule a reader checking a converted value by hand will apply.
 */
final class Decimal
{
    /** Extra working precision beyond the target scale for the intermediate add. */
    private const WORKING_SCALE_PADDING = 8;

    /**
     * @param  string  $value  a numeric string, never a float
     * @param  int  $scale  target number of fractional digits, e.g. 10
     * @return string a $scale-decimal-place string, e.g. "70.3070000000"
     */
    public static function round(string $value, int $scale): string
    {
        $value = trim($value);

        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Not a numeric string: '{$value}'");
        }

        $value = ltrim($value, '+');

        $workingScale = $scale + self::WORKING_SCALE_PADDING;

        // Half of the last retained digit at $scale, e.g. "0.00000000005" at
        // scale 10 — $scale zeros then a 5.
        $halfUlp = '0.'.str_repeat('0', $scale).'5';

        // Add half of the last retained digit in the value's own direction, then
        // truncate — bcmath truncates toward zero, so this rounds away from it.
        if (bccomp($value, '0', $workingScale) < 0) {
            $halfUlp = '-'.$halfUlp;
        }

        $rounded = bcadd(bcadd($value, $halfUlp, $workingScale), '0', $scale);

        // "-0.0000000000" is the same quantity as "0.0000000000" but a
        // different string, and stored values are compared as strings.
        $negativeZero = '-0.'.str_repeat('0', $scale);
        if ($rounded === $negativeZero) {
            $rounded = substr($rounded, 1);
        }

        return $rounded;
    }
}
