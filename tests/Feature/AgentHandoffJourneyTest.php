<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ConversationHandoff;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ConversationAgentDefinitionResolver;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
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
}
