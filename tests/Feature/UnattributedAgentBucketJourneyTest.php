<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * FR-022/SC-011 — the "Unattributed" per-agent bucket (data-model.md §4.3,
 * research.md D8): usage with no agent_id must still be visible in every
 * per-agent rollup, keyed on a reserved sentinel entity_id
 * (CostSummary::UNATTRIBUTED_AGENT_BUCKET) internally, but exposed over the
 * API only via the literal path segment/field value "unattributed" — the
 * raw sentinel UUID is never present in any response.
 */
class UnattributedAgentBucketJourneyTest extends TestCase
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

    private function recordUsage(string $conversationId, string $userId, ?string $agentId): void
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
    public function usage_with_a_null_agent_id_produces_a_cost_summaries_row_keyed_on_the_unattributed_sentinel(): void
    {
        $this->seedPrice();
        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'test']);

        $this->recordUsage($conversation->id, $user->id, null);

        $row = DB::table('cost_summaries')
            ->where('entity_type', 'agent')
            ->where('entity_id', CostSummary::UNATTRIBUTED_AGENT_BUCKET)
            ->first();

        $this->assertNotNull($row, 'A null agent_id request must still land in an agent-dimension cost_summaries row, keyed on the sentinel');
        $this->assertSame(1, (int) $row->request_count);
    }

    #[Test]
    public function get_cost_rollups_agents_unattributed_resolves_the_sentinel_and_never_exposes_the_raw_uuid(): void
    {
        $this->seedPrice();
        $user = User::factory()->create();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 'test']);

        $this->recordUsage($conversation->id, $user->id, null);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("agents/unattributed?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200);
        $this->assertSame(1, (int) $response->json('request_count'));
        $this->assertEqualsWithDelta(0.00792, (float) $response->json('priced_cost_total'), 0.0000001);

        $this->assertStringNotContainsString(
            CostSummary::UNATTRIBUTED_AGENT_BUCKET,
            $response->getContent(),
            'The internal sentinel UUID must never appear in an API response'
        );
    }

    #[Test]
    public function agents_list_includes_the_unattributed_row_alongside_named_agents_never_omitted_or_folded(): void
    {
        $this->seedPrice();
        $user = User::factory()->create();
        $namedConversation = Conversation::create(['user_id' => $user->id, 'title' => 'named']);
        $unattributedConversation = Conversation::create(['user_id' => $user->id, 'title' => 'unattributed']);

        $this->recordUsage($namedConversation->id, $user->id, 'research-agent');
        $this->recordUsage($unattributedConversation->id, $user->id, null);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("agents?from={$this->today()}&to={$this->today()}")
        );

        $response->assertStatus(200);
        $rows = collect($response->json('data'));

        $unattributedRow = $rows->firstWhere('agent_id', 'unattributed');
        $namedRow = $rows->firstWhere('agent_id', 'research-agent');

        $this->assertNotNull($unattributedRow, 'The Unattributed bucket must appear as its own row, never omitted');
        $this->assertNotNull($namedRow, 'A named agent must still appear alongside Unattributed, never folded together');
        $this->assertSame(1, (int) $unattributedRow['request_count']);
        $this->assertSame(1, (int) $namedRow['request_count']);

        $this->assertStringNotContainsString(
            CostSummary::UNATTRIBUTED_AGENT_BUCKET,
            $response->getContent()
        );
    }
}
