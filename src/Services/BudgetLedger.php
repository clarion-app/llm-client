<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Support\CalendarPeriod;
use ClarionApp\LlmClient\ValueObjects\ConsumptionSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * The sole reader of current-period consumption.
 *
 * There is no second consumption store and no counter of this feature's
 * own: the figure comes from cost_summaries, which the metrics path
 * already increments atomically and exactly once per completed unit of
 * work. This class only reads, and it reads through CostRollupQuery — the
 * same code the cost-rollup endpoints use — so a standing report and a
 * rollup can never disagree about the same period.
 *
 * The memo is per-instance and the binding is scoped(), not singleton().
 * In a web request the two are indistinguishable, but a queue worker keeps
 * one container alive across many jobs and flushes only scoped instances
 * between them: a singleton memo would carry one job's consumption figure
 * into every later job for the life of the worker, letting work through
 * after a ceiling had already been crossed. The memo exists only to avoid
 * reading the same durable number twice within one request or job — it is
 * emphatically not a process-local cache of a shared figure.
 */
class BudgetLedger
{
    /**
     * Per-instance memo keyed "<scope>|<periodType>", where <scope> is
     * "installation" or "user:<uuid>". Never static.
     *
     * @var array<string, ConsumptionSnapshot>
     */
    private array $memo = [];

    public function __construct(private readonly CostRollupQuery $rollups)
    {
    }

    public function forUser(string $userId, string $periodType): ConsumptionSnapshot
    {
        return $this->read(
            'user:'.$userId,
            $periodType,
            // isOperator: true because this is enforcement's own read, not
            // a caller's — the scoping in CostRollupQuery exists to stop a
            // user seeing another user's rollup, and there is no caller
            // here whose view should be narrowed.
            fn (string $from, string $to) => $this->rollups->userTotal($userId, $from, $to, null, true)
        );
    }

    public function forInstallation(string $periodType): ConsumptionSnapshot
    {
        return $this->read(
            'installation',
            $periodType,
            fn (string $from, string $to) => $this->rollups->installationTotal($from, $to)
        );
    }

    /**
     * Discard the memo, wholly or for one scope key ("installation" or
     * "user:<uuid>").
     *
     * Called right after a usage increment commits, because that is exactly
     * the moment the memo's premise — "the figure cannot change during this
     * request" — stops being true, and comparing against the pre-increment
     * figure would make the crossing unit's own warning fire late or not at
     * all.
     */
    public function forget(?string $scopeKey = null): void
    {
        if ($scopeKey === null) {
            $this->memo = [];

            return;
        }

        foreach (array_keys($this->memo) as $key) {
            if (str_starts_with($key, $scopeKey.'|')) {
                unset($this->memo[$key]);
            }
        }
    }

    /**
     * @param  callable(string, string): array  $query
     */
    private function read(string $scopeKey, string $periodType, callable $query): ConsumptionSnapshot
    {
        $memoKey = $scopeKey.'|'.$periodType;

        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        [$from, $to] = CalendarPeriod::containing($periodType);
        $resetsAt = CalendarPeriod::resetsAt($periodType, $to);

        try {
            $totals = $query($from, $to);

            $snapshot = new ConsumptionSnapshot(
                amount: $totals['priced_cost_total'],
                requestCount: $totals['request_count'],
                unpricedRequestCount: $totals['unpriced_request_count'],
                unpricedTotalTokens: $totals['unpriced_total_tokens'],
                hasEstimatedCost: $totals['has_estimated_cost'],
                periodType: $periodType,
                periodFrom: $from,
                periodTo: $to,
                resetsAt: $resetsAt,
                available: true,
            );
        } catch (\Throwable $e) {
            // Never a zero and never a partial figure: a zero would read as
            // "nothing spent" and let work straight through a ceiling. Every
            // occurrence is logged — the complete record of a degraded
            // period lives here even where the broadcast is throttled.
            Log::warning('Budget consumption could not be read', [
                'scope' => $scopeKey,
                'period_type' => $periodType,
                'period_from' => $from,
                'period_to' => $to,
                'error' => $e->getMessage(),
            ]);

            $snapshot = ConsumptionSnapshot::unavailable($periodType, $from, $to, $resetsAt);
        }

        return $this->memo[$memoKey] = $snapshot;
    }
}
