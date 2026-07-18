<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Models\UsageSummary;
use ClarionApp\LlmClient\Models\ContextManagementRecord;
use ClarionApp\LlmClient\Models\ContextManagementSummary;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
use ClarionApp\LlmClient\ValueObjects\ContextManagementOutcome;
use ClarionApp\LlmClient\ValueObjects\ContextManagementStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetricsRecorder
{
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
        ?array $coMemberTags = null
    ): void {
        try {
            DB::transaction(function () use ($conversationId, $userId, $attemptGroupId, $providerUsage, $inputText, $outputText, $model, $providerType, $coMemberTags) {
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
            });
        } catch (\Throwable $e) {
            Log::warning('MetricsRecorder: failed to record usage', [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
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
        ?array $coMemberTags = null
    ): void {
        try {
            ToolInvocationRecord::create([
                'id' => (string) Str::uuid(),
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'attempt_group_id' => $attemptGroupId,
                'tool_name' => $toolName,
                'outcome' => $success ? 'success' : 'failure',
                'failure_category' => $failureCategory?->value,
                'co_member_tags' => $coMemberTags,
            ]);
        } catch (\Throwable $e) {
            Log::warning('MetricsRecorder: failed to record tool invocation', [
                'conversation_id' => $conversationId,
                'tool_name' => $toolName,
                'error' => $e->getMessage(),
            ]);
        }
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
}
