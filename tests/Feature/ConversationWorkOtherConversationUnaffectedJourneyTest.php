<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationWorkCeilingService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkScope;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * One conversation exhausting its work ceiling must never perturb a
 * different conversation, even one belonging to the same user and falling
 * back to the identical conversation_default ceiling — the fixed-window
 * counter key embeds the conversation id alone (data-model.md §2), never
 * shared with any other conversation, any user-level rate_limits key, or
 * any installation-level spending_ceilings figure.
 */
class ConversationWorkOtherConversationUnaffectedJourneyTest extends TestCase
{
    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.run_trace.enabled' => false]);

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
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

    private function newConversation(): Conversation
    {
        // A pre-set title, exactly like UnconfiguredConversationJourneyTest's
        // own conversation: a conversation that completes (conversation B,
        // below) with a null title triggers run()'s real
        // OpenAIGenerateConversationTitleRequest side effect, which has
        // nothing to do with what this file tests and would otherwise hit
        // the network.
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

    // ---------------------------------------------------------------
    // The scenario
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_conversation_is_not_stopped_by_a_first_conversations_exhausted_ceiling(): void
    {
        app(ConversationWorkCeilingService::class)->upsert(
            ConversationWorkScope::ConversationDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_work_units' => 2, 'window_seconds' => 60],
        );

        // Conversation A: three tool calls in one turn, one past its
        // ceiling of two — must be stopped.
        $conversationA = $this->newConversation();
        $serviceA = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(3)]]]],
        ]);

        $resultA = $serviceA->run($conversationA, 'Do three things.');

        $this->assertSame('stopped', $resultA['status'], 'Conversation A must be stopped once its own ceiling is exceeded');
        $this->assertSame('conversation_work_ceiling_reached', $resultA['code'] ?? null);

        // Conversation B: a different conversation for the same user, no
        // override of its own, performing exactly its own ceiling's worth
        // of work in a single turn. It must not be perturbed by A's
        // already-exhausted count.
        $conversationB = $this->newConversation();
        $serviceB = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(2)]]]],
            ['choices' => [['message' => ['content' => 'Done.', 'tool_calls' => []]]]],
        ]);

        $resultB = $serviceB->run($conversationB, 'Do two things.');

        $this->assertSame(
            'completed',
            $resultB['status'],
            "Conversation B's own two-work-unit burst must succeed, unaffected by conversation A's own exhausted window"
        );
    }
}
