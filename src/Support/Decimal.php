<?php

namespace ClarionApp\LlmClient\Support;

use InvalidArgumentException;

/**
 * Rounds a numeric string to a caller-chosen scale, half-up away from zero.
 *
 * Pure bcmath: the value goes in as a string and comes out as a string, and no
 * float is intentionally formed (research.md D1). Generalized from
 * `life-log-backend/src/Support/Decimal4.php`, which is identical except for
 * a hardcoded scale of 4 — this class parameterizes the scale instead.
 *
 * Half-up away from zero is chosen over PHP's default half-to-even because it
 * is the rule a reader checking a converted value by hand will apply.
 *
 * One float DOES reach this class in practice, unintentionally: SQLite gives
 * a `decimal(x,y)` column NUMERIC storage affinity, and — unlike MariaDB/
 * MySQL, which always return a DECIMAL column as a plain string over PDO —
 * SQLite stores/returns a numeric-looking value as a native REAL when Laravel's
 * SQLite connector doesn't set `PDO::ATTR_STRINGIFY_FETCHES`. A caller doing
 * the conventional `(string) $eloquentAttribute` cast on a small value (e.g.
 * a tiny per-token cost) then hands this class a scientific-notation string
 * such as `"1.83E-5"`, which the plain-decimal regex below would otherwise
 * reject outright — see toPlainNotation(). This is a read-side
 * quirk of the `:memory:` test schema only (production runs on MariaDB); the
 * normalization is applied unconditionally so the same code path is exact in
 * both environments and never depends on which one is running.
 */
final class Decimal
{
    /** Extra working precision beyond the target scale for the intermediate add. */
    private const WORKING_SCALE_PADDING = 8;

    /**
     * Decimal places kept when re-rendering a scientific-notation/other
     * PHP-numeric-but-non-plain input via a float round-trip (see
     * normalizeToPlainNotation()) — comfortably beyond any scale this class
     * is asked to round to (the largest in this codebase is 10), so the
     * round-trip never loses precision the target scale would have kept.
     */
    private const NORMALIZATION_PRECISION = 25;

    /**
     * @param  string  $value  a numeric string. Ordinarily never a float —
     *   but see toPlainNotation() for the one tolerated exception.
     * @param  int  $scale  target number of fractional digits, e.g. 10
     * @return string a $scale-decimal-place string, e.g. "70.3070000000"
     */
    public static function round(string $value, int $scale): string
    {
        $value = trim($value);
        $value = self::toPlainNotation($value);

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

    /**
     * Renders any well-formed PHP-numeric input in plain decimal notation,
     * so a caller's bcmath call or regex validation (which only ever accept
     * plain notation, on purpose — bcmath itself rejects scientific notation
     * as malformed) never rejects a value just because of how it happened to
     * be rendered upstream. Public so any other decimal-column read site in
     * this package (e.g. a `ModelPrice` rate) can normalize defensively
     * before its own bcmath call, not only through round().
     *
     * Already-plain input (the overwhelming common case — a real bcmath
     * result, or a DECIMAL column read back as a string from MariaDB) is
     * returned untouched, with no float ever formed. A non-plain-but-numeric
     * input (scientific notation, e.g. from `(string)` on a PHP float) is
     * re-rendered via a float round-trip at high fixed precision — the
     * precision lost in that round-trip is strictly less than what a
     * `(string)` cast already lost in producing the scientific-notation
     * string in the first place, so this never discards information the
     * caller still had. A non-numeric input is returned unchanged so a
     * caller's own validation throws with the original value in its
     * message, preserving the existing "not a numeric string" guarantee —
     * scientific notation is numeric and must be accepted; garbage must
     * still be rejected.
     */
    public static function toPlainNotation(string $value): string
    {
        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $value)) {
            return $value;
        }

        if (!is_numeric($value)) {
            return $value;
        }

        return sprintf('%.'.self::NORMALIZATION_PRECISION.'F', (float) $value);
    }
}
