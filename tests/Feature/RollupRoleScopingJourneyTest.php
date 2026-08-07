<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * spec.md User Story 2 Acceptance Scenario 6 and contracts/cost-api.md §4's
 * full authorization table — every row of that table becomes at least one
 * assertion here.
 *
 * List-endpoint response envelope assumption: GET /cost-rollups/{entity}
 * (list form) is read as returning {"currency": ..., "data": [...]}, one
 * row per entity shaped as the common single-rollup shape plus its own id
 * field (e.g. {"user_id": ..., "priced_cost_total": ..., ...}) — matching
 * the same {"currency", "data"} envelope already established by
 * GET /model-prices, since contracts/cost-api.md §3 describes each list
 * row's shape but not the surrounding envelope.
 */
class RollupRoleScopingJourneyTest extends TestCase
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

    private function seedPrice(): ModelPrice
    {
        return ModelPrice::create([
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);
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
            attemptGroupId: (string) Str::uuid(),
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

    private function today(): string
    {
        return Carbon::now()->toDateString();
    }

    #[Test]
    public function non_operator_conversation_of_another_user_is_forbidden(): void
    {
        $this->seedPrice();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $owner->id, 'title' => 'test']);
        $this->recordUsage($conversation->id, $owner->id);

        $response = $this->actingAs($stranger)->getJson(
            $this->endpoint("conversations/{$conversation->id}?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(403);
    }

    #[Test]
    public function non_operator_conversation_absent_id_is_not_found(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(
            $this->endpoint('conversations/'.((string) Str::uuid())."?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(404);
    }

    #[Test]
    public function non_operator_user_rollup_for_another_user_is_forbidden(): void
    {
        $caller = User::factory()->create();
        $another = User::factory()->create();

        $response = $this->actingAs($caller)->getJson(
            $this->endpoint("users/{$another->id}?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(403);
    }

    #[Test]
    public function non_operator_users_list_returns_exactly_one_row_their_own_never_an_error(): void
    {
        $this->seedPrice();
        $caller = User::factory()->create();
        $other = User::factory()->create();

        $callerConversation = Conversation::create(['user_id' => $caller->id, 'title' => 'mine']);
        $otherConversation = Conversation::create(['user_id' => $other->id, 'title' => 'theirs']);
        $this->recordUsage($callerConversation->id, $caller->id);
        $this->recordUsage($otherConversation->id, $other->id);

        $response = $this->actingAs($caller)->getJson(
            $this->endpoint("users?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200);
        $rows = collect($response->json('data'));
        $this->assertCount(1, $rows, 'A non-operator\'s user list must contain exactly one row — their own');
        $this->assertSame($caller->id, $rows->first()['user_id']);
    }

    #[Test]
    public function non_operator_agent_rollup_for_a_shared_agent_returns_only_their_own_contribution_never_403(): void
    {
        $this->seedPrice();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversationA = Conversation::create(['user_id' => $userA->id, 'title' => 'a']);
        $conversationB = Conversation::create(['user_id' => $userB->id, 'title' => 'b']);
        $this->recordUsage($conversationA->id, $userA->id, 'shared-agent');
        $this->recordUsage($conversationB->id, $userB->id, 'shared-agent');

        $response = $this->actingAs($userA)->getJson(
            $this->endpoint("agents/shared-agent?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200, 'A non-operator reading an agent rollup must never be 403\'d');
        $this->assertSame(1, (int) $response->json('request_count'), 'Only the caller\'s own contribution, never the other user\'s');
    }

    #[Test]
    public function an_operator_sees_full_cross_user_totals_on_every_endpoint(): void
    {
        $this->seedPrice();
        $operator = User::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$operator->id]]);

        $conversationA = Conversation::create(['user_id' => $userA->id, 'title' => 'a']);
        $conversationB = Conversation::create(['user_id' => $userB->id, 'title' => 'b']);
        $this->recordUsage($conversationA->id, $userA->id, 'shared-agent');
        $this->recordUsage($conversationB->id, $userB->id, 'shared-agent');

        // Conversation of a user who isn't the operator: 200, not 403.
        $convResponse = $this->actingAs($operator)->getJson(
            $this->endpoint("conversations/{$conversationA->id}?from={$this->today()}&to={$this->today()}")
        );
        $convResponse->assertStatus(200);

        // Any user's totals: not restricted to the operator's own id.
        $userResponse = $this->actingAs($operator)->getJson(
            $this->endpoint("users/{$userA->id}?from={$this->today()}&to={$this->today()}")
        );
        $userResponse->assertStatus(200);
        $this->assertSame(1, (int) $userResponse->json('request_count'));

        // Users list: full cross-user listing, not narrowed to one row.
        $usersListResponse = $this->actingAs($operator)->getJson(
            $this->endpoint("users?from={$this->today()}&to={$this->today()}")
        );
        $usersListResponse->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, count($usersListResponse->json('data')));

        // Agent rollup: full cross-user total, not just the operator's own
        // (the operator has no usage of their own against this agent at all).
        $agentResponse = $this->actingAs($operator)->getJson(
            $this->endpoint("agents/shared-agent?from={$this->today()}&to={$this->today()}")
        );
        $agentResponse->assertStatus(200);
        $this->assertSame(2, (int) $agentResponse->json('request_count'), 'An operator sees both users\' contributions summed');
    }
}
