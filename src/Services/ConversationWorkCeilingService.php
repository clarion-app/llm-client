<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\ConversationWorkCeiling;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place a conversation_work_ceilings row is ever written — the
 * conversation_default row and every per-conversation override (a waiver
 * being an override with waived = true) alike. Nothing else in this
 * package creates, updates, or deletes one.
 *
 * Mirrors RateLimitService one scope level down: the table carries a plain
 * index on (scope_type, scope_id) rather than a unique one, for the
 * identical SoftDeletes/unique-constraint interaction reason, so "exactly
 * one live row per scope" is a property of this class alone, upheld by
 * upsert()'s withTrashed() lookup, which restores and updates a
 * soft-deleted row rather than inserting a duplicate.
 *
 * Invalid input is rejected with InvalidArgumentException, and rejection
 * happens in full before any row is touched, so a refused write leaves the
 * table byte for byte as it was. Mapping a rejection to an HTTP 422 is the
 * controller's job, not this class's.
 *
 * There is no installation-wide axis and therefore no combine()/tie-break
 * logic at all: resolution for a conversation is a single row, or none.
 */
class ConversationWorkCeilingService
{
    /**
     * Create the ceiling for a scope, or update the one that is already
     * there. A scope whose only row is soft-deleted has that row restored
     * and updated rather than a second one inserted.
     *
     * @param  array<string, mixed>  $attributes  max_work_units,
     *   window_seconds, waived — validated here.
     *
     * @throws \InvalidArgumentException when any attribute is invalid; no
     *   row is created, changed, restored, or deleted in that case.
     */
    public function upsert(ConversationWorkScope $scopeType, string $scopeId, array $attributes): ConversationWorkCeiling
    {
        // Validation runs to completion before the transaction opens: a
        // rejected upsert must write nothing at all, not even a row it
        // would immediately roll back.
        $values = $this->validated($scopeType, $scopeId, $attributes);

        return DB::transaction(function () use ($scopeType, $scopeId, $values) {
            $existing = $this->existingRow($scopeType, $scopeId);

            if ($existing === null) {
                return ConversationWorkCeiling::create($values);
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
    public function remove(ConversationWorkScope $scopeType, string $scopeId): void
    {
        $existing = ConversationWorkCeiling::query()
            ->where('scope_type', $scopeType->value)
            ->where('scope_id', $scopeId)
            ->first();

        $existing?->delete();
    }

    /**
     * Every live ceiling row, of either scope kind.
     *
     * @return Collection<int, ConversationWorkCeiling>
     */
    public function list(): Collection
    {
        return ConversationWorkCeiling::query()
            ->orderBy('scope_type')
            ->orderBy('scope_id')
            ->get();
    }

    /**
     * The ceiling that applies to one conversation: its own override if it
     * has one, otherwise the general conversation_default, otherwise none.
     * A waived row resolves to none — a waiver is the absence of an
     * enforceable ceiling, not a ceiling of unlimited size.
     */
    public function resolveForConversation(string $conversationId): ?ConversationWorkCeiling
    {
        $row = $this->applicableConversationRow($conversationId);

        if ($row === null || $row->waived) {
            return null;
        }

        return $row;
    }

    /**
     * The row the resolution chain selects for a conversation — its own
     * override if it has one, otherwise the conversation_default row —
     * *before* a waiver is applied, so a caller can tell "waived" apart
     * from "nothing configured".
     *
     * Enforcement never uses this directly: resolveForConversation() above
     * is the only entry point that decides whether a ceiling applies, and
     * it walks the chain by calling this method, so the two can never
     * disagree about which row a conversation is measured against.
     */
    public function applicableConversationRow(string $conversationId): ?ConversationWorkCeiling
    {
        $row = ConversationWorkCeiling::query()
            ->where('scope_type', ConversationWorkScope::Conversation->value)
            ->where('scope_id', $conversationId)
            ->first();

        if ($row !== null) {
            return $row;
        }

        return ConversationWorkCeiling::query()
            ->where('scope_type', ConversationWorkScope::ConversationDefault->value)
            ->where('scope_id', RateLimit::INSTALLATION_SCOPE_ID)
            ->first();
    }

    /**
     * The row for a scope, preferring a live one and falling back to a
     * soft-deleted one so it can be restored rather than duplicated.
     */
    private function existingRow(ConversationWorkScope $scopeType, string $scopeId): ?ConversationWorkCeiling
    {
        $live = ConversationWorkCeiling::query()
            ->where('scope_type', $scopeType->value)
            ->where('scope_id', $scopeId)
            ->first();

        if ($live !== null) {
            return $live;
        }

        return ConversationWorkCeiling::withTrashed()
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
    private function validated(ConversationWorkScope $scopeType, string $scopeId, array $attributes): array
    {
        if (trim($scopeId) === '') {
            throw new \InvalidArgumentException('A conversation work ceiling requires a scope id.');
        }

        $waived = $this->validatedWaived($scopeType, $attributes);

        return [
            'scope_type' => $scopeType->value,
            'scope_id' => $scopeId,
            'max_work_units' => $this->validatedCount($attributes, 'max_work_units', $waived),
            'window_seconds' => $this->validatedCount($attributes, 'window_seconds', $waived),
            'waived' => $waived,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException
     */
    private function validatedWaived(ConversationWorkScope $scopeType, array $attributes): bool
    {
        $waived = $attributes['waived'] ?? false;

        if ($waived === null) {
            $waived = false;
        }

        if (!is_bool($waived)) {
            throw new \InvalidArgumentException('waived must be true or false.');
        }

        // There is no such thing as waiving the default that applies to
        // any conversation with no override: a waiver exempts one named
        // conversation, never the general population.
        if ($waived && $scopeType !== ConversationWorkScope::Conversation) {
            throw new \InvalidArgumentException(
                "Only a conversation-scoped work ceiling can be waived; scope '{$scopeType->value}' cannot."
            );
        }

        return $waived;
    }

    /**
     * Validates max_work_units/window_seconds identically: required unless
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
                throw new \InvalidArgumentException("A waived conversation work ceiling carries no {$field}.");
            }

            return null;
        }

        if ($value === null) {
            throw new \InvalidArgumentException("{$field} is required unless the conversation work ceiling is waived.");
        }

        // Deliberately strict: a numeric string or a float is rejected
        // rather than coerced, so an operator-supplied "5.0" cannot
        // silently become an integer 5 that quietly differs from what was
        // typed.
        if (!is_int($value) || $value <= 0) {
            $rendered = is_scalar($value) ? var_export($value, true) : gettype($value);

            throw new \InvalidArgumentException("{$field} must be a positive integer, got {$rendered}.");
        }

        return $value;
    }
}
