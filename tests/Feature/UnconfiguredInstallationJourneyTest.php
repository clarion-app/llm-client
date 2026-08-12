<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\RateLimitCounter;
use ClarionApp\LlmClient\Services\RateLimitService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * With no rate_limits row of any kind, every entry path must behave exactly
 * as it does today: no request is ever refused for rate-limit reasons, and
 * no cache traffic is added to check.
 *
 * The zero-cache-traffic half is the interesting one. It would be easy to
 * write a gate that reads "is there a limit for this user" cheaply from the
 * database and then, finding none, still touches the fixed-window counter
 * "just to be safe" — passing every behavioural test here while adding a
 * cache round trip to every request an installation that never opted in
 * makes. Asserting on a RateLimitCounter double's call count, rather than
 * on the absence of a refusal alone, is what catches that.
 */
class UnconfiguredInstallationJourneyTest extends TestCase
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

    /**
     * The precondition every test in this file relies on: not a single
     * rate_limits row exists, of either scope kind.
     */
    private function assertNothingConfigured(): void
    {
        $this->assertTrue(
            app(RateLimitService::class)->list()->isEmpty(),
            'This journey requires a genuinely empty rate_limits table'
        );
    }

    /**
     * A RateLimitCounter double that must never be called, bound into the
     * container so that whatever ends up resolving RateLimitGate picks it
     * up instead of the real, cache-backed implementation.
     */
    private function bindUncalledCounter(): void
    {
        $counter = Mockery::mock(RateLimitCounter::class);
        $counter->shouldNotReceive('increment');

        $this->app->instance(RateLimitCounter::class, $counter);
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
    // Direct entry path
    // ---------------------------------------------------------------

    #[Test]
    public function a_direct_request_is_never_refused_and_touches_no_cache_counter(): void
    {
        $this->assertNothingConfigured();
        $this->bindUncalledCounter();

        for ($i = 0; $i < 5; $i++) {
            $this->conversation->update(['is_processing' => false]);

            $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
                'message' => 'Do some work.',
                'conversation_id' => $this->conversation->id,
            ])->assertStatus(200)->assertJson(['status' => 'completed']);
        }
    }

    // ---------------------------------------------------------------
    // Streamed entry path
    // ---------------------------------------------------------------

    #[Test]
    public function a_streamed_request_is_never_refused_and_touches_no_cache_counter(): void
    {
        $this->assertNothingConfigured();
        $this->bindUncalledCounter();

        for ($i = 0; $i < 5; $i++) {
            // A plain query-builder update, not an Eloquent model update on
            // $this->conversation: the model's own in-memory attribute is
            // never refreshed between iterations, so an Eloquent update()
            // would see "no change" against its stale original value and
            // silently omit the column from the UPDATE it issues.
            DB::table('conversations')->where('id', $this->conversation->id)->update(['is_processing' => false]);

            $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
                'content' => 'Do some work #'.$i,
                'conversation_id' => $this->conversation->id,
            ])->assertStatus(201);
        }

        Queue::assertPushed(SendHttpStreamRequest::class, 5);
    }

    // ---------------------------------------------------------------
    // Resumed entry path
    // ---------------------------------------------------------------

    #[Test]
    public function a_resumed_confirmation_is_never_refused_and_touches_no_cache_counter(): void
    {
        $this->assertNothingConfigured();
        $this->bindUncalledCounter();

        $runId = $this->openRunFor();
        $message = $this->pausedConfirmation($runId);

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}/confirm-api-call", [
                'approved' => false,
                'message_id' => $message->id,
            ]);

        $this->assertNotSame(429, $response->getStatusCode());
        $response->assertStatus(200);
    }
}
