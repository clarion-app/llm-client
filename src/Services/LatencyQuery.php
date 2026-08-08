<?php

namespace ClarionApp\LlmClient\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Role-scoped percentile reads over agent_runs, scoped to a single model or
 * a single agent over an explicit [from, to] period (074-latency-metrics,
 * User Story 2). Mirrors CostRollupQuery's per-scope show/list shape.
 *
 * Every *Distribution() method returns the LatencyDistribution shape:
 *   [
 *     'scope' => ['type' => 'model'|'agent', 'value' => string],
 *     'from' => string, 'to' => string,
 *     'sample_count' => int, 'no_data' => bool,
 *     'total' => [
 *         'whole' => ['p50_ms' => int, 'p95_ms' => int]|null,
 *         'streamed' => ['p50_ms' => int, 'p95_ms' => int]|null,
 *     ],
 *     'first_output' => ['p50_ms' => int, 'p95_ms' => int]|null,
 *   ]
 *
 * $callerId/$isOperator mirror CostRollupQuery's authorization pattern: an
 * operator's read is unrestricted; a non-operator's read is scoped to
 * agent_runs rows where user_id = $callerId. This class never 403s or
 * throws for a scoping mismatch -- it simply narrows, or returns the
 * no-data shape when nothing matches.
 *
 * Row selection always excludes end_state = 'in_progress' rows (they have
 * no final duration_ms yet) and never filters to end_state = 'completed'
 * only -- a failed or abandoned response's elapsed time must still be
 * reflected in the scoped sample.
 */
class LatencyQuery
{
    public function modelDistribution(string $model, string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        $rows = $this->fetchRows('model', $model, $from, $to, $isOperator ? null : $callerId);

        return $this->shape('model', $model, $from, $to, $rows);
    }

    public function modelList(string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        $models = $this->distinctModels($from, $to, $isOperator ? null : $callerId);

        return array_map(
            fn (string $model) => $this->modelDistribution($model, $from, $to, $callerId, $isOperator),
            $models
        );
    }

    /**
     * $agentId of null selects the reserved "unattributed" scope
     * (WHERE agent_id IS NULL), reported back as scope.value = "unattributed".
     */
    public function agentDistribution(?string $agentId, string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        $rows = $this->fetchRows('agent', $agentId, $from, $to, $isOperator ? null : $callerId);

        return $this->shape('agent', $agentId ?? 'unattributed', $from, $to, $rows);
    }

    public function agentList(string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        $agentIds = $this->distinctAgents($from, $to, $isOperator ? null : $callerId);

        return array_map(
            fn (?string $agentId) => $this->agentDistribution($agentId, $from, $to, $callerId, $isOperator),
            $agentIds
        );
    }

    /**
     * Fetch the in-scope rows' duration_ms/is_streamed/first_output_ms for a
     * single model or agent scope over [$from, $to], optionally restricted
     * to a single user_id.
     */
    private function fetchRows(string $scopeType, ?string $scopeValue, string $from, string $to, ?string $userIdFilter): Collection
    {
        $query = $this->periodQuery($from, $to);

        if ($scopeType === 'model') {
            $query->where('model', $scopeValue);
        } elseif ($scopeValue === null) {
            $query->whereNull('agent_id');
        } else {
            $query->where('agent_id', $scopeValue);
        }

        if ($userIdFilter !== null) {
            $query->where('user_id', $userIdFilter);
        }

        return $query->select(['duration_ms', 'is_streamed', 'first_output_ms'])->get();
    }

    private function distinctModels(string $from, string $to, ?string $userIdFilter): array
    {
        $query = $this->periodQuery($from, $to)->whereNotNull('model');

        if ($userIdFilter !== null) {
            $query->where('user_id', $userIdFilter);
        }

        return $query->distinct()->pluck('model')->all();
    }

    private function distinctAgents(string $from, string $to, ?string $userIdFilter): array
    {
        $query = $this->periodQuery($from, $to);

        if ($userIdFilter !== null) {
            $query->where('user_id', $userIdFilter);
        }

        $values = $query->distinct()->pluck('agent_id')->all();

        $named = array_values(array_unique(array_filter($values, fn ($value) => $value !== null)));

        if (in_array(null, $values, true)) {
            $named[] = null;
        }

        return $named;
    }

    private function periodQuery(string $from, string $to)
    {
        [$fromTs, $toTs] = $this->periodBounds($from, $to);

        return DB::table('agent_runs')
            ->whereBetween('started_at', [$fromTs, $toTs])
            ->where('end_state', '!=', 'in_progress');
    }

    private function periodBounds(string $from, string $to): array
    {
        return [
            Carbon::parse($from)->startOfDay()->format('Y-m-d H:i:s.u'),
            Carbon::parse($to)->endOfDay()->format('Y-m-d H:i:s.u'),
        ];
    }

    private function shape(string $scopeType, string $scopeValue, string $from, string $to, Collection $rows): array
    {
        $whole = $rows->filter(fn ($row) => (int) $row->is_streamed !== 1);
        $streamed = $rows->filter(fn ($row) => (int) $row->is_streamed === 1);
        $firstOutput = $streamed->filter(fn ($row) => $row->first_output_ms !== null);

        return [
            'scope' => ['type' => $scopeType, 'value' => $scopeValue],
            'from' => $from,
            'to' => $to,
            'sample_count' => $rows->count(),
            'no_data' => $rows->count() === 0,
            'total' => [
                'whole' => $this->percentiles($this->intColumn($whole, 'duration_ms')),
                'streamed' => $this->percentiles($this->intColumn($streamed, 'duration_ms')),
            ],
            'first_output' => $this->percentiles($this->intColumn($firstOutput, 'first_output_ms')),
        ];
    }

    private function intColumn(Collection $rows, string $column): array
    {
        return $rows->pluck($column)
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();
    }

    /**
     * Nearest-rank percentiles over an in-PHP-sorted sample. Returns null
     * (never a fabricated zero) when the sample is empty.
     */
    private function percentiles(array $values): ?array
    {
        if (empty($values)) {
            return null;
        }

        sort($values);

        $worstCasePercentile = (int) config('llm-client.latency.worst_case_percentile', 95);

        return [
            'p50_ms' => $this->nearestRank($values, 50),
            'p95_ms' => $this->nearestRank($values, $worstCasePercentile),
        ];
    }

    private function nearestRank(array $sortedValues, int $percentile): int
    {
        $count = count($sortedValues);
        $index = (int) ceil($percentile / 100 * $count) - 1;
        $index = max(0, min($count - 1, $index));

        return $sortedValues[$index];
    }
}
