<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Support\Decimal;
use ClarionApp\LlmClient\ValueObjects\EstimatedCost;
use ClarionApp\LlmClient\ValueObjects\UnpricedModelPolicy;

/**
 * Estimates a not-yet-executed unit of work's cost (research.md D2), by
 * composing two services that already exist, for a *different* moment
 * (completion), reused here for an earlier one (admission):
 *
 *  - Input tokens: UsageEstimator::estimateInput() applied to the
 *    conversation's already-persisted message history, read directly —
 *    never a freshly-assembled provider payload (research.md D2's
 *    load-bearing reason: the gate must run before that assembly work).
 *  - Output tokens: a configured default
 *    (llm-client.budget.reservation.estimated_output_tokens_default) —
 *    never UsageEstimator::estimateOutput(), which needs output text that
 *    does not exist yet.
 *  - Pricing: ModelPrice::currentFor($providerType, $model, now()),
 *    applied via bcmul() against fresh_input_rate/output_rate exactly as
 *    MetricsRecorder::recordUsage() already does for the real figures — no
 *    reused_input_rate component, since an estimate has no cache-read
 *    concept.
 *
 * @throws never for an unresolvable price — reported through the return
 *   value's `unpriced` flag, so BudgetGate can apply UnpricedModelPolicy
 *   rather than every caller needing its own try/catch. The one exception
 *   is a genuine configuration defect: `on_unpriced_model` selecting
 *   'reserve_flat_estimate' with no configured flat estimate throws
 *   \InvalidArgumentException immediately, rather than silently estimating
 *   a null amount for a policy that promised a numeric one.
 */
final class CostEstimator
{
    private UsageEstimator $estimator;

    public function __construct(?UsageEstimator $estimator = null)
    {
        $this->estimator = $estimator ?? new UsageEstimator();
    }

    public function estimate(
        string $conversationId,
        ?string $providerType,
        ?string $model,
    ): EstimatedCost {
        $inputText = Message::where('conversation_id', $conversationId)
            ->pluck('content')
            ->implode('');

        $inputTokens = $this->estimator->estimateInput($inputText);
        $outputTokens = (int) config('llm-client.budget.reservation.estimated_output_tokens_default', 1000);

        $price = ModelPrice::currentFor($providerType, $model, now());

        if ($price === null) {
            return new EstimatedCost(
                amount: $this->flatEstimateForUnpricedModel(),
                unpriced: true,
            );
        }

        $inputCost = Decimal::round(
            bcdiv(bcmul((string) $inputTokens, (string) $price->fresh_input_rate, 20), '1000000', 20),
            10
        );
        $outputCost = Decimal::round(
            bcdiv(bcmul((string) $outputTokens, (string) $price->output_rate, 20), '1000000', 20),
            10
        );

        return new EstimatedCost(
            amount: bcadd($inputCost, $outputCost, 10),
            unpriced: false,
        );
    }

    /**
     * Resolves what an unpriced model's estimate should carry, per the
     * operator-chosen `on_unpriced_model` policy (research.md D8):
     * null for 'stop'/'admit_untracked' (nothing to reserve — BudgetGate
     * decides what to do with an unpriced, amount-less estimate), or the
     * configured flat figure for 'reserve_flat_estimate', validated eagerly
     * here rather than silently proceeding with a null estimate for a
     * policy that promised a numeric one.
     */
    private function flatEstimateForUnpricedModel(): ?string
    {
        $policy = UnpricedModelPolicy::from(
            config('llm-client.budget.on_unpriced_model', 'stop')
        );

        if ($policy !== UnpricedModelPolicy::ReserveFlatEstimate) {
            return null;
        }

        $flatEstimate = config('llm-client.budget.unpriced_model_flat_estimate');

        if ($flatEstimate === null || $flatEstimate === '') {
            throw new \InvalidArgumentException(
                "llm-client.budget.unpriced_model_flat_estimate must be configured when on_unpriced_model is 'reserve_flat_estimate'."
            );
        }

        return (string) $flatEstimate;
    }
}
