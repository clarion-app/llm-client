<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DataAgentProvisioner;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry;
use ClarionApp\LlmClient\ValueObjects\AgentDefinition;
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
 * A data question answered by the data agent must always be traceable: the
 * reply has to say which data source(s) it actually queried and what time
 * period the reported figures cover, defaulting and stating a period when
 * the question itself did not name one, and it must never claim to have
 * consulted a source or period it did not actually query.
 *
 * This guarantee rests on the template's own instructions text rather than
 * on any code path, so these assertions read the parsed instructions
 * directly, mirroring the same "the template requires X" pattern already
 * established for the other agent templates in this package.
 */
class DataAgentDefinitionTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the read-only assertions to the data agent's own
        // tools.allow/safety.confirmation_required — the installation
        // ceiling (api_denylist / confirm_methods) is not this phase's
        // concern, matching ResearchAgentDefinitionTest's own setUp.
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

    private function definition(): AgentDefinition
    {
        $this->seedCatalog();

        return (new AgentDefinitionParser())->parse(
            (string) file_get_contents(__DIR__ . '/../../src/Templates/data.yaml'),
        );
    }

    private function provisioner(): DataAgentProvisioner
    {
        return new DataAgentProvisioner(
            new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader()),
        );
    }

    private function createSupportingTables(): void
    {
        // execute_operation's real path touches these; none exist in the
        // base TestCase schema bootstrap (mirrors
        // ResearchAgentDefinitionTest's own createSupportingTables()).
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
        $doc = ['paths' => [
            '/api/contacts' => ['get' => ['operationId' => 'contacts.index', 'summary' => 'List contacts']],
        ]];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    /**
     * Seeds an arbitrary [operationId => {path, method, summary}] catalog,
     * used by the read-only unit case below to exercise both a GET and a
     * mutation operationId in the same call.
     */
    private function seedOperationCatalog(array $operations): void
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

    /**
     * Builds an AgentLoopService whose LlmProvider is scripted to return a
     * single plain reply (no tool calls) -- used to prove the tool-call-
     * free-turn mechanism the "Ambiguous questions" instructions rest on.
     * Mirrors DataAgentAccessScopingTest's own service() helper.
     */
    private function serviceWithPlainReply(string $content): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => $content, 'tool_calls' => []]]],
        ]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            presetRegistry: app(StructuredOutputPresetRegistry::class),
        );
    }

    // ---------------------------------------------------------------
    // Naming sources and the period covered
    // ---------------------------------------------------------------

    #[Test]
    public function instructions_require_naming_the_sources_queried_and_the_period_covered(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'Every answer states which data source(s) you actually queried for it,',
            $instructions,
            'every answer must be required to name the source(s) it actually queried',
        );
        $this->assertStringContainsString(
            'the time period the reported figures cover.',
            $instructions,
            'every answer must be required to state the time period its figures cover',
        );
    }

    #[Test]
    public function instructions_require_stating_a_default_period_when_the_question_did_not_specify_one(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'If a question does not specify a period, choose a reasonable default and',
            $instructions,
            'an unspecified period must be required to fall back to a stated, reasonable default',
        );
        $this->assertStringContainsString(
            'state which one you chose',
            $instructions,
            'a defaulted period must be required to be stated explicitly, never left implicit',
        );
        $this->assertStringContainsString(
            'never leave the period unstated.',
            $instructions,
            'leaving the period unstated must be explicitly forbidden',
        );
    }

    #[Test]
    public function instructions_forbid_citing_a_source_or_period_not_actually_queried(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'Only name a source or period you actually queried for that specific',
            $instructions,
            'a stated source or period must be required to reflect what was actually queried for that answer',
        );
        $this->assertStringContainsString(
            'Never cite a source or period you did not query.',
            $instructions,
            'citing a source or period that was not actually queried must be explicitly forbidden',
        );
    }

    // ---------------------------------------------------------------
    // Missing data, stated plainly and never estimated
    // ---------------------------------------------------------------

    #[Test]
    public function instructions_require_stating_plainly_when_data_cannot_be_found(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'cannot be answered from the data',
            $instructions,
            'a question that cannot be answered, in whole or in part, must be addressed explicitly',
        );
        $this->assertStringContainsString(
            'say so plainly: state what you looked for and that you',
            $instructions,
            'the agent must be required to say plainly what it looked for and that it could not find it',
        );
        $this->assertStringContainsString(
            'could not find it. Never fill the gap with an estimate presented as fact.',
            $instructions,
            'filling a gap with an estimate presented as fact must be explicitly forbidden',
        );
    }

    #[Test]
    public function the_template_requires_distinguishing_missing_data_from_a_zero_finding(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'Keep this distinct from a legitimate empty or zero result.',
            $instructions,
            'a missing-data statement must be required to read differently from a legitimate zero/empty finding',
        );
        $this->assertStringContainsString(
            'is not the same statement as',
            $instructions,
            '"no source can answer this" must be required to differ from "queried X, found none for this period"',
        );
        $this->assertStringContainsString(
            'a finding, not a gap. Never word the two the same way.',
            $instructions,
            'wording a zero/empty finding the same way as a missing-data statement must be explicitly forbidden',
        );
    }

    #[Test]
    public function instructions_require_reporting_unreachable_sources_as_failed_queries(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'When a data source fails or becomes unreachable mid-query, report that as',
            $instructions,
            'a source that fails or becomes unreachable mid-query must be required to be reported as a failed query',
        );
        $this->assertStringContainsString(
            'a failed query against that source — not as a zero/empty finding, and',
            $instructions,
            'a failed query must be required to read distinctly from a zero/empty finding',
        );
        $this->assertStringContainsString(
            'not as "no source available"',
            $instructions,
            'a failed query must be required to read distinctly from "no source available"',
        );
    }

    // ---------------------------------------------------------------
    // Answering questions never writes anything: enforced at the
    // operation level, plus decline-with-reason for writes, dashboards,
    // and the out-of-scope half of a bundled request
    // ---------------------------------------------------------------

    #[Test]
    public function the_data_definition_permits_get_but_not_a_mutation_and_never_requires_confirmation(): void
    {
        $this->seedOperationCatalog([
            'contacts.index' => ['path' => '/api/contacts', 'method' => 'get', 'summary' => 'List contacts'],
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Create a contact'],
        ]);

        $definition = (new AgentDefinitionParser())->parse(
            (string) file_get_contents(__DIR__ . '/../../src/Templates/data.yaml'),
        );

        $this->assertTrue(
            $definition->isOperationPermitted('contacts.index'),
            'a representative GET operation must be permitted',
        );
        $this->assertFalse(
            $definition->isOperationPermitted('contacts.store'),
            'a representative mutation operation must NOT be permitted -- read-only, tools.allow: [GET] alone',
        );
        $this->assertFalse(
            $definition->isConfirmationRequired('contacts.index'),
            'nothing is ever gated behind confirmation -- the data agent never mutates in the first place',
        );
        $this->assertFalse(
            $definition->isConfirmationRequired('contacts.store'),
            'nothing is ever gated behind confirmation, even for a mutation op that is never permitted to begin with',
        );
    }

    #[Test]
    public function a_mutation_under_the_data_agent_is_rejected_by_the_bound_definition(): void
    {
        $this->seedOperationCatalog([
            'contacts.index' => ['path' => '/api/contacts', 'method' => 'get', 'summary' => 'List contacts'],
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Create a contact'],
        ]);

        $agent = $this->provisioner()->ensureForUser($this->user->id);

        $server = Server::create([
            'name' => 'DataServer',
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
        // HTTP call -- mocked out since this test's concern is
        // authorization, not the round trip. Only reached if the (wrongly)
        // mutation is allowed through.
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

        // Constructed directly, mirroring ResearchAgentDefinitionTest's own
        // established pattern -- drives the exact reload-then-continue
        // shape production has.
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
            'the bound data definition must reject the mutation op -- read-only, enforced at the operation level',
        );
    }

    #[Test]
    public function starting_a_conversation_through_the_real_endpoint_provisions_a_data_agent_for_a_fresh_user(): void
    {
        // Closes a coverage gap found during this feature's Phase 9
        // manual-walkthrough reconciliation, the same class of gap a prior
        // feature's own Phase 9 found for a registered HTTP controller left
        // with zero end-to-end coverage: DataAgentProvisioner::ensureForUser()
        // is wired into ConversationController::store() (the real entry
        // point a live user's "start a conversation" request goes through),
        // but every other test provisions the data agent directly via the
        // service class or supplies an explicit agent_id, so nothing proved
        // the wiring itself fires as a side effect of the real route. This
        // test drives that real POST route for a fresh user with zero
        // agents and confirms exactly one `data` agent is provisioned as a
        // result -- proving the wiring is genuinely reachable end-to-end,
        // not just correct when the service is called directly.
        $this->seedOperationCatalog([
            'contacts.index' => ['path' => '/api/contacts', 'method' => 'get', 'summary' => 'List contacts'],
        ]);

        $server = Server::create([
            'name' => 'DataServer',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'test-token',
        ]);

        $this->assertSame(
            0,
            Agent::where('user_id', $this->user->id)->where('name', 'data')->count(),
            'the fresh user must not already have a data agent before the request',
        );

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(201);

        $this->assertSame(
            1,
            Agent::where('user_id', $this->user->id)->where('name', 'data')->count(),
            'ConversationController::store() must provision exactly one data agent as a side effect of the real request',
        );
    }

    #[Test]
    public function instructions_require_declining_any_write_request_with_a_stated_reason(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'You never create, modify, or delete data, under any circumstance,',
            $instructions,
            'the agent must be required to never create, modify, or delete data under any circumstance',
        );
        $this->assertStringContainsString(
            'including when explicitly asked to. Decline plainly, stating that',
            $instructions,
            'an explicit write request must still be required to be declined plainly, with a stated reason',
        );
        $this->assertStringContainsString(
            'modifying data is outside your purpose, and do not attempt a workaround.',
            $instructions,
            'a write decline must be required to state a reason and never attempt a workaround',
        );
    }

    #[Test]
    public function instructions_require_declining_dashboard_or_persistent_reporting_requests(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'You do not build, save, or maintain a dashboard or other persistent',
            $instructions,
            'a request to build, save, or maintain a dashboard must be required to be declined',
        );
        $this->assertStringContainsString(
            'visual reporting artifact. State plainly that this is outside your',
            $instructions,
            'a dashboard decline must be required to state plainly that it is outside the agent\'s purpose',
        );
        $this->assertStringContainsString(
            'purpose when asked',
            $instructions,
            'the dashboard decline must be required whenever asked, not merely implied',
        );
        $this->assertStringContainsString(
            'you can still answer the underlying data question',
            $instructions,
            'declining to build a dashboard must not prevent answering the underlying data question itself',
        );
    }

    #[Test]
    public function instructions_require_splitting_a_bundled_in_scope_and_out_of_scope_request(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'When a request bundles an in-scope data question with an out-of-scope',
            $instructions,
            'a bundled in-scope/out-of-scope request must be addressed explicitly by the instructions',
        );
        $this->assertStringContainsString(
            'action (a write, or a dashboard), answer the in-scope part normally and',
            $instructions,
            'the in-scope part of a bundled request must be required to be answered normally',
        );
        $this->assertStringContainsString(
            'decline the out-of-scope part with a stated reason',
            $instructions,
            'the out-of-scope part of a bundled request must be required to be declined with a stated reason',
        );
        $this->assertStringContainsString(
            'never silently drop',
            $instructions,
            'silently dropping the out-of-scope part of a bundled request must be explicitly forbidden',
        );
        $this->assertStringContainsString(
            'or silently attempt either.',
            $instructions,
            'silently attempting the out-of-scope part of a bundled request must be explicitly forbidden',
        );
    }

    // ---------------------------------------------------------------
    // An ambiguous question is clarified, not guessed at: the mechanism
    // (an ordinary tool-call-free assistant turn) already exists and is
    // unaffected by this feature; the policy (when to use it) is carried
    // entirely by the template's own instructions text.
    // ---------------------------------------------------------------

    #[Test]
    public function a_tool_call_free_turn_ends_the_run_with_no_pending_confirmation(): void
    {
        $this->seedOperationCatalog([
            'contacts.index' => ['path' => '/api/contacts', 'method' => 'get', 'summary' => 'List contacts'],
        ]);

        $agent = $this->provisioner()->ensureForUser($this->user->id);

        $server = Server::create([
            'name' => 'DataServer',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'test-token',
        ]);

        $conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
            'character' => 'Clarion',
            'title' => 'Already titled',
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);

        $service = $this->serviceWithPlainReply('Do you mean the sales total or the refund total?');

        $result = $service->run($conversation, 'What is the total?');

        $this->assertSame(
            'completed',
            $result['status'],
            'a tool-call-free assistant turn must end the run/turn on its own -- this is the ordinary mechanism the "Ambiguous questions" instructions rest on (D8), not a new pause state',
        );

        $conversation->refresh();
        $this->assertFalse(
            $conversation->is_processing,
            'is_processing must be cleared once a tool-call-free turn completes -- the conversation is immediately usable again, exactly like any other reply',
        );

        $message = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();
        $this->assertNotNull($message, 'the tool-call-free turn must have produced an assistant reply');
        $this->assertNull(
            $message->tool_data,
            'a tool-call-free turn must never leave a pending-confirmation (or any other tool-call) state behind -- there is no tool call to confirm',
        );
    }

    #[Test]
    public function the_template_requires_clarifying_before_answering_ambiguous_questions(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'ask a clarifying question',
            $instructions,
            'a genuinely, materially ambiguous question must be required to be met with a clarifying question',
        );
        $this->assertStringContainsString(
            'before querying anything, rather than picking one reading and answering',
            $instructions,
            'the clarifying question must be required before any tool call or answer, never a silently assumed interpretation',
        );
        $this->assertStringContainsString(
            'as if it were the only one. Ask in your reply; do not call an operation',
            $instructions,
            'the clarifying question must be required to be asked in the reply itself, with no operation called that turn',
        );
        $this->assertStringContainsString(
            'this turn.',
            $instructions,
            'the "no operation this turn" requirement must be stated explicitly',
        );
        $this->assertStringContainsString(
            'Once the user clarifies, answer the clarified question and state which',
            $instructions,
            'once clarified, the agent must be required to answer the clarified question',
        );
        $this->assertStringContainsString(
            'interpretation you answered.',
            $instructions,
            'once clarified, the agent must be required to state which interpretation it answered',
        );
    }

    #[Test]
    public function instructions_require_answering_directly_when_only_one_reading_is_reasonable(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'When a question has only one reasonable reading, answer directly',
            $instructions,
            'a question with only one reasonable reading must be required to be answered directly, with no clarification detour',
        );
        $this->assertStringContainsString(
            'clarification is for genuine, materially different ambiguity, not for',
            $instructions,
            'clarification must be scoped to genuine, materially different ambiguity',
        );
        $this->assertStringContainsString(
            'every question that could theoretically be read two ways.',
            $instructions,
            'clarification must be explicitly required NOT to be triggered by every question that could theoretically be read two ways',
        );
    }
}
