<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationWorkCeilingService;
use ClarionApp\LlmClient\Services\ConversationWorkCounter;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * With no conversation_work_ceilings rows at all, every entry path must
 * behave exactly as it does today: no unit of agent-initiated work is ever
 * stopped for a work-ceiling reason, however many tool calls one response
 * performs, and no cache traffic is added to check.
 *
 * The zero-cache-traffic half is the interesting one. It would be easy to
 * write a gate that reads "is there a ceiling for this conversation"
 * cheaply from the database and then, finding none, still touches the
 * fixed-window counter "just to be safe" — passing every behavioural test
 * here while adding a cache round trip to every unit of work a conversation
 * with no ceiling configured ever performs. Asserting on a
 * ConversationWorkCounter double's call count, rather than on the absence
 * of a stop alone, is what catches that.
 *
 * Each of the three entry paths is driven with a genuinely tool-call-heavy
 * turn — six tool calls in one LLM response — so this exercises the real
 * in-loop call site on each path, not merely a plain-text round trip that
 * would never reach ConversationWorkGate::evaluate() at all.
 */
class UnconfiguredConversationJourneyTest extends TestCase
{
    private User $user;
    private Server $server;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.run_trace.enabled' => false]);

        // The streamed entry path resolves AgentLoopService via the
        // container (app(AgentLoopService::class)), which auto-wires a real
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

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);
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

    /**
     * The precondition every test in this file relies on: not a single
     * conversation_work_ceilings row exists, of either scope kind.
     */
    private function assertNothingConfigured(): void
    {
        $this->assertTrue(
            app(ConversationWorkCeilingService::class)->list()->isEmpty(),
            'This journey requires a genuinely empty conversation_work_ceilings table'
        );
    }

    /**
     * A ConversationWorkCounter double that must never be called, bound
     * into the container so that whatever ends up resolving
     * ConversationWorkGate picks it up instead of the real, cache-backed
     * implementation.
     */
    private function bindUncalledCounter(): void
    {
        $counter = Mockery::mock(ConversationWorkCounter::class);
        $counter->shouldNotReceive('increment');

        $this->app->instance(ConversationWorkCounter::class, $counter);
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

    // ---------------------------------------------------------------
    // Direct entry path — run()'s tool-call loop, six calls in one turn
    // ---------------------------------------------------------------

    #[Test]
    public function a_direct_request_with_a_tool_call_burst_completes_normally_and_touches_no_cache_counter(): void
    {
        $this->assertNothingConfigured();
        $this->bindUncalledCounter();

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')
            ->andReturn(
                [
                    'choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(6)]]],
                ],
                [
                    'choices' => [['message' => ['content' => 'All done.', 'tool_calls' => []]]],
                ],
            );
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $service = new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(\ClarionApp\LlmClient\Services\OperationCache::class),
            $registry,
        );

        $result = $service->run($this->conversation, 'Do a lot of work.');

        $this->assertSame('completed', $result['status'], 'An unconfigured conversation must never be stopped for a work-ceiling reason');
    }

    // ---------------------------------------------------------------
    // Streamed entry path — AgentLoopStreamHandler::handleToolCalls()
    // ---------------------------------------------------------------

    #[Test]
    public function a_streamed_tool_call_burst_completes_normally_and_touches_no_cache_counter(): void
    {
        $this->assertNothingConfigured();
        $this->bindUncalledCounter();

        // Scoped, not a blanket Event::fake(): EloquentMultiChainBridge
        // generates a model's UUID primary key from its own 'creating'
        // listener, dispatched through the same Event facade — an unscoped
        // fake() silently swallows that listener too, and every
        // Message::create() below fails on a NOT NULL id (see
        // GenerateEpisodicMemoryJobTest's own note on the identical
        // pitfall). This test asserts nothing about events at all; faking
        // is only to keep unrelated streaming broadcasts from firing.
        \Illuminate\Support\Facades\Event::fake([
            \ClarionApp\LlmClient\Events\NewConversationMessageEvent::class,
            \ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent::class,
            \ClarionApp\LlmClient\Events\ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent::class,
        ]);

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = $this->toolCallBurst(6);
        $handler->message = Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $data = json_encode([
            'conversation_id' => $this->conversation->id,
            'iteration' => 1,
        ]);

        $handler->finish($data, 2);

        $handler->message->refresh();
        $toolResults = $handler->message->tool_data['tool_results'] ?? [];

        $this->assertCount(6, $toolResults, 'Every tool call in the burst must have actually executed, none synthesized as unexecuted');

        foreach ($toolResults as $toolResult) {
            $this->assertStringNotContainsString(
                'work ceiling',
                $toolResult['content'],
                'An unconfigured conversation must never synthesize a work-ceiling refusal'
            );
        }
    }

    // ---------------------------------------------------------------
    // Resumed entry path — resumeSync()'s continuation tool-call loop
    // ---------------------------------------------------------------

    #[Test]
    public function a_resumed_confirmations_continuation_tool_call_burst_completes_normally_and_touches_no_cache_counter(): void
    {
        $this->assertNothingConfigured();
        $this->bindUncalledCounter();

        $this->conversation->update(['is_processing' => true]);

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => '',
            'user' => 'Clarion',
            'tool_data' => [
                'tool_calls' => [[
                    'id' => 'call_1',
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

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')
            ->andReturn(
                [
                    'choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(6)]]],
                ],
                [
                    'choices' => [['message' => ['content' => 'All done.', 'tool_calls' => []]]],
                ],
            );
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $service = new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(\ClarionApp\LlmClient\Services\OperationCache::class),
            $registry,
        );

        // Declined, so no ApiManager/HTTP mocking is needed to resolve the
        // confirmed operation itself — the interesting part is the
        // continuation loop's own tool-call burst.
        $result = $service->resumeSync($this->conversation, $message, false);

        $this->assertSame('completed', $result['status'], 'An unconfigured conversation must never be stopped for a work-ceiling reason');
    }
}
