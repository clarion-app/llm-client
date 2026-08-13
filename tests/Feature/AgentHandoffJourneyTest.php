<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ConversationHandoff;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ConversationAgentDefinitionResolver;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 093-agent-handoff, Phase 3 (US1 + US3, T013/T014).
 *
 * Mirrors ConversationBindingSurvivesAgentEditJourneyTest.php's own
 * setUp()/tearDown()/seedOperationCatalog()/clearOperationCatalog()
 * pattern, plus the two extra hand-declared support tables
 * (episodic_memories/condensation_states) needed by any call into
 * run()/resumeSync()/start().
 *
 * Written before the `handoff_to_agent` meta-tool exists — every test in
 * this file that dispatches through executeMetaTool('handoff_to_agent', ...)
 * is expected to fail (the generic `{"error": "Unknown tool: handoff_to_agent"}`
 * `default` branch of executeMetaTool()'s own match, not the behavior these
 * assertions expect) until T016/T017 add the tool definition and handler.
 *
 * T014 (US3) further extends this SAME file — sequenced after T013's own
 * scenarios, not [P] relative to them.
 */
class AgentHandoffJourneyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        // executeApiCall()'s own getOrCreateSession() (reached whenever an
        // execute_operation call is actually permitted, not rejected) needs
        // an MCP session row — EntryPathCoverageJourneyTest's own
        // established precedent for this exact table.
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
        // start()/run() funnel) read these tables regardless of whether
        // auto-memory retrieval or condensation ever actually triggers —
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

        DB::table('conversation_handoffs')->delete();
        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
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

    /**
     * The direct, non-HTTP dispatch precedent this package already uses
     * for testing execute_operation/propose_declarative_memory
     * (research.md D1/D8, contracts §1).
     *
     * @return array<string, mixed>
     */
    private function handoff(Conversation $conversation, string $targetAgentId): array
    {
        $result = app(AgentLoopService::class)->executeMetaTool(
            'handoff_to_agent',
            ['agent_id' => $targetAgentId],
            $conversation,
        );

        return json_decode($result, true);
    }

    private function makeConversation(?Agent $agent, string $userId): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $userId,
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    /**
     * Mirrors ConversationBindingSurvivesAgentEditJourneyTest::makeServer() —
     * an OpenAI-provider fixture needed to drive start()'s own funnel
     * (formatMessages() -> appendBoundInstructions() -> dispatchStreamRequest())
     * end-to-end.
     */
    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'TestServer',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'test-token',
        ]);
    }

    // =================================================================
    // T013 (US1)
    // =================================================================

    #[Test]
    public function a_successful_handoff_creates_a_conversation_handoff_row_and_leaves_history_intact(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $priorMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Hello there.',
            'responseTime' => 0,
        ]);

        $result = $this->handoff($conversation, $agentB->id);

        $this->assertTrue($result['success'] ?? false, 'a handoff to a real, owned, active agent must succeed');
        $this->assertSame($agentB->name, $result['handed_off_to'] ?? null);
        $this->assertSame($agentB->id, $result['agent_id'] ?? null);

        $row = ConversationHandoff::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($row, 'a successful handoff must write a ConversationHandoff row');
        $this->assertSame($agentA->id, $row->from_agent_id);
        $this->assertSame($agentB->id, $row->to_agent_id);
        $this->assertSame($agentB->current_version_id, $row->to_agent_version_id);
        $this->assertSame(1, $row->position);

        $this->assertSame(
            $conversation->id,
            $conversation->fresh()->id,
            'the conversation id must be unchanged by a handoff — it continues in place',
        );
        $this->assertSame(
            1,
            Conversation::where('user_id', $this->user->id)->count(),
            'no second Conversation row may be created by a handoff',
        );

        $reread = Message::find($priorMessage->id);
        $this->assertNotNull($reread);
        $this->assertSame('Hello there.', $reread->content, 'pre-handoff message history must be unaffected by the handoff itself');
    }

    #[Test]
    public function agent_id_is_required(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $conversation = $this->makeConversation($agentA, $this->user->id);

        $result = $this->handoff($conversation, '');

        $this->assertSame('agent_id is required', $result['error'] ?? null);
        $this->assertSame(0, ConversationHandoff::where('conversation_id', $conversation->id)->count());
    }

    #[Test]
    public function a_nonexistent_or_not_owned_target_agent_is_refused(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $conversation = $this->makeConversation($agentA, $this->user->id);

        $result = $this->handoff($conversation, (string) Str::uuid());

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsStringIgnoringCase('not found', $result['error']);
        $this->assertSame(0, ConversationHandoff::where('conversation_id', $conversation->id)->count());
    }

    #[Test]
    public function an_unbound_conversation_can_still_be_handed_off_recording_a_null_from_agent_id(): void
    {
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        $conversation = $this->makeConversation(null, $this->user->id);
        $this->assertNull($conversation->agent_id, 'fixture sanity: the conversation must start unbound');

        $result = $this->handoff($conversation, $agentB->id);

        $this->assertTrue($result['success'] ?? false);

        $row = ConversationHandoff::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->from_agent_id, 'an unbound conversation\'s handoff must record from_agent_id = null');
        $this->assertSame($agentB->id, $row->to_agent_id);

        $definition = app(ConversationAgentDefinitionResolver::class)->effectiveDefinitionFor($conversation->fresh());
        $this->assertNotNull($definition);
        $this->assertSame('I am agent B.', $definition->instructions, 'effectiveDefinitionFor() must now resolve agent B for this previously-unbound conversation');
    }

    #[Test]
    public function handoff_to_agent_needs_no_change_to_the_stream_handlers_own_routing(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/AgentLoopStreamHandler.php');
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression(
            '/\$metaToolNames\s*=\s*\[[^\]]*\]/',
            $source,
            'fixture sanity: AgentLoopStreamHandler.php must still declare its own $metaToolNames array',
        );

        preg_match('/\$metaToolNames\s*=\s*\[[^\]]*\]/', $source, $matches);
        $this->assertStringNotContainsString(
            'handoff_to_agent',
            $matches[0],
            'handoff_to_agent must NOT be named in $metaToolNames — it must fall through to the generic executeMetaTool() funnel automatically, exactly like memory_*/propose_declarative_memory already do (research.md D1/D8) — no change to AgentLoopStreamHandler.php is needed for this feature',
        );
    }

    // =================================================================
    // T014 (US3) — sequenced after T013's own scenarios, same file.
    // =================================================================

    #[Test]
    public function the_receiving_agents_instructions_govern_the_very_next_turn(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: Always respond in French.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: Always respond in English.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $result = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($result['success'] ?? false, 'fixture sanity: the handoff itself must succeed');

        $resolver = app(ConversationAgentDefinitionResolver::class);
        $conversation = $conversation->fresh();

        $effective = $resolver->effectiveDefinitionFor($conversation);
        $this->assertNotNull($effective);
        $this->assertSame(
            'Always respond in English.',
            $effective->instructions,
            'effectiveDefinitionFor() must resolve agent B\'s instructions after the handoff — this is what formatMessages() will use on the very next turn',
        );

        $original = $resolver->forConversation($conversation);
        $this->assertNotNull($original);
        $this->assertSame(
            'Always respond in French.',
            $original->instructions,
            'forConversation(), on the same conversation, must still resolve agent A\'s ORIGINAL binding, provably unchanged by the handoff',
        );
    }

    /**
     * End-to-end companion to the_receiving_agents_instructions_govern_the_very_next_turn()
     * above (T044/Polish, found while running the quickstart.md mutation
     * checklist's Row 2): that test asserts only on
     * ConversationAgentDefinitionResolver's own two methods directly, and
     * never drives formatMessages() itself — so it stays green even if
     * formatMessages()'s call site were reverted from effectiveDefinitionFor()
     * back to forConversation(), since both resolver methods still exist and
     * behave correctly in isolation. This test closes that gap by driving
     * start() end-to-end (mirroring
     * ConversationBindingSurvivesAgentEditJourneyTest::a_conversation_already_under_way_keeps_running_on_its_bound_versions_instructions_not_the_agents_current_ones())
     * and inspecting the actual dispatched request's system content.
     */
    #[Test]
    public function formatMessages_uses_the_receiving_agents_instructions_on_the_next_turn_end_to_end(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: Always respond in French.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: Always respond in English.");

        $server = $this->makeServer();

        $conversation = $this->makeConversation($agentA, $this->user->id);
        $conversation->update(['server_id' => $server->id, 'model' => 'gpt-4o']);

        $result = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($result['success'] ?? false, 'fixture sanity: the handoff itself must succeed');

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'What is the weather today?',
            'responseTime' => 0,
        ]);

        Queue::fake();

        $conversation = Conversation::find($conversation->id);
        app(AgentLoopService::class)->start($conversation);

        $capturedRequests = [];
        Queue::pushed(SendHttpStreamRequest::class, function (SendHttpStreamRequest $job) use (&$capturedRequests) {
            $reflector = new \ReflectionClass($job);
            $requestProperty = $reflector->getProperty('request');
            $requestProperty->setAccessible(true);
            $capturedRequests[] = $requestProperty->getValue($job);

            return true;
        });

        $this->assertNotEmpty($capturedRequests, 'start() must dispatch a SendHttpStreamRequest job');

        $body = $capturedRequests[0]->body;
        $messages = is_array($body->messages ?? null) ? $body->messages : [];

        $systemContent = '';
        foreach ($messages as $message) {
            $role = is_array($message) ? ($message['role'] ?? null) : ($message->role ?? null);
            if ($role === 'system') {
                $content = is_array($message) ? ($message['content'] ?? '') : ($message->content ?? '');
                $systemContent .= (string) $content;
            }
        }

        $this->assertStringContainsString(
            'Always respond in English.',
            $systemContent,
            'formatMessages() must use the RECEIVING agent (B)\'s instructions on the very next turn after a handoff',
        );
        $this->assertStringNotContainsString(
            'Always respond in French.',
            $systemContent,
            'formatMessages() must never fall back to the ORIGINAL agent (A)\'s instructions once a handoff has occurred',
        );
    }

    #[Test]
    public function permission_narrowing_takes_effect_immediately_and_automatically_after_a_handoff(): void
    {
        $this->seedOperationCatalog([
            'widget.list' => ['path' => '/api/widgets', 'method' => 'get', 'summary' => 'List widgets'],
        ]);

        $agentA = app(AgentService::class)->create(
            $this->user->id,
            "name: agent-a\ninstructions: I am agent A.\ntools:\n  allow: [widget.list]",
        );
        $agentB = app(AgentService::class)->create(
            $this->user->id,
            "name: agent-b\ninstructions: I am agent B.\ntools:\n  allow: []",
        );

        $conversation = $this->makeConversation($agentA, $this->user->id);

        // eval_run_simulating_tools (078-run-eval-suites) makes a PERMITTED
        // execute_operation call simulate its response instead of minting a
        // real Passport token and dispatching an actual HTTP call — the
        // established, dependency-free way this package already exercises
        // execute_operation's SUCCESS path (McpToolExecutorTest's own
        // precedent). Only the permission gate itself — reached before this
        // flag is ever consulted — is under test here.
        Context::add('eval_run_simulating_tools', true);

        try {
            // Before the handoff: agent A permits widget.list.
            $before = app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => 'widget.list', 'parameters' => []],
                $conversation,
            );
            $beforeDecoded = json_decode($before, true);
            $this->assertArrayNotHasKey(
                'error',
                $beforeDecoded,
                'fixture sanity: agent A must permit widget.list before any handoff — got: ' . $before,
            );

            $handoffResult = $this->handoff($conversation, $agentB->id);
            $this->assertTrue($handoffResult['success'] ?? false, 'fixture sanity: the handoff itself must succeed');

            $conversation = $conversation->fresh();

            // After the handoff: agent B does NOT permit widget.list — the
            // operation must now be refused, governed solely by B, never
            // inherited from A.
            $after = app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => 'widget.list', 'parameters' => []],
                $conversation,
            );
        } finally {
            Context::forget('eval_run_simulating_tools');
        }

        $afterDecoded = json_decode($after, true);

        $this->assertArrayHasKey(
            'error',
            $afterDecoded,
            'an operation the ORIGINAL agent (A) could perform must be refused after a handoff to a narrower agent (B) — permissions must never be inherited from the original agent',
        );
        $this->assertStringContainsString(
            'the agent version this conversation is bound to',
            $afterDecoded['error'],
            'the rejection reason must match handleExecuteOperation()\'s own existing message shape',
        );
    }

    #[Test]
    public function a_handoff_never_changes_which_llm_or_server_answers(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        $conversation = $this->makeConversation($agentA, $this->user->id);
        $conversation->update([
            'model' => 'gpt-4o',
            'server_id' => null,
            'provider_override' => null,
        ]);
        $conversation = $conversation->fresh();

        $modelBefore = $conversation->model;
        $serverIdBefore = $conversation->server_id;
        $providerOverrideBefore = $conversation->provider_override;

        $result = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($result['success'] ?? false, 'fixture sanity: the handoff itself must succeed');

        $conversation = $conversation->fresh();

        $this->assertSame($modelBefore, $conversation->model, 'a handoff must never change conversation.model');
        $this->assertSame($serverIdBefore, $conversation->server_id, 'a handoff must never change conversation.server_id');
        $this->assertSame($providerOverrideBefore, $conversation->provider_override, 'a handoff must never change conversation.provider_override');
    }

    // =================================================================
    // US5 (T029, quickstart steps 9, 10, 11) — sequenced after T013/T014,
    // same file.
    //
    // handleHandoffToAgent()'s current (Phase 3, T017) body implements
    // only contracts §1 checks 1, 3, and 7 — check 2 (system-owned
    // conversation) and check 4 (is_active) do not exist yet; both are
    // Phase 5's own scope (T030).
    //
    // Note on "already covered": T013's own
    // a_nonexistent_or_not_owned_target_agent_is_refused() (above) already
    // exercises "target agent id does not exist at all" via check 3
    // (AgentQuery::findAgent(), already ownership-scoped by
    // conversation.user_id) — that exact scenario is NOT duplicated here.
    // Only the cross-user-owned variant below is a genuinely new fixture
    // (a real agent that exists but belongs to someone else), and per
    // findAgent()'s own established "doesn't exist and isn't yours are
    // indistinguishable" contract it is expected to already PASS under
    // the current Phase 3 code — it is added here as an explicit FR-011
    // regression lock, not as a red case.
    // =================================================================

    #[Test]
    public function a_handoff_to_a_deactivated_agent_fails_gracefully_and_the_original_agent_continues_undisturbed(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        // agentA stays active, so deactivating agentB never trips
        // AgentService::deactivate()'s own last-active-agent guard — the
        // production deactivation path, mirroring
        // AgentActivationJourneyTest's own precedent.
        app(AgentService::class)->deactivate($agentB);
        $this->assertFalse($agentB->fresh()->is_active, 'fixture sanity: agent B must actually be deactivated');

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $result = $this->handoff($conversation, $agentB->id);

        $this->assertArrayHasKey('error', $result, 'a handoff to a deactivated agent must be refused, not silently succeed');
        $this->assertStringContainsString(
            $agentB->name,
            $result['error'] ?? '',
            'the refusal must name the deactivated agent',
        );
        $this->assertStringContainsStringIgnoringCase(
            'deactivated',
            $result['error'] ?? '',
            'the refusal must explain why: the target agent is deactivated',
        );

        $this->assertSame(
            0,
            ConversationHandoff::where('conversation_id', $conversation->id)->count(),
            'no ConversationHandoff row may be written when the target is deactivated',
        );

        $effective = app(ConversationAgentDefinitionResolver::class)->effectiveDefinitionFor($conversation->fresh());
        $this->assertNotNull($effective);
        $this->assertSame(
            'I am agent A.',
            $effective->instructions,
            'the original agent (A) must continue governing the conversation, completely undisturbed by the refused handoff attempt',
        );
    }

    #[Test]
    public function a_handoff_to_a_real_agent_owned_by_a_different_user_fails_identically_to_a_nonexistent_target(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $conversation = $this->makeConversation($agentA, $this->user->id);

        $otherUser = User::factory()->create();
        $otherUsersAgent = app(AgentService::class)->create($otherUser->id, "name: someone-elses-agent\ninstructions: Not yours.");

        $nonexistentResult = $this->handoff($conversation, (string) Str::uuid());
        $crossUserResult = $this->handoff($conversation, $otherUsersAgent->id);

        $this->assertArrayHasKey('error', $crossUserResult);
        $this->assertSame(
            $nonexistentResult['error'] ?? null,
            $crossUserResult['error'] ?? null,
            'a real agent owned by a different user must be refused with the IDENTICAL message as a nonexistent agent id — never a distinguishing detail that would leak the other agent\'s existence (FR-011)',
        );

        $this->assertSame(
            0,
            ConversationHandoff::where('conversation_id', $conversation->id)->count(),
            'no ConversationHandoff row may be written for a not-owned target',
        );
    }

    #[Test]
    public function a_handoff_attempted_on_a_system_owned_conversation_fails_cleanly_instead_of_throwing(): void
    {
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        $conversation = Conversation::create([
            'user_id' => null,
            'title' => 'system-owned-conversation',
            'character' => 'Clarion',
            'model' => 'test-model',
        ]);

        $result = $this->handoff($conversation, $agentB->id);

        $this->assertArrayHasKey(
            'error',
            $result,
            'a handoff attempted on a system-owned (user_id === null) conversation must return a clean error JSON string, never throw a TypeError from AgentQuery::findAgent()\'s non-nullable string $callerUserId parameter',
        );

        $this->assertSame(
            0,
            ConversationHandoff::where('conversation_id', $conversation->id)->count(),
            'no ConversationHandoff row may be written for a system-owned conversation',
        );
    }

    // =================================================================
    // US4 (quickstart steps 7, 8) — sequenced after T013/T014/T029, same
    // file.
    //
    // handleHandoffToAgent()'s current (Phase 5, T030) body implements
    // only contracts §1 checks 1, 2, 3, 4, and 7 — check 5 (chain
    // membership / cycle prevention) and check 6 (chain-length bound) do
    // not exist yet; both are Phase 6's own scope (T034). Every test
    // below is expected to FAIL against the current code: the loop-back
    // handoffs (checks 5) and the beyond-bound handoff (check 6) all
    // currently SUCCEED (writing a row and returning {"success": true})
    // when they should be refused.
    // =================================================================

    #[Test]
    public function a_handoff_chain_is_refused_once_it_reaches_the_configured_max_length(): void
    {
        config(['llm-client.handoff.max_chain_length' => 2]);

        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");
        $agentC = app(AgentService::class)->create($this->user->id, "name: agent-c\ninstructions: I am agent C.");
        $agentD = app(AgentService::class)->create($this->user->id, "name: agent-d\ninstructions: I am agent D.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $first = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($first['success'] ?? false, 'fixture sanity: the first handoff (A -> B) must succeed');

        $conversation = $conversation->fresh();
        $second = $this->handoff($conversation, $agentC->id);
        $this->assertTrue($second['success'] ?? false, 'fixture sanity: the second handoff (B -> C), reaching the configured max_chain_length of 2, must still succeed — only the NEXT one is refused');

        $this->assertSame(
            2,
            ConversationHandoff::where('conversation_id', $conversation->id)->count(),
            'fixture sanity: the chain must be exactly at the configured bound (2) before the third attempt',
        );

        $conversation = $conversation->fresh();
        $third = $this->handoff($conversation, $agentD->id);

        $this->assertArrayHasKey(
            'error',
            $third,
            'a handoff attempted once the chain has already reached max_chain_length must be refused, not silently succeed',
        );
        $this->assertStringContainsStringIgnoringCase(
            'handoff limit',
            $third['error'] ?? '',
            'the refusal must explain why: the conversation has reached its handoff limit',
        );

        $this->assertSame(
            2,
            ConversationHandoff::where('conversation_id', $conversation->id)->count(),
            'no third ConversationHandoff row may be written once the chain has reached its configured bound',
        );
    }

    #[Test]
    public function a_handoff_cannot_loop_back_to_the_conversations_original_agent(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $first = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($first['success'] ?? false, 'fixture sanity: the first handoff (A -> B) must succeed');

        $conversation = $conversation->fresh();
        $result = $this->handoff($conversation, $agentA->id);

        $this->assertArrayHasKey(
            'error',
            $result,
            'a handoff back to the conversation\'s ORIGINAL binding agent must be refused as a loop, not silently succeed',
        );
        $this->assertStringContainsString(
            $agentA->name,
            $result['error'] ?? '',
            'the refusal must name the already-visited agent (A)',
        );
        $this->assertStringContainsStringIgnoringCase(
            'loop',
            $result['error'] ?? '',
            'the refusal must explain why: handing off again would create a loop',
        );

        $this->assertSame(
            1,
            ConversationHandoff::where('conversation_id', $conversation->id)->count(),
            'no new ConversationHandoff row may be written when the target would create a loop — the chain must stay at its pre-attempt length of 1',
        );
    }

    #[Test]
    public function a_handoff_cannot_loop_back_to_any_agent_already_in_a_longer_chain(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");
        $agentC = app(AgentService::class)->create($this->user->id, "name: agent-c\ninstructions: I am agent C.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $first = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($first['success'] ?? false, 'fixture sanity: the first handoff (A -> B) must succeed');

        $conversation = $conversation->fresh();
        $second = $this->handoff($conversation, $agentC->id);
        $this->assertTrue($second['success'] ?? false, 'fixture sanity: the second handoff (B -> C) must succeed');

        $this->assertSame(
            2,
            ConversationHandoff::where('conversation_id', $conversation->id)->count(),
            'fixture sanity: the chain must be A -> B -> C (two rows) before the loop-back attempt',
        );

        $conversation = $conversation->fresh();

        // Attempt to hand off back to B — a MIDDLE link, not the original
        // (A) — proving membership is checked against the whole chain, not
        // just its first link.
        $backToB = $this->handoff($conversation, $agentB->id);

        $this->assertArrayHasKey(
            'error',
            $backToB,
            'a handoff back to ANY agent already in the chain (not just the original) must be refused as a loop',
        );
        $this->assertStringContainsString(
            $agentB->name,
            $backToB['error'] ?? '',
            'the refusal must name the already-visited agent (B)',
        );
        $this->assertStringContainsStringIgnoringCase('loop', $backToB['error'] ?? '');

        // Attempt to hand off back to A — the ORIGINAL agent, further back
        // in the chain — refused identically.
        $backToA = $this->handoff($conversation, $agentA->id);

        $this->assertArrayHasKey(
            'error',
            $backToA,
            'a handoff back to the original agent (A), still further back in a longer chain, must also be refused as a loop',
        );
        $this->assertStringContainsString($agentA->name, $backToA['error'] ?? '');
        $this->assertStringContainsStringIgnoringCase('loop', $backToA['error'] ?? '');

        $this->assertSame(
            2,
            ConversationHandoff::where('conversation_id', $conversation->id)->count(),
            'no new ConversationHandoff row may be written by either loop-back attempt — the chain must stay at its pre-attempt length of 2',
        );
    }

    // =================================================================
    // Check-ordering (contracts §1's own explicit sequence: existence (3)
    // and activation (4) must be checked BEFORE chain-membership (5) and
    // chain-bound (6), so a nonexistent/deactivated target is refused
    // with FR-010/FR-011's own specific reason, never a generic
    // chain-shaped one that would misrepresent why the handoff actually
    // failed. quickstart.md's own step 9/10 fixtures never combine "chain
    // already at bound / target already in chain" with "target
    // nonexistent/deactivated" — an empty chain means checks 5/6 never
    // fire regardless of where they sit relative to checks 3/4, so those
    // two steps' own named tests cannot observe a reordering of checks
    // 3/4 vs 5/6 (found while running the quickstart.md T044 mutation
    // checklist's Row 12 — this is a genuine gap the existing suite
    // didn't cover, not merely a checklist-description inaccuracy).
    // These two tests close it directly, by constructing a target that is
    // BOTH chain-bound-triggering/chain-member AND nonexistent/deactivated,
    // and asserting the SPECIFIC (existence/activation) reason wins.
    // =================================================================

    #[Test]
    public function a_nonexistent_target_is_refused_by_name_even_when_the_chain_is_already_at_its_bound(): void
    {
        config(['llm-client.handoff.max_chain_length' => 2]);

        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");
        $agentC = app(AgentService::class)->create($this->user->id, "name: agent-c\ninstructions: I am agent C.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $first = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($first['success'] ?? false, 'fixture sanity: the first handoff (A -> B) must succeed');
        $conversation = $conversation->fresh();

        $second = $this->handoff($conversation, $agentC->id);
        $this->assertTrue($second['success'] ?? false, 'fixture sanity: the second handoff (B -> C), reaching the configured bound of 2, must still succeed');
        $conversation = $conversation->fresh();

        // The chain is now exactly at its configured bound. Attempt a
        // handoff to an agent id that does not exist at all. Per
        // contracts §1's check ordering, existence (3) is checked BEFORE
        // the chain-bound check (6) — the refusal must name the real
        // problem ("not found"), never the chain-bound message, even
        // though the chain-bound check WOULD also refuse this attempt if
        // it ran first.
        $result = $this->handoff($conversation, (string) Str::uuid());

        $this->assertArrayHasKey('error', $result, 'fixture sanity: the attempt must still be refused one way or another');
        $this->assertStringContainsStringIgnoringCase(
            'not found',
            $result['error'] ?? '',
            'existence (check 3) must be evaluated before the chain-bound check (6) — a nonexistent target must be refused for THAT reason, never a generic "handoff limit" message that would misrepresent why the attempt actually failed',
        );
        $this->assertStringNotContainsStringIgnoringCase('handoff limit', $result['error'] ?? '');

        $this->assertSame(
            2,
            ConversationHandoff::where('conversation_id', $conversation->id)->count(),
            'no third row may be written either way',
        );
    }

    #[Test]
    public function a_deactivated_target_already_in_the_chain_is_refused_for_being_deactivated_not_for_looping(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $first = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($first['success'] ?? false, 'fixture sanity: the handoff (A -> B) must succeed');
        $conversation = $conversation->fresh();

        // agentA stays active so deactivating agentB never trips the
        // last-active-agent guard (AgentService::deactivate()'s own rule,
        // mirroring the other US5 tests in this file).
        app(AgentService::class)->deactivate($agentB);
        $this->assertFalse($agentB->fresh()->is_active, 'fixture sanity: agent B must actually be deactivated');

        // Agent B is now BOTH already a member of the chain (it is the
        // conversation's own current agent_id, per currentAgentIdentityFor())
        // AND deactivated. Per contracts §1's check ordering, activation
        // (4) is checked before chain-membership (5) — the refusal must
        // name the real problem ("deactivated"), never the generic loop
        // message, even though the cycle check WOULD also refuse this
        // attempt if it ran first.
        $result = $this->handoff($conversation, $agentB->id);

        $this->assertArrayHasKey('error', $result, 'fixture sanity: the attempt must still be refused one way or another');
        $this->assertStringContainsStringIgnoringCase(
            'deactivated',
            $result['error'] ?? '',
            'activation (check 4) must be evaluated before the chain-membership check (5) — a deactivated target must be refused for THAT reason, never a generic "loop" message that would misrepresent why the attempt actually failed',
        );
        $this->assertStringNotContainsStringIgnoringCase('loop', $result['error'] ?? '');

        $this->assertSame(
            1,
            ConversationHandoff::where('conversation_id', $conversation->id)->count(),
            'no second row may be written either way',
        );
    }

    // =================================================================
    // US6 (quickstart steps 12, 13) — sequenced after T013/T014/T029/T033,
    // same file.
    //
    // Message has no attribution-stamping `creating` listener yet (Phase 7,
    // T039) — every test below is expected to FAIL: agent_id/agent_version_id
    // stay null on every Message created regardless of handoff state, until
    // the listener is added.
    // =================================================================

    #[Test]
    public function each_message_is_attributed_to_the_agent_actually_responsible_for_it_at_creation_time(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");
        $agentC = app(AgentService::class)->create($this->user->id, "name: agent-c\ninstructions: I am agent C.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $messageUnderA = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'Reply from A.',
            'responseTime' => 0,
        ]);

        $this->assertSame(
            $agentA->id,
            $messageUnderA->agent_id,
            'a message created before any handoff must be attributed to the conversation\'s own original agent_id',
        );
        $this->assertSame($agentA->current_version_id, $messageUnderA->agent_version_id);

        $first = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($first['success'] ?? false, 'fixture sanity: the first handoff (A -> B) must succeed');
        $conversation = $conversation->fresh();

        $messageUnderB = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'Reply from B.',
            'responseTime' => 0,
        ]);

        $this->assertSame(
            $agentB->id,
            $messageUnderB->agent_id,
            'a message created after a handoff must be attributed to the RECEIVING agent (B), never the original (A)',
        );
        $this->assertSame($agentB->current_version_id, $messageUnderB->agent_version_id);

        $second = $this->handoff($conversation, $agentC->id);
        $this->assertTrue($second['success'] ?? false, 'fixture sanity: the second handoff (B -> C) must succeed');
        $conversation = $conversation->fresh();

        $messageUnderC = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'Reply from C.',
            'responseTime' => 0,
        ]);

        $this->assertSame(
            $agentC->id,
            $messageUnderC->agent_id,
            'a message created after a second handoff (a chain of 2+) must be attributed to the LATEST receiving agent (C), never an intermediate one (B)',
        );
        $this->assertSame($agentC->current_version_id, $messageUnderC->agent_version_id);

        $this->assertNotSame($messageUnderA->agent_id, $messageUnderB->agent_id, 'the three messages\' attribution must genuinely differ, not all read the same agent');
        $this->assertNotSame($messageUnderB->agent_id, $messageUnderC->agent_id);
        $this->assertNotSame($messageUnderA->agent_id, $messageUnderC->agent_id);
    }

    #[Test]
    public function a_pre_handoff_messages_attribution_is_never_retroactively_rewritten_by_a_later_handoff(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");
        $agentC = app(AgentService::class)->create($this->user->id, "name: agent-c\ninstructions: I am agent C.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $priorMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'Reply from A, before any handoff.',
            'responseTime' => 0,
        ]);

        $this->assertSame($agentA->id, $priorMessage->agent_id, 'fixture sanity: the prior message must be attributed to agent A at creation time');

        $first = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($first['success'] ?? false, 'fixture sanity: the first handoff (A -> B) must succeed');
        $conversation = $conversation->fresh();

        $second = $this->handoff($conversation, $agentC->id);
        $this->assertTrue($second['success'] ?? false, 'fixture sanity: the second handoff (B -> C) must succeed');

        $reread = Message::find($priorMessage->id);
        $this->assertNotNull($reread);
        $this->assertSame(
            $agentA->id,
            $reread->agent_id,
            'a pre-handoff message\'s attribution must never be retroactively rewritten by a later handoff — it must still read agent A',
        );
        $this->assertSame($agentA->current_version_id, $reread->agent_version_id);
    }

    #[Test]
    public function a_message_on_a_conversation_with_no_handoffs_at_all_stamps_from_the_original_binding(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $conversation = $this->makeConversation($agentA, $this->user->id);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'Reply from A, no handoff ever performed.',
            'responseTime' => 0,
        ]);

        $this->assertSame(
            $agentA->id,
            $message->agent_id,
            'a message on a conversation with no handoffs at all must stamp from the conversation\'s own original agent_id (090\'s still-common path)',
        );
        $this->assertSame($agentA->current_version_id, $message->agent_version_id);
    }

    #[Test]
    public function a_message_with_agent_id_already_explicitly_set_is_left_untouched_by_the_new_listener(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $handoffResult = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($handoffResult['success'] ?? false, 'fixture sanity: the handoff (A -> B) must succeed, so the effective agent at creation time would otherwise be B');
        $conversation = $conversation->fresh();

        $explicitAgentId = (string) Str::uuid();
        $explicitAgentVersionId = (string) Str::uuid();

        // agent_id/agent_version_id are deliberately NOT mass-assignable
        // (data-model.md §2 — mirrors run_id's own posture), so setting
        // them explicitly at creation time means assigning the attribute
        // directly, then saving — never via Message::create()'s own fill().
        $message = new Message([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'Explicitly attributed message.',
            'responseTime' => 0,
        ]);
        $message->agent_id = $explicitAgentId;
        $message->agent_version_id = $explicitAgentVersionId;
        $message->save();

        $this->assertSame(
            $explicitAgentId,
            $message->fresh()->agent_id,
            'a message with agent_id already explicitly set at creation time must be left untouched by the new attribution listener — it must never be overwritten with the conversation\'s current effective agent (B)',
        );
        $this->assertSame($explicitAgentVersionId, $message->fresh()->agent_version_id);
    }
}
