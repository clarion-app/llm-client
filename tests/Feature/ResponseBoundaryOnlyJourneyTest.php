<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Edge case / FR-006 / SC-003, quickstart step 9, mutation-testing rows
 * 2-3: a response already in progress must never switch model, tools, or
 * history handling partway through, even when the user's standing crosses
 * a reduction threshold between two of that same response's own LLM
 * round-trips. Only the *next* response (a fresh run) reflects the
 * now-crossed threshold.
 */
class ResponseBoundaryOnlyJourneyTest extends TestCase
{
    private User $user;
    private Server $server;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\Illuminate\Support\Facades\Schema::hasTable('condensation_states')) {
            \Illuminate\Support\Facades\Schema::create('condensation_states', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'big-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);

        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => '100.0000000000', 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );

        ReductionStep::create([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
            'enabled' => true,
        ]);

        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);
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

    #[Test]
    public function a_standing_change_mid_response_never_affects_the_remainder_of_that_same_response(): void
    {
        // Well below the threshold at the moment the response starts.
        $this->recordSpend('1.0000000000');

        /** @var list<string|null> $dispatchedModels */
        $dispatchedModels = [];

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options) use (&$dispatchedModels) {
            $dispatchedModels[] = $options['model'] ?? null;

            // First round-trip only: the response is now underway.
            // Advance standing past the threshold mid-response, exactly as
            // quickstart step 9 describes — "in an automated proxy."
            if (count($dispatchedModels) === 1) {
                DB::table('cost_summaries')->update([
                    'priced_cost_total' => '80.0000000000',
                ]);

                return [
                    'choices' => [['message' => ['content' => '', 'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'list_applications', 'arguments' => '{}'],
                    ]]]]],
                ];
            }

            return [
                'choices' => [['message' => ['content' => 'Final answer.', 'tool_calls' => []]]],
            ];
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $service = new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            runTraceRecorder: app(RunTraceRecorder::class),
        );

        $result = $service->run($this->conversation, 'Do one thing, then answer.');

        $this->assertSame('completed', $result['status']);
        $this->assertCount(2, $dispatchedModels, 'the scripted two-round-trip response must have actually taken two dispatches');

        $this->assertSame(
            'big-model',
            $dispatchedModels[0],
            'the first round-trip, dispatched before standing crossed the threshold, must use the original model'
        );
        $this->assertSame(
            'big-model',
            $dispatchedModels[1],
            'the SECOND round-trip of the SAME response must still use the original model — the decision made at the response boundary must not change mid-response (FR-006/SC-003)'
        );
        $this->assertFalse($result['degraded'] ?? false, 'a response that began at full capacity must not become disclosed as reduced partway through');
    }

    #[Test]
    public function only_a_fresh_response_reflects_the_now_crossed_threshold(): void
    {
        $this->recordSpend('80.0000000000'); // already past the threshold before this NEW response starts

        $dispatchedModel = null;

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options) use (&$dispatchedModel) {
            $dispatchedModel = $options['model'] ?? null;

            return ['choices' => [['message' => ['content' => 'Answer.', 'tool_calls' => []]]]];
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $service = new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            runTraceRecorder: app(RunTraceRecorder::class),
        );

        $result = $service->run($this->conversation, 'A brand new message.');

        $this->assertSame('completed', $result['status']);
        $this->assertSame(
            'small-model',
            $dispatchedModel,
            'a genuinely NEW response (a fresh run), started after standing already crossed the threshold, must reflect the reduction'
        );
    }

    /**
     * Mutation-checklist rows 2/3, exercised at the exact call site row 2
     * names: AgentLoopService::start()'s own "$runId === null" gate around
     * RunTraceRecorder::openRun() (and, inside it, DegradationGate::
     * linkRun()). AgentLoopStreamHandler re-enters start($conversation,
     * $iteration + 1, $this->runId) on every streaming iteration with the
     * run id carried forward (verbatim the same re-entry
     * RateLimitInFlightWorkCompletesJourneyTest proves for the rate
     * limiter's own identically-shaped gate) — a continuing re-entry must
     * never re-open the run or re-link the already-recorded degradation
     * event, or a multi-iteration streamed response would accumulate one
     * degradation_events row per iteration instead of exactly one per
     * response.
     */
    #[Test]
    public function a_streaming_continuation_carrying_a_run_id_never_relinks_the_degradation_event(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest::class]);
        \Illuminate\Support\Facades\Http::fake();

        // Standing already past the 0.75 rung from setUp(), so the decision
        // openRun()'s linkRun() call links is genuinely 'reduced' — the
        // branch mutation-checklist row 15 already covers is "full", this
        // test is specifically about the "reduced" branch relinking.
        $this->recordSpend('80.0000000000');

        $recorder = app(\ClarionApp\LlmClient\Services\RunTraceRecorder::class);

        // Populate DegradationGate's scoped $decisions memo exactly as
        // admitInteractiveWork() would, then mint the run — openRun()'s own
        // internal DegradationGate::linkRun() call fires exactly once here,
        // the fresh-mint branch.
        app(\ClarionApp\LlmClient\Services\DegradationGate::class)->evaluate(
            (string) $this->user->id,
            $this->conversation->id,
            \ClarionApp\LlmClient\ValueObjects\RateLimitDecision::noLimitConfigured(),
            app(\ClarionApp\LlmClient\Services\BudgetGate::class)->evaluate((string) $this->user->id),
        );

        $runId = $recorder->openRun(
            \ClarionApp\LlmClient\ValueObjects\RunKind::Interactive,
            (string) $this->user->id,
            $this->conversation->id,
            streamed: true,
        );
        $this->assertNotNull($runId);

        $this->assertSame(
            1,
            \ClarionApp\LlmClient\Models\DegradationEvent::where('run_id', $runId)->count(),
            'the fresh-mint openRun() call must link exactly one degradation_events row'
        );

        $agentLoop = app(AgentLoopService::class);

        // Every subsequent iteration is a re-entry into start() with the
        // run id carried forward — verbatim the call AgentLoopStreamHandler
        // makes at each iteration. Deliberately NOT calling
        // forgetScopedInstances() between iterations: a real streamed
        // continuation within the same request/job shares the same
        // scoped DegradationGate instance, so its $decisions memo for this
        // conversation is still populated as 'reduced' on every iteration —
        // exactly the condition under which a guard-removed regression
        // would relink.
        for ($iteration = 2; $iteration <= 4; $iteration++) {
            $agentLoop->start($this->conversation, $iteration, $runId);
        }

        $this->assertSame(
            1,
            \ClarionApp\LlmClient\Models\DegradationEvent::where('run_id', $runId)->count(),
            'a continuation carrying an existing run id must never re-open the run or relink its degradation event — exactly one row must exist for the whole response, not one per iteration'
        );
    }
}
