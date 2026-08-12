<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * A not-yet-executed unit of work's estimate, returned by CostEstimator
 * (contracts/reservation-api.md §3).
 *
 * `unpriced` is true exactly when ModelPrice::currentFor() found no row for
 * the given (providerType, model) pair — the identical predicate
 * MetricsRecorder::recordUsage() already uses at completion time.
 *
 * `amount` is null for an unpriced estimate under the 'stop'/
 * 'admit_untracked' policies (there is nothing to reserve); under
 * 'reserve_flat_estimate' it carries the configured flat figure instead
 * (research.md D8) so a caller never has to re-consult the policy just to
 * learn what number to reserve.
 */
final readonly class EstimatedCost
{
    public function __construct(
        public ?string $amount,
        public bool $unpriced,
    ) {
    }
}
