<?php

namespace ClarionApp\LlmClient\Casts;

use ClarionApp\LlmClient\Support\Decimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Restores a `decimal(x,y)`-backed attribute to the exact plain-decimal
 * string it was written as, on read, without ever introducing a native
 * numeric cast (which would form a float on this class's own account).
 *
 * Several `decimal` columns in this package (`usage_records`' four cost
 * columns, `model_prices`' three rate columns) are deliberately left out of
 * a native `$casts` entry so a read never risks silently truncating or
 * float-rounding a high-precision value — the value is meant to come back
 * exactly as the string it was stored as (research.md D1 for the cost
 * columns). That held for MariaDB/MySQL in production, where PDO always
 * returns a DECIMAL column as a plain string. It did NOT hold for the
 * SQLite `:memory:` test schema (Constitution §V — no migrations run under
 * test): SQLite gives `decimal(x,y)` NUMERIC storage affinity, and without
 * `PDO::ATTR_STRINGIFY_FETCHES` set, a numeric-looking value comes back as a
 * genuine PHP float — two compounding problems follow from that:
 *
 *  1. A small-magnitude float renders in scientific notation the moment any
 *     caller does the conventional `(string) $value` — e.g. `"1.83E-5"` —
 *     which bcmath (and a naive regex validator) rejects as malformed.
 *  2. Even for a value that stringifies in plain notation, a double cannot
 *     exactly represent most decimal fractions (e.g. 0.0000342 has no exact
 *     binary form), so a high-precision render of that double lands a few
 *     ULPs off the true value — typically visible only 15+ digits in (e.g.
 *     `"0.0000341999999999999978171"` instead of `"0.0000342000...".`).
 *     That is far beyond the column's own declared scale and would be
 *     harmless on its own, EXCEPT that bcmath's `scale` parameter
 *     *truncates* rather than rounds: summing many such values with
 *     `bcadd($sum, $value, 10)` truncates each one at the 10th digit rather
 *     than rounding it, turning a below-true float artifact into a real,
 *     accumulating shortfall (confirmed empirically: ~2000 tiny-cost values
 *     under-summed a rollup total by ~9.3e-8 relative to the same values
 *     read back and re-rounded).
 *
 * Rounding to the column's own declared scale — via `Decimal::round()`,
 * which itself now tolerates scientific notation (see that class's own
 * docblock) — resolves both: it is a no-op for a value already exact at
 * that scale (true of every value this package itself ever writes, since
 * `MetricsRecorder`/`ModelPriceService` compute via bcmath and round to the
 * column's scale before storing), and it heals a value perturbed by a float
 * round-trip back to the exact string that was originally written.
 */
class PlainDecimalCast implements CastsAttributes
{
    public function __construct(private readonly int $scale)
    {
    }

    public function get($model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // fromNumeric(), not (string): a plain cast renders a float at PHP's
        // 14-significant-digit `precision` default, which throws away digits
        // the double still holds before this class ever sees them.
        return Decimal::round(Decimal::fromNumeric($value), $this->scale);
    }

    public function set($model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}
