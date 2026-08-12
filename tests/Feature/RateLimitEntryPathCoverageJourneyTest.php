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
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * No way of starting model-consuming work skips rate-limit evaluation: the
 * direct request, the progressively delivered response, and a conversation
 * resumed after a confirmation pause are all gated by the same rule.
 *
 * Each scenario uses its own user under one shared user_default limit
 * (max_requests = 1, window_seconds = 60), rather than three separate
 * per-user limits, so a single configured default is what every path is
 * proven against — exactly the configuration an operator would actually
 * reach for first.
 *
 * The resumed path is deliberately not asserted to close the run it
 * inherited: unlike the spending-ceiling refusal this package already has,
 * a rate-limit refusal writes no agent_runs row of its own — there is
 * nothing here analogous to that feature's own record-then-throw. What
 * this file does assert for the resumed case is the property that matters
 * operationally: the conversation is left usable again (is_processing
 * cleared), not permanently wedged behind a refusal nobody can retry past.
 */
class RateLimitEntryPathCoverageJourneyTest extends TestCase
{
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

        config(['llm-client.run_trace.enabled' => true]);

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
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

        app(RateLimitService::class)->upsert(
            RateLimitScope::UserDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_requests' => 1, 'window_seconds' => 60],
        );
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

    private function newConversationFor(User $user): Conversation
    {
        return Conversation::create([
            'user_id' => $user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);
    }

    private function pausedConfirmation(Conversation $conversation, ?string $runId): Message
    {
        $conversation->update(['is_processing' => true]);

        return Message::create([
            'conversation_id' => $conversation->id,
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

    private function openRunFor(User $user, Conversation $conversation): string
    {
        $runId = app(RunTraceRecorder::class)->openRun(
            RunKind::Interactive,
            (string) $user->id,
            $conversation->id,
        );

        $this->assertNotNull($runId);

        return $runId;
    }

    // ---------------------------------------------------------------
    // Direct entry path
    // ---------------------------------------------------------------

    #[Test]
    public function a_direct_request_consumes_the_allowance_and_the_next_one_is_refused(): void
    {
        $user = User::factory()->create();
        $conversation = $this->newConversationFor($user);

        $this->actingAs($user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'First message.',
            'conversation_id' => $conversation->id,
        ])->assertStatus(200)->assertJson(['status' => 'completed']);

        $conversation->update(['is_processing' => false]);

        // A fresh container scope, so the second call is genuinely a
        // separate request as far as RateLimitGate's own admitted-once
        // memo is concerned, not a second call riding the first's memo
        // within one PHPUnit process.
        $this->app->forgetScopedInstances();

        $this->actingAs($user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Second message, over the limit.',
            'conversation_id' => $conversation->id,
        ])->assertStatus(429);
    }

    // ---------------------------------------------------------------
    // Streamed entry path
    // ---------------------------------------------------------------

    #[Test]
    public function a_streamed_request_consumes_the_allowance_identically(): void
    {
        $user = User::factory()->create();
        $conversation = $this->newConversationFor($user);

        $this->actingAs($user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'content' => 'First message.',
            'conversation_id' => $conversation->id,
        ])->assertStatus(201);

        $conversation->update(['is_processing' => false]);

        // A fresh container scope, so the second call is genuinely a
        // separate request as far as RateLimitGate's own admitted-once
        // memo is concerned, not a second call riding the first's memo
        // within one PHPUnit process.
        $this->app->forgetScopedInstances();

        $this->actingAs($user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'content' => 'Second message, over the limit.',
            'conversation_id' => $conversation->id,
        ])->assertStatus(429);

        Queue::assertPushed(SendHttpStreamRequest::class, 1);
    }

    // ---------------------------------------------------------------
    // Resumed entry path
    // ---------------------------------------------------------------

    #[Test]
    public function a_resumed_conversation_is_gated_identically_and_the_refusal_leaves_it_usable_again(): void
    {
        $user = User::factory()->create();
        $conversation = $this->newConversationFor($user);

        // Consume the one allowed request for this window first, exactly as
        // the direct and streamed scenarios do, so the resumed call is the
        // one that crosses the limit.
        $this->actingAs($user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'The one allowed request.',
            'conversation_id' => $conversation->id,
        ])->assertStatus(200);

        $runId = $this->openRunFor($user, $conversation);
        $message = $this->pausedConfirmation($conversation, $runId);

        $this->app->forgetScopedInstances();

        $response = $this->actingAs($user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/confirm-api-call", [
                'approved' => false,
                'message_id' => $message->id,
            ]);

        $response->assertStatus(429);

        $this->assertFalse(
            (bool) $conversation->fresh()->is_processing,
            'A refused resume must leave the conversation usable again, not permanently wedged behind is_processing'
        );
    }
}
