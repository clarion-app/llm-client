<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
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
 * 098-delegation-protocol, Phase 3 (US1 + US2), tasks.md T014/T015.
 *
 * Unit tests for the not-yet-built `DelegationService::delegate()`
 * (data-model.md §5, research.md D1/D2/D6/D7/D8, contracts/
 * delegation-protocol-meta-tool.md).
 *
 * T014 (US1) covers delegate()'s base sequence: a caller whose current
 * conversation's bound agent has the named agent as an active assigned
 * helper (097) succeeds -- creating a `Delegation` row (`status:
 * 'completed'`) and a fresh, isolated helper `Conversation`
 * (`channel: 'agent-delegation'`) bound to the helper's own current
 * version; refuses `not_an_assigned_helper` (no row, no helper
 * conversation) when the named agent is not an active assignment of the
 * parent's own bound agent, or when it IS an active assignment but the
 * helper `Agent` row itself has been deactivated (mirrors
 * `AgentLoopService::handleHandoffToAgent()`'s own deactivation refusal --
 * an assignment surviving the helper's own deactivation is not enough on
 * its own, research.md D8); refuses `no_bound_agent` when the calling
 * conversation has no `agent_id` at all.
 *
 * T015 (US2, appended below, sequenced after T014 -- not [P]) covers the
 * isolation guarantee at the data level: the helper conversation a
 * successful delegate() call creates carries exactly one user-originated
 * `Message` row, whose content is byte-identical to the composed preamble
 * + task + context contracts/delegation-protocol-meta-tool.md specifies --
 * never any trace of the parent conversation's own prior history; and the
 * D6 ambient-`Context` `run_id` save/restore guarantee -- the value
 * present before calling delegate() is restored afterward, even though the
 * helper's own nested `run()` call opens (and this test's own mock
 * simulates) a different run id mid-call.
 *
 * Mirrors AgentHelperServiceTest.php's own seedOperationCatalog()/agent()
 * fixture scaffolding, and AgentHandoffDisclosureJourneyTest.php's own
 * serviceWithScriptedProvider()/plainReply() scripted-LlmProvider
 * convention (research.md D1 -- a real, unmodified AgentLoopService::run()
 * call against a mocked LlmProvider, never Http::fake(), matching this
 * package's own established way of driving the agent loop end-to-end in
 * tests without a live provider).
 *
 * Written before DelegationService exists -- every test below is expected
 * to FAIL red (class not found) until T018 creates it.
 */
class DelegationServiceTest extends TestCase
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
        Context::forget('run_id');

        DB::table('agent_delegations')->delete();
        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
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
    // Operation-catalog scaffolding (AgentHelperServiceTest precedent)
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

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeConversation(?Agent $agent): Conversation
    {
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
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
    }

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
    }

    // =================================================================
    // T014 (US1) -- delegate()'s base sequence
    // =================================================================

    #[Test]
    public function delegate_succeeds_for_a_caller_whose_bound_agent_has_the_named_agent_as_an_active_assigned_helper(): void
    {
        $parent = $this->makeAgent('parent-agent-success');
        $helper = $this->makeAgent('helper-agent-success');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Helper completed the task.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $this->assertSame('completed', $result['status'] ?? null, 'a delegation to a real, active assigned helper must complete');
        $this->assertSame($helper->name, $result['helper'] ?? null);
        $this->assertSame('Helper completed the task.', $result['result'] ?? null);

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row, 'a successful delegate() call must write a Delegation row');
        $this->assertSame('completed', $row->status);
        $this->assertSame($helper->id, $row->helper_agent_id);
        $this->assertSame($this->user->id, $row->owner_user_id);
        $this->assertNotNull($row->helper_conversation_id);

        $helperConversation = Conversation::find($row->helper_conversation_id);
        $this->assertNotNull($helperConversation, 'delegate() must create a fresh helper Conversation row');
        $this->assertNotSame($conversation->id, $helperConversation->id, 'the helper conversation must be a brand-new row, never the parent\'s own');
        $this->assertSame('agent-delegation', $helperConversation->channel);
        $this->assertSame($helper->id, $helperConversation->agent_id, 'the helper conversation must be bound to the HELPER agent, not the parent');
        $this->assertSame($helper->current_version_id, $helperConversation->agent_version_id, 'the helper conversation must freeze the helper\'s own CURRENT version at delegation time');
        $this->assertSame($this->user->id, $helperConversation->user_id, 'the helper conversation must carry the real delegating user, never null (research.md D9)');
    }

    #[Test]
    public function delegate_refuses_when_the_named_agent_is_not_an_active_assigned_helper(): void
    {
        $parent = $this->makeAgent('parent-agent-unassigned');
        $notAHelper = $this->makeAgent('not-a-helper');

        $conversation = $this->makeConversation($parent);

        $result = app(DelegationService::class)->delegate($conversation, $notAHelper->id, 'Do something.', null);

        $this->assertSame('not_an_assigned_helper', $result['error'] ?? null, 'delegating to an agent that is not an active assigned helper must be refused');
        $this->assertSame(0, Delegation::count(), 'no Delegation row may be written for a refused delegation');
        $this->assertSame(
            1,
            Conversation::where('user_id', $this->user->id)->count(),
            'no helper Conversation may be created for a refused delegation',
        );
    }

    #[Test]
    public function delegate_refuses_when_the_helper_assignment_is_active_but_the_helper_agent_itself_is_deactivated(): void
    {
        $parent = $this->makeAgent('parent-agent-deactivated-helper');
        $helper = $this->makeAgent('helper-agent-deactivated');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helper->id);

        // Parent stays active, so deactivating the helper never trips
        // AgentService::deactivate()'s own last-active-agent guard --
        // AgentHandoffJourneyTest's own established precedent.
        app(AgentService::class)->deactivate($helper);
        $this->assertFalse($helper->fresh()->is_active, 'fixture sanity: the helper agent must actually be deactivated');

        $conversation = $this->makeConversation($parent);

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Do something.', null);

        $this->assertSame(
            'not_an_assigned_helper',
            $result['error'] ?? null,
            'a deactivated helper must be refused identically to one never assigned at all (research.md D8) -- the surviving AgentHelperAssignment row is not enough on its own',
        );
        $this->assertSame(0, Delegation::count());
        $this->assertSame(1, Conversation::where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function delegate_refuses_with_no_bound_agent_when_the_conversation_has_no_agent_at_all(): void
    {
        $conversation = $this->makeConversation(null);
        $this->assertNull($conversation->agent_id, 'fixture sanity: the conversation must start unbound');

        $someAgent = $this->makeAgent('some-other-agent');

        $result = app(DelegationService::class)->delegate($conversation, $someAgent->id, 'Do something.', null);

        $this->assertSame('no_bound_agent', $result['error'] ?? null, 'a conversation with no bound agent has no assigned helpers, and must be refused with its own distinct error code');
        $this->assertSame(0, Delegation::count());
    }

    // =================================================================
    // T015 (US2) -- isolation and D6 Context save/restore
    // =================================================================

    #[Test]
    public function a_successful_delegation_composes_the_helper_conversations_sole_user_message_exactly_and_never_leaks_the_parents_own_history(): void
    {
        $parent = $this->makeAgent('parent-agent-isolation');
        $helper = $this->makeAgent('helper-agent-isolation');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        // Substantial prior history, entirely unrelated to the delegated
        // task -- the isolation guarantee this test proves (US2 AC1).
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Totally unrelated prior message from the parent\'s own history.',
            'responseTime' => 0,
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'Totally unrelated prior reply from the parent\'s own history.',
            'responseTime' => 0,
        ]);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Helper reply, unrelated to any prior content.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $task = 'Extract line items from the attached invoice.';
        $context = 'Invoice #123, vendor Acme Corp.';

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, $task, $context);
        $this->assertSame('completed', $result['status'] ?? null, 'fixture sanity: the delegation itself must succeed');

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row);
        $helperConversation = Conversation::find($row->helper_conversation_id);
        $this->assertNotNull($helperConversation);

        $messages = Message::where('conversation_id', $helperConversation->id)->orderBy('created_at')->get();

        // The exact composed preamble contracts/delegation-protocol-meta-tool.md
        // specifies -- an em dash (not a hyphen) after "below", exact
        // heading spelling/casing, and the literal "## Task"/"## Context"
        // section markers.
        $expectedPreamble = "You are a helper agent carrying out a task delegated to you by another\n"
            ."agent. You can see only this task and the context below — nothing else\n"
            ."from the delegating agent's own conversation. Stay within the stated task;\n"
            ."do not expand your work beyond it. If you are missing information you need\n"
            ."to complete it, say so plainly rather than guessing or inventing an answer.\n"
            ."\n"
            ."## Task\n"
            .$task."\n"
            ."\n"
            ."## Context\n"
            .$context;

        // The helper conversation's own reply (created by run() itself) is
        // NOT part of what "crosses the isolation boundary" -- it is
        // freshly generated by the helper, never carried-over parent
        // context. The one and only USER-originated message is the entire
        // isolation surface FR-003/US2 AC1 are about, and it must be
        // exactly this composed string, nothing more and nothing less.
        $userMessages = $messages->where('role', 'user');
        $this->assertCount(
            1,
            $userMessages,
            'the helper conversation must have exactly one user-originated message -- the composed seed -- never any of the parent\'s own prior turns',
        );
        $this->assertSame($expectedPreamble, $userMessages->first()->content);

        foreach ($messages as $message) {
            $this->assertStringNotContainsString(
                'Totally unrelated prior',
                $message->content,
                'no trace of the parent conversation\'s own prior history may ever reach the helper conversation, in any message, in any form',
            );
        }
    }

    #[Test]
    public function delegate_composes_the_context_section_with_a_placeholder_when_no_context_is_explicitly_passed(): void
    {
        $parent = $this->makeAgent('parent-agent-no-context');
        $helper = $this->makeAgent('helper-agent-no-context');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Helper reply.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $task = 'Summarize the attached document.';

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, $task, null);
        $this->assertSame('completed', $result['status'] ?? null, 'fixture sanity: the delegation itself must succeed');

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $helperConversation = Conversation::find($row->helper_conversation_id);

        $userMessage = Message::where('conversation_id', $helperConversation->id)->where('role', 'user')->first();
        $this->assertNotNull($userMessage);
        $this->assertStringContainsString(
            "## Context\n(none provided)",
            $userMessage->content,
            'an explicitly-omitted context must compose the literal "(none provided)" placeholder per contracts/delegation-protocol-meta-tool.md',
        );
    }

    #[Test]
    public function delegate_saves_and_restores_the_ambient_context_run_id_around_the_nested_run_call(): void
    {
        $parent = $this->makeAgent('parent-agent-context-restore');
        $helper = $this->makeAgent('helper-agent-context-restore');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        $enclosingRunId = (string) Str::uuid();
        Context::add('run_id', $enclosingRunId);

        $capturedMidCallRunId = null;

        // A faked nested run() call (per tasks.md T015's own instruction) --
        // it simulates exactly what the real run()/RunTraceRecorder::openRun()
        // does to the ambient Context slot (research.md D6, Grounding note
        // item 5): overwrite it with a different, freshly-minted run id for
        // the duration of the call.
        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')
            ->once()
            ->andReturnUsing(function (Conversation $helperConversation, string $message, array $options = []) use (&$capturedMidCallRunId) {
                $capturedMidCallRunId = (string) Str::uuid();
                Context::add('run_id', $capturedMidCallRunId);

                return [
                    'status' => 'completed',
                    'content' => 'Helper reply.',
                    'message_id' => null,
                ];
            });

        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Do something.', null);

        $this->assertSame('completed', $result['status'] ?? null, 'fixture sanity: the delegation itself must succeed');
        $this->assertNotNull($capturedMidCallRunId, 'fixture sanity: the mocked nested run() must actually have been invoked');
        $this->assertNotSame(
            $enclosingRunId,
            $capturedMidCallRunId,
            'fixture sanity: the mocked nested run() must genuinely open a DIFFERENT run id mid-call, or this test proves nothing',
        );

        $this->assertSame(
            $enclosingRunId,
            Context::get('run_id'),
            'delegate() must restore the enclosing run id once the nested run() call returns, even though it was overwritten to a different value mid-call (research.md D6)',
        );
    }
}
