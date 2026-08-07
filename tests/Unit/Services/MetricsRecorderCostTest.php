<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for MetricsRecorder::recordUsage()'s cost-computation block
 * (T032/T033, FR-005/FR-006/FR-007/FR-008, data-model.md §2).
 *
 * The priced/mixed-rate expectations below are lifted verbatim from
 * contracts/cost-api.md §2's own worked example (input_tokens=1200,
 * reused_input_tokens=900, fresh=300, output_tokens=450, rates
 * 0.3/3.0/15.0 per 1,000,000 tokens => reused_input_cost=0.00027,
 * fresh_input_cost=0.0009, output_cost=0.00675, total_cost=0.00792) so the
 * test doubles as a check that the contract's own numbers are internally
 * consistent, not just an arbitrary fixture.
 *
 * Decimal columns are compared via (float) cast rather than assertSame on
 * the raw string, matching the precedent already established in
 * ModelPriceConfigurationJourneyTest — SQLite's NUMERIC column affinity does
 * not reliably preserve a decimal string's exact trailing-zero formatting on
 * read-back the way MySQL/MariaDB DECIMAL does, so an exact-string
 * comparison would be testing SQLite's storage quirks rather than this
 * package's own logic. Where the point of the assertion is specifically
 * "null vs a real zero" (the unpriced/zero-priced distinction), assertNull /
 * assertNotNull is used instead, since that distinction is not affected by
 * SQLite's numeric formatting at all.
 */
class MetricsRecorderCostTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('model_prices')->delete();
        parent::tearDown();
    }

    private function seedPrice(array $overrides = []): ModelPrice
    {
        return ModelPrice::create(array_merge([
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ], $overrides));
    }

    /**
     * The exact provider-usage shape backing contracts/cost-api.md §2's
     * worked example: 1200 input tokens (900 reused via
     * cache_read_input_tokens, 300 fresh), 450 output tokens.
     */
    private function pricedProviderUsage(): array
    {
        return [
            'prompt_tokens' => 1200,
            'completion_tokens' => 450,
            'total_tokens' => 1650,
            'cache_read_input_tokens' => 900,
        ];
    }

    private function record(
        array $providerUsage,
        ?string $model,
        ?string $providerType,
        ?string $agentId = null,
    ): UsageRecord {
        $recorder = new MetricsRecorder();

        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: $providerUsage,
            inputText: 'input text',
            outputText: 'output text',
            model: $model,
            providerType: $providerType,
            agentId: $agentId,
        );

        return UsageRecord::first();
    }

    #[Test]
    public function a_priced_model_computes_the_three_component_costs_and_total_from_its_own_effective_rates(): void
    {
        $price = $this->seedPrice();

        $record = $this->record($this->pricedProviderUsage(), 'claude-sonnet-5', 'anthropic');

        $this->assertNotNull($record);
        $this->assertFalse((bool) $record->cost_unpriced);
        $this->assertNotNull($record->reused_input_cost);
        $this->assertNotNull($record->fresh_input_cost);
        $this->assertNotNull($record->output_cost);
        $this->assertNotNull($record->total_cost);

        // 900 reused tokens x 0.30 / 1,000,000
        $this->assertEqualsWithDelta(0.00027, (float) $record->reused_input_cost, 0.0000001);
        // 300 fresh tokens x 3.00 / 1,000,000
        $this->assertEqualsWithDelta(0.0009, (float) $record->fresh_input_cost, 0.0000001);
        // 450 output tokens x 15.00 / 1,000,000
        $this->assertEqualsWithDelta(0.00675, (float) $record->output_cost, 0.0000001);
        // Sum of the three components, not one blended rate.
        $this->assertEqualsWithDelta(0.00792, (float) $record->total_cost, 0.0000001);

        $this->assertSame($price->id, $record->model_price_id);
    }

    #[Test]
    public function a_model_with_no_configured_price_leaves_all_four_cost_columns_null_and_flags_unpriced(): void
    {
        // Deliberately no ModelPrice row for this (provider_type, model) pair.
        $record = $this->record($this->pricedProviderUsage(), 'no-such-model', 'unknown_provider');

        $this->assertNotNull($record);
        $this->assertTrue((bool) $record->cost_unpriced, 'No price configured must set cost_unpriced=true');
        $this->assertNull($record->model_price_id);
        $this->assertNull($record->reused_input_cost);
        $this->assertNull($record->fresh_input_cost);
        $this->assertNull($record->output_cost);
        $this->assertNull(
            $record->total_cost,
            'An unpriced record must have total_cost=null, never a fabricated zero (FR-006)'
        );
    }

    #[Test]
    public function a_genuine_zero_price_produces_a_real_stored_zero_total_cost_distinct_from_unpriced(): void
    {
        $this->seedPrice([
            'provider_type' => 'llama_cpp',
            'model' => 'local-llama',
            'reused_input_rate' => '0',
            'fresh_input_rate' => '0',
            'output_rate' => '0',
        ]);

        $record = $this->record($this->pricedProviderUsage(), 'local-llama', 'llama_cpp');

        $this->assertNotNull($record);
        $this->assertFalse(
            (bool) $record->cost_unpriced,
            'A configured zero price is priced, not unpriced (SC-005)'
        );
        $this->assertNotNull(
            $record->total_cost,
            'A genuine zero price must store a real 0 value, never null'
        );
        $this->assertEqualsWithDelta(0.0, (float) $record->total_cost, 0.0000001);
    }

    #[Test]
    public function estimated_token_counts_set_cost_estimated_true(): void
    {
        $this->seedPrice();

        $recorder = new MetricsRecorder();
        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            // Empty provider usage forces the full-estimation fallback path
            // (input_estimated = output_estimated = true).
            providerUsage: [],
            inputText: str_repeat('a', 400),
            outputText: str_repeat('b', 200),
            model: 'claude-sonnet-5',
            providerType: 'anthropic',
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertTrue((bool) $record->input_estimated);
        $this->assertTrue(
            (bool) $record->cost_estimated,
            'A cost derived even partly from estimated token counts must be flagged estimated (FR-008)'
        );
    }

    #[Test]
    public function the_same_now_instant_is_used_for_the_records_own_timestamp_and_the_effective_dated_price_lookup(): void
    {
        $frozen = Carbon::parse('2026-08-07 12:00:00');
        Carbon::setTestNow($frozen);

        try {
            // A price open exactly starting at the frozen "now" — only
            // resolvable if the price lookup uses that same instant.
            $this->seedPrice([
                'effective_from' => $frozen->copy(),
                'effective_until' => null,
            ]);

            $recorder = new MetricsRecorder();
            $recorder->recordUsage(
                conversationId: (string) Str::uuid(),
                userId: (string) Str::uuid(),
                attemptGroupId: (string) Str::uuid(),
                providerUsage: $this->pricedProviderUsage(),
                inputText: 'input text',
                outputText: 'output text',
                model: 'claude-sonnet-5',
                providerType: 'anthropic',
            );
        } finally {
            Carbon::setTestNow();
        }

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertNotNull($record->created_at, 'created_at must be explicitly captured, not left to drift');
        $this->assertSame(
            '2026-08-07 12:00:00',
            Carbon::parse($record->created_at)->format('Y-m-d H:i:s'),
            'The record\'s own created_at must be the same frozen instant used to resolve the price'
        );
        $this->assertFalse(
            (bool) $record->cost_unpriced,
            'A price open exactly at the frozen now must be found by the lookup using that same now'
        );
        $this->assertNotNull($record->total_cost);
    }
}
