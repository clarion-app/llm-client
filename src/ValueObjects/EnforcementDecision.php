<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Models\SpendingCeiling;

/**
 * What the gate decided about one unit of work, and why.
 *
 * The `reason` sentence is built here and nowhere else. The 402 body, the
 * broadcast payload, and the recorded run's end_reason all read this one
 * string, so the three can never drift into saying different things about
 * the same refusal — which is the failure mode that turns "we told the user
 * exactly why" into three plausible half-truths.
 *
 * Every monetary figure stays a plain-decimal string. No float is formed
 * anywhere in this class, including in the sentence a human reads: the
 * "human" rendering below trims trailing zeros with string operations and
 * never rounds, so a ceiling of 1000000.0000000001 is reported as itself
 * rather than as a nearby double.
 */
final readonly class EnforcementDecision
{
    public const ALLOW = 'allow';
    public const ALLOW_WITH_WARNING = 'allow_with_warning';
    public const STOP = 'stop';

    /** The refusal code carried in the body when the figure was readable. */
    public const CODE_CEILING_REACHED = 'budget_ceiling_reached';

    /** ...and when it was not: never dressed up as a real crossing. */
    public const CODE_CONSUMPTION_UNAVAILABLE = 'budget_consumption_unavailable';

    /** The two flavours of notice composeNoticeReason() can produce. */
    public const NOTICE_APPROACH = 'approach';
    public const NOTICE_REACHED = 'reached';

    /** Decimal places every stored monetary figure carries. */
    private const SCALE = 10;

    /**
     * @param  string  $outcome  one of ALLOW, ALLOW_WITH_WARNING, STOP
     * @param  SpendingCeiling|null  $governingCeiling  the ceiling that produced the
     *   outcome — the smallest remaining headroom among those that did, ties
     *   broken installation-first. Null only when no ceiling applies at all.
     * @param  ConsumptionSnapshot|null  $snapshot  the figure the governing ceiling
     *   was measured against. Null only in the no-ceiling case, where nothing
     *   was measured because nothing was read: an installation that has not
     *   opted in never reaches the ledger.
     * @param  bool  $degraded  the figure could not be read and the fail-closed
     *   policy applied
     * @param  string|null  $reason  the single plain-language sentence naming the
     *   ceiling amount, the consumption to date, and the reset time
     * @param  ReservationSnapshot|null  $held  what the governing scope currently
     *   holds in reservation, additive to $snapshot for remaining() — null for
     *   every 076-era caller that constructs this class without it, which
     *   leaves remaining() byte-identical to its pre-084 behavior
     */
    public function __construct(
        public string $outcome,
        public ?SpendingCeiling $governingCeiling = null,
        public ?ConsumptionSnapshot $snapshot = null,
        public bool $degraded = false,
        public ?string $reason = null,
        public ?ReservationSnapshot $held = null,
    ) {
    }

    /**
     * Nothing is configured for any applicable scope, so nothing was read
     * and nothing applies.
     */
    public static function noCeilingConfigured(): self
    {
        return new self(self::ALLOW);
    }

    public function isStop(): bool
    {
        return $this->outcome === self::STOP;
    }

    /**
     * Which of the two enforcement axes the governing ceiling sits on.
     *
     * A `user_default` row governs *a user*, so it reports "user" — the
     * scope_type names where the row came from, this names whose allowance
     * was exhausted, and a refusal has to say the latter or it sends the
     * reader to change the wrong setting.
     */
    public function governingScope(): ?string
    {
        if ($this->governingCeiling === null) {
            return null;
        }

        return $this->governingCeiling->scope_type === BudgetScope::Installation->value
            ? BudgetScope::Installation->value
            : 'user';
    }

    /**
     * Headroom left, floored at zero.
     *
     * Floored deliberately: a ceiling lowered below what has already been
     * spent leaves a scope genuinely over, and reporting "-30.00 remaining"
     * invites an interface to render a negative allowance. The *unfloored*
     * figure is what chooses the governing ceiling, and that comparison
     * lives in BudgetGate — the two are different questions.
     *
     * When $held is present and available, it is netted out alongside
     * $snapshot: a currently-held reservation is allowance that is already
     * spoken for, even though nothing has been recorded as spent yet, so a
     * standing report's remaining figure must already reflect it (contracts
     * §1) — otherwise a predictive decline (US1) would refuse work the same
     * report just claimed there was room for. $held being null or
     * unavailable leaves this method byte-identical to its pre-084 shape.
     */
    public function remaining(): ?string
    {
        if ($this->governingCeiling === null
            || $this->governingCeiling->amount === null
            || $this->snapshot === null
            || !$this->snapshot->available) {
            return null;
        }

        $remaining = bcsub((string) $this->governingCeiling->amount, (string) $this->snapshot->amount, self::SCALE);

        if ($this->held !== null && $this->held->available) {
            $remaining = bcsub($remaining, (string) $this->held->amount, self::SCALE);
        }

        return bccomp($remaining, '0', self::SCALE) < 0
            ? bcadd('0', '0', self::SCALE)
            : $remaining;
    }

    /** The wire shape of a ceiling, or null when no ceiling applies. */
    public function ceilingArray(): ?array
    {
        $ceiling = $this->governingCeiling;

        if ($ceiling === null) {
            return null;
        }

        return [
            'id' => $ceiling->id,
            'scope_type' => $ceiling->scope_type,
            'scope_id' => $ceiling->scope_id,
            'amount' => $ceiling->amount === null ? null : (string) $ceiling->amount,
            'period_type' => $ceiling->period_type,
            'enforcement_mode' => $ceiling->enforcement_mode,
            'approach_threshold' => $ceiling->approach_threshold === null
                ? null
                : (string) $ceiling->approach_threshold,
            'waived' => (bool) $ceiling->waived,
        ];
    }

    /** The wire shape of a period, or null when nothing was measured. */
    public function periodArray(): ?array
    {
        if ($this->snapshot === null) {
            return null;
        }

        return [
            'type' => $this->snapshot->periodType,
            'from' => $this->snapshot->periodFrom,
            'to' => $this->snapshot->periodTo,
            'resets_at' => $this->snapshot->resetsAt->toIso8601String(),
        ];
    }

    public function code(): string
    {
        return $this->degraded ? self::CODE_CONSUMPTION_UNAVAILABLE : self::CODE_CEILING_REACHED;
    }

    /**
     * The refusal body. `work_kind` is the caller's, because the decision
     * itself is identical for every kind of work — the kind selects only the
     * surface a refusal is delivered on, never the rule that produced it.
     */
    public function toArray(BudgetWorkKind $kind): array
    {
        return [
            'code' => $this->code(),
            'message' => $this->reason,
            'ceiling' => $this->ceilingArray(),
            'period' => $this->periodArray(),
            'consumption' => $this->snapshot?->toArray(),
            'governing_scope' => $this->governingScope(),
            'work_kind' => $kind->value,
            'degraded' => $this->degraded,
        ];
    }

    /**
     * Compose the one sentence every surface reuses.
     *
     * Three facts, always, in plain language: what the limit is, how much
     * has been recorded against it, and when the period turns over — plus
     * the approximation caveat, which appears in the sentence as well as in
     * its own field because a human reading the message must not have to
     * find the caveat elsewhere.
     */
    public static function composeReason(
        SpendingCeiling $ceiling,
        ConsumptionSnapshot $snapshot,
        string $governingScope,
        bool $degraded,
    ): string {
        return self::compose('Work stopped: ', $ceiling, $snapshot, $governingScope, $degraded);
    }

    /**
     * The same sentence, said about something that did *not* stop the work.
     *
     * A warning and a warn-mode ceiling both let work through, so the
     * refusal sentence's opening clause — "Work stopped" — would be a plain
     * falsehood on either. Only the opening clause differs: the three facts
     * and the caveat that follow are composed by the same code as the
     * refusal's, which is the whole point of the sentence living in this
     * class. A notice that composed its own message would drift from the
     * refusal the first time either was reworded, and the reader would have
     * no way to tell which of the two was current.
     *
     * @param  string  $kind  NOTICE_APPROACH | NOTICE_REACHED
     */
    public static function composeNoticeReason(
        SpendingCeiling $ceiling,
        ConsumptionSnapshot $snapshot,
        string $governingScope,
        string $kind,
    ): string {
        $opening = $kind === self::NOTICE_REACHED
            ? 'Spending ceiling reached: '
            : 'Approaching a spending ceiling: ';

        return self::compose($opening, $ceiling, $snapshot, $governingScope, degraded: false);
    }

    /**
     * The shared body of every sentence this class produces: an opening
     * clause chosen by the caller, then the amount, the consumption to
     * date, the reset time, and the caveat — in that order, always.
     */
    private static function compose(
        string $opening,
        SpendingCeiling $ceiling,
        ConsumptionSnapshot $snapshot,
        string $governingScope,
        bool $degraded,
    ): string {
        $currency = (string) config('llm-client.cost.currency', 'USD');
        $whose = $governingScope === BudgetScope::Installation->value
            ? 'this installation'
            : 'your account';
        $period = self::periodAdjective($ceiling->period_type);
        $resetsAt = $snapshot->resetsAt->format('Y-m-d H:i').' UTC';

        if ($degraded || !$snapshot->available) {
            return $opening.sprintf(
                'the %s spending ceiling for %s is %s %s, but the amount recorded '
                .'against it could not be read, so new work is being refused until it can be. '
                .'The period resets on %s. Any figure here would be approximate — the cost of a '
                .'unit of work is only known once that work completes.',
                $period,
                $whose,
                self::human((string) $ceiling->amount),
                $currency,
                $resetsAt,
            );
        }

        return $opening.sprintf(
            'the %s spending ceiling for %s is %s %s and %s %s has been recorded '
            .'against it so far. The period resets on %s. This figure is approximate — the cost '
            .'of a unit of work is only known once that work completes.',
            $period,
            $whose,
            self::human((string) $ceiling->amount),
            $currency,
            self::human((string) $snapshot->amount),
            $currency,
            $resetsAt,
        );
    }

    private static function periodAdjective(string $periodType): string
    {
        return match ($periodType) {
            'day' => 'daily',
            'week' => 'weekly',
            'month' => 'monthly',
            default => $periodType,
        };
    }

    /**
     * A stored 10-decimal-place figure as a person would write it: trailing
     * zeros dropped, but never below two places, and never rounded. String
     * operations only — rounding to two places here would put "0.00" in a
     * message about a real, tiny amount.
     */
    private static function human(string $amount): string
    {
        if (!str_contains($amount, '.')) {
            return $amount.'.00';
        }

        [$whole, $fraction] = explode('.', $amount, 2);
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, 2, '0');

        return $whole.'.'.$fraction;
    }
}
