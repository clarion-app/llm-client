<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\ModelPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only write path for model_prices (data-model.md §1's state-transition
 * rules, FR-001/FR-002/FR-003). A price is never updated in place: setting a
 * new price closes whichever row was previously open for that
 * (provider_type, model) pair and opens a new one, inside one transaction.
 */
class ModelPriceService
{
    /**
     * @param  array{reused_input_rate: string, fresh_input_rate: string, output_rate: string}  $rates
     * @return array{price: ModelPrice, previous_effective_until: ?\DateTimeInterface}
     */
    public function setPrice(
        string $providerType,
        string $model,
        array $rates,
        ?\DateTimeInterface $effectiveFrom = null,
    ): array {
        $effectiveFrom = $effectiveFrom ?? Carbon::now();

        return DB::transaction(function () use ($providerType, $model, $rates, $effectiveFrom) {
            $priorOpen = ModelPrice::query()
                ->where('provider_type', $providerType)
                ->where('model', $model)
                ->whereNull('effective_until')
                ->first();

            $previousEffectiveUntil = null;

            if ($priorOpen !== null) {
                $priorOpen->effective_until = $effectiveFrom;
                $priorOpen->save();
                $previousEffectiveUntil = $effectiveFrom;
            }

            $new = ModelPrice::create([
                'provider_type' => $providerType,
                'model' => $model,
                'reused_input_rate' => $rates['reused_input_rate'],
                'fresh_input_rate' => $rates['fresh_input_rate'],
                'output_rate' => $rates['output_rate'],
                'effective_from' => $effectiveFrom,
                'effective_until' => null,
            ]);

            return [
                'price' => $new,
                'previous_effective_until' => $previousEffectiveUntil,
            ];
        });
    }
}
