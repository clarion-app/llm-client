<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 5 end-to-end (spec.md Acceptance Scenarios 1-2, SC-006, FR-015,
 * quickstart.md step 6 / mutation-testing row 4): a rollup total must be
 * fully reconcilable, to the last stored digit, with an independent sum of
 * the individual usage records that fall within its scope and period — and
 * must stay identical across repeated views with no intervening writes.
 *
 * Per tasks.md T054, this test is expected to pass with zero src/ changes:
 * cost is captured once at write time as an already-rounded decimal string
 * (MetricsRecorder::recordUsage(), T033) and summed via SQL DECIMAL
 * arithmetic in cost_summaries (T036/CostRollupQuery), so reconciliation is
 * exact by construction (research.md D1's "lossless sum of its detail rows
 * by construction").
 *
 * Performance note: usage is generated through the real
 * MetricsRecorder::recordUsage() write path in a tight loop (not real HTTP
 * round-trips per record — thousands of HTTP requests through the full
 * middleware stack would push this file's runtime into the tens of seconds
 * to minutes), matching the established precedent in every other journey
 * test in this suite (PriceChangeMidPeriodJourneyTest,
 * RollupRoleScopingJourneyTest, etc.). The independent-sum check itself
 * reads UsageRecord rows directly (summed in PHP via bcmath — never a
 * float) rather than issuing GET /usage-records/{id} once per record, for
 * the same reason. A representative subsample (40 records spanning every
 * category: normally priced, estimated, zero-priced, unpriced, and
 * thousands-of-tiny-cost) IS additionally verified through the real HTTP
 * endpoint, so the API surface itself — not just the underlying model — is
 * proven reconcilable too.
 */
class RollupReconciliationJourneyTest extends TestCase
{
    private const AGENT_ID = 'audit-agent';

    private const PROVIDER_NORMAL = 'anthropic';
    private const MODEL_NORMAL = 'claude-sonnet-5';

    private const PROVIDER_ZERO = 'llama_cpp';
    private const MODEL_ZERO = 'local-llama';

    private const PROVIDER_UNPRICED = 'unpriced_provider';
    private const MODEL_UNPRICED = 'no-such-model';

    // A distinct (provider_type, model) pair — even though it shares
    // PROVIDER_NORMAL's provider family — so the thousands-of-tiny-cost
    // batch can be queried back out separately from the "several hundred,
    // moderate size" batch for the representative-subsample check below.
    private const PROVIDER_TINY = 'anthropic';
    private const MODEL_TINY = 'claude-tiny-scale';

    // "Several hundred" (spec.md User Story 5's own Independent Test
    // wording) across the non-tiny categories combined.
    private const NORMAL_COUNT = 250;
    private const ESTIMATED_COUNT = 30;
    private const ZERO_COUNT = 30;
    private const UNPRICED_COUNT = 30;

    // "Thousands of tiny-cost records" (T053) — the scale at which a
    // (float) cast anywhere in the cost pipeline would visibly diverge the
    // rollup total from an independent re-sum of its detail rows
    // (quickstart.md mutation row 4).
    private const TINY_COUNT = 2000;

    protected function tearDown(): void
    {
        DB::table('model_prices')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('usage_records')->delete();
        DB::table('conversations')->delete();
        DB::table('users')->delete();
        parent::tearDown();
    }

    private function seedPrice(string $providerType, string $model, array $rates): ModelPrice
    {
        return ModelPrice::create(array_merge([
            'provider_type' => $providerType,
            'model' => $model,
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ], $rates));
    }

    /**
     * Records one usage_records row through the real MetricsRecorder write
     * path, with explicit reused/fresh/output token counts (via the
     * cache_read_input_tokens provider-usage shape), matching
     * MetricsRecorderCostTest's own worked-example shape.
     */
    private function recordUsage(
        string $conversationId,
        string $userId,
        string $providerType,
        string $model,
        int $reused,
        int $fresh,
        int $output,
    ): void {
        $input = $reused + $fresh;

        (new MetricsRecorder())->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [
                'prompt_tokens' => $input,
                'completion_tokens' => $output,
                'total_tokens' => $input + $output,
                'cache_read_input_tokens' => $reused,
            ],
            inputText: 'input text',
            outputText: 'output text',
            model: $model,
            providerType: $providerType,
            agentId: self::AGENT_ID,
        );
    }

    /**
     * Records one priced-but-estimated usage_records row by forcing
     * MetricsRecorder's full-estimation fallback path (empty provider
     * usage), matching UnpricedAndEstimatedUsageJourneyTest's own
     * precedent for triggering cost_estimated=true.
     */
    private function recordEstimatedUsage(
        string $conversationId,
        string $userId,
        string $providerType,
        string $model,
    ): void {
        (new MetricsRecorder())->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [],
            inputText: str_repeat('a', 400),
            outputText: str_repeat('b', 200),
            model: $model,
            providerType: $providerType,
            agentId: self::AGENT_ID,
        );
    }

    private function rollupEndpoint(string $path): string
    {
        return '/api/clarion-app/llm-client/cost-rollups/'.$path;
    }

    private function usageRecordEndpoint(string $id): string
    {
        return '/api/clarion-app/llm-client/usage-records/'.$id;
    }

    /**
     * Acceptance Scenario 1 / SC-006 / quickstart.md step 6 / mutation row
     * 4: a rollup total, at thousands-of-tiny-cost-records scale, must
     * equal an independent sum of the individual usage records in scope for
     * it — to the last stored digit — never merely "close."
     */
    #[Test]
    public function rollup_total_reconciles_exactly_with_an_independent_sum_of_its_usage_records_at_thousands_of_records_scale(): void
    {
        $this->seedPrice(self::PROVIDER_NORMAL, self::MODEL_NORMAL, [
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
        ]);
        $this->seedPrice(self::PROVIDER_ZERO, self::MODEL_ZERO, [
            'reused_input_rate' => '0',
            'fresh_input_rate' => '0',
            'output_rate' => '0',
        ]);
        $this->seedPrice(self::PROVIDER_TINY, self::MODEL_TINY, [
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
        ]);
        // Deliberately no ModelPrice row for (PROVIDER_UNPRICED, MODEL_UNPRICED).

        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'reconciliation']);

        // A: several hundred normally priced records, moderate/varying token
        // counts.
        for ($i = 0; $i < self::NORMAL_COUNT; $i++) {
            $this->recordUsage(
                $conversation->id,
                $user->id,
                self::PROVIDER_NORMAL,
                self::MODEL_NORMAL,
                reused: ($i % 137) + 1,
                fresh: ($i % 211) + 1,
                output: ($i % 89) + 1,
            );
        }

        // B: priced but estimated (cost_estimated=true) — still contributes
        // to priced_cost_total, just carries the estimate marker.
        for ($i = 0; $i < self::ESTIMATED_COUNT; $i++) {
            $this->recordEstimatedUsage($conversation->id, $user->id, self::PROVIDER_NORMAL, self::MODEL_NORMAL);
        }

        // C: genuinely zero-priced records (self-hosted-style all-zero rate).
        for ($i = 0; $i < self::ZERO_COUNT; $i++) {
            $this->recordUsage(
                $conversation->id,
                $user->id,
                self::PROVIDER_ZERO,
                self::MODEL_ZERO,
                reused: ($i % 5) + 1,
                fresh: ($i % 7) + 1,
                output: ($i % 3) + 1,
            );
        }

        // D: unpriced records — no ModelPrice row for this pair at all, so
        // they must be excluded from priced_cost_total entirely.
        for ($i = 0; $i < self::UNPRICED_COUNT; $i++) {
            $this->recordUsage(
                $conversation->id,
                $user->id,
                self::PROVIDER_UNPRICED,
                self::MODEL_UNPRICED,
                reused: ($i % 5) + 1,
                fresh: ($i % 7) + 1,
                output: ($i % 3) + 1,
            );
        }

        // E: thousands of tiny-cost priced records.
        for ($i = 0; $i < self::TINY_COUNT; $i++) {
            $this->recordUsage(
                $conversation->id,
                $user->id,
                self::PROVIDER_TINY,
                self::MODEL_TINY,
                reused: ($i % 5) + 1,
                fresh: ($i % 7) + 1,
                output: ($i % 3) + 1,
            );
        }

        $totalRecords = self::NORMAL_COUNT + self::ESTIMATED_COUNT + self::ZERO_COUNT + self::UNPRICED_COUNT + self::TINY_COUNT;
        $this->assertSame($totalRecords, UsageRecord::where('conversation_id', $conversation->id)->count());

        // Independent sum: read every priced (incl. zero-priced)
        // usage_records row's own stored total_cost back out and sum it
        // with bcmath — never a float — mirroring exactly what an operator
        // or an automated check would do per spec.md User Story 5
        // Acceptance Scenario 1 / SC-006.
        $independentSum = '0.0000000000';
        $pricedCount = 0;
        UsageRecord::where('conversation_id', $conversation->id)
            ->where('cost_unpriced', false)
            ->orderBy('id')
            ->chunk(500, function ($records) use (&$independentSum, &$pricedCount) {
                foreach ($records as $record) {
                    $independentSum = bcadd($independentSum, (string) $record->total_cost, 10);
                    $pricedCount++;
                }
            });

        $this->assertSame(
            self::NORMAL_COUNT + self::ESTIMATED_COUNT + self::ZERO_COUNT + self::TINY_COUNT,
            $pricedCount,
            'Every non-unpriced record (priced + zero-priced) must be included in the independent sum'
        );

        $today = Carbon::now()->toDateString();

        $conversationRollup = $this->actingAs($user)->getJson(
            $this->rollupEndpoint("conversations/{$conversation->id}?from={$today}&to={$today}")
        );
        $conversationRollup->assertStatus(200);

        $this->assertSame(
            $independentSum,
            $conversationRollup->json('priced_cost_total'),
            'The conversation rollup total must equal an independent sum of its underlying usage records exactly, to the last stored digit (SC-006)'
        );
        $this->assertSame($totalRecords, (int) $conversationRollup->json('request_count'));
        $this->assertSame(self::ZERO_COUNT, (int) $conversationRollup->json('zero_priced_request_count'));
        $this->assertSame(self::UNPRICED_COUNT, (int) $conversationRollup->json('unpriced_request_count'));

        // Because every record in this test shares the same single
        // conversation, user, and agent, the user- and agent-scoped
        // rollups must reconcile to the exact same total.
        $userRollup = $this->actingAs($user)->getJson(
            $this->rollupEndpoint("users/{$user->id}?from={$today}&to={$today}")
        );
        $userRollup->assertStatus(200);
        $this->assertSame(
            $independentSum,
            $userRollup->json('priced_cost_total'),
            'The user rollup (aggregated across the same single conversation) must reconcile identically'
        );

        $agentRollup = $this->actingAs($user)->getJson(
            $this->rollupEndpoint('agents/'.self::AGENT_ID."?from={$today}&to={$today}")
        );
        $agentRollup->assertStatus(200);
        $this->assertSame(
            $independentSum,
            $agentRollup->json('priced_cost_total'),
            'The agent rollup (aggregated across the same single conversation) must reconcile identically'
        );

        // Representative subsample verified through the real HTTP endpoint
        // (GET /usage-records/{id}) rather than every one of the thousands
        // of records, keeping this file's runtime to a few seconds rather
        // than minutes — see the class docblock for why. 8 ids from each of
        // the 5 categories (normal, estimated, zero-priced, unpriced,
        // tiny-scale) = 40 total HTTP round-trips.
        $subsampleIds = collect()
            ->concat(
                UsageRecord::where('conversation_id', $conversation->id)
                    ->where('provider_type', self::PROVIDER_NORMAL)->where('model', self::MODEL_NORMAL)
                    ->where('cost_estimated', false)->orderBy('id')->limit(8)->pluck('id')
            )
            ->concat(
                UsageRecord::where('conversation_id', $conversation->id)
                    ->where('cost_estimated', true)->orderBy('id')->limit(8)->pluck('id')
            )
            ->concat(
                UsageRecord::where('conversation_id', $conversation->id)
                    ->where('provider_type', self::PROVIDER_ZERO)->orderBy('id')->limit(8)->pluck('id')
            )
            ->concat(
                UsageRecord::where('conversation_id', $conversation->id)
                    ->where('cost_unpriced', true)->orderBy('id')->limit(8)->pluck('id')
            )
            ->concat(
                UsageRecord::where('conversation_id', $conversation->id)
                    ->where('provider_type', self::PROVIDER_TINY)->orderBy('id')->limit(8)->pluck('id')
            );

        $this->assertSame(40, $subsampleIds->count(), 'The representative subsample must span every category (8 each)');

        $subsampleSum = '0.0000000000';
        foreach ($subsampleIds as $id) {
            $record = UsageRecord::find($id);
            $response = $this->actingAs($user)->getJson($this->usageRecordEndpoint($id));
            $response->assertStatus(200, "GET /usage-records/{$id} must succeed for a record whose stored total_cost is {$record->total_cost}");

            if ($record->cost_unpriced) {
                $this->assertNull($response->json('cost.total_cost'));
                continue;
            }

            $this->assertSame(
                Decimal::round((string) $record->total_cost, 10),
                $response->json('cost.total_cost'),
                "GET /usage-records/{$id} must report exactly the stored total_cost for this record"
            );
            $subsampleSum = bcadd($subsampleSum, $response->json('cost.total_cost'), 10);
        }

        // Cross-check: the subsample's own sum (as reported by the real
        // HTTP endpoint) must independently agree with a direct-model sum
        // of the exact same ids.
        $subsampleDirectSum = '0.0000000000';
        foreach ($subsampleIds as $id) {
            $record = UsageRecord::find($id);
            if (!$record->cost_unpriced) {
                $subsampleDirectSum = bcadd($subsampleDirectSum, (string) $record->total_cost, 10);
            }
        }
        $this->assertSame(
            $subsampleDirectSum,
            $subsampleSum,
            'Summing the subsample through the real per-record HTTP endpoint must agree exactly with summing the same rows directly'
        );
    }

    /**
     * Acceptance Scenario 2 / FR-015: viewing the same rollup twice, with no
     * new usage or price changes in between, returns identical totals.
     */
    #[Test]
    public function viewing_the_same_rollup_twice_with_no_new_usage_or_price_changes_in_between_returns_identical_totals(): void
    {
        $this->seedPrice(self::PROVIDER_NORMAL, self::MODEL_NORMAL, [
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'stable-view']);

        for ($i = 0; $i < 25; $i++) {
            $this->recordUsage(
                $conversation->id,
                $user->id,
                self::PROVIDER_NORMAL,
                self::MODEL_NORMAL,
                reused: ($i % 5) + 1,
                fresh: ($i % 7) + 1,
                output: ($i % 3) + 1,
            );
        }

        $today = Carbon::now()->toDateString();
        $url = $this->rollupEndpoint("conversations/{$conversation->id}?from={$today}&to={$today}");

        $firstView = $this->actingAs($user)->getJson($url);
        $firstView->assertStatus(200);

        $secondView = $this->actingAs($user)->getJson($url);
        $secondView->assertStatus(200);

        $this->assertSame(
            $firstView->json('priced_cost_total'),
            $secondView->json('priced_cost_total'),
            'Viewing the same rollup twice with no new usage or price changes in between must return identical totals (FR-015)'
        );
        $this->assertSame($firstView->json(), $secondView->json());
    }
}
