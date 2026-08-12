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
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * When the fixed-window counter's own store cannot be read or written, a
 * rate limit fails open — every request is admitted, however tight the
 * configured limit, for as long as the store stays broken. This is a
 * deliberate, disclosed contrast to this package's spending ceiling, which
 * fails closed by default: an outage here costs a brief, self-healing lapse
 * in fairness among users, while blocking every user's legitimate work over
 * a cache blip that has nothing to do with any of them would be worse than
 * the unfairness it exists to prevent.
 *
 * The store is made to fail with a genuinely throwing Illuminate\Contracts
 * \Cache\Store implementation registered as a real cache driver — the same
 * technique RateLimitCounterTest already uses at the unit level — so this
 * file exercises the real RateLimitCounter and (once it exists)
 * RateLimitGate rather than a test double standing in for either.
 */
class StoreUnavailableJourneyTest extends TestCase
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

        // A limit tight enough that, if it were actually being enforced,
        // the second request in any scenario below would be refused.
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

    private function newRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    /** Registers a cache store that throws on every operation and points the feature at it. */
    private function breakTheStore(): void
    {
        $store = new class implements Store {
            public function get($key)
            {
                throw new \RuntimeException('store unavailable');
            }

            public function many(array $keys)
            {
                throw new \RuntimeException('store unavailable');
            }

            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('store unavailable');
            }

            public function putMany(array $values, $seconds)
            {
                throw new \RuntimeException('store unavailable');
            }

            public function add($key, $value, $seconds)
            {
                throw new \RuntimeException('store unavailable');
            }

            public function increment($key, $value = 1)
            {
                throw new \RuntimeException('store unavailable');
            }

            public function decrement($key, $value = 1)
            {
                throw new \RuntimeException('store unavailable');
            }

            public function forever($key, $value)
            {
                throw new \RuntimeException('store unavailable');
            }

            public function forget($key)
            {
                throw new \RuntimeException('store unavailable');
            }

            public function flush()
            {
                throw new \RuntimeException('store unavailable');
            }

            public function getPrefix()
            {
                return '';
            }
        };

        Cache::extend('rate_limit_store_unavailable_test', fn () => new Repository($store));
        config(['cache.stores.rate_limit_store_unavailable_test' => ['driver' => 'rate_limit_store_unavailable_test']]);
        config(['llm-client.rate_limit.store' => 'rate_limit_store_unavailable_test']);
    }

    /** Points the feature back at the application's ordinary, working cache store. */
    private function restoreTheStore(): void
    {
        config(['llm-client.rate_limit.store' => null]);
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
    // Every entry path is admitted while the store is broken
    // ---------------------------------------------------------------

    #[Test]
    public function every_request_across_every_entry_path_is_admitted_while_the_store_is_broken_and_every_occurrence_is_logged(): void
    {
        $this->breakTheStore();
        Log::spy();

        // Direct path, twice — the second would be refused under a working
        // store with max_requests = 1.
        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'First direct request.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(200);
        $this->newRequestBoundary();

        $this->conversation->update(['is_processing' => false]);
        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Second direct request, over the nominal limit.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(200);
        $this->newRequestBoundary();

        // Streamed path, twice.
        $this->conversation->update(['is_processing' => false]);
        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'content' => 'First streamed request.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(201);
        $this->newRequestBoundary();

        $this->conversation->update(['is_processing' => false]);
        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'content' => 'Second streamed request, over the nominal limit.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(201);
        $this->newRequestBoundary();

        // Resumed path.
        $runId = $this->openRunFor();
        $message = $this->pausedConfirmation($runId);

        $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}/confirm-api-call", [
                'approved' => false,
                'message_id' => $message->id,
            ])
            ->assertStatus(200);

        Log::shouldHaveReceived('warning')->atLeast()->times(5);
    }

    // ---------------------------------------------------------------
    // Enforcement resumes correctly once the store is restored
    // ---------------------------------------------------------------

    #[Test]
    public function enforcement_resumes_correctly_on_the_next_request_once_the_store_is_restored_with_no_restart(): void
    {
        $this->breakTheStore();

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Admitted while the store is broken.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(200);
        $this->newRequestBoundary();

        $this->restoreTheStore();
        $this->conversation->update(['is_processing' => false]);

        // The real counter never actually incremented while the store was
        // broken (nothing was written), so the very first genuinely counted
        // request lands at count 1 — within max_requests = 1 — and is
        // admitted with no restart, migration, or manual reset required.
        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'The first genuinely counted request.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(200);
        $this->newRequestBoundary();

        $this->conversation->update(['is_processing' => false]);
        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'The second genuinely counted request, over the limit.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(429);
    }
}
