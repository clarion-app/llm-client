<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Work that is already executing when the ceiling is crossed finishes.
 *
 * This is the requirement that pulls in the opposite direction from every
 * other one in the story, and the place where a plausible-looking change
 * breaks the feature in either of two directions:
 *
 *  - Gate the streaming continuation and a response is abandoned halfway
 *    through, which the spec calls out as worse than no enforcement at all.
 *  - Drop the gate from start() altogether and the streamed entry path
 *    walks straight past the ceiling.
 *
 * The signal that separates the two is the run id. AgentLoopStreamHandler
 * re-enters AgentLoopService::start($conversation, $iteration + 1,
 * $this->runId) on every streaming iteration with the run carried forward,
 * so a non-null run id *is* "already executing" — a run is opened only when
 * work begins. That is why the gate on start() is conditional, and this
 * file is what holds the condition in place.
 *
 * The third case here is subtler and is the reason the gate remembers what
 * it has already admitted: an embedding call made *inside* a live turn (the
 * auto-memory retriever builds context that way) goes through the
 * system-initiated funnel, so without that memory it would be re-evaluated
 * mid-turn and would throw the moment some other request crossed the
 * ceiling — abandoning exactly the half-built response this requirement
 * forbids.
 */
class InFlightWorkCompletesJourneyTest extends TestCase
{
    private User $user;
    private Server $server;
    private Conversation $conversation;

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

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));
        config(['llm-client.run_trace.enabled' => true]);

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);

        \Illuminate\Support\Facades\Http::fake();
        Queue::fake([SendHttpStreamRequest::class]);

        $this->seedZeroRatePrice();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('agent_runs')->delete();
        DB::table('model_prices')->delete();

        Mockery::close();

        parent::tearDown();
    }

    /**
     * A priced (zero-rate) row for this file's test-model. 084 added an
     * admission-time cost estimate that treats a genuinely unpriced model
     * under a stop-mode ceiling as refused by default (research.md D8) — a
     * policy this file's tests are not about. A zero-rate price keeps every
     * request here priced (so that policy never engages) while adding
     * nothing measurable to what is held.
     *
     * provider_type is 'openai', not this file's own 'llama_cpp' server
     * value: Server::getProviderTypeAttribute() maps any string ProviderType
     * does not recognize — 'llama_cpp' is not 'llama.cpp' — back to
     * ProviderType::OpenAI, and that resolved value is what
     * Conversation::getEffectiveProviderTypeAttribute() actually returns.
     */
    private function seedZeroRatePrice(): void
    {
        \ClarionApp\LlmClient\Models\ModelPrice::create([
            'provider_type' => 'openai',
            'model' => 'test-model',
            'reused_input_rate' => '0.00000000',
            'fresh_input_rate' => '0.00000000',
            'output_rate' => '0.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function declareStopCeiling(string $amount): void
    {
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );
    }

    /**
     * Set the user's recorded consumption for the current period.
     *
     * An upsert rather than an insert: cost_summaries carries a unique key
     * per (entity, user, day), which is the same constraint the real metrics
     * path relies on for its atomic increment.
     */
    private function recordSpend(string $amount): void
    {
        DB::table('cost_summaries')->updateOrInsert(
            [
                'entity_type' => CostSummary::ENTITY_USER,
                'entity_id' => $this->user->id,
                'user_id' => $this->user->id,
                'period_date' => '2026-08-14',
            ],
            [
                'id' => (string) Str::uuid(),
                'request_count' => 1,
                'priced_cost_total' => $amount,
                'zero_priced_request_count' => 0,
                'unpriced_request_count' => 0,
                'unpriced_total_tokens' => 0,
                'estimated_request_count' => 0,
                'updated_at' => Carbon::now(),
            ]
        );
    }

    /** Some other request completes and takes the scope over its ceiling. */
    /**
     * Somebody else's work pushes the scope past its ceiling while this
     * request is still running.
     *
     * The ledger's memo is discarded along with the write, and that second
     * half is load-bearing rather than tidiness. Within one request the memo
     * would otherwise keep serving the pre-crossing figure, and every case
     * below would pass whether or not the gate remembered its admission —
     * the memo, not the record under test, would be doing the work. The
     * discard is not artificial either: BudgetThresholdNotifier drops the
     * memo after every usage write, so a live turn that records a completion
     * of its own has exactly this state a moment later.
     */
    private function anotherRequestCrossesTheCeiling(): void
    {
        $this->recordSpend('9999.0000000000');

        app(\ClarionApp\LlmClient\Services\BudgetLedger::class)->forget();
    }

    private function fakeProvider(?\Closure $onChat = null): void
    {
        $provider = Mockery::mock(\ClarionApp\LlmClient\Contracts\LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function () use ($onChat) {
            if ($onChat !== null) {
                $onChat();
            }

            return [
                'choices' => [['message' => ['content' => 'A whole answer, start to finish.']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ];
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(\ClarionApp\LlmClient\Providers\ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(\ClarionApp\LlmClient\Providers\ProviderRegistry::class, $registry);
    }

    // ---------------------------------------------------------------
    // The streamed continuation
    // ---------------------------------------------------------------

    #[Test]
    public function every_streaming_continuation_proceeds_after_the_ceiling_is_crossed_mid_turn(): void
    {
        $this->fakeProvider();
        $this->declareStopCeiling('25.00');
        $this->recordSpend('1.0000000000');

        $agentLoop = app(AgentLoopService::class);
        $recorder = app(RunTraceRecorder::class);

        // A turn begins: the run is opened, which is what "executing" means.
        $runId = $recorder->openRun(
            RunKind::Interactive,
            (string) $this->user->id,
            $this->conversation->id,
            streamed: true,
        );
        $this->assertNotNull($runId);

        $agentLoop->start($this->conversation, 1, $runId);

        // ...and mid-turn, another request completes and takes the scope
        // past its ceiling.
        $this->anotherRequestCrossesTheCeiling();

        // Every subsequent iteration is a re-entry into start() with the run
        // id carried forward. This is verbatim the call the stream handler
        // makes at each iteration.
        for ($iteration = 2; $iteration <= 5; $iteration++) {
            // A fresh container scope per iteration, so the continuation is
            // not being carried by a remembered admission from iteration 1 —
            // the run id alone has to be enough.
            $this->app->forgetScopedInstances();

            $agentLoop->start($this->conversation, $iteration, $runId);
        }

        Queue::assertPushed(SendHttpStreamRequest::class, 5);

        $this->assertSame(
            0,
            DB::table('agent_runs')->where('end_state', 'stopped_early')->count(),
            'A response in flight is never refused; enforcement decides only whether new work may start'
        );
    }

    /**
     * The same property stated as the exception it must not throw, so a
     * failure names the defect rather than an absent queue job.
     */
    #[Test]
    public function a_continuation_carrying_a_run_id_never_throws_a_ceiling_refusal(): void
    {
        $this->fakeProvider();
        $this->declareStopCeiling('1.00');
        $this->recordSpend('500.0000000000');

        $runId = app(RunTraceRecorder::class)->openRun(
            RunKind::Interactive,
            (string) $this->user->id,
            $this->conversation->id,
            streamed: true,
        );

        try {
            app(AgentLoopService::class)->start($this->conversation, 3, $runId);
        } catch (BudgetExceededException $e) {
            $this->fail(
                'The streaming continuation was refused. A non-null run id means the work is '
                .'already executing; gating it truncates a response the user is already reading.'
            );
        }

        Queue::assertPushed(SendHttpStreamRequest::class);
    }

    /**
     * The condition is only meaningful if the *other* branch is still gated.
     * Stated here so this file cannot be satisfied by deleting the gate.
     */
    #[Test]
    public function a_null_run_id_is_new_work_and_is_still_gated(): void
    {
        $this->fakeProvider();
        $this->declareStopCeiling('1.00');
        $this->recordSpend('500.0000000000');

        $this->expectException(BudgetExceededException::class);

        app(AgentLoopService::class)->start($this->conversation, 1, null);
    }

    /**
     * A cheap structural check that the call this file exercises is the call
     * the stream handler actually makes. If the run id ever stops being
     * carried forward, every continuation becomes new work and the cases
     * above stop describing the product.
     */
    #[Test]
    public function the_stream_handler_carries_the_run_id_into_its_continuation(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(\ClarionApp\LlmClient\AgentLoopStreamHandler::class))->getFileName()
        );

        $this->assertStringContainsString(
            'start($conversation, $iteration + 1, $this->runId)',
            $source,
            'The streaming continuation must pass the open run id; without it the continuation '
            .'is indistinguishable from new work and will be gated'
        );
    }

    // ---------------------------------------------------------------
    // The synchronous turn
    // ---------------------------------------------------------------

    #[Test]
    public function a_synchronous_turn_already_past_its_gate_is_unaffected_by_a_crossing_during_it(): void
    {
        // The crossing happens *while the model call is in progress*, which
        // is the only moment that matters: the gate has already run, and
        // there is deliberately no second check inside the loop.
        $this->fakeProvider(onChat: fn () => $this->anotherRequestCrossesTheCeiling());

        $this->declareStopCeiling('25.00');
        $this->recordSpend('1.0000000000');

        $result = app(AgentLoopService::class)->run($this->conversation, 'A question mid-crossing.');

        $this->assertSame('completed', $result['status']);
        $this->assertSame('A whole answer, start to finish.', $result['content']);

        // The whole exchange is on record — not a user turn with no answer.
        $this->assertSame(2, Message::where('conversation_id', $this->conversation->id)->count());
        $this->assertSame(
            1,
            Message::where('conversation_id', $this->conversation->id)->where('role', 'assistant')->count()
        );
    }

    // ---------------------------------------------------------------
    // Nested work inside an admitted unit
    // ---------------------------------------------------------------

    #[Test]
    public function work_nested_inside_an_admitted_turn_is_not_re_evaluated_when_the_ceiling_is_crossed(): void
    {
        $this->fakeProvider();
        $this->declareStopCeiling('25.00');
        $this->recordSpend('1.0000000000');

        $gate = app(BudgetGate::class);

        // The turn is admitted.
        $gate->admit($this->user->id, BudgetWorkKind::Interactive, $this->conversation->id);

        // Another request crosses the ceiling while the turn is building its
        // context.
        $this->anotherRequestCrossesTheCeiling();

        // The context builder embeds a query. That call goes through the
        // system-initiated funnel, so without the already-admitted record it
        // would throw here and abandon a half-built response.
        $result = app(RunTraceRecorder::class)->traceSystemRun(
            'embedding',
            (string) $this->user->id,
            null,
            fn () => 'the embedding vector',
        );

        $this->assertSame('the embedding vector', $result);

        // ...and a plain second admission is equally a no-op.
        $gate->admit($this->user->id, BudgetWorkKind::SystemInitiated, $this->conversation->id);

        $this->assertSame(
            0,
            DB::table('agent_runs')->where('end_state', 'stopped_early')->count(),
            'Nothing inside an admitted unit of work may record a refusal'
        );
    }

    /**
     * The record is per request or job, not a standing pass. The next unit
     * of work — a new request, or a worker picking up the next job — must
     * see the crossing.
     */
    #[Test]
    public function the_next_unit_of_work_after_the_turn_is_refused(): void
    {
        $this->fakeProvider();
        $this->declareStopCeiling('25.00');
        $this->recordSpend('1.0000000000');

        app(BudgetGate::class)->admit($this->user->id, BudgetWorkKind::Interactive, $this->conversation->id);
        $this->anotherRequestCrossesTheCeiling();

        // What a queue worker does between jobs, and what a deployment gets
        // for free at every request boundary.
        $this->app->forgetScopedInstances();

        $this->expectException(BudgetExceededException::class);

        app(BudgetGate::class)->admit($this->user->id, BudgetWorkKind::Interactive, $this->conversation->id);
    }
}
