<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 4 end-to-end (spec.md Acceptance Scenarios 1-4,
 * contracts/cost-api.md §2): a rollup — and an individual usage record —
 * must always distinguish unpriced usage from genuinely zero-priced usage,
 * and must always mark a cost derived even partly from estimated token
 * counts as an estimate, never collapsing any of this into one ambiguous
 * number.
 *
 * Per tasks.md T047/T050, no production code exists for
 * GET /usage-records/{id} yet (UsageRecordController/route land in T048/
 * T049) — every assertion touching that endpoint is expected to fail with a
 * 404 (route not found) until then. This file's job in this phase is to
 * fail for exactly that reason, in the correct TDD-red way, and to already
 * contain every assertion needed to go green once T048/T049 land.
 *
 * Usage is generated through the real MetricsRecorder::recordUsage() write
 * path (matching CostRollupJourneyTest/RollupRoleScopingJourneyTest's own
 * precedent), never by hand-inserting usage_records/cost_summaries rows.
 *
 * Response envelope assumptions mirror the established precedents in this
 * test suite: GET /cost-rollups/... returns the contracts/cost-api.md §3
 * common shape flat at the top level (CostRollupJourneyTest); GET
 * /usage-records/{id} returns the contracts/cost-api.md §2 shape with a
 * nested "cost" object (unpriced/estimated/reused_input_cost/
 * fresh_input_cost/output_cost/total_cost) — read directly from §2's own
 * documented example response.
 */
class UnpricedAndEstimatedUsageJourneyTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('model_prices')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('usage_records')->delete();
        DB::table('conversations')->delete();
        DB::table('users')->delete();
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

    /**
     * Records one usage_records row through the real MetricsRecorder write
     * path and returns its id, located via a fresh attempt_group_id rather
     * than "most recently created" so multiple calls within one test stay
     * unambiguous.
     */
    private function recordUsage(
        string $conversationId,
        string $userId,
        string $providerType,
        string $model,
        array $providerUsage,
        string $inputText = 'input text',
        string $outputText = 'output text',
    ): string {
        $attemptGroupId = (string) Str::uuid();

        (new MetricsRecorder())->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            providerUsage: $providerUsage,
            inputText: $inputText,
            outputText: $outputText,
            model: $model,
            providerType: $providerType,
        );

        return UsageRecord::where('attempt_group_id', $attemptGroupId)->firstOrFail()->id;
    }

    private function rollupEndpoint(string $path): string
    {
        return '/api/clarion-app/llm-client/cost-rollups/'.$path;
    }

    private function usageRecordEndpoint(string $id): string
    {
        return '/api/clarion-app/llm-client/usage-records/'.$id;
    }

    private function today(): string
    {
        return Carbon::now()->toDateString();
    }

    /**
     * Acceptance Scenario 1 / FR-013: usage on a model with no configured
     * price is called out as unpriced — never summed as free.
     */
    #[Test]
    public function unpriced_usage_is_called_out_as_unpriced_at_both_the_record_and_rollup_level(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'unpriced']);

        // Deliberately no ModelPrice row for this (provider_type, model) pair.
        $recordId = $this->recordUsage(
            $conversation->id,
            $user->id,
            'unknown_provider',
            'no-such-model',
            $this->pricedProviderUsage(),
        );

        // Per-record view: cost.unpriced=true, every component cost and the
        // total are null — never "0.0000000000" (contracts/cost-api.md §2).
        $recordResponse = $this->actingAs($user)->getJson($this->usageRecordEndpoint($recordId));
        $recordResponse->assertStatus(200);
        $this->assertTrue((bool) $recordResponse->json('cost.unpriced'));
        $this->assertNull($recordResponse->json('cost.reused_input_cost'));
        $this->assertNull($recordResponse->json('cost.fresh_input_cost'));
        $this->assertNull($recordResponse->json('cost.output_cost'));
        $this->assertNull(
            $recordResponse->json('cost.total_cost'),
            'An unpriced record must report total_cost=null, never a fabricated zero string (FR-006)'
        );

        // Rollup view: the unpriced request is counted separately and never
        // folded into priced_cost_total as though it cost nothing (FR-013).
        $rollupResponse = $this->actingAs($user)->getJson(
            $this->rollupEndpoint("conversations/{$conversation->id}?from={$this->today()}&to={$this->today()}")
        );
        $rollupResponse->assertStatus(200);
        $this->assertSame(1, (int) $rollupResponse->json('request_count'));
        $this->assertSame(
            1,
            (int) $rollupResponse->json('unpriced_request_count'),
            'Unpriced usage must be counted in unpriced_request_count'
        );
        $this->assertSame(
            0,
            (int) $rollupResponse->json('zero_priced_request_count'),
            'Unpriced usage must never be counted as zero-priced'
        );
        $this->assertEqualsWithDelta(
            0.0,
            (float) $rollupResponse->json('priced_cost_total'),
            0.0000001,
            'Unpriced usage must never be summed into priced_cost_total as though it were free'
        );
    }

    /**
     * Acceptance Scenario 2 / SC-005: a genuine zero price appears as a
     * real $0.00, visibly distinct from the unpriced case.
     */
    #[Test]
    public function genuine_zero_priced_usage_appears_as_a_real_zero_distinct_from_unpriced(): void
    {
        $this->seedPrice([
            'provider_type' => 'llama_cpp',
            'model' => 'local-llama',
            'reused_input_rate' => '0',
            'fresh_input_rate' => '0',
            'output_rate' => '0',
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'zero-priced']);

        $recordId = $this->recordUsage(
            $conversation->id,
            $user->id,
            'llama_cpp',
            'local-llama',
            $this->pricedProviderUsage(),
        );

        $recordResponse = $this->actingAs($user)->getJson($this->usageRecordEndpoint($recordId));
        $recordResponse->assertStatus(200);
        $this->assertFalse(
            (bool) $recordResponse->json('cost.unpriced'),
            'A configured zero price is priced, not unpriced (SC-005)'
        );
        $this->assertNotNull(
            $recordResponse->json('cost.total_cost'),
            'A genuine zero price must report a real total_cost value, never null'
        );
        $this->assertSame(
            '0.0000000000',
            $recordResponse->json('cost.total_cost'),
            'A genuine zero price must report the literal zero string, never null'
        );

        $rollupResponse = $this->actingAs($user)->getJson(
            $this->rollupEndpoint("conversations/{$conversation->id}?from={$this->today()}&to={$this->today()}")
        );
        $rollupResponse->assertStatus(200);
        $this->assertSame(1, (int) $rollupResponse->json('request_count'));
        $this->assertSame(
            1,
            (int) $rollupResponse->json('zero_priced_request_count'),
            'A genuine zero price must be counted in zero_priced_request_count'
        );
        $this->assertSame(
            0,
            (int) $rollupResponse->json('unpriced_request_count'),
            'A genuine zero price must never be counted as unpriced'
        );
    }

    /**
     * Acceptance Scenario 3 / FR-008 / SC-009: usage whose token counts
     * were estimated shows cost_estimated at both the per-record and the
     * rollup level.
     */
    #[Test]
    public function estimated_token_counts_mark_the_cost_as_an_estimate_at_both_record_and_rollup_level(): void
    {
        $this->seedPrice();

        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'estimated']);

        // Empty provider usage forces MetricsRecorder's full-estimation
        // fallback path (input_estimated = output_estimated = true),
        // matching MetricsRecorderCostTest's own precedent for triggering
        // cost_estimated=true.
        $recordId = $this->recordUsage(
            $conversation->id,
            $user->id,
            'anthropic',
            'claude-sonnet-5',
            [],
            str_repeat('a', 400),
            str_repeat('b', 200),
        );

        $recordResponse = $this->actingAs($user)->getJson($this->usageRecordEndpoint($recordId));
        $recordResponse->assertStatus(200);
        $this->assertTrue(
            (bool) $recordResponse->json('cost.estimated'),
            'A cost derived from estimated token counts must be marked estimated at the per-record level (FR-008)'
        );

        $rollupResponse = $this->actingAs($user)->getJson(
            $this->rollupEndpoint("conversations/{$conversation->id}?from={$this->today()}&to={$this->today()}")
        );
        $rollupResponse->assertStatus(200);
        $this->assertTrue(
            (bool) $rollupResponse->json('has_estimated_cost'),
            'A rollup covering an estimated-cost record must report has_estimated_cost=true'
        );
    }

    /**
     * Acceptance Scenario 4: a rollup mixing priced, unpriced, and
     * zero-priced usage in the same period keeps all three categories
     * distinguishable rather than collapsing into one number.
     */
    #[Test]
    public function a_rollup_mixing_priced_unpriced_and_zero_priced_usage_keeps_all_three_distinguishable(): void
    {
        $this->seedPrice([
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
        ]);
        $this->seedPrice([
            'provider_type' => 'llama_cpp',
            'model' => 'local-llama',
            'reused_input_rate' => '0',
            'fresh_input_rate' => '0',
            'output_rate' => '0',
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'mixed']);

        // Priced: 900 reused @ 0.30 + 300 fresh @ 3.00 + 450 output @ 15.00
        // = 0.00027 + 0.0009 + 0.00675 = 0.00792 (per 1,000,000 tokens).
        $this->recordUsage($conversation->id, $user->id, 'anthropic', 'claude-sonnet-5', $this->pricedProviderUsage());

        // Zero-priced: a genuine, stored $0.00.
        $this->recordUsage($conversation->id, $user->id, 'llama_cpp', 'local-llama', $this->pricedProviderUsage());

        // Unpriced: no ModelPrice row for this pair at all.
        $this->recordUsage($conversation->id, $user->id, 'unknown_provider', 'no-such-model', $this->pricedProviderUsage());

        $rollupResponse = $this->actingAs($user)->getJson(
            $this->rollupEndpoint("conversations/{$conversation->id}?from={$this->today()}&to={$this->today()}")
        );
        $rollupResponse->assertStatus(200);

        $this->assertSame(3, (int) $rollupResponse->json('request_count'));
        $this->assertSame(
            1,
            (int) $rollupResponse->json('zero_priced_request_count'),
            'The zero-priced request must be counted separately from priced and unpriced'
        );
        $this->assertSame(
            1,
            (int) $rollupResponse->json('unpriced_request_count'),
            'The unpriced request must be counted separately from priced and zero-priced'
        );
        $this->assertEqualsWithDelta(
            0.00792,
            (float) $rollupResponse->json('priced_cost_total'),
            0.0000001,
            'priced_cost_total must reflect only the genuinely priced (incl. zero-priced) request, never the unpriced one'
        );
    }
}
