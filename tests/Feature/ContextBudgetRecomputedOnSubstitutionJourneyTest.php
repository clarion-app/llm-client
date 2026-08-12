<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Message;
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
 * research.md D4/D5, quickstart.md steps 10-11, mutation-testing rows 4-5.
 *
 * Proves that a degraded response's context trimming is genuinely
 * recomputed against the governing rung's effective model/history ratio —
 * not merely that a substitute model string is threaded through
 * dispatch (ReducedNotRefusedJourneyTest already covers that).
 *
 * Two conversations, seeded with an identical history, are compared: one
 * kept below every threshold (baseline — the full history fits and is
 * dispatched unmodified) and one pushed past a threshold (the same-sized
 * history must now be trimmed because the effective budget shrank).
 */
class ContextBudgetRecomputedOnSubstitutionJourneyTest extends TestCase
{
    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        // ConversationCondenser (real, container-resolved, unlike
        // ReducedNotRefusedJourneyTest's sibling fixture) genuinely
        // condenses rather than merely trims once a degraded response's
        // effective budget is small enough to force it — this test's own
        // point (D4/D5 shrink the dispatched budget for real). Required by
        // CondensationSummaryStore::get/set once that path is exercised;
        // mirrors ContextManagementMetricsIntegrationTest's own fixture.
        if (!Schema::hasTable('chunk_summaries')) {
            Schema::create('chunk_summaries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->index();
                $table->unsignedInteger('chunk_index');
                $table->string('source_hash', 64);
                $table->unsignedInteger('source_message_count');
                $table->json('summary');
                $table->unsignedInteger('summary_tokens')->nullable();
                $table->string('condensation_model')->nullable();
                $table->string('condensation_provider')->nullable();
                $table->timestamps();

                $table->unique(['conversation_id', 'chunk_index']);
            });
        }

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        // A generous context window for the conversation's own model, and a
        // deliberately tiny one for the substitute — so a history that
        // comfortably fits the former needs real trimming under the
        // latter (research.md D4).
        config([
            'llm-client.context_window.models' => [
                'big-model' => ['context' => 60000, 'response_reserve' => 2000],
                'small-model' => ['context' => 1200, 'response_reserve' => 200],
            ],
            'llm-client.budget.on_unpriced_model' => 'admit_untracked',
        ]);

        \Illuminate\Support\Facades\Http::fake();
        Queue::fake([SendHttpStreamRequest::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('agent_runs')->delete();
        DB::table('reduction_steps')->delete();
        DB::table('degradation_events')->delete();
        DB::table('degradation_summaries')->delete();
        if (DB::getSchemaBuilder()->hasTable('cost_reservations')) {
            DB::table('cost_reservations')->delete();
        }
        if (DB::getSchemaBuilder()->hasTable('budget_reservation_ledger')) {
            DB::table('budget_reservation_ledger')->delete();
        }

        Mockery::close();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function newConversation(): Conversation
    {
        return Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'big-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);
    }

    private function declareCeiling(string $amount): void
    {
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );
    }

    private function recordSpend(string $amount): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $this->user->id,
            'user_id' => $this->user->id,
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

    private function seedHistory(Conversation $conversation, int $messages = 60, int $charsEach = 400): void
    {
        for ($i = 0; $i < $messages; $i++) {
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'user' => $i % 2 === 0 ? 'Test User' : 'Clarion',
                'content' => str_repeat('a', $charsEach),
                'responseTime' => 0,
            ]);
        }
    }

    /** @var int|null number of messages captured on the last dispatched chat() call */
    private ?int $dispatchedMessageCount = null;

    private function fakeProviderCapturingMessageCount(): void
    {
        $this->dispatchedMessageCount = null;

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options) {
            $this->dispatchedMessageCount = count($messages);

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

    private function sendMessage(Conversation $conversation): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Please continue.',
            'conversation_id' => $conversation->id,
        ]);
    }

    // =================================================================
    // D4 — model substitution genuinely shrinks the dispatched history
    // =================================================================

    #[Test]
    public function crossing_the_threshold_dispatches_a_shorter_message_array_than_the_baseline_below_it(): void
    {
        $this->declareCeiling('100.0000000000');
        ReductionStep::create([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
            'enabled' => true,
        ]);

        // Baseline: well below the threshold, full history dispatched.
        $baselineConversation = $this->newConversation();
        $this->seedHistory($baselineConversation);
        $this->recordSpend('1.0000000000');
        $this->fakeProviderCapturingMessageCount();
        $this->sendMessage($baselineConversation)->assertStatus(200);
        $baselineCount = $this->dispatchedMessageCount;
        $this->assertNotNull($baselineCount);

        // Reduced: past the threshold, same-sized seeded history, must
        // trim to the substitute's much smaller budget. A fresh request
        // boundary is required here: BudgetGate's own per-scope "already
        // admitted" memo is scoped() to the container instance, which —
        // unlike two genuinely separate HTTP requests — persists across
        // two postJson() calls within the same test method
        // (StoreUnavailableJourneyTest's own precedent), and would
        // otherwise silently reuse the baseline call's decision here.
        DB::table('cost_summaries')->delete();
        $this->app->forgetScopedInstances();
        $reducedConversation = $this->newConversation();
        $this->seedHistory($reducedConversation);
        $this->recordSpend('80.0000000000');
        $this->fakeProviderCapturingMessageCount();
        $this->sendMessage($reducedConversation)->assertStatus(200);
        $reducedCount = $this->dispatchedMessageCount;
        $this->assertNotNull($reducedCount);

        $this->assertLessThan(
            $baselineCount,
            $reducedCount,
            'a degraded response dispatched against the substitute model must carry a shorter trimmed history than the identical, un-degraded baseline (research.md D4)'
        );
    }

    // =================================================================
    // D5 — history_budget_ratio without a model swap
    // =================================================================

    #[Test]
    public function a_history_budget_ratio_rung_with_no_substitute_model_dispatches_the_original_model_but_a_shorter_history(): void
    {
        $this->declareCeiling('100.0000000000');
        ReductionStep::create([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'history_budget_ratio' => '0.5000',
            'enabled' => true,
        ]);

        // A larger history than the D4 test's default: 'big-model''s own
        // (unhalved) budget comfortably fits it, but half of it does not —
        // the volume this specific assertion needs to force a real
        // difference, since D4's default 60x400 seed fits comfortably
        // under 'big-model''s budget even halved.
        $baselineConversation = $this->newConversation();
        $this->seedHistory($baselineConversation, 60, 2000);
        $this->recordSpend('1.0000000000');
        $this->fakeProviderCapturingMessageCount();
        $this->sendMessage($baselineConversation)->assertStatus(200);
        $baselineCount = $this->dispatchedMessageCount;

        DB::table('cost_summaries')->delete();
        $this->app->forgetScopedInstances();
        $reducedConversation = $this->newConversation();
        $this->seedHistory($reducedConversation, 60, 2000);
        $this->recordSpend('80.0000000000');
        $this->fakeProviderCapturingMessageCount();
        $this->sendMessage($reducedConversation)->assertStatus(200);
        $reducedCount = $this->dispatchedMessageCount;

        $this->assertLessThan(
            $baselineCount,
            $reducedCount,
            'a history_budget_ratio rung must genuinely shrink the dispatched history even with no model substitution (research.md D5)'
        );
    }

    // =================================================================
    // Combined levers — the ratio scales the SUBSTITUTE's budget
    // =================================================================

    #[Test]
    public function a_rung_combining_a_substitute_model_and_a_history_ratio_scales_the_substitutes_own_budget(): void
    {
        $this->declareCeiling('100.0000000000');

        // A larger substitute context than the D4 test's tiny 1200 — that
        // budget is already deeply negative regardless of content, so
        // model-only and combined both hit the "still admit a truncated
        // newest unit" floor identically and never show a difference. This
        // test needs the substitute's OWN (unhalved) budget to be
        // positive and genuinely constraining, so halving it via
        // history_budget_ratio produces a visibly smaller admitted set
        // than substitution alone. Condensation is disabled so message
        // count is a direct, predictable function of the token budget
        // (condenseOrTrim()'s own chunk-sized summarization would
        // otherwise round both scenarios to the same chunk boundary and
        // mask the difference this assertion exists to prove).
        config([
            'llm-client.context_window.models.small-model' => ['context' => 8000, 'response_reserve' => 500],
            'llm-client.condensation.enabled' => false,
        ]);

        // Model-substitution-only rung on one conversation.
        ReductionStep::create([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
            'enabled' => true,
        ]);

        $modelOnlyConversation = $this->newConversation();
        $this->seedHistory($modelOnlyConversation, 60, 2000);
        $this->recordSpend('80.0000000000');
        $this->fakeProviderCapturingMessageCount();
        $this->sendMessage($modelOnlyConversation)->assertStatus(200);
        $modelOnlyCount = $this->dispatchedMessageCount;
        $this->assertNotNull($modelOnlyCount);

        // Now add a second, more severe rung combining both levers.
        ReductionStep::create([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.9000',
            'substitute_model' => 'small-model',
            'history_budget_ratio' => '0.5000',
            'enabled' => true,
        ]);

        DB::table('cost_summaries')->delete();
        $this->app->forgetScopedInstances();
        $combinedConversation = $this->newConversation();
        $this->seedHistory($combinedConversation, 60, 2000);
        $this->recordSpend('95.0000000000');
        $this->fakeProviderCapturingMessageCount();
        $this->sendMessage($combinedConversation)->assertStatus(200);
        $combinedCount = $this->dispatchedMessageCount;
        $this->assertNotNull($combinedCount);

        $this->assertLessThan(
            $modelOnlyCount,
            $combinedCount,
            'combining a substitute model with a history_budget_ratio must scale the SUBSTITUTE\'s already-smaller budget further, '
            .'producing a shorter history than substitution alone (mutation-testing row 5)'
        );
    }
}
