<?php

namespace ClarionApp\LlmClient\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * What a scope has consumed in the period currently under way, together
 * with the period it was measured over.
 *
 * Every monetary figure is a plain-decimal string at 10 decimal places.
 * No (float) cast appears anywhere in this class, and none may be added:
 * a float formed here would propagate straight into a bcmath comparison
 * against a ceiling and into the JSON body a user reads.
 *
 * `available = false` is the "the figure could not be read" case. When it
 * is false the numeric fields are omitted from toArray() entirely rather
 * than being rendered as zero — an omitted figure cannot be misread as
 * "nothing spent", whereas a zero can.
 */
final readonly class ConsumptionSnapshot
{
    /**
     * The caveat carried on every warning, refusal, and standing report
     * without exception. It is a field rather than prose assembled by each
     * caller so an interface renders it rather than reconstructing it.
     */
    public const APPROXIMATION_NOTE = 'Consumption is approximate: the cost of a unit of work is only known once that work completes.';

    public function __construct(
        public ?string $amount,
        public ?int $requestCount,
        public ?int $unpricedRequestCount,
        public ?int $unpricedTotalTokens,
        public ?bool $hasEstimatedCost,
        public string $periodType,
        public string $periodFrom,
        public string $periodTo,
        public CarbonImmutable $resetsAt,
        public bool $available = true,
    ) {
    }

    /**
     * The figure could not be read. Only the period is known — every
     * consumption field is absent, not zero.
     */
    public static function unavailable(
        string $periodType,
        string $periodFrom,
        string $periodTo,
        CarbonImmutable $resetsAt,
    ): self {
        return new self(
            amount: null,
            requestCount: null,
            unpricedRequestCount: null,
            unpricedTotalTokens: null,
            hasEstimatedCost: null,
            periodType: $periodType,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            resetsAt: $resetsAt,
            available: false,
        );
    }

    /**
     * The wire shape. `approximate` is always true and `approximation_note`
     * is always present; `unpriced_disclosure` appears only when there is
     * genuinely unpriced usage to disclose, so it is never rendered as
     * "0 unpriced" noise.
     */
    public function toArray(): array
    {
        $body = [
            'currency' => (string) config('llm-client.cost.currency', 'USD'),
        ];

        if ($this->available) {
            $body['amount'] = $this->amount;
            $body['request_count'] = $this->requestCount;
        }

        $body['approximate'] = true;
        $body['approximation_note'] = self::APPROXIMATION_NOTE;

        if ($this->available) {
            $body['unpriced_request_count'] = $this->unpricedRequestCount;
            $body['unpriced_total_tokens'] = $this->unpricedTotalTokens;

            if (($this->unpricedRequestCount ?? 0) > 0) {
                $body['unpriced_disclosure'] = sprintf(
                    'This figure excludes %d request(s) on models with no configured price; '
                    .'that usage has no currency cost and cannot be counted toward a currency ceiling.',
                    $this->unpricedRequestCount
                );
            }

            $body['has_estimated_cost'] = (bool) $this->hasEstimatedCost;
        }

        $body['available'] = $this->available;

        return $body;
    }
}
