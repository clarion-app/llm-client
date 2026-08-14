<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 098-delegation-protocol, Phase 3 (US1 + US2), tasks.md T016/T017.
 *
 * The full HTTP journey for `delegate_to_helper` (contracts/
 * delegation-protocol-meta-tool.md): a helper is assigned via the
 * existing, unmodified `POST /agents/{id}/helpers` (097), the delegating
 * turn itself is driven end-to-end through the real, unmodified
 * `AgentLoopService::run()` against a scripted `LlmProvider` double
 * (AgentHandoffDisclosureJourneyTest's own established convention,
 * research.md D1 -- never Http::fake(), since the whole point is to
 * exercise the real agent loop, tool dispatch, and nested `run()` call
 * without a live provider), and the resulting helper conversation's own
 * message history is read back over the existing, unmodified
 * `GET /conversation/{id}/message` endpoint.
 *
 * T016 (US1) covers the end-to-end mechanism: a delegated turn only
 * resolves once the helper's own nested run has completed (no polling),
 * and the parent's final assistant message discloses the helper by name
 * (quickstart scenario 1); delegating to an agent that is not an active
 * assigned helper is refused, writing nothing (quickstart scenario 10);
 * and a Message/UsageRecord created by an iteration AFTER a mid-turn
 * delegation still carries the PARENT's own run_id, never null and never
 * the helper's (quickstart scenario 13, research.md D6).
 *
 * T017 (US2, appended below, sequenced after T016 -- not [P]) covers
 * isolation over the real HTTP read surface: a parent seeded with
 * substantial, unrelated prior history delegates a narrow task, and the
 * helper conversation's own message history -- fetched over HTTP, as the
 * same user -- contains only the composed seed, never any of the parent's
 * own history (quickstart scenario 2); and two sequential delegations to
 * the SAME helper, with different context each time, never let the second
 * helper conversation see the first's own context string (quickstart
 * scenario 3).
 *
 * Written before the `delegate_to_helper` meta-tool exists -- every test
 * below is expected to FAIL red: `executeMetaTool('delegate_to_helper',
 * ...)` is not yet wired into `buildToolsPayload()`/the dispatch `match`,
 * so a scripted tool call naming it falls through to the generic
 * `{"error": "Unknown tool: delegate_to_helper"}` `default` branch instead
 * of ever reaching `DelegationService` (which does not exist yet either).
 */
class DelegationJourneyTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

        // executeApiCall()'s own getOrCreateSession() (reached whenever an
        // execute_operation call is actually permitted) needs an MCP
        // session row -- AgentHandoffJourneyTest's own established
        // precedent for this exact table.
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

        // buildMessagesPayload()/applyContextWindowTrim() (both in the
        // run() funnel) read these tables regardless of whether auto-memory
        // retrieval or condensation ever actually triggers --
        // ConversationBindingSurvivesAgentEditJourneyTest's own established
        // precedent.
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
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        if (Schema::hasTable('usage_records')) {
            DB::table('usage_records')->delete();
        }
        if (Schema::hasTable('agent_run_actions')) {
            DB::table('agent_run_actions')->delete();
        }
        if (Schema::hasTable('agent_run_steps')) {
            DB::table('agent_run_steps')->delete();
        }
        if (Schema::hasTable('agent_runs')) {
            DB::table('agent_runs')->delete();
        }
        DB::table('agent_helper_assignments')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Operation-catalog scaffolding (AgentHandoffJourneyTest precedent)
    // -----------------------------------------------------------------

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

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function agentsUrl(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function agentUrl(string $id): string
    {
        return $this->agentsUrl().'/'.$id;
    }

    private function helpersUrl(string $agentId): string
    {
        return $this->agentUrl($agentId).'/helpers';
    }

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    /**
     * The real, unmodified 097 HTTP endpoint -- the exact same precedent
     * AgentHelperAssignmentJourneyTest.php's own T017 uses.
     */
    private function assignHelper(string $parentAgentId, string $helperAgentId): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson($this->helpersUrl($parentAgentId), ['helper_agent_id' => $helperAgentId])
            ->assertStatus(201);
    }

    private function makeConversation(?Agent $agent): Conversation
    {
        // 'title' is pre-set (AgentHandoffDisclosureJourneyTest's own
        // precedent) so run()'s own title-generation dispatch is never
        // triggered.
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    // -----------------------------------------------------------------
    // Scripted-provider scaffolding (AgentHandoffDisclosureJourneyTest's
    // own established precedent, research.md D1)
    // -----------------------------------------------------------------

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses) {
            return array_shift($responses);
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            metricsRecorder: new \ClarionApp\LlmClient\Services\MetricsRecorder(),
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
    }

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
    }

    private function toolCall(string $name, array $arguments, string $id): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
        ];
    }

    private function toolCallReply(array $calls): array
    {
        return ['choices' => [['message' => ['content' => '', 'tool_calls' => $calls]]]];
    }

    // =================================================================
    // T016 (US1) -- the full HTTP-adjacent delegation journey
    // =================================================================

    #[Test]
    public function a_delegated_task_resolves_synchronously_and_the_parents_final_message_discloses_the_helper(): void
    {
        $parent = $this->makeAgent('parent-agent-journey');
        $helper = $this->makeAgent('helper-agent-journey');
        $this->assignHelper($parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $helper->id,
                    'task' => 'Summarize the attached report.',
                    'context' => 'Report covers Q1 2026 sales figures.',
                ], 'call_delegate_1'),
            ]),
            $this->plainReply('The report shows strong Q1 growth.'),
            $this->plainReply('Here is your summary, incorporating the helper\'s findings.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Please summarize the attached report.');

        $this->assertSame('completed', $result['status'], 'the parent\'s own turn must resolve, not hang or error, once the delegated task has an outcome (FR-002)');
        $this->assertStringContainsString(
            $helper->name,
            $result['content'],
            'the parent\'s final assistant message must name the helper it delegated to (FR-005/SC-001)',
        );
        $this->assertStringContainsString('Here is your summary', $result['content']);

        $delegationRow = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegationRow, 'the completed delegation must have written a Delegation row (US1 AC1)');
        $this->assertSame('completed', $delegationRow->status);
        $this->assertSame($helper->id, $delegationRow->helper_agent_id);

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertStringContainsString($helper->name, $message->content);

        // "No polling" -- the helper's own nested run must have fully
        // completed (its own seed message AND its own reply persisted)
        // before delegate() ever returns control to the parent's own
        // loop, which is what let the SAME synchronous run() call above
        // already produce the final, disclosed answer.
        $helperConversation = Conversation::find($delegationRow->helper_conversation_id);
        $this->assertNotNull($helperConversation);
        $helperMessages = Message::where('conversation_id', $helperConversation->id)->get();
        $this->assertGreaterThanOrEqual(
            2,
            $helperMessages->count(),
            'the helper\'s own nested run (seed message + its own reply) must be fully persisted by the time the parent\'s synchronous run() call returns',
        );
    }

    #[Test]
    public function delegating_to_an_agent_that_is_not_an_assigned_helper_is_refused_writing_no_delegation_row_and_no_helper_conversation(): void
    {
        $parent = $this->makeAgent('parent-agent-no-helpers');
        $arbitraryAgent = $this->makeAgent('unrelated-agent-never-assigned');

        $conversation = $this->makeConversation($parent);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $arbitraryAgent->id,
                    'task' => 'Do something out of bounds.',
                    'context' => null,
                ], 'call_bad_delegate'),
            ]),
            $this->plainReply('I was unable to delegate that, so here is a direct answer instead.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $conversationCountBefore = Conversation::where('user_id', $this->user->id)->count();

        $result = $service->run($conversation->fresh(), 'Please handle this.');

        $this->assertSame('completed', $result['status'], 'a refused delegation must not crash the parent\'s own turn -- the model simply receives an error result and continues');
        $this->assertSame(0, Delegation::count(), 'no Delegation row may be created for an agent that is not an active assigned helper (FR-011)');
        $this->assertSame(
            $conversationCountBefore,
            Conversation::where('user_id', $this->user->id)->count(),
            'no helper Conversation may be created for a refused delegation attempt',
        );

        $toolResultMessage = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data')
            ->orderBy('created_at')
            ->first();
        $this->assertNotNull($toolResultMessage);
        $toolResults = $toolResultMessage->tool_data['tool_results'] ?? [];
        $this->assertNotEmpty($toolResults, 'fixture sanity: the delegating iteration must have produced a tool result');
        $decoded = json_decode($toolResults[0]['content'] ?? '', true);
        $this->assertSame('not_an_assigned_helper', $decoded['error'] ?? null);
    }

    #[Test]
    public function messages_and_usage_records_created_after_a_mid_turn_delegation_carry_the_parents_own_run_id_never_null_never_the_helpers(): void
    {
        $parent = $this->makeAgent('parent-agent-run-id');
        $helper = $this->makeAgent('helper-agent-run-id');
        $this->assignHelper($parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $helper->id,
                    'task' => 'Look something up.',
                    'context' => null,
                ], 'call_delegate_run_id'),
            ]),
            $this->plainReply('Helper found the answer.'),
            $this->toolCallReply([
                $this->toolCall('list_applications', [], 'call_list_apps_after_delegation'),
            ]),
            $this->plainReply('Final answer, after both the delegation and a further tool call.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Please look something up and summarize.');
        $this->assertSame('completed', $result['status']);

        $parentRun = DB::table('agent_runs')->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($parentRun, 'fixture sanity: the parent turn must have opened its own run trace');

        $delegationRow = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegationRow);
        $this->assertNotNull($delegationRow->helper_run_id, 'fixture sanity: the helper\'s own nested run must itself have opened a run trace');
        $this->assertNotSame(
            $parentRun->id,
            $delegationRow->helper_run_id,
            'fixture sanity: the helper\'s own run must be a genuinely different run id from the parent\'s, or this test proves nothing',
        );

        $parentMessages = Message::where('conversation_id', $conversation->id)->get();
        $this->assertGreaterThanOrEqual(
            3,
            $parentMessages->count(),
            'fixture sanity: the parent turn must have produced at least the trigger message, the delegating iteration\'s message, and the message from the iteration AFTER the delegation',
        );
        foreach ($parentMessages as $message) {
            $this->assertNotNull(
                $message->run_id,
                'every Message the parent\'s own turn creates -- including any iteration AFTER a mid-turn delegation -- must carry a non-null run_id (research.md D6); a lost ambient Context after the nested delegation would silently null this out',
            );
            $this->assertSame(
                $parentRun->id,
                $message->run_id,
                'every Message on the parent\'s own conversation must carry the PARENT\'s own run_id, never the helper\'s',
            );
        }

        $usageRecords = DB::table('usage_records')->where('conversation_id', $conversation->id)->get();
        $this->assertGreaterThanOrEqual(
            2,
            $usageRecords->count(),
            'fixture sanity: the parent turn must have made at least two of its own chat() calls -- the delegating iteration and the one after it',
        );
        foreach ($usageRecords as $usageRecord) {
            $this->assertNotNull($usageRecord->run_id, 'every UsageRecord the parent\'s own turn creates must carry a non-null run_id (research.md D6)');
            $this->assertSame(
                $parentRun->id,
                $usageRecord->run_id,
                'every UsageRecord created during the parent\'s own turn -- including any iteration AFTER a mid-turn delegation -- must carry the PARENT\'s own run_id, never the helper\'s',
            );
        }
    }

    // =================================================================
    // T017 (US2) -- isolation over the real HTTP read surface
    // =================================================================

    #[Test]
    public function the_helper_conversations_message_history_fetched_over_http_contains_only_the_composed_seed_never_the_parents_own_prior_history(): void
    {
        $parent = $this->makeAgent('parent-agent-t017a');
        $helper = $this->makeAgent('helper-agent-t017a');
        $this->assignHelper($parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        // Substantial prior history, entirely unrelated to the delegated
        // task.
        for ($i = 1; $i <= 5; $i++) {
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => $i % 2 === 0 ? 'assistant' : 'user',
                'user' => $i % 2 === 0 ? 'Clarion' : 'Tim',
                'content' => "Unrelated prior turn number {$i}.",
                'responseTime' => 0,
            ]);
        }

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $helper->id,
                    'task' => 'Translate the phrase "good morning" into French.',
                    'context' => 'Formal register.',
                ], 'call_delegate_t017a'),
            ]),
            $this->plainReply('Bonjour.'),
            $this->plainReply('The translation is "Bonjour."'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Please translate this for me.');
        $this->assertSame('completed', $result['status'], 'fixture sanity: the turn must complete');

        $delegationRow = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegationRow);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/clarion-app/llm-client/conversation/'.$delegationRow->helper_conversation_id.'/message');
        $response->assertStatus(200);

        $messages = collect($response->json());
        $userMessages = $messages->where('role', 'user')->values();
        $this->assertCount(1, $userMessages, 'the helper conversation must have exactly one user-originated message, over HTTP too');

        $content = $userMessages->first()['content'];
        $this->assertStringContainsString('## Task', $content);
        $this->assertStringContainsString('Translate the phrase "good morning" into French.', $content);
        $this->assertStringContainsString('Formal register.', $content);

        foreach ($messages as $message) {
            $this->assertStringNotContainsString(
                'Unrelated prior turn',
                $message['content'],
                'the helper conversation\'s own message history, fetched over HTTP as the same user, must never contain any trace of the parent\'s own prior history',
            );
        }
    }

    #[Test]
    public function a_second_delegation_to_the_same_helper_does_not_carry_over_the_first_delegations_own_context(): void
    {
        $parent = $this->makeAgent('parent-agent-t017b');
        $helper = $this->makeAgent('helper-agent-t017b');
        $this->assignHelper($parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        $firstService = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $helper->id,
                    'task' => 'Summarize document A.',
                    'context' => 'FIRST-DELEGATION-SECRET-CONTEXT-STRING',
                ], 'call_delegate_first'),
            ]),
            $this->plainReply('Summary of document A.'),
            $this->plainReply('Here is the summary of document A.'),
        ]);
        $this->app->instance(AgentLoopService::class, $firstService);

        $first = $firstService->run($conversation->fresh(), 'Please summarize document A.');
        $this->assertSame('completed', $first['status'], 'fixture sanity: the first delegation must succeed');

        $secondService = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $helper->id,
                    'task' => 'Summarize document B.',
                    'context' => 'SECOND-DELEGATION-DIFFERENT-CONTEXT-STRING',
                ], 'call_delegate_second'),
            ]),
            $this->plainReply('Summary of document B.'),
            $this->plainReply('Here is the summary of document B.'),
        ]);
        $this->app->instance(AgentLoopService::class, $secondService);

        $second = $secondService->run($conversation->fresh(), 'Please also summarize document B.');
        $this->assertSame('completed', $second['status'], 'fixture sanity: the second delegation must succeed');

        $delegations = Delegation::where('parent_conversation_id', $conversation->id)->orderBy('started_at')->get()->values();
        $this->assertCount(2, $delegations, 'fixture sanity: exactly two independent Delegation rows must exist -- one per delegate_to_helper call');
        $this->assertNotSame(
            $delegations[0]->helper_conversation_id,
            $delegations[1]->helper_conversation_id,
            'each delegation must spin up its own brand-new helper conversation, never reusing the prior one (data-model.md §1 -- never re-opened)',
        );

        $secondHelperMessages = Message::where('conversation_id', $delegations[1]->helper_conversation_id)->get();
        foreach ($secondHelperMessages as $message) {
            $this->assertStringNotContainsString(
                'FIRST-DELEGATION-SECRET-CONTEXT-STRING',
                $message->content,
                'the SECOND delegation\'s own helper conversation must never carry over the FIRST delegation\'s own context string (US2 AC3)',
            );
        }

        $secondUserMessage = $secondHelperMessages->where('role', 'user')->first();
        $this->assertNotNull($secondUserMessage);
        $this->assertStringContainsString('SECOND-DELEGATION-DIFFERENT-CONTEXT-STRING', $secondUserMessage->content);
    }
}
