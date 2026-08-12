<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\RateLimitService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RateLimitScope;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * A rate-limit refusal writes nothing into the conversation.
 *
 * Every entry path performs the gate check before anything else — before
 * Message::create() on the streamed path, before is_processing is set and
 * before a run is opened on the direct path. A refusal has to be a clean
 * no-op on all three counts, or a rejected request leaves behind evidence
 * indistinguishable from work that actually happened: condensation would
 * summarise a message nobody answered, memory capture would remember it,
 * and the next turn's context would include it.
 *
 * Unlike this package's spending-ceiling refusal, a rate-limit refusal
 * writes no agent_runs row of its own — there is no operator-visible
 * record analogous to that feature's stopped_early close. The resumed
 * scenario below asserts that directly: the run the conversation already
 * had open when it entered the confirmation pause is left exactly as it
 * was, neither closed nor duplicated, and is_processing is the only thing
 * a refusal clears.
 */
class RateLimitRefusalLeavesTranscriptUntouchedTest extends TestCase
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

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => 'Here is your answer.']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    protected function tearDown(): void
    {
        DB::table('rate_limits')->delete();
        DB::table('agent_runs')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** A limit of exactly one request per window, already fully consumed. */
    private function blockTheUserOverAgent(): void
    {
        app(RateLimitService::class)->upsert(
            RateLimitScope::UserDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_requests' => 1, 'window_seconds' => 60],
        );

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'The one allowed request.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(200);

        $this->app->forgetScopedInstances();
    }

    private function existingExchange(): void
    {
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => 'An earlier question.',
            'user' => 'User',
        ]);
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => 'An earlier answer.',
            'user' => 'Clarion',
        ]);
    }

    private function transcript(): array
    {
        return DB::table('messages')
            ->where('conversation_id', $this->conversation->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

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

    // ---------------------------------------------------------------
    // POST /message — the streamed entry path
    // ---------------------------------------------------------------

    #[Test]
    public function a_refused_message_post_stores_no_new_message_row(): void
    {
        $this->existingExchange();
        $this->blockTheUserOverAgent();

        $before = $this->transcript();

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'content' => 'A question that will never be answered.',
            'conversation_id' => $this->conversation->id,
        ]);

        $response->assertStatus(429);

        $this->assertSame(
            $before,
            $this->transcript(),
            'The stored history must be identical: the gate has to run before Message::create(), not after it'
        );

        $this->assertSame(
            0,
            Message::where('conversation_id', $this->conversation->id)
                ->where('content', 'A question that will never be answered.')
                ->count()
        );
    }

    // ---------------------------------------------------------------
    // POST /agent — the synchronous entry path
    // ---------------------------------------------------------------

    #[Test]
    public function a_refused_agent_post_leaves_is_processing_unchanged_and_opens_no_new_run(): void
    {
        $this->existingExchange();
        $this->blockTheUserOverAgent();

        $before = $this->transcript();
        $processingBefore = (bool) $this->conversation->fresh()->is_processing;
        $runsBefore = DB::table('agent_runs')->count();

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'A question that will never be answered.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(429);

        $this->assertSame($before, $this->transcript());
        $this->assertSame(
            $processingBefore,
            (bool) $this->conversation->fresh()->is_processing,
            'A refusal ahead of is_processing being set must leave it exactly as it was'
        );
        $this->assertSame(
            $runsBefore,
            DB::table('agent_runs')->count(),
            'A refusal ahead of openRun() must open no new run'
        );
    }

    // ---------------------------------------------------------------
    // The resumed path — is_processing is cleared, the inherited run is
    // left exactly as it was (neither closed nor duplicated)
    // ---------------------------------------------------------------

    #[Test]
    public function a_refused_resume_clears_is_processing_and_leaves_the_inherited_run_untouched(): void
    {
        $this->blockTheUserOverAgent();

        $runId = $this->openRunFor();
        $message = $this->pausedConfirmation($runId);

        $this->app->forgetScopedInstances();

        $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}/confirm-api-call", [
                'approved' => false,
                'message_id' => $message->id,
            ])
            ->assertStatus(429);

        $this->assertFalse(
            (bool) $this->conversation->fresh()->is_processing,
            'A refused resume must clear is_processing, exactly as the existing expired-confirmation branch does'
        );

        $runs = DB::table('agent_runs')->where('id', $runId)->get();
        $this->assertCount(1, $runs, 'The inherited run must not be duplicated');
        $this->assertSame(
            RunEndState::InProgress->value,
            $runs[0]->end_state,
            'A rate-limit refusal writes no agent_runs row of its own, so the run it inherited is left open '
            .'(still in_progress), not closed as stopped_early'
        );
    }
}
