<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Support\Decimal;
use ClarionApp\LlmClient\ValueObjects\LimitAxis;
use ClarionApp\LlmClient\ValueObjects\ReducibleTool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place a reduction_steps row is ever written (data-model.md §1,
 * research.md D12) — the sole write path DegradationGate::evaluate() and
 * DegradationStatusController both read through, never write to.
 *
 * Mirrors ConversationWorkCeilingService's reject-before-write discipline:
 * every write is validated to completion, as an \InvalidArgumentException,
 * before any row is touched — a rejected write leaves the table byte for
 * byte as it was. Mapping that rejection to an HTTP 422 is the controller's
 * job, not this class's; each rejection message is prefixed with its
 * contracts §1 machine code as "{code}: {human text}" so the controller can
 * recover both halves without a second lookup table.
 *
 * Two of the five validation rules are load-bearing rather than incidental:
 *
 *  - withheld_tools is validated against the closed ReducibleTool enum, so
 *    `list_applications`/`execute_operation`/`search_operations` can never
 *    reach the column (research.md D6, FR-008).
 *  - The threshold collision rule is scoped to *enabled* rows only, and
 *    excludes the row being updated — disabling or soft-deleting a rung
 *    frees its (axis, threshold_ratio) slot for a new one (FR-011/US3
 *    Acceptance Scenario 2).
 */
class ReductionLadderService
{
    /**
     * Every live rung, sorted by (axis, threshold_ratio) ascending — a
     * display order, not DegradationGate::evaluate()'s own margin-based
     * evaluation order (research.md D7/D12).
     *
     * @return Collection<int, ReductionStep>
     */
    public function list(): Collection
    {
        return ReductionStep::query()
            ->orderBy('axis')
            ->orderBy('threshold_ratio')
            ->get();
    }

    /**
     * Create a new rung, or update the one named by $id.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException when any attribute is invalid, or
     *   $id names no row at all; no row is created or changed in that case.
     */
    public function put(array $attributes, ?string $id = null): ReductionStep
    {
        $existing = $id !== null ? $this->existingRow($id) : null;

        if ($id !== null && $existing === null) {
            throw new \InvalidArgumentException('reduction_step_not_found: No reduction step exists with that id.');
        }

        $values = $this->validated($attributes, $existing?->id);

        return DB::transaction(function () use ($existing, $values) {
            if ($existing === null) {
                return ReductionStep::create($values);
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
     * Soft-delete a rung. Equivalent in effect to put() with enabled:false
     * (contracts §1) — offered as a separate verb for the "operator removes
     * a rung" phrasing in US3 Acceptance Scenario 2. A no-op when $id names
     * no row (or an already-deleted one).
     */
    public function destroy(string $id): void
    {
        ReductionStep::query()->where('id', $id)->first()?->delete();
    }

    private function existingRow(string $id): ?ReductionStep
    {
        return ReductionStep::withTrashed()->find($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed> the column values to write
     *
     * @throws \InvalidArgumentException
     */
    private function validated(array $attributes, ?string $excludingId): array
    {
        $axis = $this->validatedAxis($attributes);
        $thresholdRatio = $this->validatedRatio($attributes, 'threshold_ratio', required: true);
        $historyBudgetRatio = $this->validatedRatio($attributes, 'history_budget_ratio', required: false);
        $substituteModel = $this->validatedSubstituteModel($attributes);
        $withheldTools = $this->validatedWithheldTools($attributes);
        $substituteServerId = $this->validatedServerId($attributes);

        if ($substituteModel === null && $withheldTools === null && $historyBudgetRatio === null) {
            throw new \InvalidArgumentException(
                'reduction_step_reduces_nothing: A reduction step must set at least one of substitute_model, withheld_tools, or history_budget_ratio.'
            );
        }

        $enabled = $this->validatedEnabled($attributes);

        if ($enabled) {
            $this->assertNoThresholdCollision($axis, $thresholdRatio, $excludingId);
        }

        return [
            'axis' => $axis,
            'threshold_ratio' => $thresholdRatio,
            'substitute_model' => $substituteModel,
            'substitute_server_id' => $substituteServerId,
            'withheld_tools' => $withheldTools,
            'history_budget_ratio' => $historyBudgetRatio,
            'enabled' => $enabled,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException
     */
    private function validatedAxis(array $attributes): string
    {
        $axis = $attributes['axis'] ?? null;

        if (!is_string($axis) || LimitAxis::tryFrom($axis) === null) {
            $rendered = is_scalar($axis) ? var_export($axis, true) : gettype($axis);

            throw new \InvalidArgumentException(
                "reduction_step_invalid_axis: axis must be one of ".implode(', ', array_map(fn (LimitAxis $case) => $case->value, LimitAxis::cases())).", got {$rendered}."
            );
        }

        return $axis;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException
     */
    private function validatedRatio(array $attributes, string $field, bool $required): ?string
    {
        $hasValue = array_key_exists($field, $attributes) && $attributes[$field] !== null;

        if (!$hasValue) {
            if ($required) {
                throw new \InvalidArgumentException("reduction_step_ratio_out_of_range: {$field} is required.");
            }

            return null;
        }

        $value = $attributes[$field];

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException("reduction_step_ratio_out_of_range: {$field} must be a numeric string.");
        }

        $rounded = Decimal::round((string) $value, 4);

        if (bccomp($rounded, '0', 10) <= 0 || bccomp($rounded, '1', 10) > 0) {
            throw new \InvalidArgumentException("reduction_step_ratio_out_of_range: {$field} must satisfy 0 < ratio <= 1, got '{$value}'.");
        }

        return $rounded;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function validatedSubstituteModel(array $attributes): ?string
    {
        $model = $attributes['substitute_model'] ?? null;

        return $model === '' ? null : $model;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>|null
     *
     * @throws \InvalidArgumentException
     */
    private function validatedWithheldTools(array $attributes): ?array
    {
        if (!array_key_exists('withheld_tools', $attributes) || $attributes['withheld_tools'] === null) {
            return null;
        }

        $tools = $attributes['withheld_tools'];

        if (!is_array($tools)) {
            throw new \InvalidArgumentException('reduction_step_withholds_essential_tool: withheld_tools must be an array.');
        }

        if ($tools === []) {
            // An empty list reduces nothing on its own — the "reduces
            // nothing" rule (checked by the caller) is what rejects it,
            // not this one; returning null here lets that check treat an
            // omitted key and an explicit empty array identically.
            return null;
        }

        foreach ($tools as $tool) {
            if (!is_string($tool) || ReducibleTool::tryFrom($tool) === null) {
                $rendered = is_scalar($tool) ? var_export($tool, true) : gettype($tool);

                throw new \InvalidArgumentException(
                    "reduction_step_withholds_essential_tool: withheld_tools contains {$rendered}, which is not a permitted (non-essential) tool."
                );
            }
        }

        return array_values($tools);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException
     */
    private function validatedServerId(array $attributes): ?string
    {
        if (!array_key_exists('substitute_server_id', $attributes) || $attributes['substitute_server_id'] === null) {
            return null;
        }

        $serverId = $attributes['substitute_server_id'];

        if (!is_string($serverId) || !Server::query()->where('id', $serverId)->exists()) {
            throw new \InvalidArgumentException('reduction_step_unknown_server: substitute_server_id names no existing Server.');
        }

        return $serverId;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException
     */
    private function validatedEnabled(array $attributes): bool
    {
        $enabled = $attributes['enabled'] ?? true;

        if (!is_bool($enabled)) {
            throw new \InvalidArgumentException('reduction_step_invalid_enabled: enabled must be true or false.');
        }

        return $enabled;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertNoThresholdCollision(string $axis, string $thresholdRatio, ?string $excludingId): void
    {
        $query = ReductionStep::query()
            ->where('axis', $axis)
            ->where('threshold_ratio', $thresholdRatio)
            ->where('enabled', true);

        if ($excludingId !== null) {
            $query->where('id', '!=', $excludingId);
        }

        if ($query->exists()) {
            throw new \InvalidArgumentException(
                "reduction_step_threshold_collision: another enabled reduction step already exists at axis '{$axis}', threshold_ratio '{$thresholdRatio}'."
            );
        }
    }
}
