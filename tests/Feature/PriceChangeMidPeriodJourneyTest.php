<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\ModelPriceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 3 end-to-end (spec.md Acceptance Scenarios 1-3, FR-003/FR-006/
 * FR-007): a price change — made exclusively through
 * ModelPriceService::setPrice(), the only write path for model_prices — must
 * never reach an already-written usage_records row. Cost is computed once,
 * at write time, from ModelPrice::currentFor() at that instant, and never
 * re-derived afterward (research.md D2, T033's cost-computation block).
 *
 * This test is expected to pass with zero production-code changes (T044):
 * the write-time-capture design already satisfies FR-003/FR-007/FR-006 by
 * construction, since nothing in this package ever re-reads model_prices
 * for an existing usage_records row after it is created.
 *
 * Response envelope / field-name assumptions mirror CostRollupJourneyTest's
 * own documented assumptions (contracts/cost-api.md §3's flat top-level
 * shape for the single-entity endpoints). The individual-record checks read
 * UsageRecord directly via Eloquent rather than through GET
 * /usage-records/{id}, which does not exist until Phase 6 (User Story 4).
 */
class PriceChangeMidPeriodJourneyTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::table('model_prices')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('usage_records')->delete();
        DB::table('conversations')->delete();
        DB::table('users')->delete();
        parent::tearDown();
    }

    /**
     * 900 reused / 300 fresh / 450 output tokens — the same shape used
     * throughout MetricsRecorderCostTest/CostRollupJourneyTest, so the
     * per-component math below is directly comparable to those tests'
     * documented worked examples.
     */
    private function providerUsage(): array
    {
        return [
            'prompt_tokens' => 1200,
            'completion_tokens' => 450,
            'total_tokens' => 1650,
            'cache_read_input_tokens' => 900,
        ];
    }

    /**
     * Records one usage_records row through the real MetricsRecorder write
     * path and returns its id, located via a fresh attempt_group_id rather
     * than "most recently created" so it stays unambiguous even when two
     * calls in the same test share a frozen Carbon::setTestNow() instant.
     */
    private function recordUsage(string $conversationId, string $userId, string $providerType, string $model): string
    {
        $attemptGroupId = (string) Str::uuid();

        (new MetricsRecorder())->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            providerUsage: $this->providerUsage(),
            inputText: 'input text',
            outputText: 'output text',
            model: $model,
            providerType: $providerType,
        );

        return UsageRecord::where('attempt_group_id', $attemptGroupId)->firstOrFail()->id;
    }

    private function endpoint(string $path): string
    {
        return '/api/clarion-app/llm-client/cost-rollups/'.$path;
    }

    #[Test]
    public function a_price_change_via_model_price_service_leaves_the_earlier_records_stored_total_cost_byte_for_byte_unchanged(): void
    {
        $providerType = 'anthropic';
        $model = 'claude-sonnet-5';

        $t0 = Carbon::parse('2026-08-10 08:00:00');
        Carbon::setTestNow($t0);

        $service = new ModelPriceService();
        // Price A, effective from t0.
        $service->setPrice($providerType, $model, [
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
        ], $t0);

        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'price-a-period']);

        $recordAId = $this->recordUsage($conversation->id, $user->id, $providerType, $model);
        $storedBeforeChange = UsageRecord::find($recordAId)->total_cost;

        $this->assertNotNull($storedBeforeChange, 'Usage under price A must be priced, not unpriced');

        // Change the price to B, one hour later, the same calendar day —
        // exclusively through ModelPriceService::setPrice(), the only write
        // path for model_prices (FR-003).
        $t1 = $t0->copy()->addHour();
        Carbon::setTestNow($t1);
        $service->setPrice($providerType, $model, [
            'reused_input_rate' => '0.60000000',
            'fresh_input_rate' => '6.00000000',
            'output_rate' => '30.00000000',
        ], $t1);

        // More usage recorded under the new, higher price B.
        $this->recordUsage($conversation->id, $user->id, $providerType, $model);

        $storedAfterChange = UsageRecord::find($recordAId)->total_cost;

        $this->assertSame(
            $storedBeforeChange,
            $storedAfterChange,
            'A later price change must never rewrite the cost already stored for earlier usage (FR-003/FR-007)'
        );
    }

    #[Test]
    public function a_rollup_spanning_a_price_change_sums_the_first_batch_at_the_old_rate_and_the_second_at_the_new_rate_never_recomputing_the_first(): void
    {
        $providerType = 'anthropic';
        $model = 'claude-sonnet-5';

        $t0 = Carbon::parse('2026-08-10 08:00:00');
        Carbon::setTestNow($t0);

        $service = new ModelPriceService();
        $service->setPrice($providerType, $model, [
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
        ], $t0);

        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'spans-price-change']);

        // Price A: 900 reused @ 0.30 + 300 fresh @ 3.00 + 450 output @ 15.00
        // (all per 1,000,000 tokens) = 0.00027 + 0.0009 + 0.00675 = 0.00792
        $this->recordUsage($conversation->id, $user->id, $providerType, $model);

        $t1 = $t0->copy()->addHour();
        Carbon::setTestNow($t1);
        $service->setPrice($providerType, $model, [
            'reused_input_rate' => '0.60000000',
            'fresh_input_rate' => '6.00000000',
            'output_rate' => '30.00000000',
        ], $t1);

        // Price B (exactly double price A's rates): 900 reused @ 0.60 + 300
        // fresh @ 6.00 + 450 output @ 30.00 = 0.00054 + 0.0018 + 0.0135 = 0.01584
        $this->recordUsage($conversation->id, $user->id, $providerType, $model);

        $day = $t0->toDateString();
        $response = $this->actingAs($user)->getJson(
            $this->endpoint("conversations/{$conversation->id}?from={$day}&to={$day}")
        );

        $response->assertStatus(200);
        $this->assertSame(2, (int) $response->json('request_count'));

        // 0.00792 (price A record's already-stored total_cost) + 0.01584
        // (price B record's own cost) = 0.02376. If the first record had
        // instead been recomputed at price B's rate, the total would be
        // 0.01584 * 2 = 0.03168 — a distinctly different, wrong number this
        // assertion would catch.
        $this->assertEqualsWithDelta(
            0.02376,
            (float) $response->json('priced_cost_total'),
            0.0000001,
            'A rollup spanning a price change must sum each record at its own effective rate, never recompute an earlier record at the new rate'
        );
    }

    #[Test]
    public function usage_recorded_while_a_model_had_no_price_configured_stays_unpriced_permanently_after_a_price_is_later_configured(): void
    {
        $providerType = 'newly_priced_provider';
        $model = 'newly-priced-model';

        $t0 = Carbon::parse('2026-08-11 08:00:00');
        Carbon::setTestNow($t0);

        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'unpriced-then-priced']);

        // Deliberately no ModelPrice row for this (provider_type, model)
        // pair at this point.
        $unpricedRecordId = $this->recordUsage($conversation->id, $user->id, $providerType, $model);

        $unpricedRecord = UsageRecord::find($unpricedRecordId);
        $this->assertTrue((bool) $unpricedRecord->cost_unpriced);
        $this->assertNull($unpricedRecord->total_cost);

        // A price is configured for this pair for the first time, one hour
        // later, the same calendar day.
        $t1 = $t0->copy()->addHour();
        Carbon::setTestNow($t1);
        (new ModelPriceService())->setPrice($providerType, $model, [
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
        ], $t1);

        $pricedRecordId = $this->recordUsage($conversation->id, $user->id, $providerType, $model);
        $pricedRecord = UsageRecord::find($pricedRecordId);
        $this->assertFalse((bool) $pricedRecord->cost_unpriced);
        $this->assertNotNull($pricedRecord->total_cost);

        // The earlier, unpriced record is unaffected by the price now
        // existing for this pair — re-read individually.
        $unpricedRecordAfter = UsageRecord::find($unpricedRecordId);
        $this->assertTrue(
            (bool) $unpricedRecordAfter->cost_unpriced,
            'Usage recorded before a model had any price must remain unpriced permanently (FR-007)'
        );
        $this->assertNull(
            $unpricedRecordAfter->total_cost,
            'A newly configured price must not retroactively cost earlier, previously-unpriced usage'
        );

        // A rollup spanning the price's introduction: exactly one priced
        // request (the second) and one unpriced request (the first) — the
        // earlier usage must never be folded into priced_cost_total as if
        // it were free, nor recomputed under the newly configured price.
        $day = $t0->toDateString();
        $response = $this->actingAs($user)->getJson(
            $this->endpoint("conversations/{$conversation->id}?from={$day}&to={$day}")
        );

        $response->assertStatus(200);
        $this->assertSame(2, (int) $response->json('request_count'));
        $this->assertSame(
            1,
            (int) $response->json('unpriced_request_count'),
            'The earlier, pre-price usage must remain visibly unpriced in a rollup spanning the price\'s introduction'
        );

        // 900 reused @ 0.30 + 300 fresh @ 3.00 + 450 output @ 15.00 =
        // 0.00792 — the priced record's cost alone; the unpriced record
        // contributes nothing to priced_cost_total (FR-013).
        $this->assertEqualsWithDelta(
            0.00792,
            (float) $response->json('priced_cost_total'),
            0.0000001,
            'Unpriced usage must never be summed into priced_cost_total as though it cost nothing'
        );
    }
}
