<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 2 end-to-end (spec.md Acceptance Scenarios 1-5): an operator
 * (or, here, any caller reading their own scope) picks a period and sees
 * the correct rolled-up cost for a conversation/user/agent, computed from
 * that entity's own usage and each portion priced at its own rate.
 *
 * Usage is generated through the real MetricsRecorder::recordUsage() write
 * path (not by hand-inserting cost_summaries rows) so this test exercises
 * the full write-then-read journey exactly as quickstart.md's manual
 * walkthrough steps 3-4 describe it.
 *
 * Response envelope assumption: GET /cost-rollups/... is read as returning
 * the contracts/cost-api.md §3 common shape flat at the top level for the
 * single-entity endpoints (conversations/{id}, users/{id}, agents/{id}),
 * matching the shape shown verbatim in §3 — {"currency":..., "period":...,
 * "priced_cost_total":..., "request_count":..., ...}.
 */
class CostRollupJourneyTest extends TestCase
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

    private function pricedProviderUsage(): array
    {
        return [
            'prompt_tokens' => 1200,
            'completion_tokens' => 450,
            'total_tokens' => 1650,
            'cache_read_input_tokens' => 900,
        ];
    }

    private function recordUsage(string $conversationId, string $userId, ?string $agentId = null): void
    {
        (new MetricsRecorder())->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) \Illuminate\Support\Str::uuid(),
            providerUsage: $this->pricedProviderUsage(),
            inputText: 'input text',
            outputText: 'output text',
            model: 'claude-sonnet-5',
            providerType: 'anthropic',
            agentId: $agentId,
        );
    }

    private function endpoint(string $path): string
    {
        return '/api/clarion-app/llm-client/cost-rollups/'.$path;
    }

    #[Test]
    public function conversation_rollup_shows_the_correct_total_for_the_chosen_period(): void
    {
        $this->seedPrice();
        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'test']);

        $this->recordUsage($conversation->id, $user->id);

        $today = Carbon::now()->toDateString();
        $response = $this->actingAs($user)->getJson(
            $this->endpoint("conversations/{$conversation->id}?from={$today}&to={$today}")
        );

        $response->assertStatus(200);
        $this->assertSame(1, (int) $response->json('request_count'));
        $this->assertEqualsWithDelta(0.00792, (float) $response->json('priced_cost_total'), 0.0000001);
    }

    #[Test]
    public function user_rollup_shows_the_correct_total_for_the_chosen_period(): void
    {
        $this->seedPrice();
        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'test']);

        $this->recordUsage($conversation->id, $user->id);

        $today = Carbon::now()->toDateString();
        $response = $this->actingAs($user)->getJson(
            $this->endpoint("users/{$user->id}?from={$today}&to={$today}")
        );

        $response->assertStatus(200);
        $this->assertSame(1, (int) $response->json('request_count'));
        $this->assertEqualsWithDelta(0.00792, (float) $response->json('priced_cost_total'), 0.0000001);
    }

    #[Test]
    public function agent_rollup_shows_the_correct_total_for_the_chosen_period(): void
    {
        $this->seedPrice();
        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'test']);

        $this->recordUsage($conversation->id, $user->id, 'research-agent');

        $today = Carbon::now()->toDateString();
        $response = $this->actingAs($user)->getJson(
            $this->endpoint("agents/research-agent?from={$today}&to={$today}")
        );

        $response->assertStatus(200);
        $this->assertSame(1, (int) $response->json('request_count'));
        $this->assertEqualsWithDelta(0.00792, (float) $response->json('priced_cost_total'), 0.0000001);
    }

    #[Test]
    public function a_real_caller_owned_conversation_with_no_usage_ever_returns_the_zero_shape_not_a_404(): void
    {
        $user = User::factory()->create();
        // A genuine conversation, owned by the caller, that has never had
        // any usage recorded — proves conversationShow establishes
        // existence/ownership via Conversation::findOrFail(), not via the
        // presence of a cost_summaries row (contracts/cost-api.md §3).
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'never used']);

        $today = Carbon::now()->toDateString();
        $response = $this->actingAs($user)->getJson(
            $this->endpoint("conversations/{$conversation->id}?from={$today}&to={$today}")
        );

        $response->assertStatus(200);
        $this->assertSame(0, (int) $response->json('request_count'));
        $this->assertEqualsWithDelta(0.0, (float) $response->json('priced_cost_total'), 0.0000001);
    }

    #[Test]
    public function a_period_excluding_the_actual_usage_date_also_returns_the_zero_shape(): void
    {
        $this->seedPrice();
        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'test']);

        $this->recordUsage($conversation->id, $user->id);

        // A date range that cannot possibly include "today"'s usage.
        $response = $this->actingAs($user)->getJson(
            $this->endpoint("conversations/{$conversation->id}?from=2000-01-01&to=2000-01-02")
        );

        $response->assertStatus(200);
        $this->assertSame(0, (int) $response->json('request_count'));
        $this->assertEqualsWithDelta(0.0, (float) $response->json('priced_cost_total'), 0.0000001);
    }

    #[Test]
    public function a_rollup_mixing_reused_fresh_and_output_rates_prices_each_portion_at_its_own_rate(): void
    {
        // Distinct rates make a blended-rate bug observable: if the
        // implementation ever priced the whole input at one rate instead of
        // splitting reused vs fresh, this total would differ.
        $this->seedPrice([
            'reused_input_rate' => '1.00000000',
            'fresh_input_rate' => '10.00000000',
            'output_rate' => '20.00000000',
        ]);
        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'test']);

        // 900 reused @ 1.00, 300 fresh @ 10.00, 450 output @ 20.00
        // = 0.0009 + 0.003 + 0.009 = 0.0129
        $this->recordUsage($conversation->id, $user->id);

        $today = Carbon::now()->toDateString();
        $response = $this->actingAs($user)->getJson(
            $this->endpoint("conversations/{$conversation->id}?from={$today}&to={$today}")
        );

        $response->assertStatus(200);
        $this->assertEqualsWithDelta(0.0129, (float) $response->json('priced_cost_total'), 0.0000001);
    }
}
