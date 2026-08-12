<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Models\UsageSummary;
use ClarionApp\LlmClient\Models\ContextManagementRecord;
use ClarionApp\LlmClient\Models\ContextManagementSummary;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\DegradationEvent;
use ClarionApp\LlmClient\Models\DegradationSummary;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Models\ToolReliabilitySummary;
use ClarionApp\LlmClient\Services\Concerns\RetriesConcurrencyAborts;
use ClarionApp\LlmClient\Support\Decimal;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
use ClarionApp\LlmClient\ValueObjects\ContextManagementOutcome;
use ClarionApp\LlmClient\ValueObjects\ContextManagementStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetricsRecorder
{
    use RetriesConcurrencyAborts;

    private UsageEstimator $estimator;

    public function __construct(?UsageEstimator $estimator = null)
    {
        $this->estimator = $estimator ?? new UsageEstimator();
    }

    /**
     * Record LLM usage for a single API call.
     *
     * @param string  $conversationId   Conversation UUID
     * @param string  $userId           User UUID (attribution)
     * @param string  $attemptGroupId   Groups retries within a turn
     * @param array   $providerUsage    Raw usage from provider (may be empty)
     * @param string  $inputText        Input messages concatenated text (for estimation fallback)
     * @param string  $outputText       Output content text (for estimation fallback)
     * @param string|null  $model            Model name (nullable)
     * @param string|null  $providerType     Provider family string (nullable)
     * @param array|null   $coMemberTags     Co-member user ID tags (nullable)
     * @param string|null  $agentId          Agent configuration identifier (nullable; written exactly as passed, no fallback/derivation)
     */
    public function recordUsage(
        string $conversationId,
        string $userId,
        string $attemptGroupId,
        array $providerUsage,
        string $inputText,
        string $outputText,
        ?string $model = null,
        ?string $providerType = null,
        ?array $coMemberTags = null,
        ?string $agentId = null
    ): void {
        try {
            $this->transactionWithConcurrencyRetries(function () use ($conversationId, $userId, $attemptGroupId, $providerUsage, $inputText, $outputText, $model, $providerType, $coMemberTags, $agentId) {
                // Captured once so the record's own timestamp, the model_prices
                // effective-dated lookup, and the cost_summaries period_date
                // bucket can never drift from one another (data-model.md §2).
                $now = now();

                $hasProviderUsage = !empty($providerUsage);

                if ($hasProviderUsage) {
                    $inputTokens = (int) ($providerUsage['prompt_tokens'] ?? 0);
                    $outputTokens = (int) ($providerUsage['completion_tokens'] ?? 0);
                    $totalTokens = (int) ($providerUsage['total_tokens'] ?? ($inputTokens + $outputTokens));
                    $inputEstimated = false;
                    $outputEstimated = false;

                    // Handle partial provider data: estimate missing fields
                    if ($inputTokens === 0 && strlen($inputText) > 0) {
                        $inputTokens = $this->estimator->estimateInput($inputText);
                        $inputEstimated = true;
                    }
                    if ($outputTokens === 0 && strlen($outputText) > 0) {
                        $outputTokens = $this->estimator->estimateOutput($outputText);
                        $outputEstimated = true;
                    }
                } else {
                    // Full estimation fallback
                    $estimates = $this->estimator->estimate($inputText, $outputText);
                    $inputTokens = $estimates['input_tokens'];
                    $outputTokens = $estimates['output_tokens'];
                    $totalTokens = $estimates['total_tokens'];
                    $inputEstimated = true;
                    $outputEstimated = true;
                }

                // Recalculate total if partial estimation was used
                if ($inputEstimated !== $outputEstimated || (!$inputEstimated && !$outputEstimated)) {
                    // Only recalculate if we didn't get a provider total
                    if (empty($providerUsage['total_tokens'])) {
                        $totalTokens = $inputTokens + $outputTokens;
                    }
                }

                // research.md D9/D9a/D9b: reused-input extraction and clamping.
                // Gated on having real provider usage and an unestimated input
                // figure — reuse is never reported against a fabricated total
                // (D9a). Isolated in its own inner try/catch (D9b) so a
                // failure specific to these new fields can never suppress the
                // rest of the record's existing token counts.
                $reusedInputTokens = null;
                $reusedInputEstimated = false;
                $reusedInputAdjusted = false;

                if ($hasProviderUsage && !$inputEstimated) {
                    try {
                        $reusedRaw = $this->extractReusedInputTokens($providerUsage);

                        if ($reusedRaw !== null) {
                            if ($reusedRaw > $inputTokens || $reusedRaw < 0) {
                                $reusedInputTokens = max(0, min($reusedRaw, $inputTokens));
                                $reusedInputAdjusted = true;
                            } else {
                                $reusedInputTokens = $reusedRaw;
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning('MetricsRecorder: failed to extract reused input tokens', [
                            'conversation_id' => $conversationId,
                            'user_id' => $userId,
                            'error' => $e->getMessage(),
                        ]);
                        $reusedInputTokens = null;
                        $reusedInputEstimated = false;
                        $reusedInputAdjusted = false;
                    }
                }

                // Cost computation (research.md D1/D2/D3, data-model.md §2):
                // resolve the effective price once, at write time, and compute
                // each component cost via bcmath only (never a float). Isolated
                // in its own inner try/catch, matching the reused-input-tokens
                // block above, so a costing-specific failure can never suppress
                // the rest of the record's existing token counts.
                $modelPriceId = null;
                $reusedInputCost = null;
                $freshInputCost = null;
                $outputCost = null;
                $totalCost = null;
                $costUnpriced = true;
                $costEstimated = false;

                try {
                    $price = ModelPrice::currentFor($providerType, $model, $now);

                    if ($price !== null) {
                        // research.md D3: an unknown reuse split is costed as
                        // if the reused portion were 0 (entire input fresh) —
                        // never understates spend, since fresh_input_rate is
                        // always >= reused_input_rate in real pricing.
                        $reusedForCosting = $reusedInputTokens ?? 0;
                        $freshForCosting = $inputTokens - $reusedForCosting;

                        // $price->reused_input_rate/fresh_input_rate/output_rate are
                        // decimal(14,8) columns guaranteed to come back in plain
                        // decimal notation at their own scale by ModelPrice's
                        // PlainDecimalCast (see that cast's docblock for why an
                        // uncast read of a small configured rate could otherwise
                        // reach bcmul() — which, unlike Decimal::round(), has no
                        // tolerance of its own for scientific notation — as a
                        // malformed string under SQLite's NUMERIC storage affinity).
                        $reusedInputCost = Decimal::round(
                            bcdiv(bcmul((string) $reusedForCosting, (string) $price->reused_input_rate, 20), '1000000', 20),
                            10
                        );
                        $freshInputCost = Decimal::round(
                            bcdiv(bcmul((string) $freshForCosting, (string) $price->fresh_input_rate, 20), '1000000', 20),
                            10
                        );
                        $outputCost = Decimal::round(
                            bcdiv(bcmul((string) $outputTokens, (string) $price->output_rate, 20), '1000000', 20),
                            10
                        );
                        $totalCost = bcadd(bcadd($reusedInputCost, $freshInputCost, 10), $outputCost, 10);

                        $modelPriceId = $price->id;
                        $costUnpriced = false;
                        $costEstimated = $inputEstimated || $outputEstimated || $reusedInputEstimated;
                    }
                } catch (\Throwable $e) {
                    Log::warning('MetricsRecorder: failed to compute cost', [
                        'conversation_id' => $conversationId,
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                    $modelPriceId = null;
                    $reusedInputCost = null;
                    $freshInputCost = null;
                    $outputCost = null;
                    $totalCost = null;
                    $costUnpriced = true;
                    $costEstimated = false;
                }

                // A genuinely zero-priced request (all rates configured as 0)
                // stores a real "0.0000000000" total_cost and must stay
                // visibly distinct from an unpriced request (FR-013/SC-005).
                $isZeroPricedCost = !$costUnpriced && bccomp($totalCost, '0', 10) === 0;

                // Create usage record
                UsageRecord::create([
                    'id' => (string) Str::uuid(),
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'attempt_group_id' => $attemptGroupId,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => $totalTokens,
                    'input_estimated' => $inputEstimated,
                    'output_estimated' => $outputEstimated,
                    'model' => $model,
                    'provider_type' => $providerType,
                    'co_member_tags' => $coMemberTags,
                    'reused_input_tokens' => $reusedInputTokens,
                    'reused_input_estimated' => $reusedInputEstimated,
                    'reused_input_adjusted' => $reusedInputAdjusted,
                    'agent_id' => $agentId,
                    'created_at' => $now,
                    'model_price_id' => $modelPriceId,
                    'reused_input_cost' => $reusedInputCost,
                    'fresh_input_cost' => $freshInputCost,
                    'output_cost' => $outputCost,
                    'total_cost' => $totalCost,
                    'cost_unpriced' => $costUnpriced,
                    'cost_estimated' => $costEstimated,
                ]);

                // Update conversation summary
                $this->upsertSummary(
                    UsageSummary::ENTITY_CONVERSATION,
                    $conversationId,
                    $inputTokens,
                    $outputTokens,
                    $totalTokens,
                    $inputEstimated ? $inputTokens : 0,
                    $outputEstimated ? $outputTokens : 0,
                    ($inputEstimated ? $inputTokens : 0) + ($outputEstimated ? $outputTokens : 0),
                );

                // Update user summary
                $this->upsertSummary(
                    UsageSummary::ENTITY_USER,
                    $userId,
                    $inputTokens,
                    $outputTokens,
                    $totalTokens,
                    $inputEstimated ? $inputTokens : 0,
                    $outputEstimated ? $outputTokens : 0,
                    ($inputEstimated ? $inputTokens : 0) + ($outputEstimated ? $outputTokens : 0),
                );

                // cost_summaries bucketing (T036, data-model.md §3) — isolated
                // in its own try/catch so a failure specific to this step can
                // never roll back the UsageRecord row or the usage_summaries
                // upserts above, matching the isolation pattern already
                // established for the reused-input-tokens/cost-computation
                // blocks in this same method.
                try {
                    $periodDate = $now->toDateString();
                    $summaryCost = $totalCost ?? '0.0000000000';

                    $this->upsertCostSummary(
                        CostSummary::ENTITY_CONVERSATION,
                        $conversationId,
                        $userId,
                        $periodDate,
                        $summaryCost,
                        $costUnpriced,
                        $isZeroPricedCost,
                        $costEstimated,
                        $totalTokens,
                    );
                    $this->upsertCostSummary(
                        CostSummary::ENTITY_USER,
                        $userId,
                        $userId,
                        $periodDate,
                        $summaryCost,
                        $costUnpriced,
                        $isZeroPricedCost,
                        $costEstimated,
                        $totalTokens,
                    );
                    $this->upsertCostSummary(
                        CostSummary::ENTITY_AGENT,
                        $agentId ?? CostSummary::UNATTRIBUTED_AGENT_BUCKET,
                        $userId,
                        $periodDate,
                        $summaryCost,
                        $costUnpriced,
                        $isZeroPricedCost,
                        $costEstimated,
                        $totalTokens,
                    );
                } catch (\Throwable $e) {
                    // One exception is not isolated here: an engine that has
                    // aborted the surrounding transaction for concurrency has
                    // already discarded every write above, so swallowing it
                    // would leave this method reporting a success it did not
                    // have. It is rethrown for the retry wrapper instead.
                    if ($this->isConcurrencyAbort($e)) {
                        throw $e;
                    }

                    Log::warning('MetricsRecorder: failed to upsert cost summaries', [
                        'conversation_id' => $conversationId,
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Reservation reconciliation (US2, research.md D7) — the
                // exact same $summaryCost figure the cost_summaries
                // increments above just used, so the reconciled amount and
                // the recorded amount can never disagree (FR-014). Isolated
                // in its own try/catch, matching the cost_summaries block
                // immediately above: a concurrency abort is rethrown for
                // the retry wrapper, since this reconciliation touches
                // budget_reservation_ledger inside the same transaction and
                // carries the identical abort risk; everything else is
                // logged and swallowed, because a reservation-specific
                // failure must never suppress the UsageRecord/
                // usage_summaries/cost_summaries writes already committed
                // above in this same closure.
                try {
                    app(BudgetGate::class)->reconcileHeld($userId, $summaryCost ?? '0.0000000000');
                } catch (\Throwable $e) {
                    if ($this->isConcurrencyAbort($e)) {
                        throw $e;
                    }

                    Log::warning('MetricsRecorder: failed to reconcile a held reservation', [
                        'conversation_id' => $conversationId,
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

            // Spending thresholds are evaluated here because this is the
            // only place in the package where consumption can increase.
            //
            // Placement is two decisions, both with a wrong answer that
            // looks right. It sits *after* the transaction closure returns,
            // not inside it: inside, the notifier would read a total that has
            // not been committed and could latch a period's single warning
            // for consumption a subsequent rollback erases — and a
            // once-per-period latch cannot be undone by any later unit of
            // work. And it has its *own* try/catch, matching the three
            // isolation blocks above, because this method is fire-and-forget
            // and must never throw into a conversation: a broadcast failure
            // in front of the usage record would suppress the record itself.
            //
            // Resolved from the container at call time rather than injected,
            // because MetricsRecorder is constructed with `new` at most of
            // its call sites and would otherwise have no notifier at all.
            try {
                app(BudgetThresholdNotifier::class)->notify($userId);
            } catch (\Throwable $e) {
                Log::warning('MetricsRecorder: failed to evaluate spending thresholds', [
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('MetricsRecorder: failed to record usage', [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Detect a provider-reported reused (cache-read) input token figure from
     * the raw provider usage array, checking two known shapes in order:
     * Anthropic's `cache_read_input_tokens` key, then OpenAI's nested
     * `prompt_tokens_details.cached_tokens` key. Returns null when neither
     * shape is present — unknown, never zero (research.md D3, contracts §2).
     *
     * Protected (not private) so a test subclass can override this to force
     * the inner try/catch failure-isolation path deterministically
     * (research.md D9b).
     */
    protected function extractReusedInputTokens(array $providerUsage): ?int
    {
        if (array_key_exists('cache_read_input_tokens', $providerUsage)) {
            return (int) $providerUsage['cache_read_input_tokens'];
        }

        if (array_key_exists('prompt_tokens_details', $providerUsage)) {
            $details = $providerUsage['prompt_tokens_details'];

            // A shallow (array) cast of a json_decode()'d object (used by the
            // legacy OpenAI stream handler) leaves nested values as stdClass —
            // normalize before checking the nested key.
            if (is_object($details)) {
                $details = (array) $details;
            }

            if (is_array($details) && array_key_exists('cached_tokens', $details)) {
                return (int) $details['cached_tokens'];
            }
        }

        return null;
    }

    /**
     * Record a single tool invocation attempt outcome.
     */
    public function recordToolInvocation(
        string $conversationId,
        string $userId,
        string $attemptGroupId,
        string $toolName,
        bool $success,
        ?ToolFailureCategory $failureCategory = null,
        ?array $coMemberTags = null,
        ?string $agentId = null,
    ): void {
        try {
            // Captured once so the detail row's own timestamp and the
            // tool_reliability_summaries.period_date bucket it feeds below
            // can never drift apart from one another.
            $now = now();

            ToolInvocationRecord::create([
                'id' => (string) Str::uuid(),
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'attempt_group_id' => $attemptGroupId,
                'tool_name' => $toolName,
                'outcome' => $success ? 'success' : 'failure',
                'failure_category' => $failureCategory?->value,
                'co_member_tags' => $coMemberTags,
                'agent_id' => $agentId,
                'created_at' => $now,
            ]);

            // tool_reliability_summaries bucketing — isolated in its own
            // try/catch so a failure specific to this rollup can never
            // suppress the detail-row write above, matching the isolation
            // pattern already established for upsertCostSummary() in
            // recordUsage().
            try {
                $this->upsertToolReliabilitySummary(
                    $toolName,
                    $agentId ?? ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET,
                    $userId,
                    $now->toDateString(),
                    $success,
                    $failureCategory,
                );
            } catch (\Throwable $e) {
                Log::warning('MetricsRecorder: failed to upsert tool reliability summary', [
                    'conversation_id' => $conversationId,
                    'tool_name' => $toolName,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('MetricsRecorder: failed to record tool invocation', [
                'conversation_id' => $conversationId,
                'tool_name' => $toolName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Upsert a tool_reliability_summaries row using the same atomic
     * insertOrIgnore + column = column + n idiom as upsertSummary()/
     * upsertCostSummary() (data-model.md §3). Increments invocation_count by
     * 1, exactly one of success_count/failure_count by 1, and — only for a
     * failure — exactly one of the seven breakdown columns by 1: the column
     * matching $failureCategory->value when a category was given, else the
     * reserved "uncategorized" column (kept distinct from "other", which is
     * itself a real classification).
     */
    private function upsertToolReliabilitySummary(
        string $toolName,
        string $agentId,
        string $userId,
        string $periodDate,
        bool $success,
        ?ToolFailureCategory $failureCategory,
    ): void {
        // Ensure the summary row exists. insertOrIgnore is a no-op when a row
        // for this bucket already exists (unique constraint), so a
        // concurrent create by another request cannot produce a duplicate.
        DB::table('tool_reliability_summaries')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'tool_name' => $toolName,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'period_date' => $periodDate,
            'invocation_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'failure_timeout_count' => 0,
            'failure_connection_failure_count' => 0,
            'failure_authentication_failure_count' => 0,
            'failure_invalid_input_count' => 0,
            'failure_server_error_count' => 0,
            'failure_other_count' => 0,
            'failure_uncategorized_count' => 0,
            'updated_at' => now(),
        ]);

        $failureBreakdownColumn = null;

        if (!$success) {
            $failureBreakdownColumn = $failureCategory !== null
                ? (ToolReliabilitySummary::FAILURE_CATEGORY_COLUMNS[$failureCategory->value] ?? ToolReliabilitySummary::UNCATEGORIZED_COLUMN)
                : ToolReliabilitySummary::UNCATEGORIZED_COLUMN;
        }

        // Atomic increment. Every incremented value here is a literal 1 or
        // 0, never a caller-influenced string, so raw interpolation is
        // injection-safe (matching upsertCostSummary()'s identical
        // argument).
        $update = [
            'invocation_count' => DB::raw('invocation_count + 1'),
            'success_count' => DB::raw('success_count + '.($success ? 1 : 0)),
            'failure_count' => DB::raw('failure_count + '.($success ? 0 : 1)),
            'updated_at' => now(),
        ];

        if ($failureBreakdownColumn !== null) {
            $update[$failureBreakdownColumn] = DB::raw("{$failureBreakdownColumn} + 1");
        }

        DB::table('tool_reliability_summaries')
            ->where('tool_name', $toolName)
            ->where('agent_id', $agentId)
            ->where('user_id', $userId)
            ->where('period_date', $periodDate)
            ->update($update);
    }

    /**
     * Upsert a usage summary row using an atomic DB-side increment.
     *
     * The counts are applied with a single `column = column + n` UPDATE so
     * concurrent writers (parallel tool calls, multiple workers) cannot lose
     * updates via a read-modify-write race. The row is first materialised with
     * `insertOrIgnore`, which relies on the unique `(entity_type, entity_id)`
     * constraint to make the create idempotent under concurrency.
     */
    private function upsertSummary(
        string $entityType,
        string $entityId,
        int $inputTokens,
        int $outputTokens,
        int $totalTokens,
        int $estimatedInputTokens,
        int $estimatedOutputTokens,
        int $estimatedTotalTokens,
    ): void {
        // Ensure the summary row exists. insertOrIgnore is a no-op when a row
        // for this entity already exists (unique constraint), so a concurrent
        // create by another request cannot produce a duplicate.
        DB::table('usage_summaries')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'estimated_input_tokens' => 0,
            'estimated_output_tokens' => 0,
            'estimated_total_tokens' => 0,
            'request_count' => 0,
            'updated_at' => now(),
        ]);

        // Atomic increment. The values are typed ints, so interpolating them
        // into the raw expression is injection-safe.
        DB::table('usage_summaries')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->update([
                'input_tokens' => DB::raw("input_tokens + {$inputTokens}"),
                'output_tokens' => DB::raw("output_tokens + {$outputTokens}"),
                'total_tokens' => DB::raw("total_tokens + {$totalTokens}"),
                'estimated_input_tokens' => DB::raw("estimated_input_tokens + {$estimatedInputTokens}"),
                'estimated_output_tokens' => DB::raw("estimated_output_tokens + {$estimatedOutputTokens}"),
                'estimated_total_tokens' => DB::raw("estimated_total_tokens + {$estimatedTotalTokens}"),
                'request_count' => DB::raw('request_count + 1'),
                'updated_at' => now(),
            ]);
    }

    /**
     * Upsert a cost_summaries row using the same atomic insertOrIgnore +
     * column = column + n idiom as upsertSummary() (data-model.md §3).
     *
     * $periodDate is a pre-formatted Y-m-d string, not a DateTimeInterface,
     * matching every call site and the raw DB::table() idiom this class
     * already uses elsewhere. $totalCost is validated against a strict
     * decimal-string shape before being interpolated into the atomic
     * priced_cost_total increment, mirroring upsertSummary()'s own stated
     * injection-safety argument for typed-value interpolation.
     */
    private function upsertCostSummary(
        string $entityType,
        string $entityId,
        string $userId,
        string $periodDate,
        string $totalCost,
        bool $unpriced,
        bool $isZeroPriced,
        bool $estimated,
        int $totalTokens,
    ): void {
        if (!preg_match('/^-?\d+\.\d{10}$/', $totalCost)) {
            throw new \InvalidArgumentException("Invalid cost string for cost_summaries upsert: '{$totalCost}'");
        }

        // Ensure the summary row exists. insertOrIgnore is a no-op when a row
        // for this bucket already exists (unique constraint), so a concurrent
        // create by another request cannot produce a duplicate.
        DB::table('cost_summaries')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $userId,
            'period_date' => $periodDate,
            'request_count' => 0,
            'priced_cost_total' => '0.0000000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => now(),
        ]);

        // Atomic increment. priced_cost_total is 0 for an unpriced request
        // (never adding a null), and unpriced_total_tokens is 0 for a priced
        // request — no lost update across concurrent writers for the same
        // bucket.
        $unpricedTokensIncrement = $unpriced ? $totalTokens : 0;

        DB::table('cost_summaries')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('user_id', $userId)
            ->where('period_date', $periodDate)
            ->update([
                'request_count' => DB::raw('request_count + 1'),
                'priced_cost_total' => DB::raw("priced_cost_total + {$totalCost}"),
                'zero_priced_request_count' => DB::raw('zero_priced_request_count + '.($isZeroPriced ? 1 : 0)),
                'unpriced_request_count' => DB::raw('unpriced_request_count + '.($unpriced ? 1 : 0)),
                'unpriced_total_tokens' => DB::raw("unpriced_total_tokens + {$unpricedTokensIncrement}"),
                'estimated_request_count' => DB::raw('estimated_request_count + '.($estimated ? 1 : 0)),
                'updated_at' => now(),
            ]);
    }

    /**
     * Record context management metrics for a single request.
     *
     * Writes one detail row per mechanism step in the outcome, or exactly one
     * `none` row when no mechanisms fired. Upserts conversation and user
     * summaries with atomic increments.
     *
     * @param string                       $conversationId Conversation UUID
     * @param string                       $userId         User UUID (attribution)
     * @param string|null                  $attemptGroupId Groups retries within a turn (nullable on streaming-start path)
     * @param ContextManagementOutcome     $outcome        Outcome populated by budgeter/condenser
     */
    public function recordContextManagement(
        string $conversationId,
        string $userId,
        ?string $attemptGroupId,
        ContextManagementOutcome $outcome,
    ): void {
        try {
            $steps = $outcome->getSteps();

            // If no mechanisms fired, record a single 'none' row.
            if (empty($steps)) {
                ContextManagementRecord::create([
                    'id' => (string) Str::uuid(),
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'attempt_group_id' => $attemptGroupId,
                    'mechanism' => 'none',
                    'history_budget' => $outcome->historyBudget,
                    'context_capacity' => $outcome->contextCapacity,
                    'tokens_before' => $outcome->tokensBefore,
                    'tokens_after' => $outcome->tokensAfter,
                    'request_tokens_before' => $outcome->tokensBefore,
                    'tokens_saved' => 0,
                    'model' => $outcome->model,
                    'provider_type' => $outcome->providerType,
                ]);

                // Increment total_requests once for the 'none' request.
                $this->upsertContextManagementSummary(
                    ContextManagementSummary::ENTITY_CONVERSATION,
                    $conversationId,
                    0, 0, 0, 0, 1,
                );
                $this->upsertContextManagementSummary(
                    ContextManagementSummary::ENTITY_USER,
                    $userId,
                    0, 0, 0, 0, 1,
                );
                return;
            }

            // Write one detail row per step.
            foreach ($steps as $step) {
                ContextManagementRecord::create([
                    'id' => (string) Str::uuid(),
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'attempt_group_id' => $attemptGroupId,
                    'mechanism' => $step->mechanism,
                    'history_budget' => $outcome->historyBudget,
                    'context_capacity' => $outcome->contextCapacity,
                    'tokens_before' => $step->tokensBefore,
                    'tokens_after' => $step->tokensAfter,
                    // Request-level numerator, identical on every row of this request.
                    'request_tokens_before' => $outcome->tokensBefore,
                    'tokens_saved' => $step->tokensSaved,
                    'model' => $outcome->model,
                    'provider_type' => $outcome->providerType,
                    'error' => $step->error,
                ]);
            }

            // Aggregate activation counts from steps.
            $trimActivations = 0;
            $smartTrimActivations = 0;
            $condenseActivations = 0;
            $totalTokensSaved = 0;

            foreach ($steps as $step) {
                $totalTokensSaved += $step->tokensSaved;
                match ($step->mechanism) {
                    'trim' => $trimActivations++,
                    'smart_trim' => $smartTrimActivations++,
                    'condense' => $condenseActivations++,
                    default => null,
                };
            }

            // Upsert conversation and user summaries (total_requests incremented once per request).
            $this->upsertContextManagementSummary(
                ContextManagementSummary::ENTITY_CONVERSATION,
                $conversationId,
                $trimActivations,
                $smartTrimActivations,
                $condenseActivations,
                $totalTokensSaved,
                1,
            );
            $this->upsertContextManagementSummary(
                ContextManagementSummary::ENTITY_USER,
                $userId,
                $trimActivations,
                $smartTrimActivations,
                $condenseActivations,
                $totalTokensSaved,
                1,
            );
        } catch (\Throwable $e) {
            Log::warning('MetricsRecorder: failed to record context management', [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Upsert a context management summary row using an atomic DB-side increment.
     *
     * Same pattern as upsertSummary: insertOrIgnore + column = column + n UPDATE
     * so concurrent writers cannot lose updates via a read-modify-write race.
     */
    private function upsertContextManagementSummary(
        string $entityType,
        string $entityId,
        int $trimActivations,
        int $smartTrimActivations,
        int $condenseActivations,
        int $totalTokensSaved,
        int $totalRequests,
    ): void {
        DB::table('context_management_summaries')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'trim_activations' => 0,
            'smart_trim_activations' => 0,
            'condense_activations' => 0,
            'total_tokens_saved' => 0,
            'total_requests' => 0,
            'updated_at' => now(),
        ]);

        DB::table('context_management_summaries')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->update([
                'trim_activations' => DB::raw("trim_activations + {$trimActivations}"),
                'smart_trim_activations' => DB::raw("smart_trim_activations + {$smartTrimActivations}"),
                'condense_activations' => DB::raw("condense_activations + {$condenseActivations}"),
                'total_tokens_saved' => DB::raw("total_tokens_saved + {$totalTokensSaved}"),
                'total_requests' => DB::raw("total_requests + {$totalRequests}"),
                'updated_at' => now(),
            ]);
    }

    /**
     * Record one degraded response — the only write for this fact
     * (research.md D11): called once, from inside
     * DegradationGate::linkRun(), the same moment the decision is first
     * persisted for a fresh run. Writes one DegradationEvent detail row
     * and upserts DegradationSummary rows for both the conversation's user
     * (when known) and the installation, using the identical
     * insertOrIgnore + column = column + n atomic-upsert idiom
     * upsertContextManagementSummary() already establishes — never a
     * read-modify-write (mutation-testing row 14).
     *
     * Never throws — mirrors recordContextManagement()'s own outer
     * try/catch shape.
     */
    public function recordDegradation(
        string $conversationId,
        ?string $userId,
        ?string $runId,
        ReductionStep $step,
        string $axis,
        string $ratio,
    ): void {
        try {
            DegradationEvent::create([
                'run_id' => $runId,
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'reduction_step_id' => $step->id,
                'axis' => $axis,
                'ratio' => $ratio,
                'applied_at' => now(),
            ]);

            if ($userId !== null) {
                $this->upsertDegradationSummary(DegradationSummary::ENTITY_USER, $userId);
            }

            $this->upsertDegradationSummary(DegradationSummary::ENTITY_INSTALLATION, SpendingCeiling::INSTALLATION_SCOPE_ID);
        } catch (\Throwable $e) {
            Log::warning('MetricsRecorder: failed to record degradation', [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Upsert a degradation summary row using an atomic DB-side increment —
     * same pattern as upsertContextManagementSummary(): insertOrIgnore +
     * column = column + n UPDATE, so concurrent writers cannot lose
     * updates via a read-modify-write race.
     */
    private function upsertDegradationSummary(string $entityType, string $entityId): void
    {
        DB::table('degradation_summaries')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'degraded_response_count' => 0,
            'last_degraded_at' => null,
            'updated_at' => now(),
        ]);

        DB::table('degradation_summaries')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->update([
                'degraded_response_count' => DB::raw('degraded_response_count + 1'),
                'last_degraded_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Record memory retrieval metrics for a single retrieval operation.
     *
     * Log-only recording — writes structured info to the Laravel log.
     * Follows the same try-catch/log-on-fail, never-throws pattern as other
     * record* methods.
     *
     * @param string  $userId           User UUID (attribution)
     * @param string  $agentId          Agent/character ID
     * @param string  $conversationId   Conversation UUID
     * @param float   $latencyMs        Retrieval latency in milliseconds
     * @param int     $tokensAdded      Total tokens added to context
     * @param int     $hitCount         Number of memory hits returned
     * @param array   $hitsByStore      Count of hits per store (e.g., ['declarative' => 3, 'episodic' => 1])
     * @param array   $degradationEvents List of degradation event strings
     */
    public function recordMemoryRetrieval(
        string $userId,
        string $agentId,
        string $conversationId,
        float $latencyMs,
        int $tokensAdded,
        int $hitCount,
        array $hitsByStore,
        array $degradationEvents,
    ): void {
        try {
            Log::info('Memory retrieval recorded', [
                'user_id' => $userId,
                'agent_id' => $agentId,
                'conversation_id' => $conversationId,
                'latency_ms' => round($latencyMs, 2),
                'tokens_added' => $tokensAdded,
                'hit_count' => $hitCount,
                'hits_by_store' => $hitsByStore,
                'degradation_events' => $degradationEvents,
                'degradation_count' => count($degradationEvents),
            ]);
        } catch (\Throwable $e) {
            Log::warning('MetricsRecorder: failed to record memory retrieval', [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
