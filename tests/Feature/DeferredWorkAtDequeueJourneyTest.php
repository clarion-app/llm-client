<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Jobs\GenerateEpisodicMemoryJob;
use ClarionApp\LlmClient\Jobs\PreWarmChunkSummaryJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Presets\CondensationPreset;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\CondensationSummaryStore;
use ClarionApp\LlmClient\Services\EmbeddingService;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Deferred work is evaluated when a worker picks it up, never when it was
 * enqueued.
 *
 * The distinction is the whole point. "Already started" means execution has
 * begun; a job sitting in a queue has not begun, so a backlog accumulated
 * just under a ceiling would otherwise drain straight through it and the
 * overshoot would no longer be bounded by the work actually executing at
 * the moment of crossing. Gating at enqueue would be that unbounded bypass
 * in the other direction — a job that was refused when queued would stay
 * refused even after an operator had raised the ceiling.
 *
 * Both properties are asserted here, because either one alone is
 * satisfiable by a wrong implementation.
 *
 * The placement inside traceSystemRun() is equally load-bearing and is the
 * subject of the last case in this file. The method already closes its run
 * as `failed`, with the exception message, from its own catch — so a gate
 * placed after openRun() records a ceiling refusal as a failure *and*
 * leaves two rows behind, because the gate opens its own refusal record
 * too. Gating first leaves exactly one row, closed stopped_early, written
 * by the one implementation that writes every refusal record.
 */
class DeferredWorkAtDequeueJourneyTest extends TestCase
{
    private User $user;
    private Server $server;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createDeferredWorkTables();

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));
        config(['llm-client.run_trace.enabled' => true]);

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'openai',
            'token' => 'sk-test',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);

        $this->writeTranscript();
        $this->seedZeroRatePrice();

        Queue::fake();
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

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function createDeferredWorkTables(): void
    {
        if (!Schema::hasTable('episodic_memories')) {
            Schema::create('episodic_memories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('conversation_id');
                $table->text('summary');
                $table->json('topics');
                $table->boolean('protected')->default(false);
                $table->unsignedInteger('word_count');
                $table->unsignedInteger('summary_word_count');
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
                $table->index('conversation_id');
            });
        }

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

        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }
    }

    /** Enough of a conversation for both jobs to consider it worth summarising. */
    private function writeTranscript(int $messageCount = 12): void
    {
        for ($i = 0; $i < $messageCount; $i++) {
            Message::create([
                'conversation_id' => $this->conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message number {$i}, with enough words in it to be worth summarising later on.",
                'user' => $i % 2 === 0 ? 'User' : 'Clarion',
            ]);
        }
    }

    /**
     * A priced (zero-rate) row for this file's openai/test-model pair. 084
     * added an admission-time cost estimate that treats a genuinely
     * unpriced model under a stop-mode ceiling as refused by default
     * (research.md D8) — a policy this file's tests are not about. A
     * zero-rate price keeps every request here priced (so that policy
     * never engages) while adding nothing measurable to what is held.
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

    private function declareStopCeiling(string $amount): void
    {
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );
    }

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

    /**
     * A provider that records whether the model was reached at all. "The
     * model is never called" is the property; a spy is the only way to
     * observe it.
     */
    private function countingProvider(int &$calls): LlmProvider
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return [
                'choices' => [['message' => ['content' => json_encode([
                    'summary' => 'The user and the agent discussed several numbered messages at length.',
                    'topics' => ['numbers', 'discussion'],
                    'decisions' => ['A decision'],
                    'constraints' => [],
                    'open_questions' => [],
                    'facts' => [],
                    'commitments' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ];
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => is_array($t) ? count($t) * 10 : 10);

        return $provider;
    }

    private function registryFor(LlmProvider $provider): ProviderRegistry
    {
        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return $registry;
    }

    private function disabledEmbeddings(): EmbeddingService
    {
        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('isEnabled')->andReturn(false);
        $embeddings->shouldReceive('generate')->never();

        return $embeddings;
    }

    private function condensationConfig(): void
    {
        config(['llm-client.condensation' => [
            'enabled' => true,
            'chunk_size' => 4,
            'model' => 'gpt-4o-mini',
            'provider' => 'openai',
            'timeout_seconds' => 20,
        ]]);
    }

    /**
     * Invoke a job's handle() the way a worker would, capturing a ceiling
     * refusal rather than letting it abort the assertions that follow.
     *
     * A refusal on a deferred path has no request boundary above it, so what
     * matters is the recorded stop and the model not being called — not
     * whether the exception is swallowed at the job boundary or surfaces to
     * the worker. What must never happen is some *other* exception.
     */
    private function dequeue(callable $handle): ?BudgetExceededException
    {
        try {
            $handle();

            return null;
        } catch (BudgetExceededException $e) {
            return $e;
        }
    }

    private function refusalRuns(): \Illuminate\Support\Collection
    {
        return DB::table('agent_runs')->where('end_state', 'stopped_early')->get();
    }

    // ---------------------------------------------------------------
    // Refused at dequeue
    // ---------------------------------------------------------------

    #[Test]
    public function an_episodic_memory_job_enqueued_under_the_ceiling_is_refused_when_the_worker_picks_it_up(): void
    {
        $this->declareStopCeiling('25.00');
        $this->recordSpend('1.0000000000');

        // Enqueued while there was room. No dispatch() call site is gated,
        // so this must succeed.
        GenerateEpisodicMemoryJob::dispatch($this->conversation->id, 'agent-1');
        Queue::assertPushed(GenerateEpisodicMemoryJob::class);

        // The scope goes over before a worker gets to it.
        $this->recordSpend('999.0000000000');

        $calls = 0;
        $registry = $this->registryFor($this->countingProvider($calls));
        $embeddings = $this->disabledEmbeddings();
        $job = new GenerateEpisodicMemoryJob($this->conversation->id, 'agent-1');

        $this->dequeue(fn () => $job->handle($registry, $embeddings));

        $this->assertSame(0, $calls, 'The model must never be reached for work refused at dequeue');

        $runs = $this->refusalRuns();
        $this->assertCount(1, $runs);
        $this->assertNotNull($runs[0]->end_reason);
        $this->assertSame($this->user->id, $runs[0]->user_id);
    }

    #[Test]
    public function a_prewarm_chunk_summary_job_is_refused_at_dequeue_too(): void
    {
        $this->condensationConfig();
        $this->declareStopCeiling('25.00');
        $this->recordSpend('999.0000000000');

        $calls = 0;
        $registry = $this->registryFor($this->countingProvider($calls));
        $store = app(CondensationSummaryStore::class);
        $preset = app(CondensationPreset::class);
        $agentLoop = app(AgentLoopService::class);
        $job = new PreWarmChunkSummaryJob($this->conversation->id, 0);

        $this->dequeue(fn () => $job->handle($registry, $store, $preset, $agentLoop));

        $this->assertSame(0, $calls, 'The model must never be reached for work refused at dequeue');
        $this->assertCount(1, $this->refusalRuns());
    }

    /**
     * Operator visibility: the stop is on the record an operator
     * already reads, not only in a log they would have to go looking for.
     */
    #[Test]
    public function the_refused_job_is_visible_through_the_existing_agent_runs_listing(): void
    {
        $this->declareStopCeiling('25.00');
        $this->recordSpend('999.0000000000');

        $calls = 0;
        $registry = $this->registryFor($this->countingProvider($calls));
        $embeddings = $this->disabledEmbeddings();
        $job = new GenerateEpisodicMemoryJob($this->conversation->id, 'agent-1');

        $this->dequeue(fn () => $job->handle($registry, $embeddings));

        $this->app->forgetScopedInstances();

        $listing = $this->actingAs($this->user, 'api')
            ->getJson('/api/clarion-app/llm-client/agent-runs')
            ->assertStatus(200)
            ->json('data');

        $stopped = array_values(array_filter($listing, fn ($run) => $run['end_state'] === 'stopped_early'));

        $this->assertCount(1, $stopped, 'An operator must be able to see why the background work stopped');
        $this->assertNotEmpty($stopped[0]['end_reason']);
    }

    // ---------------------------------------------------------------
    // Not at enqueue
    // ---------------------------------------------------------------

    #[Test]
    public function a_job_enqueued_over_the_ceiling_runs_normally_once_the_ceiling_is_raised(): void
    {
        $this->declareStopCeiling('25.00');
        $this->recordSpend('999.0000000000');

        // Enqueued while the scope is over. Nothing about dispatching is
        // gated — that is what makes the check a dequeue-time check.
        GenerateEpisodicMemoryJob::dispatch($this->conversation->id, 'agent-1');
        Queue::assertPushed(GenerateEpisodicMemoryJob::class);

        // The operator raises the ceiling before a worker gets to it.
        $this->declareStopCeiling('5000.00');
        $this->app->forgetScopedInstances();

        $calls = 0;
        $job = new GenerateEpisodicMemoryJob($this->conversation->id, 'agent-1');
        $job->handle($this->registryFor($this->countingProvider($calls)), $this->disabledEmbeddings());

        $this->assertSame(1, $calls, 'A job admitted at dequeue runs in full, whatever the ceiling was at enqueue');
        $this->assertCount(0, $this->refusalRuns());
    }

    #[Test]
    public function dispatching_over_the_ceiling_never_throws(): void
    {
        $this->declareStopCeiling('25.00');
        $this->recordSpend('999.0000000000');

        GenerateEpisodicMemoryJob::dispatch($this->conversation->id, 'agent-1');
        PreWarmChunkSummaryJob::dispatch($this->conversation->id, 0);

        Queue::assertPushed(GenerateEpisodicMemoryJob::class);
        Queue::assertPushed(PreWarmChunkSummaryJob::class);

        $this->assertCount(
            0,
            $this->refusalRuns(),
            'Enqueueing is not starting work; nothing is evaluated and nothing is recorded here'
        );
    }

    // ---------------------------------------------------------------
    // Exactly one row, closed stopped_early — the gate runs before openRun()
    // ---------------------------------------------------------------

    #[Test]
    public function a_refused_system_run_leaves_exactly_one_row_and_it_is_not_a_failure(): void
    {
        $this->declareStopCeiling('25.00');
        $this->recordSpend('999.0000000000');

        $calls = 0;
        $registry = $this->registryFor($this->countingProvider($calls));
        $embeddings = $this->disabledEmbeddings();
        $job = new GenerateEpisodicMemoryJob($this->conversation->id, 'agent-1');

        $this->dequeue(fn () => $job->handle($registry, $embeddings));

        $allRuns = DB::table('agent_runs')->get();

        $this->assertCount(
            1,
            $allRuns,
            'Two rows means the gate ran after openRun(): the traced run plus the gate\'s own '
            .'refusal record, for one refused unit of work'
        );
        $this->assertSame(
            'stopped_early',
            $allRuns[0]->end_state,
            "A ceiling refusal is a stop, not a failure. traceSystemRun()'s own catch closes a run "
            .'as failed with the exception message, so a `failed` row here means the gate is inside it.'
        );

        // A run opened and then abandoned would have left a step behind.
        $this->assertSame(0, DB::table('agent_run_steps')->count());
    }

    /**
     * Only a ceiling refusal may escape a refused job. Anything else means
     * the refusal broke something on its way out.
     */
    #[Test]
    public function nothing_other_than_a_ceiling_refusal_escapes_a_refused_job(): void
    {
        $this->declareStopCeiling('25.00');
        $this->recordSpend('999.0000000000');

        $calls = 0;
        $job = new GenerateEpisodicMemoryJob($this->conversation->id, 'agent-1');

        try {
            $job->handle($this->registryFor($this->countingProvider($calls)), $this->disabledEmbeddings());
            $escaped = null;
        } catch (\Throwable $e) {
            $escaped = $e;
        }

        if ($escaped !== null) {
            $this->assertInstanceOf(BudgetExceededException::class, $escaped);
        }

        $this->assertSame(0, $calls);
        $this->assertCount(1, $this->refusalRuns());
    }

    // ---------------------------------------------------------------
    // Nothing configured, nothing changes
    // ---------------------------------------------------------------

    #[Test]
    public function with_no_ceiling_configured_a_deferred_job_runs_exactly_as_before(): void
    {
        $calls = 0;
        $job = new GenerateEpisodicMemoryJob($this->conversation->id, 'agent-1');
        $job->handle($this->registryFor($this->countingProvider($calls)), $this->disabledEmbeddings());

        $this->assertSame(1, $calls);
        $this->assertCount(0, $this->refusalRuns());
    }
}
