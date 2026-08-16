<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\ResearchAgentProvisioner;
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
 * US3 (P1) — research never changes anything (FR-008/FR-009).
 *
 * The research agent is read-only, enforced at the operation level: the
 * template's tools.allow = ['GET', 'clarionApp.llmClient.fetchPage.*'] (T005,
 * D3) is enforced by the existing AgentDefinition::isOperationPermitted() at
 * AgentLoopService::handleExecuteOperation() (Grounding notes 1/3). This is a
 * confirm-or-fix phase: enforcement holds by construction (existing primitive
 * + template), so these tests prove it — the unit case against the definition
 * itself, and the integration case against the load-bearing integration point
 * (a conversation bound to the research agent driving a mutation op).
 */
class ResearchAgentDefinitionTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate every assertion to the research agent's own tools.allow —
        // the installation ceiling (api_denylist / confirm_methods) is not
        // this phase's concern.
        $this->app['config']->set('llm-client.confirm_methods', []);
        $this->app['config']->set('llm-client.api_denylist', []);

        $this->user = User::factory()->create();

        $this->createSupportingTables();
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

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function provisioner(): ResearchAgentProvisioner
    {
        return new ResearchAgentProvisioner(
            new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader()),
        );
    }

    private function createSupportingTables(): void
    {
        // execute_operation's real path touches these; none exist in the base
        // TestCase schema bootstrap (mirrors ConversationBindingSurvivesQueueContinuationTest).
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

    private function seedCatalog(): void
    {
        $this->seedOperationCatalog([
            'clarionApp.llmClient.fetchPage.getTextFromUrl' => [
                'path' => '/api/page/text',
                'method' => 'post',
                'summary' => 'Fetch the text of a page',
            ],
            'clarionApp.llmClient.conversations.index' => [
                'path' => '/api/conversations',
                'method' => 'get',
                'summary' => 'List conversations',
            ],
            'contacts.store' => [
                'path' => '/api/contacts',
                'method' => 'post',
                'summary' => 'Store a contact',
            ],
        ]);
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

    // ---------------------------------------------------------------
    // US3 — read-only enforcement
    // ---------------------------------------------------------------

    #[Test]
    public function the_research_definition_permits_get_and_page_text_but_not_a_mutation(): void
    {
        $this->seedCatalog();

        $definition = (new AgentDefinitionParser())->parse(
            (string) file_get_contents(__DIR__ . '/../../src/Templates/research.yaml'),
        );

        $this->assertTrue(
            $definition->isOperationPermitted('clarionApp.llmClient.conversations.index'),
            'a representative GET operation must be permitted',
        );
        $this->assertTrue(
            $definition->isOperationPermitted('clarionApp.llmClient.fetchPage.getTextFromUrl'),
            'the page/text operation must be permitted (the fetchPage.* glob)',
        );
        $this->assertFalse(
            $definition->isOperationPermitted('contacts.store'),
            'a mutation operation must NOT be permitted (read-only)',
        );
    }

    #[Test]
    public function a_mutation_under_the_research_agent_is_rejected_by_the_bound_definition(): void
    {
        $this->seedCatalog();

        $agent = $this->provisioner()->ensureForUser($this->user->id);

        $server = Server::create([
            'name' => 'ResearchServer',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'test-token',
        ]);

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);
        $response->assertStatus(201);
        $conversationId = $response->json('id');

        // The real executeApiCall() would mint a token and make an outgoing
        // HTTP call — mocked out since this test's concern is authorization,
        // not the round trip. Only reached if the (wrongly) mutation is
        // allowed through.
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $executorMock->shouldReceive('extractArguments')->andReturn(['path' => '/api/contacts', 'query' => [], 'body' => []]);
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

        // Constructed directly, mirroring the binding test's established
        // pattern — drives the exact reload-then-continue shape production has.
        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_mutation',
                'type' => 'function',
                'function' => [
                    'name' => 'execute_operation',
                    'arguments' => json_encode(['operationId' => 'contacts.store', 'parameters' => []]),
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
            'the bound research definition must reject the mutation op — read-only, enforced at the operation level',
        );
    }
}
