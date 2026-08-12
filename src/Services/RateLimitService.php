<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\ValueObjects\RateLimitScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place a `rate_limits` row is ever written — the user_default row
 * and every per-user override (a waiver being an override with
 * waived = true) alike. Nothing else in this package creates, updates, or
 * deletes one.
 *
 * That matters more here than it would for an ordinary model, because the
 * table carries a *plain* index on (scope_type, scope_id) rather than a
 * unique one: soft deletes and a unique constraint interact badly in both
 * directions (a soft-deleted row occupies the key; adding deleted_at to the
 * constraint lets two live rows through on MySQL, where every NULL is
 * distinct). "Exactly one live row per scope" is therefore a property of
 * this class alone, upheld by upsert()'s withTrashed() lookup, which
 * restores and updates a soft-deleted row rather than inserting a
 * duplicate.
 *
 * Invalid input is rejected with \InvalidArgumentException — the
 * convention this package's other configuration services already use —
 * and rejection happens in full before any row is touched, so a refused
 * write leaves the table byte for byte as it was. Mapping a rejection to
 * an HTTP 422 is the controller's job, not this class's.
 *
 * There is no installation-wide axis and therefore no combine()/tie-break
 * logic at all: resolution for a user is a single row, or none.
 */
class RateLimitService
{
    /**
     * Create the limit for a scope, or update the one that is already
     * there. A scope whose only row is soft-deleted has that row restored
     * and updated rather than a second one inserted.
     *
     * @param  array<string, mixed>  $attributes  max_requests, window_seconds,
     *   waived — validated here.
     *
     * @throws \InvalidArgumentException when any attribute is invalid; no
     *   row is created, changed, restored, or deleted in that case.
     */
    public function upsert(RateLimitScope $scopeType, string $scopeId, array $attributes): RateLimit
    {
        // Validation runs to completion before the transaction opens: a
        // rejected upsert must write nothing at all, not even a row it
        // would immediately roll back.
        $values = $this->validated($scopeType, $scopeId, $attributes);

        return DB::transaction(function () use ($scopeType, $scopeId, $values) {
            $existing = $this->existingRow($scopeType, $scopeId);

            if ($existing === null) {
                return RateLimit::create($values);
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
     * Remove a scope's limit. A soft delete: the row survives as history
     * and a later upsert for the same scope restores it.
     */
    public function remove(RateLimitScope $scopeType, string $scopeId): void
    {
        $existing = RateLimit::query()
            ->where('scope_type', $scopeType->value)
            ->where('scope_id', $scopeId)
            ->first();

        $existing?->delete();
    }

    /**
     * Every live limit row, of either scope kind.
     *
     * @return Collection<int, RateLimit>
     */
    public function list(): Collection
    {
        return RateLimit::query()
            ->orderBy('scope_type')
            ->orderBy('scope_id')
            ->get();
    }

    /**
     * The limit that applies to one user: their own override if they have
     * one, otherwise the general user_default, otherwise none. A waived row
     * resolves to none — a waiver is the absence of an enforceable limit,
     * not a limit of unlimited size.
     */
    public function resolveForUser(string $userId): ?RateLimit
    {
        $row = $this->applicableUserRow($userId);

        if ($row === null || $row->waived) {
            return null;
        }

        return $row;
    }

    /**
     * The row the user chain selects for a user — their own override if
     * they have one, otherwise the user_default row — *before* a waiver is
     * applied, so a caller can tell "waived" apart from "nothing
     * configured".
     *
     * Enforcement never uses this directly: resolveForUser() above is the
     * only entry point that decides whether a limit applies, and it walks
     * the chain by calling this method, so the two can never disagree about
     * which row a user is measured against.
     */
    public function applicableUserRow(string $userId): ?RateLimit
    {
        $row = RateLimit::query()
            ->where('scope_type', RateLimitScope::User->value)
            ->where('scope_id', $userId)
            ->first();

        if ($row !== null) {
            return $row;
        }

        return RateLimit::query()
            ->where('scope_type', RateLimitScope::UserDefault->value)
            ->where('scope_id', RateLimit::INSTALLATION_SCOPE_ID)
            ->first();
    }

    /**
     * The row for a scope, preferring a live one and falling back to a
     * soft-deleted one so it can be restored rather than duplicated.
     */
    private function existingRow(RateLimitScope $scopeType, string $scopeId): ?RateLimit
    {
        $live = RateLimit::query()
            ->where('scope_type', $scopeType->value)
            ->where('scope_id', $scopeId)
            ->first();

        if ($live !== null) {
            return $live;
        }

        return RateLimit::withTrashed()
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
    private function validated(RateLimitScope $scopeType, string $scopeId, array $attributes): array
    {
        if (trim($scopeId) === '') {
            throw new \InvalidArgumentException('A rate limit requires a scope id.');
        }

        $waived = $this->validatedWaived($scopeType, $attributes);

        return [
            'scope_type' => $scopeType->value,
            'scope_id' => $scopeId,
            'max_requests' => $this->validatedCount($attributes, 'max_requests', $waived),
            'window_seconds' => $this->validatedCount($attributes, 'window_seconds', $waived),
            'waived' => $waived,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException
     */
    private function validatedWaived(RateLimitScope $scopeType, array $attributes): bool
    {
        $waived = $attributes['waived'] ?? false;

        if ($waived === null) {
            $waived = false;
        }

        if (!is_bool($waived)) {
            throw new \InvalidArgumentException('waived must be true or false.');
        }

        // There is no such thing as waiving the default that applies to
        // every user: a waiver exempts one named user, never the general
        // population.
        if ($waived && $scopeType !== RateLimitScope::User) {
            throw new \InvalidArgumentException(
                "Only a user-scoped rate limit can be waived; scope '{$scopeType->value}' cannot."
            );
        }

        return $waived;
    }

    /**
     * Validates max_requests/window_seconds identically: required unless
     * waived, a positive integer, and null when waived. No upper or lower
     * bound beyond "positive integer" is imposed — an operator-chosen
     * one-second or one-week window is a choice this service does not
     * second-guess.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException
     */
    private function validatedCount(array $attributes, string $field, bool $waived): ?int
    {
        $value = array_key_exists($field, $attributes) ? $attributes[$field] : null;

        if ($waived) {
            if ($value !== null) {
                throw new \InvalidArgumentException("A waived rate limit carries no {$field}.");
            }

            return null;
        }

        if ($value === null) {
            throw new \InvalidArgumentException("{$field} is required unless the rate limit is waived.");
        }

        // Deliberately strict: a numeric string or a float is rejected
        // rather than coerced, so an operator-supplied "5.0" cannot silently
        // become an integer 5 that quietly differs from what was typed.
        if (!is_int($value) || $value <= 0) {
            $rendered = is_scalar($value) ? var_export($value, true) : gettype($value);

            throw new \InvalidArgumentException("{$field} must be a positive integer, got {$rendered}.");
        }

        return $value;
    }
}
