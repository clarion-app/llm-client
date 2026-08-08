<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Jobs\GenerateEpisodicMemoryJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\EmbeddingService;
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
 * Every way of starting model-consuming work crosses the gate — and an
 * installation with no ceiling configured crosses nothing.
 *
 * "No path bypasses the ceiling" is one property, but it only becomes true
 * once every funnel is in place at the same time, so it is asserted here
 * path by path rather than inferred from any one of them. Five paths, each
 * twice: refused when a stop-mode ceiling is reached, and untouched when
 * nothing is configured.
 *
 *   1. an ordinary synchronous request     POST /agent          → run()
 *   2. a progressively delivered response  POST /message        → start()
 *   3. a conversation resumed after a
 *      confirmation pause                  POST …/confirm-api-call → resume(),
 *                                          and its synchronous sibling resumeSync()
 *   4. delayed background work             the queued jobs, at dequeue
 *   5. system-initiated work               traceSystemRun(), plus the
 *                                          null-user paths that cannot go
 *                                          through it at all
 *
 * The resumed path needs cleanup the other two do not, and that is the part
 * most easily missed: resume() is entered with is_processing already true
 * and with a run already open in the message's tool_data, so "gate before
 * anything is set" is not available to it. A refusal there has to close the
 * run it inherited — not open a second one — and clear is_processing,
 * exactly as the neighbouring expired-confirmation branch already does.
 *
 * start() has a sixth caller with no request boundary above it:
 * checkForUnprocessedMessages(), inside the stream handler's own queue job.
 * It is gated like any new work, but nobody is waiting on a status code
 * there, so the refusal is recorded and swallowed rather than surfacing as
 * a failed job.
 */
class EntryPathCoverageJourneyTest extends TestCase
{
    private User $user;
    private Server $server;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSupportingTables();

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

        \Illuminate\Support\Facades\Http::fake();
        Queue::fake();

        $this->fakeProvider();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('agent_runs')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function createSupportingTables(): void
    {
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        // Approving a paused confirmation executes the API call, which opens
        // an MCP session. Only needed so the *unrefused* resume reaches its
        // normal ending rather than a schema error.
        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
            });
        }

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
            });
        }
    }

    private function fakeProvider(): void
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => json_encode([
                'summary' => 'A summary of the conversation that took place.',
                'topics' => ['summarising'],
            ])]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        $provider->shouldReceive('embed')->andReturn(['embeddings' => [[0.1, 0.2, 0.3]]]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function declareStopCeiling(string $amount = '25.00'): void
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

    /** The scope is over its ceiling in stopping mode. */
    private function blocked(): void
    {
        $this->declareStopCeiling('25.00');
        $this->recordSpend('999.0000000000');
    }

    private function newRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    /**
     * Consumption *reads* issued while $fn runs.
     *
     * Writes are excluded deliberately: the metrics path increments
     * cost_summaries at the end of every completed unit of work and always
     * has. What an installation with no ceiling is promised is that nothing
     * new *reads* that table on the request path.
     *
     * @return string[]
     */
    private function consumptionReadsDuring(callable $fn): array
    {
        $reads = [];

        DB::listen(function ($query) use (&$reads) {
            $sql = ltrim($query->sql);

            if (stripos($sql, 'select') === 0 && str_contains($sql, 'cost_summaries')) {
                $reads[] = $sql;
            }
        });

        $fn();

        return $reads;
    }

    /** A message paused on a confirmation, carrying an already-open run. */
    private function pausedConfirmation(?string $runId): Message
    {
        $this->conversation->update(['is_processing' => true]);

        return Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => '',
            'user' => 'Clarion',
            'tool_data' => [
                'run_id' => $runId,
                'iteration' => 1,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'function' => ['name' => 'execute_operation', 'arguments' => '{}'],
                ]],
                'pending_confirmation' => [
                    'operationId' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/1',
                    'arguments' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);
    }

    private function openRunFor(): string
    {
        $runId = app(RunTraceRecorder::class)->openRun(
            RunKind::Interactive,
            (string) $this->user->id,
            $this->conversation->id,
        );

        $this->assertNotNull($runId);

        return $runId;
    }

    // =================================================================
    // Path 1 — an ordinary synchronous request
    // =================================================================

    #[Test]
    public function path_1_synchronous_request_is_refused_over_a_stop_ceiling(): void
    {
        $this->blocked();

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Do some work.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(402);
    }

    #[Test]
    public function path_1_synchronous_request_is_untouched_with_no_ceiling_configured(): void
    {
        $reads = $this->consumptionReadsDuring(function () {
            $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
                'message' => 'Do some work.',
                'conversation_id' => $this->conversation->id,
            ])->assertStatus(200)->assertJson(['status' => 'completed']);
        });

        $this->assertSame([], $reads, 'Nothing configured means the ledger is never read on this path');
    }

    // =================================================================
    // Path 2 — a progressively delivered response
    // =================================================================

    #[Test]
    public function path_2_streamed_request_is_refused_over_a_stop_ceiling(): void
    {
        $this->blocked();

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'content' => 'Do some work.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(402);

        Queue::assertNotPushed(SendHttpStreamRequest::class);
    }

    #[Test]
    public function path_2_streamed_request_is_untouched_with_no_ceiling_configured(): void
    {
        $reads = $this->consumptionReadsDuring(function () {
            $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
                'content' => 'Do some work.',
                'conversation_id' => $this->conversation->id,
            ])->assertStatus(201);
        });

        Queue::assertPushed(SendHttpStreamRequest::class);
        $this->assertSame([], $reads);
    }

    // =================================================================
    // Path 3 — a conversation resumed after a confirmation pause
    // =================================================================

    #[Test]
    public function path_3_resumed_conversation_is_refused_over_a_stop_ceiling(): void
    {
        $runId = $this->openRunFor();
        $message = $this->pausedConfirmation($runId);

        // The ceiling was crossed during the human's pause. The work is not
        // executing — a confirmation wait is a person deciding — so this is
        // new work as far as enforcement is concerned.
        $this->blocked();
        $this->newRequestBoundary();

        $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}/confirm-api-call", [
                // Declining still continues the loop — the model is told
                // the call was refused — so it is new model work either
                // way, and the gate sits ahead of the approval branch.
                'approved' => false,
                'message_id' => $message->id,
            ])
            ->assertStatus(402);
    }

    #[Test]
    public function path_3_a_refused_resume_closes_the_run_it_inherited_and_opens_no_second_one(): void
    {
        $runId = $this->openRunFor();
        $message = $this->pausedConfirmation($runId);

        $this->blocked();
        $this->newRequestBoundary();

        $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}/confirm-api-call", [
                // Declining still continues the loop — the model is told
                // the call was refused — so it is new model work either
                // way, and the gate sits ahead of the approval branch.
                'approved' => false,
                'message_id' => $message->id,
            ])
            ->assertStatus(402);

        $runs = DB::table('agent_runs')->get();

        $this->assertCount(
            1,
            $runs,
            'The resumed path arrives with a run already open; a refusal closes that one rather '
            .'than opening a second, so one refused unit of work is always one row'
        );
        $this->assertSame($runId, $runs[0]->id);
        $this->assertSame('stopped_early', $runs[0]->end_state);
        $this->assertNotNull($runs[0]->end_reason);
    }

    #[Test]
    public function path_3_a_refused_resume_does_not_leave_the_conversation_wedged(): void
    {
        $runId = $this->openRunFor();
        $message = $this->pausedConfirmation($runId);

        $this->assertTrue((bool) $this->conversation->fresh()->is_processing, 'Precondition');

        $this->blocked();
        $this->newRequestBoundary();

        $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}/confirm-api-call", [
                // Declining still continues the loop — the model is told
                // the call was refused — so it is new model work either
                // way, and the gate sits ahead of the approval branch.
                'approved' => false,
                'message_id' => $message->id,
            ])
            ->assertStatus(402);

        $this->assertFalse(
            (bool) $this->conversation->fresh()->is_processing,
            'A resumed conversation is entered with is_processing already true, so a refusal has to '
            .'clear it — otherwise only the abandonment sweep ever will'
        );
    }

    #[Test]
    public function path_3_the_synchronous_resume_sibling_is_refused_too(): void
    {
        $runId = $this->openRunFor();
        $message = $this->pausedConfirmation($runId);

        $this->blocked();
        $this->newRequestBoundary();

        $this->expectException(BudgetExceededException::class);

        app(AgentLoopService::class)->resumeSync($this->conversation, $message, false);
    }

    #[Test]
    public function path_3_resumed_conversation_is_untouched_with_no_ceiling_configured(): void
    {
        $runId = $this->openRunFor();
        $message = $this->pausedConfirmation($runId);

        $reads = $this->consumptionReadsDuring(function () use ($message) {
            $this->actingAs($this->user, 'api')
                ->postJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}/confirm-api-call", [
                    'approved' => false,
                    'message_id' => $message->id,
                ])
                ->assertStatus(200);
        });

        $this->assertSame([], $reads);
        $this->assertSame(0, DB::table('agent_runs')->where('end_state', 'stopped_early')->count());
    }

    // =================================================================
    // Path 4 — delayed background work, at dequeue
    // =================================================================

    #[Test]
    public function path_4_deferred_work_is_refused_at_dequeue_over_a_stop_ceiling(): void
    {
        $this->writeTranscript();
        $this->blocked();

        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('isEnabled')->andReturn(false);

        $job = new GenerateEpisodicMemoryJob($this->conversation->id, 'agent-1');

        try {
            $job->handle(app(ProviderRegistry::class), $embeddings);
        } catch (BudgetExceededException $e) {
            // No request boundary here; the recorded stop is the surface.
        }

        $this->assertSame(1, DB::table('agent_runs')->where('end_state', 'stopped_early')->count());
    }

    #[Test]
    public function path_4_deferred_work_is_untouched_with_no_ceiling_configured(): void
    {
        $this->writeTranscript();

        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('isEnabled')->andReturn(false);

        $job = new GenerateEpisodicMemoryJob($this->conversation->id, 'agent-1');

        $reads = $this->consumptionReadsDuring(function () use ($job, $embeddings) {
            $job->handle(app(ProviderRegistry::class), $embeddings);
        });

        $this->assertSame([], $reads);
        $this->assertSame(0, DB::table('agent_runs')->where('end_state', 'stopped_early')->count());
        $this->assertSame(1, DB::table('agent_runs')->where('end_state', 'completed')->count());
    }

    // =================================================================
    // Path 5 — system-initiated work
    // =================================================================

    #[Test]
    public function path_5_system_initiated_work_is_refused_over_a_stop_ceiling(): void
    {
        $this->blocked();

        $reached = false;

        try {
            app(RunTraceRecorder::class)->traceSystemRun(
                'condensation',
                (string) $this->user->id,
                $this->conversation->id,
                function () use (&$reached) {
                    $reached = true;

                    return 'a summary';
                },
            );
            $this->fail('System-initiated work must be refused once the ceiling is reached');
        } catch (BudgetExceededException $e) {
            // Expected.
        }

        $this->assertFalse($reached, 'The model must never be reached');
        $this->assertSame(1, DB::table('agent_runs')->where('end_state', 'stopped_early')->count());
    }

    #[Test]
    public function path_5_system_initiated_work_is_untouched_with_no_ceiling_configured(): void
    {
        $reached = false;

        $reads = $this->consumptionReadsDuring(function () use (&$reached) {
            $result = app(RunTraceRecorder::class)->traceSystemRun(
                'title_generation',
                (string) $this->user->id,
                $this->conversation->id,
                function () use (&$reached) {
                    $reached = true;

                    return 'a title';
                },
            );

            $this->assertSame('a title', $result);
        });

        $this->assertTrue($reached);
        $this->assertSame([], $reads);
    }

    /**
     * Two system paths accept a nullable user id while traceSystemRun()'s
     * own is a plain string — embedding generation and the role connectivity
     * test. They cannot go through the funnel at all when there is no user,
     * so they call the gate directly, and a null user means the installation
     * scope alone is evaluated.
     */
    #[Test]
    public function path_5_a_null_user_system_path_is_evaluated_against_the_installation_ceiling(): void
    {
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::Installation,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => '10.00', 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );
        $this->recordSpend('50.0000000000');

        $this->expectException(BudgetExceededException::class);

        app(BudgetGate::class)->admit(null, BudgetWorkKind::SystemInitiated);
    }

    #[Test]
    public function path_5_a_null_user_system_path_is_untouched_with_no_ceiling_configured(): void
    {
        $reads = $this->consumptionReadsDuring(function () {
            app(BudgetGate::class)->admit(null, BudgetWorkKind::SystemInitiated);
        });

        $this->assertSame([], $reads);
    }

    // =================================================================
    // start()'s second, non-HTTP call site
    // =================================================================

    #[Test]
    public function the_stream_handlers_own_continuation_records_the_refusal_and_swallows_it(): void
    {
        $this->blocked();
        $this->newRequestBoundary();

        $handler = new AgentLoopStreamHandler();

        $data = json_encode([
            'conversation_id' => $this->conversation->id,
            'iteration' => 1,
        ]);

        $handler->handle(
            'data: '.json_encode([
                'choices' => [['delta' => ['content' => 'An answer.'], 'finish_reason' => null]],
            ])."\n\n",
            $data,
            0
        );

        // A message the user sent while the previous answer was still
        // streaming. finish() notices it and starts fresh work for it — from
        // inside a queue job, with nobody awaiting a status code.
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => 'And one more thing.',
            'user' => 'User',
            'created_at' => Carbon::now()->addMinute(),
            'updated_at' => Carbon::now()->addMinute(),
        ]);

        $escaped = null;

        try {
            $handler->finish($data, 1);
        } catch (\Throwable $e) {
            $escaped = $e;
        }

        $this->assertNull(
            $escaped,
            'A refusal on this path must be recorded and swallowed: an escaping exception surfaces '
            .'only as a failed job, which is exactly the unexplained background failure this '
            .'feature exists to replace with a recorded reason'
        );

        $this->assertGreaterThanOrEqual(
            1,
            DB::table('agent_runs')->where('end_state', 'stopped_early')->count(),
            'The refusal is still recorded — swallowed is not the same as invisible'
        );

        $this->assertFalse((bool) $this->conversation->fresh()->is_processing);
    }

    // =================================================================
    // The nothing-configured baseline, stated once over every path together
    // =================================================================

    #[Test]
    public function with_nothing_configured_no_entry_path_reads_consumption_at_all(): void
    {
        $this->writeTranscript();

        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('isEnabled')->andReturn(false);

        $reads = $this->consumptionReadsDuring(function () use ($embeddings) {
            $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
                'message' => 'Do some work.',
                'conversation_id' => $this->conversation->id,
            ]);
            $this->newRequestBoundary();

            $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
                'content' => 'And more work.',
                'conversation_id' => $this->conversation->id,
            ]);
            $this->newRequestBoundary();

            (new GenerateEpisodicMemoryJob($this->conversation->id, 'agent-1'))
                ->handle(app(ProviderRegistry::class), $embeddings);
            $this->newRequestBoundary();

            app(RunTraceRecorder::class)->traceSystemRun(
                'condensation',
                (string) $this->user->id,
                $this->conversation->id,
                fn () => 'a summary',
            );
        });

        $this->assertSame(
            [],
            $reads,
            "An installation that has configured no ceiling must behave exactly as it did before "
            .'this feature existed, including in what it costs to serve a request'
        );

        $this->assertSame(0, DB::table('spending_ceilings')->count());
    }

    /** Enough of a conversation for the episodic job to consider it. */
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
}
