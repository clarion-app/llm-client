<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Support\CalendarPeriod;
use ClarionApp\LlmClient\Support\Decimal;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\EnforcementMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place a `spending_ceilings` row is ever written — installation
 * ceilings, the per-user default, a single user's override, and a waiver
 * alike. Nothing else in this package creates, updates, or deletes one.
 *
 * That matters more here than it would for an ordinary model, because the
 * table carries a *plain* index on (scope_type, scope_id) rather than a
 * unique one: soft deletes and a unique constraint interact badly in both
 * directions (a soft-deleted row occupies the key; adding deleted_at to the
 * constraint lets two live rows through on MySQL, where every NULL is
 * distinct). "Exactly one live row per scope" is therefore a property of
 * this class alone, upheld by upsert()'s withTrashed() lookup, which
 * restores and updates a soft-deleted row rather than inserting a duplicate.
 *
 * Invalid input is rejected with \InvalidArgumentException — the convention
 * this package's other services already use — and rejection happens in full
 * before any row is touched, so a refused write leaves the table byte for
 * byte as it was. Mapping a rejection to an HTTP 422 is the controller's
 * job, not this class's.
 *
 * Every monetary and proportional value is handled as a plain-decimal
 * string throughout. No float is intentionally formed anywhere in this
 * class.
 */
class SpendingCeilingService
{
    /** Decimal places accepted (and stored) for a ceiling amount. */
    private const AMOUNT_SCALE = 10;

    /** Decimal places accepted (and stored) for an approach threshold. */
    private const THRESHOLD_SCALE = 4;

    /**
     * Create the ceiling for a scope, or update the one that is already
     * there. A scope whose only row is soft-deleted has that row restored
     * and updated rather than a second one inserted.
     *
     * @param  array<string, mixed>  $attributes  amount, period_type,
     *   enforcement_mode, approach_threshold, waived — validated here.
     *
     * @throws \InvalidArgumentException when any attribute is invalid; no
     *   row is created, changed, restored, or deleted in that case.
     */
    public function upsert(BudgetScope $scopeType, string $scopeId, array $attributes): SpendingCeiling
    {
        // Validation runs to completion before the transaction opens: a
        // rejected upsert must write nothing at all, not even a row it
        // would immediately roll back.
        $values = $this->validated($scopeType, $scopeId, $attributes);

        return DB::transaction(function () use ($scopeType, $scopeId, $values) {
            $existing = $this->existingRow($scopeType, $scopeId);

            if ($existing === null) {
                return SpendingCeiling::create($values);
            }

            if ($existing->trashed()) {
                $existing->restore();
            }

            $existing->fill($values);
            $existing->save();

            return $existing;
        });
    }

    /**
     * Remove a scope's ceiling. A soft delete: the row survives as history
     * and a later upsert for the same scope restores it.
     */
    public function remove(BudgetScope $scopeType, string $scopeId): void
    {
        $existing = SpendingCeiling::query()
            ->where('scope_type', $scopeType->value)
            ->where('scope_id', $scopeId)
            ->first();

        $existing?->delete();
    }

    /**
     * Every live ceiling row, of every scope kind.
     *
     * @return Collection<int, SpendingCeiling>
     */
    public function list(): Collection
    {
        return SpendingCeiling::query()
            ->orderBy('scope_type')
            ->orderBy('scope_id')
            ->get();
    }

    /**
     * The ceiling that applies to one user: their own override if they have
     * one, otherwise the installation-wide per-user default, otherwise
     * none. A waived row resolves to none — a waiver is the absence of a
     * user-scoped ceiling, not a ceiling of unlimited size.
     */
    public function resolveForUser(string $userId): ?SpendingCeiling
    {
        $row = SpendingCeiling::query()
            ->where('scope_type', BudgetScope::User->value)
            ->where('scope_id', $userId)
            ->first();

        if ($row === null) {
            $row = SpendingCeiling::query()
                ->where('scope_type', BudgetScope::UserDefault->value)
                ->where('scope_id', SpendingCeiling::INSTALLATION_SCOPE_ID)
                ->first();
        }

        if ($row === null || $row->waived) {
            return null;
        }

        return $row;
    }

    /**
     * The installation-wide ceiling, or none.
     *
     * This never consults the user chain, which is what makes it
     * structurally impossible for a user-scoped waiver to waive the
     * installation-wide ceiling — no data state can produce that outcome,
     * because no data state is consulted that could.
     */
    public function resolveInstallation(): ?SpendingCeiling
    {
        return SpendingCeiling::query()
            ->where('scope_type', BudgetScope::Installation->value)
            ->where('scope_id', SpendingCeiling::INSTALLATION_SCOPE_ID)
            ->first();
    }

    /**
     * The row for a scope, preferring a live one and falling back to a
     * soft-deleted one so it can be restored rather than duplicated.
     */
    private function existingRow(BudgetScope $scopeType, string $scopeId): ?SpendingCeiling
    {
        $live = SpendingCeiling::query()
            ->where('scope_type', $scopeType->value)
            ->where('scope_id', $scopeId)
            ->first();

        if ($live !== null) {
            return $live;
        }

        return SpendingCeiling::withTrashed()
            ->where('scope_type', $scopeType->value)
            ->where('scope_id', $scopeId)
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed> the column values to write
     *
     * @throws \InvalidArgumentException
     */
    private function validated(BudgetScope $scopeType, string $scopeId, array $attributes): array
    {
        if (trim($scopeId) === '') {
            throw new \InvalidArgumentException('A spending ceiling requires a scope id.');
        }

        $waived = $this->validatedWaived($scopeType, $attributes);

        return [
            'scope_type' => $scopeType->value,
            'scope_id' => $scopeId,
            'amount' => $this->validatedAmount($attributes, $waived),
            'period_type' => $this->validatedPeriodType($attributes),
            'enforcement_mode' => $this->validatedEnforcementMode($attributes),
            'approach_threshold' => $this->validatedApproachThreshold($attributes),
            'waived' => $waived,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException
     */
    private function validatedWaived(BudgetScope $scopeType, array $attributes): bool
    {
        $waived = $attributes['waived'] ?? false;

        if ($waived === null) {
            $waived = false;
        }

        if (!is_bool($waived)) {
            throw new \InvalidArgumentException('waived must be true or false.');
        }

        // There is no such thing as waiving the installation-wide ceiling
        // or the default that applies to everyone: a waiver exempts one
        // named user and nobody else.
        if ($waived && $scopeType !== BudgetScope::User) {
            throw new \InvalidArgumentException(
                "Only a user-scoped ceiling can be waived; scope '{$scopeType->value}' cannot."
            );
        }

        return $waived;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException
     */
    private function validatedAmount(array $attributes, bool $waived): ?string
    {
        $amount = $attributes['amount'] ?? null;

        if ($waived) {
            if ($amount !== null) {
                throw new \InvalidArgumentException('A waived ceiling carries no amount.');
            }

            return null;
        }

        if ($amount === null) {
            throw new \InvalidArgumentException('amount is required unless the ceiling is waived.');
        }

        $amount = $this->decimalString($amount, self::AMOUNT_SCALE, 'amount');

        if (bccomp($amount, '0', self::AMOUNT_SCALE) <= 0) {
            throw new \InvalidArgumentException("amount must be greater than zero, got '{$amount}'.");
        }

        return $amount;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException
     */
    private function validatedPeriodType(array $attributes): string
    {
        $periodType = $attributes['period_type'] ?? null;

        // Rejected, never coerced onto the nearest supported period: a
        // coerced period would enforce a limit over a window the operator
        // never declared.
        if (!is_string($periodType) || !in_array($periodType, CalendarPeriod::TYPES, true)) {
            $rendered = is_string($periodType) ? "'{$periodType}'" : gettype($periodType);
            $supported = implode(', ', CalendarPeriod::TYPES);

            throw new \InvalidArgumentException("period_type must be one of {$supported}, got {$rendered}.");
        }

        return $periodType;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException
     */
    private function validatedEnforcementMode(array $attributes): string
    {
        $mode = $attributes['enforcement_mode'] ?? null;

        if (!is_string($mode) || EnforcementMode::tryFrom($mode) === null) {
            $rendered = is_string($mode) ? "'{$mode}'" : gettype($mode);

            throw new \InvalidArgumentException("enforcement_mode must be warn or stop, got {$rendered}.");
        }

        return $mode;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException
     */
    private function validatedApproachThreshold(array $attributes): string
    {
        $threshold = $attributes['approach_threshold'] ?? null;

        if ($threshold === null) {
            $threshold = config('llm-client.budget.default_approach_threshold');
        }

        $threshold = $this->decimalString($threshold, self::THRESHOLD_SCALE, 'approach_threshold');

        // The range is (0, 1]: zero would warn before a single request, and
        // anything above one names a point past the ceiling itself, which
        // could never be crossed before the ceiling was.
        if (bccomp($threshold, '0', self::THRESHOLD_SCALE) <= 0
            || bccomp($threshold, '1', self::THRESHOLD_SCALE) > 0) {
            throw new \InvalidArgumentException(
                "approach_threshold must be greater than 0 and at most 1, got '{$threshold}'."
            );
        }

        return $threshold;
    }

    /**
     * Normalizes a caller-supplied numeric value to a plain-decimal string
     * and rejects anything that is not one — including a value carrying
     * more fractional digits than the column can hold, which would
     * otherwise be silently truncated to a limit nobody declared.
     *
     * An array (a caller asking for several thresholds, say) lands here as
     * a non-scalar and is rejected like any other malformed value.
     *
     * @throws \InvalidArgumentException
     */
    private function decimalString(mixed $value, int $maxScale, string $field): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            // Only reached for a config-supplied default, which is written
            // as a PHP float in the config file. Rendered at the target
            // scale so no float ever reaches bcmath.
            $value = sprintf('%.'.$maxScale.'F', $value);
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$field} must be a decimal string, got ".gettype($value).'.');
        }

        $value = Decimal::toPlainNotation(trim($value));

        if (!preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException("{$field} must be a decimal number, got '{$value}'.");
        }

        $point = strpos($value, '.');
        $decimals = $point === false ? 0 : strlen($value) - $point - 1;

        if ($decimals > $maxScale) {
            throw new \InvalidArgumentException(
                "{$field} carries more than {$maxScale} decimal places: '{$value}'."
            );
        }

        return $value;
    }
}
