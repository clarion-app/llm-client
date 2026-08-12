<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * spec.md US3 Acceptance Scenarios 1-2, through the real HTTP endpoints
 * (contracts §1), covering FR-001/FR-011/SC-008 and quickstart.md steps
 * 2-3/14-15.
 *
 * The CRUD-only assertions (creation, read-back sort order, operator
 * gating) mirror RateLimitConfigurationJourneyTest's own shape one sibling
 * ceiling over. The "takes effect on the very next request, no restart, no
 * cache to bust" assertions reuse the exact real-entry-path machinery
 * ReducedNotRefusedJourneyTest (Phase 3) already established — a user's
 * standing is brought to a fixed point between an old and a new threshold,
 * and the same standing is evaluated twice: once against the ladder as it
 * was, once after an operator PUT/DELETE changed it, with nothing else in
 * between (no restart, no config cache to bust — the service reads the
 * live reduction_steps table fresh on every call).
 */
class LadderConfigurationJourneyTest extends TestCase
{
    private User $operator;
    private User $nonOperator;
    private Server $server;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        // The real container-resolved AgentLoopService's ConversationCondenser
        // queries this table on every trim — needed only when driving the
        // real HTTP entry path, matching ReducedNotRefusedJourneyTest's own
        // setUp() precedent.
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));

        $this->operator = User::factory()->create();
        $this->nonOperator = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);
        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);

        $this->server = Server::create([
            'name' => 'Primary Server',
            'server_url' => 'http://primary.local:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->nonOperator->id,
            'server_id' => $this->server->id,
            'model' => 'big-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);

        \Illuminate\Support\Facades\Http::fake();
        Queue::fake([SendHttpStreamRequest::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('reduction_steps')->delete();
        DB::table('degradation_events')->delete();
        DB::table('degradation_summaries')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('agent_runs')->delete();
        DB::table('users')->delete();
        if (DB::getSchemaBuilder()->hasTable('cost_reservations')) {
            DB::table('cost_reservations')->delete();
        }
        if (DB::getSchemaBuilder()->hasTable('budget_reservation_ledger')) {
            DB::table('budget_reservation_ledger')->delete();
        }

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function base(): string
    {
        return '/api/clarion-app/llm-client/reduction-steps';
    }

    private function endpoint(string $id): string
    {
        return $this->base().'/'.$id;
    }

    private function liveRowCount(): int
    {
        return DB::table('reduction_steps')->whereNull('deleted_at')->count();
    }

    private function declareCeiling(string $amount, string $mode = 'stop'): SpendingCeiling
    {
        return app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => 'month', 'enforcement_mode' => $mode],
        );
    }

    private function recordSpend(string $amount): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $this->nonOperator->id,
            'user_id' => $this->nonOperator->id,
            'period_date' => '2026-08-12',
            'request_count' => 1,
            'priced_cost_total' => $amount,
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
    }

    /** @var array<int, array{model?: ?string}> */
    private array $dispatchedCalls = [];

    private function fakeProviderRecording(): void
    {
        $this->dispatchedCalls = [];

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options) {
            $this->dispatchedCalls[] = ['model' => $options['model'] ?? null];

            return [
                'choices' => [['message' => ['content' => 'Here is your answer.', 'tool_calls' => []]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ];
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function requestSynchronousWork(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->nonOperator, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Please continue.',
            'conversation_id' => $this->conversation->id,
        ]);
    }

    private function dispatchedModel(): ?string
    {
        foreach ($this->dispatchedCalls as $call) {
            if (array_key_exists('model', $call)) {
                return $call['model'];
            }
        }

        return null;
    }

    // =================================================================
    // Scenario 1 — declared via PUT, read back exactly via GET, sorted
    // =================================================================

    #[Test]
    public function an_operator_creates_a_rung_via_put_and_reads_it_back_via_get(): void
    {
        $put = $this->actingAs($this->operator, 'api')->putJson($this->base(), [
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
        ]);

        $put->assertStatus(200);
        $put->assertJsonStructure([
            'id',
            'axis',
            'threshold_ratio',
            'substitute_model',
            'substitute_server_id',
            'withheld_tools',
            'history_budget_ratio',
            'enabled',
        ]);

        $this->assertSame('budget_user', $put->json('axis'));
        $this->assertSame('0.7500', $put->json('threshold_ratio'));
        $this->assertSame('small-model', $put->json('substitute_model'));
        $this->assertTrue($put->json('enabled'));

        $get = $this->actingAs($this->operator, 'api')->getJson($this->base());
        $get->assertStatus(200);

        $row = collect($get->json('reduction_steps'))->firstWhere('id', $put->json('id'));
        $this->assertNotNull($row, 'The stored rung must be visible in the list');
        $this->assertSame('0.7500', $row['threshold_ratio']);
    }

    #[Test]
    public function get_reduction_steps_is_sorted_by_axis_then_threshold_ratio_ascending(): void
    {
        $this->actingAs($this->operator, 'api')->putJson($this->base(), [
            'axis' => 'rate_limit',
            'threshold_ratio' => '0.9000',
            'substitute_model' => 'small-model',
        ])->assertStatus(200);

        $this->actingAs($this->operator, 'api')->putJson($this->base(), [
            'axis' => 'budget_user',
            'threshold_ratio' => '0.9000',
            'substitute_model' => 'small-model',
        ])->assertStatus(200);

        $this->actingAs($this->operator, 'api')->putJson($this->base(), [
            'axis' => 'budget_user',
            'threshold_ratio' => '0.5000',
            'substitute_model' => 'small-model',
        ])->assertStatus(200);

        $get = $this->actingAs($this->operator, 'api')->getJson($this->base());
        $get->assertStatus(200);

        $pairs = collect($get->json('reduction_steps'))
            ->map(fn (array $row) => [$row['axis'], $row['threshold_ratio']])
            ->values()
            ->all();

        $this->assertSame([
            ['budget_user', '0.5000'],
            ['budget_user', '0.9000'],
            ['rate_limit', '0.9000'],
        ], $pairs, 'GET /reduction-steps must sort by (axis, threshold_ratio) ascending');
    }

    // =================================================================
    // Scenario — a non-operator is locked out of both read and write
    // =================================================================

    #[Test]
    public function a_non_operator_cannot_write_or_read_the_ladder_and_no_row_is_created(): void
    {
        $this->actingAs($this->nonOperator, 'api')->putJson($this->base(), [
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
        ])->assertStatus(403);

        $this->assertSame(0, $this->liveRowCount(), 'A rejected non-operator PUT must create no row');

        $this->actingAs($this->nonOperator, 'api')->getJson($this->base())->assertStatus(403);
    }

    // =================================================================
    // Scenario 2 — a threshold change takes effect on the very next
    // request, no restart, no cache to bust (US3 Acceptance Scenario 2)
    // =================================================================

    #[Test]
    public function changing_a_rungs_threshold_governs_the_very_next_request_at_a_standing_between_the_old_and_new_value(): void
    {
        $this->fakeProviderRecording();
        $this->declareCeiling('100.0000000000');
        $this->recordSpend('60.0000000000'); // 60% of the ceiling — a fixed standing

        $created = $this->actingAs($this->operator, 'api')->putJson($this->base(), [
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
        ]);
        $created->assertStatus(200);
        $id = $created->json('id');

        // 60% is below the 75% threshold — the request must complete
        // unsubstituted.
        $before = $this->requestSynchronousWork();
        $before->assertStatus(200);
        $this->assertSame(
            'big-model',
            $this->dispatchedModel(),
            'below the configured threshold, the response must be unsubstituted'
        );

        // The operator lowers the threshold to 50% — now below the fixed
        // 60% standing.
        $changed = $this->actingAs($this->operator, 'api')->putJson($this->endpoint($id), [
            'axis' => 'budget_user',
            'threshold_ratio' => '0.5000',
            'substitute_model' => 'small-model',
        ]);
        $changed->assertStatus(200);
        $this->assertSame('0.5000', $changed->json('threshold_ratio'));

        // The identical standing (60%), evaluated again with no restart and
        // no cache to bust, is now on the reduced side of the new threshold.
        $this->fakeProviderRecording();
        $after = $this->requestSynchronousWork();
        $after->assertStatus(200);
        $this->assertSame(
            'small-model',
            $this->dispatchedModel(),
            'the same standing must now be reduced under the lowered threshold, on the very next request'
        );
    }

    // =================================================================
    // Scenario 2 (continued) — removing a rung restores full capacity
    // =================================================================

    #[Test]
    public function deleting_a_rung_restores_full_capacity_on_the_next_request_at_the_same_standing(): void
    {
        $this->fakeProviderRecording();
        $this->declareCeiling('100.0000000000');
        $this->recordSpend('80.0000000000'); // 80% of the ceiling — a fixed standing

        $created = $this->actingAs($this->operator, 'api')->putJson($this->base(), [
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
        ]);
        $created->assertStatus(200);
        $id = $created->json('id');

        // 80% crosses the 75% threshold — the request must be reduced.
        $before = $this->requestSynchronousWork();
        $before->assertStatus(200);
        $this->assertSame(
            'small-model',
            $this->dispatchedModel(),
            'above the configured threshold, the response must be reduced'
        );

        $delete = $this->actingAs($this->operator, 'api')->deleteJson($this->endpoint($id));
        $delete->assertStatus(204);

        $this->assertSame(
            0,
            $this->liveRowCount(),
            'DELETE /reduction-steps/{id} must soft-delete rather than hard-delete'
        );
        $this->assertNotNull(
            ReductionStep::withTrashed()->find($id),
            'the row survives as a soft delete'
        );

        // The identical standing (80%), evaluated again with no ladder
        // rungs left, must now complete at full capacity.
        $this->fakeProviderRecording();
        $after = $this->requestSynchronousWork();
        $after->assertStatus(200);
        $this->assertSame(
            'big-model',
            $this->dispatchedModel(),
            'with the governing rung removed, the same standing must now complete unsubstituted'
        );
    }
}
