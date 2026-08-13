<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-005's own crux test (quickstart.md step 5, mutation-checklist rows
 * 3/9), 090-agent-version-binding Phase 4/T023.
 *
 * Proves the binding survives being read fresh off a Conversation reloaded
 * from the database at a queue-worker boundary — not merely surviving
 * within one PHP process's in-memory object graph
 * (AgentLoopStreamHandler::handle()/finish() both do
 * `Conversation::find($conversationId)` at the top of every invocation,
 * research.md D1). Constructed directly (`new AgentLoopStreamHandler()`),
 * mirroring tests/Unit/AgentLoopStreamHandlerTest.php's own established
 * construction pattern, never via the container — so this test drives the
 * exact reload-then-continue shape production actually has.
 *
 * Written first, confirmed RED: AgentLoopService::handleExecuteOperation()
 * does not yet consult any bound definition, so the denied operation is
 * allowed through rather than rejected.
 */
class ConversationBindingSurvivesQueueContinuationTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // execute_operation's real path touches: the operation cache/
        // ApiManager catalog (seeded below per-test), buildMessagesPayload()'s
        // auto-memory retrieval (episodic store), applyContextWindowTrim()'s
        // condensation-state read, and getOrCreateSession()'s McpSession
        // lookup/create when the operation is actually executed — none of
        // these tables exist in the base TestCase schema bootstrap (mirrors
        // EntryPathCoverageJourneyTest's own createSupportingTables()).
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

        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

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
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('mcp_sessions')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function seedOperationCatalog(array $operations = []): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
            ];
        }
        $doc = ['paths' => $paths];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'TestServer',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'test-token',
        ]);
    }

    #[Test]
    public function a_queue_continuation_reload_still_enforces_the_bound_versions_denial_not_the_agents_current_permissive_one(): void
    {
        $this->seedOperationCatalog([
            'contacts.list' => ['path' => '/contacts', 'method' => 'GET', 'summary' => 'List contacts'],
        ]);

        // Version 1 denies contacts.list.
        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: ops-agent\ninstructions: Handle contacts.\ntools:\n  deny:\n    - contacts.list\n",
        );

        $server = $this->makeServer();

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);
        $response->assertStatus(201);
        $conversationId = $response->json('id');

        // Between binding and the continuation below: edit the agent to
        // version 2, which no longer denies contacts.list.
        app(AgentService::class)->update(
            $agent,
            $this->user->id,
            "name: ops-agent\ninstructions: Handle contacts.\n",
        );

        // The real executeApiCall() path would mint a token and make a real
        // outgoing HTTP call via McpToolExecutor::executeHttpCall() — mocked
        // out here since this test's concern is authorization narrowing, not
        // the mechanics of a real API round trip. Only reached at all if the
        // (wrongly, pre-fix) operation is allowed through.
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $executorMock->shouldReceive('extractArguments')->andReturn(['path' => '/contacts', 'query' => [], 'body' => []]);
        $executorMock->shouldReceive('executeHttpCall')->andReturn([
            'content' => [['type' => 'text', 'text' => 'unexpectedly allowed through']],
            'isError' => false,
        ]);
        $this->app->instance(McpToolExecutor::class, $executorMock);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
            ApiCallConfirmationRequiredEvent::class,
        ]);
        Queue::fake();

        // Constructed directly, never via the container — mirrors
        // tests/Unit/AgentLoopStreamHandlerTest.php's own established
        // pattern, and drives the exact reload-then-continue shape
        // AgentLoopStreamHandler::finish() has in production.
        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_deny1',
                'type' => 'function',
                'function' => [
                    'name' => 'execute_operation',
                    'arguments' => json_encode(['operationId' => 'contacts.list', 'parameters' => []]),
                ],
            ],
        ];
        $handler->message = Message::create([
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        // finish() itself does Conversation::find($conversationId) fresh —
        // no in-memory Conversation object is ever handed to it. This is the
        // exact reload discipline this test exists to exercise (research.md D1).
        $handler->finish(json_encode([
            'conversation_id' => $conversationId,
            'iteration' => 1,
        ]), 2);

        $handler->message->refresh();
        $toolResults = $handler->message->tool_data['tool_results'] ?? [];
        $this->assertNotEmpty($toolResults, 'the execute_operation tool call must have produced a result');

        $resultContent = $toolResults[0]['content'] ?? '';

        $this->assertStringContainsString(
            'Operation not permitted by the agent version this conversation is bound to.',
            $resultContent,
            'the reloaded conversation must still enforce version 1\'s denial of contacts.list — reading it fresh off the database, not carrying version 2\'s permissive state forward from any in-memory object',
        );

        // The denial must make the loop CONTINUE (not treat the turn as a
        // successful terminal execute_operation) — proving the continuation
        // itself (the next call AgentLoopService::start($conversation,
        // $iteration + 1, $runId) would make) is reached at all.
        Queue::assertPushed(SendHttpStreamRequest::class);
    }
}
