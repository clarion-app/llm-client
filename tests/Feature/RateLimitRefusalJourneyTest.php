<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use Carbon\CarbonImmutable;
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
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * A rate-limit refusal has to explain itself the same way regardless of how
 * the user reached the agent: a plain HTTP 429, a Retry-After header, and a
 * body that names both the limit that was hit and when the window opens
 * back up. This file proves that shape identically across the direct,
 * streamed, and resumed entry paths — the mechanism producing a refusal at
 * all is proven elsewhere; what is proven here is that its content is
 * genuinely informative and never a generic failure.
 *
 * Every scenario uses its own user under one shared user_default limit
 * (max_requests = 5, window_seconds = 60), matching the configuration an
 * operator would actually reach for first.
 */
class RateLimitRefusalJourneyTest extends TestCase
{
    private const MAX_REQUESTS = 5;

    private const WINDOW_SECONDS = 60;

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
            ['max_requests' => self::MAX_REQUESTS, 'window_seconds' => self::WINDOW_SECONDS],
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

    /**
     * Consume the entire allowance via the direct /agent endpoint, each
     * call in its own fresh container scope so RateLimitGate's admitted-once
     * memo cannot mistake successive HTTP calls in this one PHPUnit process
     * for a single request.
     */
    private function exhaustAllowanceDirect(User $user, Conversation $conversation): void
    {
        for ($i = 0; $i < self::MAX_REQUESTS; $i++) {
            $this->app->forgetScopedInstances();

            $this->actingAs($user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
                'message' => "Allowed message {$i}.",
                'conversation_id' => $conversation->id,
            ])->assertStatus(200);

            $conversation->update(['is_processing' => false]);
        }
    }

    /**
     * Consume the entire allowance via the streamed /message endpoint,
     * identically fresh-scoped per call.
     */
    private function exhaustAllowanceStreamed(User $user, Conversation $conversation): void
    {
        for ($i = 0; $i < self::MAX_REQUESTS; $i++) {
            $this->app->forgetScopedInstances();

            $this->actingAs($user, 'api')->postJson('/api/clarion-app/llm-client/message', [
                'content' => "Allowed message {$i}.",
                'conversation_id' => $conversation->id,
            ])->assertStatus(201);

            $conversation->update(['is_processing' => false]);
        }
    }

    /**
     * Every field T040 requires, asserted once and reused by all three
     * entry-path scenarios so "identically" is a property the test itself
     * enforces, not merely a claim in a comment.
     */
    private function assertRefusalShape(TestResponse $response, string $expectedWorkKind): void
    {
        $response->assertStatus(429);

        $retryAfterHeader = $response->headers->get('Retry-After');
        $this->assertNotNull($retryAfterHeader, 'A 429 refusal must carry a Retry-After header');
        $this->assertIsNumeric($retryAfterHeader);
        $this->assertGreaterThan(0, (int) $retryAfterHeader);
        $this->assertLessThanOrEqual(self::WINDOW_SECONDS, (int) $retryAfterHeader);

        $body = $response->json();

        $this->assertSame('rate_limit_exceeded', $body['code']);

        // The message must plainly state a rate limit was hit and name both
        // the limit and a retry time — never a generic failure string.
        $this->assertIsString($body['message']);
        $message = trim($body['message']);
        $this->assertNotSame('', $message);
        $this->assertNotContains(strtolower($message), ['error', 'forbidden', 'unauthorized', 'something went wrong']);
        $this->assertStringContainsString((string) self::MAX_REQUESTS.' requests per', $message, 'The message must name the limit');
        $this->assertMatchesRegularExpression('/try again/i', $message, 'The message must state when the user can try again');

        $this->assertIsInt($body['retry_after_seconds']);
        $this->assertGreaterThan(0, $body['retry_after_seconds']);
        $this->assertSame((int) $retryAfterHeader, $body['retry_after_seconds'], 'The body and the header must agree on the retry time');

        $this->assertNotNull($body['resets_at']);
        $resetsAt = CarbonImmutable::parse($body['resets_at']);
        $this->assertTrue($resetsAt->greaterThan(now()), 'resets_at must name a moment still in the future');

        $this->assertIsArray($body['limit']);
        $this->assertSame(self::MAX_REQUESTS, $body['limit']['max_requests']);
        $this->assertSame(self::WINDOW_SECONDS, $body['limit']['window_seconds']);

        $this->assertIsArray($body['usage']);
        $this->assertTrue($body['usage']['available']);
        $this->assertGreaterThan(self::MAX_REQUESTS, $body['usage']['count']);

        $this->assertSame($expectedWorkKind, $body['work_kind']);
    }

    // ---------------------------------------------------------------
    // Direct entry path
    // ---------------------------------------------------------------

    #[Test]
    public function a_refusal_on_the_direct_entry_path_states_the_limit_and_the_retry_time(): void
    {
        $user = User::factory()->create();
        $conversation = $this->newConversationFor($user);

        $this->exhaustAllowanceDirect($user, $conversation);

        $this->app->forgetScopedInstances();

        $response = $this->actingAs($user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Over the limit.',
            'conversation_id' => $conversation->id,
        ]);

        $this->assertRefusalShape($response, 'interactive');
    }

    // ---------------------------------------------------------------
    // Streamed entry path
    // ---------------------------------------------------------------

    #[Test]
    public function a_refusal_on_the_streamed_entry_path_states_the_limit_and_the_retry_time(): void
    {
        $user = User::factory()->create();
        $conversation = $this->newConversationFor($user);

        $this->exhaustAllowanceStreamed($user, $conversation);

        $this->app->forgetScopedInstances();

        $response = $this->actingAs($user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'content' => 'Over the limit.',
            'conversation_id' => $conversation->id,
        ]);

        $this->assertRefusalShape($response, 'interactive');
    }

    // ---------------------------------------------------------------
    // Resumed entry path
    // ---------------------------------------------------------------

    #[Test]
    public function a_refusal_on_the_resumed_entry_path_states_the_limit_and_the_retry_time(): void
    {
        $user = User::factory()->create();
        $conversation = $this->newConversationFor($user);

        $this->exhaustAllowanceDirect($user, $conversation);

        $runId = $this->openRunFor($user, $conversation);
        $message = $this->pausedConfirmation($conversation, $runId);

        $this->app->forgetScopedInstances();

        $response = $this->actingAs($user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/confirm-api-call", [
                'approved' => false,
                'message_id' => $message->id,
            ]);

        $this->assertRefusalShape($response, 'resumed');
    }
}
