<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationWorkCeilingService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkScope;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * When the fixed-window conversation work counter's own store cannot be
 * read or written, a work ceiling fails open — every unit of work is
 * admitted, however tight the configured ceiling, for as long as the store
 * stays broken. This is deliberate, and mirrors the sibling rate limiter's
 * own disclosed contrast with this package's spending ceiling, which fails
 * closed by default: an outage here costs a brief, self-healing lapse in
 * one conversation's own bound, while stopping every in-flight response
 * across every conversation mid-turn over a cache blip that has nothing to
 * do with any of them would be a strictly worse failure than the
 * unbounded-for-a-moment lapse fail-open produces.
 *
 * The store is made to fail with a genuinely throwing
 * Illuminate\Contracts\Cache\Store implementation registered as a real
 * cache driver, so this file exercises the real ConversationWorkCounter and
 * ConversationWorkGate rather than a test double standing in for either.
 */
class ConversationWorkStoreUnavailableJourneyTest extends TestCase
{
    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // The streaming path resolves AgentLoopService via the container
        // (app(AgentLoopService::class)), which auto-wires a real
        // ConversationCondenser — unlike the hand-constructed instances the
        // other entry paths in this file use, which pass null for it. This
        // feature has nothing to do with conversation condensation; turning
        // it off avoids a condensation_states table this file's schema has
        // no reason to declare, matching the established pattern elsewhere
        // in this suite (e.g. ContextManagementMetricsFailureGracefulTest).
        config(['llm-client.condensation.enabled' => false]);

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        // A ceiling tight enough that, if it were actually being enforced,
        // the second work unit in any scenario below would be refused.
        app(ConversationWorkCeilingService::class)->upsert(
            ConversationWorkScope::ConversationDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_work_units' => 1, 'window_seconds' => 60],
        );
    }

    protected function tearDown(): void
    {
        DB::table('conversation_work_ceilings')->delete();
        DB::table('agent_runs')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function newConversation(): Conversation
    {
        // A pre-set title, exactly like UnconfiguredConversationJourneyTest's
        // own conversation: a conversation that completes with a null title
        // triggers run()'s real OpenAIGenerateConversationTitleRequest side
        // effect, which has nothing to do with what this file tests and
        // would otherwise hit the network.
        return Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);
    }

    private function toolCallBurst(int $count): array
    {
        $calls = [];
        for ($i = 1; $i <= $count; $i++) {
            $calls[] = [
                'id' => "call_{$i}",
                'type' => 'function',
                'function' => ['name' => 'list_applications', 'arguments' => '{}'],
            ];
        }

        return $calls;
    }

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn(...$responses);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
        );
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

        Cache::extend('conversation_work_store_unavailable_test', fn () => new Repository($store));
        config(['cache.stores.conversation_work_store_unavailable_test' => ['driver' => 'conversation_work_store_unavailable_test']]);
        config(['llm-client.conversation_work.store' => 'conversation_work_store_unavailable_test']);
    }

    /** Points the feature back at the application's ordinary, working cache store. */
    private function restoreTheStore(): void
    {
        config(['llm-client.conversation_work.store' => null]);
    }

    // ---------------------------------------------------------------
    // Every entry path is admitted while the store is broken
    // ---------------------------------------------------------------

    #[Test]
    public function every_work_unit_across_every_entry_path_is_admitted_while_the_store_is_broken_and_every_occurrence_is_logged(): void
    {
        $this->breakTheStore();
        Log::spy();

        // Direct path: three tool calls in one turn, two past the nominal
        // ceiling of one — all three must execute.
        $direct = $this->newConversation();
        $directService = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(3)]]]],
            ['choices' => [['message' => ['content' => 'Done.', 'tool_calls' => []]]]],
        ]);
        $directResult = $directService->run($direct, 'Do three things.');
        $this->assertSame('completed', $directResult['status'], 'A broken store must never stop a response');

        // Resumed path: a declined confirmation whose continuation also
        // bursts past the nominal ceiling.
        $resumed = $this->newConversation();
        $resumed->update(['is_processing' => true]);
        $resumedMessage = Message::create([
            'conversation_id' => $resumed->id,
            'role' => 'assistant',
            'content' => '',
            'user' => 'Clarion',
            'tool_data' => [
                'tool_calls' => [[
                    'id' => 'call_confirmed',
                    'function' => ['name' => 'execute_operation', 'arguments' => '{}'],
                ]],
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/1',
                    'arguments' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);
        $resumedService = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(3)]]]],
            ['choices' => [['message' => ['content' => 'Done.', 'tool_calls' => []]]]],
        ]);
        $resumedResult = $resumedService->resumeSync($resumed, $resumedMessage, false);
        $this->assertSame('completed', $resumedResult['status'], 'A broken store must never stop a resumed response either');

        // Streaming path.
        $streamed = $this->newConversation();
        $streamed->update(['is_processing' => true]);
        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = $this->toolCallBurst(3);
        $handler->message = Message::create([
            'conversation_id' => $streamed->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);
        $handler->finish(json_encode([
            'conversation_id' => $streamed->id,
            'iteration' => 1,
        ]), 2);
        $handler->message->refresh();
        $streamedResults = $handler->message->tool_data['tool_results'] ?? [];
        $this->assertCount(3, $streamedResults, 'All three streamed tool calls must have actually executed');
        foreach ($streamedResults as $result) {
            $this->assertStringNotContainsString('work ceiling', $result['content']);
        }

        Log::shouldHaveReceived('warning')->atLeast()->times(3);
    }

    // ---------------------------------------------------------------
    // Enforcement resumes correctly once the store is restored
    // ---------------------------------------------------------------

    #[Test]
    public function enforcement_resumes_correctly_on_the_next_work_unit_once_the_store_is_restored_with_no_restart(): void
    {
        $this->breakTheStore();

        $conversation = $this->newConversation();
        $service = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(1)]]]],
            ['choices' => [['message' => ['content' => 'Done.', 'tool_calls' => []]]]],
        ]);

        $result = $service->run($conversation, 'Do one thing while the store is broken.');
        $this->assertSame('completed', $result['status']);

        $this->restoreTheStore();

        // The real counter never actually incremented while the store was
        // broken (nothing was written), so the very first genuinely counted
        // work unit lands at count 1 — within max_work_units = 1 — and is
        // admitted with no restart, migration, or manual reset required.
        $secondConversation = $this->newConversation();
        $secondService = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(2)]]]],
        ]);
        $secondResult = $secondService->run($secondConversation, 'Do two things, the second over the nominal limit.');

        $this->assertSame(
            'stopped',
            $secondResult['status'],
            'Once the store is healthy again, the very first genuinely counted work unit past the ceiling must be refused'
        );
        $this->assertSame('conversation_work_ceiling_reached', $secondResult['code'] ?? null);
    }
}
