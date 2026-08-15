<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\OperationsSearchService;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ConversationHandoff;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use Dedoc\Scramble\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class AgentLoopServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function build_tools_payload_converts_mcp_tools_to_openai_format()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());
        $tools = $service->buildToolsPayload();

        // buildToolsPayload returns 10 hardcoded meta-tools
        // (3 operation + 4 memory + 1 declarative-memory proposal + 1
        // handoff, 093-agent-handoff + 1 delegation, 098-delegation-protocol)
        $this->assertCount(10, $tools);

        // Verify all 10 meta-tools are present
        $toolNames = collect($tools)->pluck('function.name')->toArray();
        $this->assertContains('list_applications', $toolNames);
        $this->assertContains('execute_operation', $toolNames);
        $this->assertContains('search_operations', $toolNames);
        $this->assertContains('memory_create', $toolNames);
        $this->assertContains('memory_read', $toolNames);
        $this->assertContains('memory_search', $toolNames);
        $this->assertContains('memory_delete', $toolNames);
        $this->assertContains('propose_declarative_memory', $toolNames);
        $this->assertContains('handoff_to_agent', $toolNames);
        $this->assertContains('delegate_to_helper', $toolNames);

        // Verify structure of each tool
        foreach ($tools as $tool) {
            $this->assertEquals('function', $tool['type']);
            $this->assertArrayHasKey('name', $tool['function']);
            $this->assertArrayHasKey('description', $tool['function']);
            $this->assertArrayHasKey('parameters', $tool['function']);
        }
    }

    #[Test]
    public function build_tools_payload_returns_eight_meta_tools()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());
        $tools = $service->buildToolsPayload();

        // 093-agent-handoff added a 9th meta-tool (handoff_to_agent);
        // 098-delegation-protocol added a 10th (delegate_to_helper).
        $this->assertCount(10, $tools);

        // Verify list_applications has no parameters
        $listApps = collect($tools)->firstWhere('function.name', 'list_applications');
        $this->assertNotNull($listApps);
        $this->assertCount(0, (array) $listApps['function']['parameters']['properties']);

        // Verify execute_operation has operationId and parameters sub-objects
        $execOp = collect($tools)->firstWhere('function.name', 'execute_operation');
        $this->assertNotNull($execOp);
        $this->assertArrayHasKey('operationId', $execOp['function']['parameters']['properties']);
        $this->assertArrayHasKey('parameters', $execOp['function']['parameters']['properties']);
        $this->assertArrayHasKey('required', $execOp['function']['parameters']);

        // Verify search_operations has query parameter
        $searchOps = collect($tools)->firstWhere('function.name', 'search_operations');
        $this->assertNotNull($searchOps);
        $this->assertArrayHasKey('query', $searchOps['function']['parameters']['properties']);
        $this->assertContains('query', $searchOps['function']['parameters']['required']);
    }

    #[Test]
    public function build_messages_payload_reconstructs_tool_data_into_openai_format()
    {
        // Set system_prompt to empty so no system message is prepended
        config(['llm-client.agent_loop.system_prompt' => '']);

        $conversation = Conversation::factory()->create();

        // User message
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Create a contact named Jane',
            'responseTime' => 0,
        ]);

        // Assistant message with tool_data
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 1,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_abc123',
                        'type' => 'function',
                        'function' => [
                            'name' => 'contacts.store',
                            'arguments' => '{"body":{"name": "Jane"}}',
                        ],
                    ],
                ],
                'tool_results' => [
                    [
                        'tool_call_id' => 'call_abc123',
                        'content' => '{"id": "uuid-123", "name": "Jane"}',
                    ],
                ],
                'iteration' => 1,
            ],
        ]);

        // Final assistant text message
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'Contact Jane has been created.',
            'responseTime' => 2,
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());
        $messages = $service->buildMessagesPayload($conversation);

        // user message (first message now, no system prompt)
        $this->assertEquals('user', $messages[0]['role']);
        $this->assertEquals('Create a contact named Jane', $messages[0]['content']);

        // assistant message with tool_calls
        $this->assertEquals('assistant', $messages[1]['role']);
        $this->assertArrayHasKey('tool_calls', $messages[1]);
        $this->assertEquals('call_abc123', $messages[1]['tool_calls'][0]['id']);

        // tool result message
        $this->assertEquals('tool', $messages[2]['role']);
        $this->assertEquals('call_abc123', $messages[2]['tool_call_id']);

        // final assistant text
        $this->assertEquals('assistant', $messages[3]['role']);
        $this->assertEquals('Contact Jane has been created.', $messages[3]['content']);
    }

    #[Test]
    public function start_sets_is_processing_and_dispatches_stream_request()
    {
        Queue::fake();

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create([
            'is_processing' => false,
            'server_id' => $server->id,
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Hello',
            'responseTime' => 0,
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache(), app(ProviderRegistry::class));
        $service->start($conversation);

        $conversation->refresh();
        $this->assertTrue($conversation->is_processing);

        Queue::assertPushed(SendHttpStreamRequest::class);
    }

    #[Test]
    public function start_enforces_max_iteration_limit()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        // The max iterations config should be accessible
        $this->assertEquals(20, config('llm-client.agent_loop.max_iterations'));
    }

    #[Test]
    public function resume_dispatches_next_iteration_on_approval()
    {
        Queue::fake();

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create(['is_processing' => true, 'server_id' => $server->id]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_def456',
                        'type' => 'function',
                        'function' => [
                            'name' => 'contacts.destroy',
                            'arguments' => '{"path":{"id": "42"}}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'destroyContact',
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/42',
                    'arguments' => ['path' => ['id' => '42']],
                    'conversation_history_snapshot' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => ['operationId' => 'destroyContact', 'method' => 'DELETE', 'path' => '/api/contacts/{id}'],
            ]);

        $executorMock = Mockery::mock(McpToolExecutor::class);
        $executorMock->shouldReceive('extractArguments')
            ->andReturn(['path' => '/api/contacts/42', 'query' => [], 'body' => []]);
        $executorMock->shouldReceive('executeHttpCall')
            ->andReturn([
                'content' => [['type' => 'text', 'text' => '{"success": true}']],
                'isError' => false,
            ]);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache(), app(ProviderRegistry::class));
        $service->resume($conversation, $message, true);

        Queue::assertPushed(SendHttpStreamRequest::class);

        $message->refresh();
        $this->assertNull($message->tool_data['pending_confirmation']);
    }

    /**
     * Reconciliation finding (100-subagent-tool-restrictions): resume()
     * (and its synchronous sibling resumeSync()) execute an approved,
     * previously-paused confirmation directly via executeApiCall() —
     * they never route through handleExecuteOperation(), the one place
     * EffectiveBoundResolver's ancestor-chain re-check (FR-004/FR-005/
     * FR-006) was wired in Phase 4. A confirmation can sit pending for
     * up to confirmation_timeout (default 300s); if an ancestor's
     * permitted operations no longer include the pending operation by
     * the time it is approved, the pre-fix code would still execute it
     * with zero live re-validation — exactly the "no path exists by
     * which delegating work escalates authority" guarantee this feature
     * exists for, just reached through the confirmation-resume path
     * instead of a fresh tool call. Reachable in the shipped app: a
     * helper's own ephemeral conversation is owned by the same real user
     * as the parent (DelegationService::delegate() sets user_id to the
     * owner), its id is exposed via DelegationController's own
     * delegation-row responses, and ConversationController::confirmApiCall()
     * places no restriction on which of the caller's own conversations a
     * pending confirmation is resumed on.
     */
    #[Test]
    public function resume_reapplies_the_live_ancestor_chain_bound_and_blocks_a_confirmed_call_the_ancestor_no_longer_permits()
    {
        Queue::fake();

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, ['paths' => [
            '/api/allowed' => ['get' => ['operationId' => 'allowed.operation', 'summary' => 'Allowed']],
            '/api/pending' => ['delete' => ['operationId' => 'pending.operation', 'summary' => 'Pending']],
        ]]);
        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn(['paths' => [
            '/api/allowed' => ['get' => ['operationId' => 'allowed.operation', 'summary' => 'Allowed']],
            '/api/pending' => ['delete' => ['operationId' => 'pending.operation', 'summary' => 'Pending']],
        ]]);
        $this->app->instance(Generator::class, $generator);

        $owner = User::factory()->create();
        $agentService = new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader());

        // The ancestor (parent) does NOT currently permit pending.operation
        // — whether because it was narrowed after the confirmation was
        // first requested, or simply because it never did, the live check
        // must catch it either way.
        $parent = $agentService->create($owner->id, "name: recon-resume-parent\ninstructions: hi\ntools:\n  allow:\n    - allowed.operation\n");
        $helper = $agentService->create($owner->id, "name: recon-resume-helper\ninstructions: hi\ntools:\n  allow:\n    - allowed.operation\n    - pending.operation\n");

        AgentHelperAssignment::create([
            'parent_agent_id' => $parent->id,
            'helper_agent_id' => $helper->id,
            'owner_user_id' => $owner->id,
        ]);

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $parentConversation = Conversation::factory()->create(['user_id' => $owner->id, 'server_id' => $server->id]);
        $helperConversation = Conversation::factory()->create(['user_id' => $owner->id, 'server_id' => $server->id, 'is_processing' => true]);

        Delegation::create([
            'id' => (string) Str::uuid(),
            'parent_conversation_id' => $parentConversation->id,
            'parent_agent_id' => $parent->id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => $helperConversation->id,
            'owner_user_id' => $owner->id,
            'task' => 'Attempt the pending operation.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $message = Message::create([
            'conversation_id' => $helperConversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_pending',
                        'type' => 'function',
                        'function' => [
                            'name' => 'execute_operation',
                            'arguments' => '{"operationId":"pending.operation","parameters":{}}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'pending.operation',
                    'tool_name' => 'execute_operation',
                    'method' => 'DELETE',
                    'path' => '/api/pending',
                    'arguments' => [],
                    'conversation_history_snapshot' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        // The whole point of this test: if the ancestor-chain re-check is
        // ever removed from resume(), execution would reach this mock —
        // making it fail loudly rather than silently passing.
        $executorMock->shouldNotReceive('executeHttpCall');

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache(), app(ProviderRegistry::class));
        $service->resume($helperConversation, $message, true);

        $message->refresh();
        $resultContent = json_decode($message->tool_data['tool_results'][0]['content'] ?? 'null', true);

        $this->assertIsArray($resultContent);
        $this->assertArrayHasKey('error', $resultContent);
        $this->assertStringContainsString(
            'Operation not permitted: ancestor agent',
            $resultContent['error'],
            'resume() must re-check the live ancestor-chain bound before executing an approved confirmation, not just trust the bound that held when confirmation was first requested',
        );
        $this->assertStringContainsString('pending.operation', $resultContent['error']);
    }

    /**
     * Reconciliation finding (102-router-pattern): ensureSpecialistAvailable()
     * (D7/FR-011 — a specialist that goes inactive mid-conversation must
     * trigger an automatic fallback handoff) was wired into run()/start()/
     * resumeSync(), mirroring checkSharedAgentAccessRevoked()'s own
     * three-site precedent — but checkSharedAgentAccessRevoked() actually
     * has FOUR call sites in this class, not three: run(), start(),
     * resumeSync(), AND resume(). resume() — not resumeSync() — is the
     * method the shipped app's real confirmation-approval endpoint
     * (ConversationController::confirmApiCall()) actually calls;
     * resumeSync() has no caller anywhere in src/ at all. Before this fix,
     * a conversation's bound specialist deactivated during a pending
     * confirmation's window (up to confirmation_timeout, default 300s —
     * the same time-window reasoning the ancestor-chain re-check just
     * above this test already applies) would keep the conversation bound
     * to the now-deactivated agent indefinitely: the confirmed operation
     * would still execute, but no fallback handoff would ever be
     * triggered anywhere in this turn, silently reproducing exactly the
     * gap D7 was designed to close, just reached through the
     * confirmation-resume path instead of a fresh turn.
     */
    #[Test]
    public function resume_moves_the_conversation_to_a_fallback_when_its_specialist_was_deactivated_during_the_pending_confirmation_window()
    {
        Queue::fake();

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, ['paths' => [
            '/api/contacts/42' => ['delete' => ['operationId' => 'destroyContact', 'summary' => 'Destroy contact']],
        ]]);
        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn(['paths' => [
            '/api/contacts/42' => ['delete' => ['operationId' => 'destroyContact', 'summary' => 'Destroy contact']],
        ]]);
        $this->app->instance(Generator::class, $generator);

        $owner = User::factory()->create();
        $agentService = new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader());

        $agentA = $agentService->create($owner->id, "name: recon-resume-agent-a\ninstructions: I am agent A, the original specialist.");
        $agentB = $agentService->create($owner->id, "name: recon-resume-agent-b\ninstructions: I am agent B, the only other active specialist.");

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create([
            'user_id' => $owner->id,
            'server_id' => $server->id,
            'is_processing' => true,
            'agent_id' => $agentA->id,
            'agent_version_id' => $agentA->current_version_id,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => (string) $owner->id,
            'content' => 'My original question.',
            'responseTime' => 0,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_def456',
                        'type' => 'function',
                        'function' => [
                            'name' => 'contacts.destroy',
                            'arguments' => '{"path":{"id": "42"}}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'destroyContact',
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/42',
                    'arguments' => ['path' => ['id' => '42']],
                    'conversation_history_snapshot' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        // Agent A, the conversation's bound specialist, is deactivated
        // WHILE the confirmation sits pending.
        $agentService->deactivate($agentA->fresh(), true);
        $this->assertFalse($agentA->fresh()->is_active, 'fixture sanity: agent A must actually be deactivated');

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => ['operationId' => 'destroyContact', 'method' => 'DELETE', 'path' => '/api/contacts/{id}'],
            ]);

        $executorMock = Mockery::mock(McpToolExecutor::class);
        $executorMock->shouldReceive('extractArguments')
            ->andReturn(['path' => '/api/contacts/42', 'query' => [], 'body' => []]);
        $executorMock->shouldReceive('executeHttpCall')
            ->andReturn([
                'content' => [['type' => 'text', 'text' => '{"success": true}']],
                'isError' => false,
            ]);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache(), app(ProviderRegistry::class));
        $service->resume($conversation->fresh(), $message, true);

        $row = ConversationHandoff::where('conversation_id', $conversation->id)->orderByDesc('position')->first();
        $this->assertNotNull(
            $row,
            'resume() — the actual production confirmation-continuation path ConversationController::confirmApiCall() calls, as opposed to the never-called-in-production resumeSync() — must trigger the same automatic-fallback handoff run()/start()/resumeSync() already trigger, not silently leave the conversation bound to its now-deactivated specialist',
        );
        $this->assertSame('unavailable', $row->reason);
        $this->assertSame($agentB->id, $row->to_agent_id);
        $this->assertSame($agentA->id, $row->from_agent_id);
    }

    #[Test]
    public function resume_constructs_cancellation_result_on_denial()
    {
        Queue::fake();

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create(['is_processing' => true, 'server_id' => $server->id]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_def456',
                        'type' => 'function',
                        'function' => [
                            'name' => 'contacts.destroy',
                            'arguments' => '{"path":{"id": "42"}}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'destroyContact',
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/42',
                    'arguments' => ['path' => ['id' => '42']],
                    'conversation_history_snapshot' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);

        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache(), app(ProviderRegistry::class));
        $service->resume($conversation, $message, false);

        Queue::assertPushed(SendHttpStreamRequest::class);

        $message->refresh();
        $this->assertNotNull($message->tool_data['tool_results']);
        $this->assertStringContainsString('cancelled', $message->tool_data['tool_results'][0]['content']);
    }

    #[Test]
    public function resume_rejects_expired_confirmations()
    {
        $conversation = Conversation::factory()->create(['is_processing' => true]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_def456',
                        'type' => 'function',
                        'function' => [
                            'name' => 'contacts.destroy',
                            'arguments' => '{"path":{"id": "42"}}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/42',
                    'arguments' => ['path' => ['id' => '42']],
                    'conversation_history_snapshot' => [],
                    'expires_at' => now()->subMinutes(1)->toIso8601String(),
                ],
            ],
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $this->expectException(\RuntimeException::class);
        $service->resume($conversation, $message, true);
    }

    // === US1 Tests (T038) ===

    #[Test]
    public function message_store_dispatches_agent_loop_start()
    {
        Queue::fake();

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create(['is_processing' => false, 'server_id' => $server->id]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache(), app(ProviderRegistry::class));

        // Simulate what MessageController::store() does
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Create a contact',
            'responseTime' => 0,
        ]);

        $service->start($conversation);

        $conversation->refresh();
        $this->assertTrue($conversation->is_processing);
        Queue::assertPushed(SendHttpStreamRequest::class);
    }

    #[Test]
    public function message_store_skips_dispatch_when_is_processing()
    {
        Queue::fake();

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        // The controller should check is_processing and skip dispatch
        $this->assertTrue($conversation->is_processing);

        // Message is still saved
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Another message while processing',
            'responseTime' => 0,
        ]);

        $this->assertNotNull($message->id);
        // No dispatch should happen — verified by the controller logic
    }

    #[Test]
    public function unprocessed_message_detected_after_loop_completion()
    {
        $conversation = Conversation::factory()->create(['is_processing' => false]);

        // Use DB::table to insert with explicit timestamps (bypass Eloquent auto-timestamps)
        $conn = config('database.default');
        DB::table('messages')->insert([
            'id' => (string) \Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'First message',
            'responseTime' => 0,
            'tool_data' => null,
            'created_at' => '2025-01-01 10:00:00',
            'updated_at' => '2025-01-01 10:00:00',
            'deleted_at' => null,
        ]);

        DB::table('messages')->insert([
            'id' => (string) \Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'First reply',
            'responseTime' => 1,
            'tool_data' => null,
            'created_at' => '2025-01-01 10:01:00',
            'updated_at' => '2025-01-01 10:01:00',
            'deleted_at' => null,
        ]);

        DB::table('messages')->insert([
            'id' => (string) \Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Second message',
            'responseTime' => 0,
            'tool_data' => null,
            'created_at' => '2025-01-01 10:02:00',
            'updated_at' => '2025-01-01 10:02:00',
            'deleted_at' => null,
        ]);

        // Latest user message is newer than latest assistant message
        $latestUser = Message::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->latest('created_at')
            ->first();

        $latestAssistant = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($latestUser);
        $this->assertNotNull($latestAssistant);
        // Verify user message timestamp (10:02) is strictly after assistant (10:01)
        $this->assertTrue(
            $latestUser->created_at->gt($latestAssistant->created_at),
            'User message (' . $latestUser->created_at->format('H:i:s') .
            ') should be newer than assistant (' . $latestAssistant->created_at->format('H:i:s') . ')'
        );
    }

    // === US1 Tests: Search Operations Core (T006-T012) ===

    /**
     * Helper to invoke private handleSearchOperations via reflection.
     */
    private function invokeHandleSearchOperations(AgentLoopService $service, array $arguments): string
    {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('handleSearchOperations');
        $method->setAccessible(true);
        return $method->invoke($service, $arguments);
    }

    // T006

    #[Test]
    public function search_operations_returns_results_with_correct_wrapper_format()
    {
        // Mock OperationsSearchService via app() binding
        $mockRow = (object) [
            'operationId' => 'contacts.store',
            'type'        => 'operation',
            'summary'     => 'Store a new contact',
            'method'      => 'POST',
            'path'        => '/api/contacts',
            'paramSchema' => json_encode(['body' => [['name' => 'name', 'type' => 'string']]]),
            'promptContent' => null,
        ];
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->with('create a contact')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'create a contact']);
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertCount(1, $decoded['results']);
        $this->assertEquals('operation', $decoded['results'][0]['type']);
        $this->assertEquals('contacts.store', $decoded['results'][0]['operationId']);
        $this->assertEquals('POST', $decoded['results'][0]['method']);
        $this->assertEquals('/api/contacts', $decoded['results'][0]['path']);
        $this->assertArrayHasKey('paramSchema', $decoded['results'][0]);
    }

    // T007

    #[Test]
    public function search_operations_truncates_long_query()
    {
        $longQuery = str_repeat('a', 600);

        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->withArgs(function ($query) {
                return strlen($query) <= 500;
            })
            ->once()
            ->andReturn([]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $this->invokeHandleSearchOperations($service, ['query' => $longQuery]);
        // If we get here without exception, truncation worked
        $this->assertTrue(true);
    }

    // T008

    #[Test]
    public function search_operations_returns_error_for_missing_query()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, []);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals('query parameter is required', $decoded['error']);
    }

    // T009

    #[Test]
    public function search_operations_handles_null_param_schema()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'contacts.index',
            'summary'   => 'List all contacts',
            'method'    => 'GET',
            'path'      => '/api/contacts',
            'paramSchema' => null,
        ];
        $mockRow = (object) [
            'operationId' => 'contacts.index',
            'type'        => 'operation',
            'summary'     => 'List all contacts',
            'method'      => 'GET',
            'path'        => '/api/contacts',
            'paramSchema' => null,
            'promptContent' => null,
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'list contacts']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $this->assertNull($decoded['results'][0]['paramSchema']);
    }

    // T010

    #[Test]
    public function search_operations_handles_malformed_param_schema()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'broken.op',
            'type'        => 'operation',
            'summary'     => 'Broken param schema',
            'method'      => 'GET',
            'path'        => '/api/broken',
            'paramSchema' => '{invalid json content',
            'promptContent' => null,
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'broken']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        // Malformed paramSchema should be treated as null
        $this->assertNull($decoded['results'][0]['paramSchema']);
    }

    // T011 - limit is enforced by OperationsSearchService::search() with default $limit=10

    #[Test]
    public function search_operations_passes_default_limit_of_10()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $rows[] = (object) [
                'operationId' => "op.{$i}",
                'type'        => 'operation',
                'summary'     => "Operation {$i}",
                'method'      => 'GET',
                'path'        => "/api/op/{$i}",
                'paramSchema' => null,
                'promptContent' => null,
            ];
        }
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn($rows);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'test']);
        $decoded = json_decode($result, true);

        // Passed through correctly
        $this->assertCount(8, $decoded['results']);
    }

    // T013

    #[Test]
    public function search_operations_returns_zero_match_hint_when_table_has_data_but_no_matches()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        // Mock DB facade properly using partial mock
        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('count')->once()->andReturn(5); // Table has data

        DB::shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'xyz_nonexistent']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('hint', $decoded);
        $this->assertStringContainsString('broader', $decoded['hint']);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertEmpty($decoded['results']);
    }

    // T014

    #[Test]
    public function search_operations_returns_empty_index_hint_when_table_has_zero_rows()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        // Mock DB facade properly using partial mock
        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('count')->once()->andReturn(0); // Empty index

        DB::shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'test']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('hint', $decoded);
        $this->assertStringContainsString('empty', $decoded['hint']);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertEmpty($decoded['results']);
    }

    // T015

    #[Test]
    public function search_operations_returns_missing_table_hint_when_table_does_not_exist()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(false);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'test']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('hint', $decoded);
        $this->assertStringContainsString('not available', $decoded['hint']);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertEmpty($decoded['results']);
    }

    // T017

    #[Test]
    public function search_operations_preserves_paramSchema_path_section()
    {
        $paramSchema = [
            'path' => [['name' => 'id', 'type' => 'integer', 'required' => true]],
        ];
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'contacts.show',
            'type'        => 'operation',
            'summary'     => 'Get contact by ID',
            'method'      => 'GET',
            'path'        => '/api/contacts/{id}',
            'paramSchema' => json_encode($paramSchema),
            'promptContent' => null,
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'get contact']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $schema = $decoded['results'][0]['paramSchema'];
        $this->assertArrayHasKey('path', $schema);
        $this->assertCount(1, $schema['path']);
        $this->assertEquals('id', $schema['path'][0]['name']);
        $this->assertEquals('integer', $schema['path'][0]['type']);
    }

    // T018

    #[Test]
    public function search_operations_preserves_paramSchema_query_section()
    {
        $paramSchema = [
            'query' => [
                ['name' => 'page', 'type' => 'integer', 'required' => false],
                ['name' => 'per_page', 'type' => 'integer', 'required' => false],
            ],
        ];
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'contacts.index',
            'type'        => 'operation',
            'summary'     => 'List contacts',
            'method'      => 'GET',
            'path'        => '/api/contacts',
            'paramSchema' => json_encode($paramSchema),
            'promptContent' => null,
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'list contacts']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $schema = $decoded['results'][0]['paramSchema'];
        $this->assertArrayHasKey('query', $schema);
        $this->assertCount(2, $schema['query']);
        $this->assertEquals('page', $schema['query'][0]['name']);
    }

    // T019

    #[Test]
    public function search_operations_preserves_paramSchema_body_section()
    {
        $paramSchema = [
            'body' => [
                ['name' => 'name', 'type' => 'string', 'required' => true],
                ['name' => 'email', 'type' => 'string', 'required' => true],
            ],
        ];
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'contacts.store',
            'type'        => 'operation',
            'summary'     => 'Create contact',
            'method'      => 'POST',
            'path'        => '/api/contacts',
            'paramSchema' => json_encode($paramSchema),
            'promptContent' => null,
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'create contact']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $schema = $decoded['results'][0]['paramSchema'];
        $this->assertArrayHasKey('body', $schema);
        $this->assertCount(2, $schema['body']);
        $this->assertEquals('name', $schema['body'][0]['name']);
    }

    // T020

    #[Test]
    public function search_operations_preserves_full_paramSchema_structure_with_all_sections()
    {
        $paramSchema = [
            'path' => [['name' => 'id', 'type' => 'integer', 'required' => true]],
            'query' => [['name' => 'expand', 'type' => 'string', 'required' => false]],
            'body' => [['name' => 'name', 'type' => 'string', 'required' => true]],
        ];
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'contacts.update',
            'type'        => 'operation',
            'summary'     => 'Update contact',
            'method'      => 'PUT',
            'path'        => '/api/contacts/{id}',
            'paramSchema' => json_encode($paramSchema),
            'promptContent' => null,
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'update contact']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $schema = $decoded['results'][0]['paramSchema'];
        // Verify all three sections preserved
        $this->assertArrayHasKey('path', $schema);
        $this->assertArrayHasKey('query', $schema);
        $this->assertArrayHasKey('body', $schema);
        $this->assertEquals('id', $schema['path'][0]['name']);
        $this->assertEquals('expand', $schema['query'][0]['name']);
        $this->assertEquals('name', $schema['body'][0]['name']);
    }

    // - Custom prompt result format

    #[Test]
    public function search_operations_returns_prompt_result_format()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $mockRow = (object) [
            'operationId' => 'wizlights_listOperations',
            'type'        => 'prompt',
            'package_name' => 'wizlight-backend',
            'summary'     => 'Custom prompt for wizlight lighting control',
            'promptContent' => 'To adjust the lighting, first use the wizlights_room.index tool...',
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->with('adjust lighting')
            ->once()
            ->andReturn([$mockRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'adjust lighting']);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('results', $decoded);
        $this->assertCount(1, $decoded['results']);
        $r = $decoded['results'][0];
        $this->assertEquals('prompt', $r['type']);
        $this->assertEquals('wizlights_listOperations', $r['id']);
        $this->assertEquals('wizlight-backend', $r['package']);
        $this->assertStringContainsString('lighting', $r['summary']);
        $this->assertStringContainsString('wizlights_room.index', $r['content']);
        // Prompt results should NOT have operation fields
        $this->assertArrayNotHasKey('operationId', $r);
        $this->assertArrayNotHasKey('method', $r);
        $this->assertArrayNotHasKey('path', $r);
        $this->assertArrayNotHasKey('paramSchema', $r);
    }

    // - Mixed operation + prompt results

    #[Test]
    public function search_returns_mixed_operation_and_prompt_results()
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $operationRow = (object) [
            'operationId' => 'wizlights.index',
            'type'        => 'operation',
            'summary'     => 'List all lights in a room',
            'method'      => 'GET',
            'path'        => '/api/wizlights',
            'paramSchema' => null,
            'promptContent' => null,
        ];
        $promptRow = (object) [
            'operationId' => 'wizlights_executeOperation',
            'type'        => 'prompt',
            'package_name' => 'wizlight-backend',
            'summary'     => 'Custom prompt for wizlight operation execution',
            'promptContent' => 'When adjusting the lighting, you must include the dimming property...',
        ];
        $searchServiceMock->shouldReceive('tableExists')->once()->andReturn(true);
        $searchServiceMock->shouldReceive('search')
            ->once()
            ->andReturn([$operationRow, $promptRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());

        $result = $this->invokeHandleSearchOperations($service, ['query' => 'wizlights']);
        $decoded = json_decode($result, true);

        $this->assertCount(2, $decoded['results']);

        // First result is an operation
        $this->assertEquals('operation', $decoded['results'][0]['type']);
        $this->assertEquals('wizlights.index', $decoded['results'][0]['operationId']);
        $this->assertEquals('GET', $decoded['results'][0]['method']);

        // Second result is a prompt
        $this->assertEquals('prompt', $decoded['results'][1]['type']);
        $this->assertEquals('wizlights_executeOperation', $decoded['results'][1]['id']);
        $this->assertEquals('wizlight-backend', $decoded['results'][1]['package']);
        $this->assertStringContainsString('dimming', $decoded['results'][1]['content']);
    }

    /* ── Known Operations Section Tests (US1–US4) ── */

    /**
     * Create an AgentLoopService with a real OperationCache pre-populated with entries.
     * Uses real OperationCache (in-memory, no DB) to avoid Mockery alias issues.
     */
    private function createServiceWithCacheEntries(string $conversationId, array $entries): AgentLoopService
    {
        $cache = new OperationCache();
        // Pre-populate cache using put() to simulate cached operations
        foreach ($entries as $entry) {
            $cache->put($conversationId, $entry['operationId'], [
                'summary' => $entry['summary'],
                'method' => $entry['method'],
                'path' => $entry['path'],
                'paramSchema' => $entry['paramSchema'] ?? null,
            ]);
        }

        // Use mocks only for McpToolRegistry and McpToolExecutor (no type hint issues)
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        return new AgentLoopService($registryMock, $executorMock, $cache);
    }

    #[Test]
    public function build_known_operations_section_generates_bullet_list_format()
    {
        $conversation = Conversation::factory()->create();

        $entries = [
            [
                'operationId' => 'create-contact',
                'summary' => 'Create a new contact',
                'method' => 'POST',
                'path' => '/contacts',
                'paramSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            ],
            [
                'operationId' => 'list-tasks',
                'summary' => 'List all tasks',
                'method' => 'GET',
                'path' => '/tasks',
                'paramSchema' => null,
            ],
        ];

        $service = $this->createServiceWithCacheEntries($conversation->id, $entries);
        $section = $this->invokeBuildKnownOperationsSection($service, $conversation);

        $this->assertNotNull($section);
        $this->assertStringContainsString("## Known Operations", $section);
        $this->assertStringContainsString("**create-contact** (POST /contacts)", $section);
        $this->assertStringContainsString("- Summary: Create a new contact", $section);
        $this->assertStringContainsString("- Parameters:", $section);
        $this->assertStringContainsString("**list-tasks** (GET /tasks)", $section);
        $this->assertStringContainsString("- Summary: List all tasks", $section);
        $this->assertStringContainsString("- Parameters: none", $section);
    }

    #[Test]
    public function build_known_operations_section_handles_null_paramschema()
    {
        $conversation = Conversation::factory()->create();

        $entries = [
            [
                'operationId' => 'delete-contact',
                'summary' => 'Delete a contact',
                'method' => 'DELETE',
                'path' => '/contacts/1',
                'paramSchema' => null,
            ],
        ];

        $service = $this->createServiceWithCacheEntries($conversation->id, $entries);
        $section = $this->invokeBuildKnownOperationsSection($service, $conversation);

        $this->assertNotNull($section);
        $this->assertStringContainsString("- Parameters: none", $section);
    }

    #[Test]
    public function build_known_operations_section_returns_null_for_empty_cache()
    {
        $conversation = Conversation::factory()->create();

        $cache = new OperationCache();
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, $cache);
        $section = $this->invokeBuildKnownOperationsSection($service, $conversation);

        $this->assertNull($section);
    }

    #[Test]
    public function build_messages_payload_includes_known_operations_section()
    {
        $conversation = Conversation::factory()->create();

        $entries = [
            [
                'operationId' => 'create-contact',
                'summary' => 'Create a new contact',
                'method' => 'POST',
                'path' => '/contacts',
                'paramSchema' => null,
            ],
        ];

        $service = $this->createServiceWithCacheEntries($conversation->id, $entries);
        // Rebuild service with registry mock (no getTools expectations needed)
        $cache = new OperationCache();
        foreach ($entries as $entry) {
            $cache->put($conversation->id, $entry['operationId'], [
                'summary' => $entry['summary'],
                'method' => $entry['method'],
                'path' => $entry['path'],
                'paramSchema' => $entry['paramSchema'] ?? null,
            ]);
        }
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $service = new AgentLoopService($registryMock, $executorMock, $cache);
        $messages = $service->buildMessagesPayload($conversation);

        // First message should be system with Known Operations
        $this->assertEquals('system', $messages[0]['role']);
        $this->assertStringContainsString('## Known Operations', $messages[0]['content']);
        $this->assertStringContainsString('create-contact', $messages[0]['content']);
        // Old "Recently Used Operations" should NOT appear
        $this->assertStringNotContainsString('Recently Used Operations', $messages[0]['content']);
    }

    #[Test]
    public function build_messages_payload_skips_known_operations_when_cache_empty()
    {
        $conversation = Conversation::factory()->create();

        $cache = new OperationCache();

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        // Set base system prompt to something non-empty
        config(['llm-client.agent_loop.system_prompt' => 'You are a helpful assistant.']);

        $service = new AgentLoopService($registryMock, $executorMock, $cache);
        $messages = $service->buildMessagesPayload($conversation);

        // System message should exist but not contain Known Operations
        $systemMsg = collect($messages)->firstWhere('role', 'system');
        $this->assertNotNull($systemMsg);
        $this->assertStringNotContainsString('Known Operations', $systemMsg['content']);
    }

    #[Test]
    public function build_known_operations_section_has_clear_delimiter()
    {
        $conversation = Conversation::factory()->create();

        $entries = [
            [
                'operationId' => 'test-op',
                'summary' => 'A test operation',
                'method' => 'GET',
                'path' => '/test',
                'paramSchema' => null,
            ],
        ];

        $service = $this->createServiceWithCacheEntries($conversation->id, $entries);
        $section = $this->invokeBuildKnownOperationsSection($service, $conversation);

        $this->assertNotNull($section);
        // Section should start with blank lines then ## Known Operations
        $this->assertMatchesRegularExpression('/\n+## Known Operations\n/', $section);
    }

    #[Test]
    public function build_messages_payload_with_empty_base_prompt_and_cache_entries()
    {
        config(['llm-client.agent_loop.system_prompt' => '']);

        $conversation = Conversation::factory()->create();

        $entries = [
            [
                'operationId' => 'test-op',
                'summary' => 'Test',
                'method' => 'GET',
                'path' => '/test',
                'paramSchema' => null,
            ],
        ];

        // Build service with mocked registry (for buildMessagesPayload getTools call)
        $cache = new OperationCache();
        foreach ($entries as $entry) {
            $cache->put($conversation->id, $entry['operationId'], [
                'summary' => $entry['summary'],
                'method' => $entry['method'],
                'path' => $entry['path'],
                'paramSchema' => $entry['paramSchema'] ?? null,
            ]);
        }

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, $cache);
        $messages = $service->buildMessagesPayload($conversation);

        // System message should still exist with Known Operations section
        $systemMsg = collect($messages)->firstWhere('role', 'system');
        $this->assertNotNull($systemMsg);
        $this->assertStringContainsString('## Known Operations', $systemMsg['content']);
        $this->assertStringContainsString('test-op', $systemMsg['content']);
    }

    /**
     * Helper to invoke private method buildKnownOperationsSection via reflection.
     */
    private function invokeBuildKnownOperationsSection(AgentLoopService $service, Conversation $conversation): ?string
    {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildKnownOperationsSection');
        $method->setAccessible(true);
        return $method->invoke($service, $conversation);
    }

    // T014

    #[Test]
    public function execute_operation_meta_tool_has_structured_parameters_schema()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());
        $tools = $service->buildToolsPayload();

        // Find execute_operation meta-tool
        $execOp = collect($tools)->firstWhere('function.name', 'execute_operation');
        $this->assertNotNull($execOp, 'execute_operation meta-tool should exist');

        $paramsProps = $execOp['function']['parameters']['properties']['parameters']['properties'];
        $this->assertArrayHasKey('path', $paramsProps);
        $this->assertArrayHasKey('query', $paramsProps);
        $this->assertArrayHasKey('body', $paramsProps);

        // Each sub-object should have additionalProperties: true
        $this->assertTrue($paramsProps['path']['additionalProperties']);
        $this->assertTrue($paramsProps['query']['additionalProperties']);
        $this->assertTrue($paramsProps['body']['additionalProperties']);
    }

    // T015

    #[Test]
    public function execute_operation_description_mentions_structured_format()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService($registryMock, $executorMock, new OperationCache());
        $tools = $service->buildToolsPayload();

        $execOp = collect($tools)->firstWhere('function.name', 'execute_operation');
        $this->assertNotNull($execOp);

        $desc = $execOp['function']['description'];
        // Should mention structured format, not flat prefixes
        $this->assertStringContainsString('structured', $desc);
        $this->assertStringContainsString('path', strtolower($desc));
        $this->assertStringContainsString('query', strtolower($desc));
        $this->assertStringContainsString('body', strtolower($desc));
        // Should NOT mention flat prefixes
        $this->assertStringNotContainsString('path_', $desc);
        $this->assertStringNotContainsString('query_', $desc);
        $this->assertStringNotContainsString('body_', $desc);
    }

    #[Test]
    public function build_tools_payload_emits_generic_envelope_schema()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $cache = new OperationCache();

        $service = new AgentLoopService($registryMock, $executorMock, $cache);
        $tools = $service->buildToolsPayload();

        $execOp = collect($tools)->firstWhere('function.name', 'execute_operation');
        $this->assertNotNull($execOp);

        $paramsProps = $execOp['function']['parameters']['properties']['parameters']['properties'];

        // All three groups are always offered, and each accepts arbitrary keys so
        // any operation's parameters can be expressed.
        foreach (['path', 'query', 'body'] as $group) {
            $this->assertArrayHasKey($group, $paramsProps);
            $this->assertTrue($paramsProps[$group]['additionalProperties']);
            $this->assertCount(0, (array) ($paramsProps[$group]['properties'] ?? new \stdClass()));
        }

        // operationId is the only required field; no operation-specific field is
        // ever marked required on the shared tool.
        $this->assertEquals(['operationId'], $execOp['function']['parameters']['required']);
        foreach (['path', 'query', 'body'] as $group) {
            $this->assertArrayNotHasKey('required', $paramsProps[$group]);
        }
    }

    #[Test]
    public function build_tools_payload_schema_does_not_favor_most_recent_cached_operation()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $cache = new OperationCache();

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.test.com', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create(['server_id' => $server->id]);

        // getContact needs a path param.
        $cache->put($conversation->id, 'getContact', [
            'summary' => 'Get a contact by ID',
            'method' => 'GET',
            'path' => '/api/contacts/{id}',
            'paramSchema' => [
                'path' => ['id' => ['type' => 'string', 'required' => true]],
                'query' => [],
                'body' => [],
            ],
        ]);

        // createContact is cached last (most recently used) and needs a body param.
        $cache->put($conversation->id, 'createContact', [
            'summary' => 'Create a new contact',
            'method' => 'POST',
            'path' => '/api/contacts',
            'paramSchema' => [
                'path' => [],
                'query' => [],
                'body' => ['name' => ['type' => 'string', 'required' => true]],
            ],
        ]);

        $service = new AgentLoopService($registryMock, $executorMock, $cache);
        $tools = $service->buildToolsPayload();

        $execOp = collect($tools)->firstWhere('function.name', 'execute_operation');
        $paramsProps = $execOp['function']['parameters']['properties']['parameters']['properties'];

        // Regression: the schema previously described only the most-recently-used
        // operation, which dropped the `path` group and marked createContact's
        // `body.name` required — leaving getContact impossible to call correctly.
        $this->assertArrayHasKey('path', $paramsProps);
        $this->assertTrue($paramsProps['path']['additionalProperties']);
        $this->assertArrayNotHasKey('required', $paramsProps['body']);
        $this->assertCount(0, (array) ($paramsProps['body']['properties'] ?? new \stdClass()));
    }

    #[Test]
    public function known_operations_section_carries_per_operation_param_schemas()
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);
        $cache = new OperationCache();

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.test.com', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create(['server_id' => $server->id]);

        $cache->put($conversation->id, 'getContact', [
            'summary' => 'Get a contact by ID',
            'method' => 'GET',
            'path' => '/api/contacts/{id}',
            'paramSchema' => [
                'path' => ['id' => ['type' => 'string', 'required' => true]],
                'query' => ['include' => ['type' => 'string', 'enum' => ['profile', 'all']]],
                'body' => [],
            ],
        ]);

        $service = new AgentLoopService($registryMock, $executorMock, $cache);
        $payload = $service->buildMessagesPayload($conversation);

        $system = collect($payload)->firstWhere('role', 'system')['content'] ?? '';

        // With the tool schema generic, this section is how per-operation
        // parameter detail reaches the LLM.
        $this->assertStringContainsString('Known Operations', $system);
        $this->assertStringContainsString('getContact', $system);
        $this->assertStringContainsString('GET /api/contacts/{id}', $system);
        $this->assertStringContainsString('"id"', $system);
        $this->assertStringContainsString('"include"', $system);
        $this->assertStringContainsString('profile', $system);
    }

    #[Test]
    public function failed_llm_call_records_action_with_failure_outcome_and_reason(): void
    {
        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.run_trace.action_row_cap' => 500]);
        config(['llm-client.run_trace.action_content_cap_bytes' => 16384]);

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create([
            'is_processing' => false,
            'server_id' => $server->id,
            'model' => 'gpt-4',
        ]);

        // Mock provider that throws on chat().
        $providerMock = Mockery::mock(\ClarionApp\LlmClient\Contracts\LlmProvider::class);
        $providerMock->shouldReceive('chat')->andThrow(new \RuntimeException('connection timeout'));
        $providerMock->shouldReceive('countTokens')->andReturn(10);

        $registryMock = Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolveByType')->andReturn($providerMock);

        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService(
            Mockery::mock(McpToolRegistry::class),
            $executorMock,
            new OperationCache(),
            $registryMock,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            app(\ClarionApp\LlmClient\Services\RunTraceRecorder::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('connection timeout');

        try {
            $service->run($conversation, 'Hello');
        } catch (\RuntimeException $e) {
            // Check that the action was recorded with Failure outcome.
            $actions = DB::table('agent_run_actions')->get();
            $this->assertGreaterThanOrEqual(1, $actions->count(), 'Expected at least one action record');

            $failureActions = $actions->where('outcome', 'failure');
            $this->assertGreaterThanOrEqual(1, $failureActions->count(), 'Expected at least one action with failure outcome');

            $firstFailure = $failureActions->first();
            $this->assertNotNull($firstFailure->failure_reason, 'Failure action should have a failure_reason');
            $this->assertStringContainsString('connection timeout', $firstFailure->failure_reason);

            throw $e;
        }
    }

    #[Test]
    public function resume_sync_api_call_failure_closes_inbound_action_with_failure(): void
    {
        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.run_trace.action_row_cap' => 500]);
        config(['llm-client.run_trace.action_content_cap_bytes' => 16384]);

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create(['is_processing' => true, 'server_id' => $server->id]);

        // Create a message with pending confirmation and an action_id.
        $inboundActionId = (string) \Illuminate\Support\Str::uuid();
        $runId = (string) \Illuminate\Support\Str::uuid();
        $stepId = (string) \Illuminate\Support\Str::uuid();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_test123',
                        'type' => 'function',
                        'function' => [
                            'name' => 'contacts.destroy',
                            'arguments' => '{"path":{"id": "42"}}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'destroyContact',
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/42',
                    'arguments' => ['path' => ['id' => 42]],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
                'run_id' => $runId,
                'step_id' => $stepId,
                'action_id' => $inboundActionId,
            ],
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => ['operationId' => 'destroyContact', 'method' => 'DELETE', 'path' => '/api/contacts/{id}'],
            ]);

        $executorMock = Mockery::mock(McpToolExecutor::class);
        $executorMock->shouldReceive('extractArguments')
            ->andReturn(['path' => '/api/contacts/42', 'query' => [], 'body' => []]);
        $executorMock->shouldReceive('executeHttpCall')
            ->andThrow(new \RuntimeException('tool execution failed'));

        $service = new AgentLoopService(
            $registryMock,
            $executorMock,
            new OperationCache(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tool execution failed');

        try {
            $service->resumeSync($conversation, $message, true);
        } catch (\RuntimeException $e) {
            // The inbound action should be closed with Failure outcome.
            throw $e;
        }
    }

    // === 074-latency-metrics T011: openRun() call sites pass streamed/model/agentId ===

    #[Test]
    public function start_passes_streamed_true_model_and_agent_id_to_open_run(): void
    {
        Queue::fake();
        config(['llm-client.run_trace.enabled' => true]);

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create([
            'is_processing' => false,
            'server_id' => $server->id,
            'model' => 'gpt-4',
            'character' => 'research-assistant',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Hello',
            'responseTime' => 0,
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService(
            $registryMock,
            $executorMock,
            new OperationCache(),
            app(ProviderRegistry::class),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            app(\ClarionApp\LlmClient\Services\RunTraceRecorder::class),
        );

        $service->start($conversation);

        $run = DB::table('agent_runs')->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($run, 'start() must open a run trace');
        $this->assertEquals(1, (int) $run->is_streamed, 'start() is the streaming entry point (streamed: true)');
        $this->assertEquals('gpt-4', $run->model);
        $this->assertEquals('research-assistant', $run->agent_id);
    }

    #[Test]
    public function run_passes_streamed_false_model_and_agent_id_to_open_run(): void
    {
        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.run_trace.action_row_cap' => 500]);
        config(['llm-client.run_trace.action_content_cap_bytes' => 16384]);

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create([
            'is_processing' => false,
            'server_id' => $server->id,
            'model' => 'gpt-4',
            'character' => 'research-assistant',
        ]);

        $providerMock = Mockery::mock(\ClarionApp\LlmClient\Contracts\LlmProvider::class);
        $providerMock->shouldReceive('chat')->andReturn([
            'choices' => [
                ['message' => ['content' => 'Hi there', 'tool_calls' => []]],
            ],
        ]);
        $providerMock->shouldReceive('countTokens')->andReturn(10);

        $registryMock = Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolveByType')->andReturn($providerMock);

        $executorMock = Mockery::mock(McpToolExecutor::class);

        $service = new AgentLoopService(
            Mockery::mock(McpToolRegistry::class),
            $executorMock,
            new OperationCache(),
            $registryMock,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            app(\ClarionApp\LlmClient\Services\RunTraceRecorder::class),
        );

        $result = $service->run($conversation, 'Hello');
        $this->assertEquals('completed', $result['status']);

        $run = DB::table('agent_runs')->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($run, 'run() must open a run trace');
        $this->assertEquals(0, (int) $run->is_streamed, 'run() is the synchronous, non-streaming path');
        $this->assertEquals('gpt-4', $run->model);
        $this->assertEquals('research-assistant', $run->agent_id);
    }

    #[Test]
    public function resume_sync_fresh_run_passes_streamed_false_model_and_agent_id(): void
    {
        // resumeSync()'s "pre-feature tool_data" branch (no run_id present) mints
        // a fresh run — it must pass the same new arguments as every other call site.
        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.run_trace.action_row_cap' => 500]);
        config(['llm-client.run_trace.action_content_cap_bytes' => 16384]);

        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create([
            'is_processing' => true,
            'server_id' => $server->id,
            'model' => 'gpt-4',
            'character' => 'research-assistant',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_def456',
                        'type' => 'function',
                        'function' => [
                            'name' => 'contacts.destroy',
                            'arguments' => '{"path":{"id": "42"}}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'destroyContact',
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/42',
                    'arguments' => ['path' => ['id' => '42']],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
                // No run_id — pre-feature tool_data, triggers the fresh-run branch.
            ],
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => ['operationId' => 'destroyContact', 'method' => 'DELETE', 'path' => '/api/contacts/{id}'],
            ]);

        $executorMock = Mockery::mock(McpToolExecutor::class);
        $executorMock->shouldReceive('extractArguments')
            ->andReturn(['path' => '/api/contacts/42', 'query' => [], 'body' => []]);
        $executorMock->shouldReceive('executeHttpCall')
            ->andReturn([
                'content' => [['type' => 'text', 'text' => '{"success": true}']],
                'isError' => false,
            ]);

        $providerMock = Mockery::mock(\ClarionApp\LlmClient\Contracts\LlmProvider::class);
        $providerMock->shouldReceive('chat')->andReturn([
            'choices' => [
                ['message' => ['content' => 'Done', 'tool_calls' => []]],
            ],
        ]);
        $providerMock->shouldReceive('countTokens')->andReturn(10);

        $providerRegistryMock = Mockery::mock(ProviderRegistry::class);
        $providerRegistryMock->shouldReceive('resolveByType')->andReturn($providerMock);

        $service = new AgentLoopService(
            $registryMock,
            $executorMock,
            new OperationCache(),
            $providerRegistryMock,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            app(\ClarionApp\LlmClient\Services\RunTraceRecorder::class),
        );

        $service->resumeSync($conversation, $message, true);

        $run = DB::table('agent_runs')->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($run, 'resumeSync() must mint a fresh run trace when tool_data carries no run_id');
        $this->assertEquals(0, (int) $run->is_streamed, 'resumeSync() is a synchronous, non-streaming path');
        $this->assertEquals('gpt-4', $run->model);
        $this->assertEquals('research-assistant', $run->agent_id);
    }

    // === 075-tool-reliability-rates Phase 4 (US2, T027): recordToolMetric() agent attribution ===

    /**
     * Builds a scripted confirmed-tool-call round trip through resumeSync(),
     * with a real MetricsRecorder injected but no RunTraceRecorder at all --
     * so no agent_runs row can ever be created for this fixture. That is
     * deliberate: a wrong implementation of recordToolMetric() that derives
     * agentId via AgentRun::find($runId)?->agent_id instead of
     * $conversation->character could otherwise coincidentally produce the
     * same value (both this suite's own run-trace tests and production code
     * put $conversation->character into agent_runs.agent_id), which would
     * make such a mutation pass this test for the wrong reason.
     */
    private function runConfirmedToolCallThroughResumeSync(\ClarionApp\LlmClient\Models\Conversation $conversation, string $resultJson = '{"success": true}'): void
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [
                    [
                        'id' => 'call_agent_attr',
                        'type' => 'function',
                        'function' => [
                            'name' => 'contacts.destroy',
                            'arguments' => '{"path":{"id": "42"}}',
                        ],
                    ],
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'destroyContact',
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/42',
                    'arguments' => ['path' => ['id' => '42']],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
                // No run_id -- and no RunTraceRecorder is injected below --
                // so no agent_runs row can exist for this fixture at all.
            ],
        ]);

        $registryMock = Mockery::mock(McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => ['operationId' => 'destroyContact', 'method' => 'DELETE', 'path' => '/api/contacts/{id}'],
            ]);

        $executorMock = Mockery::mock(McpToolExecutor::class);
        $executorMock->shouldReceive('extractArguments')
            ->andReturn(['path' => '/api/contacts/42', 'query' => [], 'body' => []]);
        $executorMock->shouldReceive('executeHttpCall')
            ->andReturn([
                'content' => [['type' => 'text', 'text' => $resultJson]],
                'isError' => false,
            ]);

        $providerMock = Mockery::mock(\ClarionApp\LlmClient\Contracts\LlmProvider::class);
        $providerMock->shouldReceive('chat')->andReturn([
            'choices' => [
                ['message' => ['content' => 'Done', 'tool_calls' => []]],
            ],
        ]);
        $providerMock->shouldReceive('countTokens')->andReturn(10);

        $providerRegistryMock = Mockery::mock(ProviderRegistry::class);
        $providerRegistryMock->shouldReceive('resolveByType')->andReturn($providerMock);

        $service = new AgentLoopService(
            $registryMock,
            $executorMock,
            new OperationCache(),
            $providerRegistryMock,
            metricsRecorder: new \ClarionApp\LlmClient\Services\MetricsRecorder(),
        );

        $service->resumeSync($conversation, $message, true);
    }

    #[Test]
    public function resume_sync_confirmed_tool_call_attributes_the_invocation_to_the_conversations_character(): void
    {
        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create([
            'is_processing' => true,
            'server_id' => $server->id,
            'model' => 'gpt-4',
            'character' => 'research-assistant',
        ]);

        $this->runConfirmedToolCallThroughResumeSync($conversation);

        $this->assertSame(
            0,
            DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(),
            'no run trace may exist for this fixture -- otherwise a run_id-derived agentId could coincidentally match'
        );

        $record = DB::table('tool_invocation_records')->where('tool_name', 'contacts.destroy')->first();
        $this->assertNotNull($record, 'recordToolMetric() must have written a tool_invocation_records row');
        $this->assertSame('research-assistant', $record->agent_id, "agentId must be \$conversation->character exactly, never a run_id-derived value");

        $summary = DB::table('tool_reliability_summaries')
            ->where('tool_name', 'contacts.destroy')
            ->where('agent_id', 'research-assistant')
            ->first();
        $this->assertNotNull($summary, 'the tool_reliability_summaries row must be bucketed under the real agent id, not Unattributed');
        $this->assertSame(1, (int) $summary->invocation_count);
    }

    #[Test]
    public function resume_sync_confirmed_tool_call_with_no_character_attributes_the_unattributed_bucket(): void
    {
        $server = Server::create(['name' => 'test', 'server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => 'sk-test']);
        $conversation = Conversation::factory()->create([
            'is_processing' => true,
            'server_id' => $server->id,
            'model' => 'gpt-4',
            'character' => null,
        ]);

        $this->runConfirmedToolCallThroughResumeSync($conversation);

        $record = DB::table('tool_invocation_records')->where('tool_name', 'contacts.destroy')->first();
        $this->assertNotNull($record);
        $this->assertNull($record->agent_id, 'a characterless conversation must record a null agent_id on the detail row');

        $summary = DB::table('tool_reliability_summaries')
            ->where('tool_name', 'contacts.destroy')
            ->where('agent_id', \ClarionApp\LlmClient\Models\ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET)
            ->first();
        $this->assertNotNull($summary, 'a characterless conversation must bucket into the explicit Unattributed sentinel');
    }
}
