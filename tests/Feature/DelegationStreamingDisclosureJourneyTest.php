<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
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
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 098-delegation-protocol reconciliation fix. FR-005/SC-001 (the parent's
 * final response must disclose a delegation by naming the helper) and
 * FR-012 (a delegation must be linkable back to the parent's own run) are
 * both proven, throughout this feature's own test suite, only against the
 * SYNCHRONOUS path (AgentLoopService::run(), see DelegationJourneyTest.php
 * and AgentHandoffDisclosureJourneyTest.php). The real, deployed frontend
 * (Conversation.tsx) drives the STREAMING path
 * (AgentLoopStreamHandler::finish()/handleToolCalls()) instead, via
 * POST /message -- and that path never wires either requirement in:
 *
 *  1. finish()'s plain-reply-and-finish branch composes and prepends the
 *     degradation disclosure and the handoff disclosure, but never calls
 *     AgentLoopService::composeDelegationDisclosure() at all -- a
 *     delegation made during a streamed run is never disclosed to the
 *     user (FR-005/SC-001).
 *
 *  2. handleToolCalls()'s dispatch to executeMetaTool('delegate_to_helper',
 *     ...) relies on the ambient Context 'run_id' slot DelegationService::
 *     delegate() reads as Delegation.parent_run_id. That slot is only
 *     ever populated as a side effect of RunTraceRecorder::openRun() --
 *     which only runs when finish() had to mint a brand-new run because
 *     the incoming payload carried none. On a CONTINUATION iteration
 *     (payload already carries a run_id from a prior iteration), finish()
 *     never calls Context::add('run_id', ...) itself, so if the ambient
 *     Context from the run-opening iteration did not survive (a very
 *     plausible outcome across iteration/job boundaries), the delegation
 *     is recorded with a null parent_run_id -- unrecoverable per FR-012.
 *
 * Mirrors AgentHandoffDisclosureJourneyTest's own streamed-path scaffolding
 * (admitAndOpenStreamedRun()/runStreamedFinish() shape) and
 * DelegationJourneyTest's own agent/helper/scripted-provider fixtures.
 *
 * Written before either fix lands: test 1 is expected to FAIL because the
 * final persisted Message::content never contains the delegation
 * disclosure sentence; test 2's continuation case is expected to FAIL
 * because the resulting Delegation row's parent_run_id is null instead of
 * the pre-opened run id.
 */
class DelegationStreamingDisclosureJourneyTest extends TestCase
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
    // Operation-catalog scaffolding (DelegationJourneyTest precedent)
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
    // Fixture helpers (DelegationJourneyTest precedent)
    // -----------------------------------------------------------------

    private function agentsUrl(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function helpersUrl(string $agentId): string
    {
        return $this->agentsUrl().'/'.$agentId.'/helpers';
    }

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function assignHelper(string $parentAgentId, string $helperAgentId): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson($this->helpersUrl($parentAgentId), ['helper_agent_id' => $helperAgentId])
            ->assertStatus(201);
    }

    /**
     * server_id is deliberately null: after handleToolCalls() resolves the
     * delegate_to_helper call, a non-null server_id would make the
     * streamed handler dispatch a real next-iteration HTTP request via
     * AgentLoopService::start() -- unrelated to what these tests are
     * about, and nothing in this suite can service that request. With
     * server_id null, the handler instead closes the run as stopped_early
     * and returns, which is enough: the Delegation row and the run id it
     * was recorded against already exist by that point.
     */
    private function makeConversation(?Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => null,
            'model' => 'test-model',
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

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

    /**
     * Drives AgentLoopStreamHandler::handleToolCalls() with a single
     * delegate_to_helper tool call, via finish() -- exactly the entry
     * path handleToolCalls() actually uses in production
     * (WithheldToolRefusalJourneyTest's own established convention for
     * exercising this method).
     */
    private function runStreamedDelegation(
        Conversation $conversation,
        string $helperAgentId,
        ?string $runId,
        ?string $stepId,
    ): void {
        Event::fake([FinishOpenAIConversationResponseEvent::class, NewConversationMessageEvent::class]);

        $handler = new AgentLoopStreamHandler(null, null, app(RunTraceRecorder::class));
        $handler->toolCalls = [
            $this->toolCall('delegate_to_helper', [
                'helper_agent_id' => $helperAgentId,
                'task' => 'Summarize the attached report.',
                'context' => 'Report covers Q1 2026 sales figures.',
            ], 'call_delegate_stream_1'),
        ];

        $data = json_encode(array_filter([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
            'run_id' => $runId,
            'step_id' => $stepId,
        ], fn ($v) => $v !== null));

        $handler->finish($data, 2);
    }

    // =================================================================
    // 1. Streaming path -- the plain-reply branch must disclose a
    //    delegation by name (FR-005/SC-001), same as run()'s own branch.
    // =================================================================

    #[Test]
    public function streamed_finish_after_a_delegation_discloses_the_helper_by_name(): void
    {
        $agentA = $this->makeAgent('parent-agent-stream-disclosure');
        $agentB = $this->makeAgent('helper-agent-stream-disclosure');
        $this->assignHelper($agentA->id, $agentB->id);

        $conversation = $this->makeConversation($agentA);
        $conversation->update(['is_processing' => true]);

        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(
            RunKind::Interactive,
            (string) $conversation->user_id,
            $conversation->id,
            streamed: true,
            model: $conversation->model,
            agentId: $conversation->character ?? $conversation->id,
        );
        $this->assertNotNull($runId, 'run tracing must be enabled for this test to exercise the streamed delegation disclosure');

        // A completed delegation recorded against this run -- the exact
        // durable fact composeDelegationDisclosure($runId) reads back
        // (AgentLoopService.php ~L3335). Written directly rather than via
        // the full delegate_to_helper tool dispatch, since this test is
        // narrowly about finish()'s plain-reply branch composing and
        // prepending the disclosure -- test 2 below covers the write path
        // itself (Context::add('run_id', ...) wiring).
        Delegation::create([
            'parent_conversation_id' => $conversation->id,
            'parent_agent_id' => $agentA->id,
            'helper_agent_id' => $agentB->id,
            'helper_conversation_id' => $this->makeConversation(null)->id,
            'helper_agent_version_id' => $agentB->current_version_id,
            'owner_user_id' => (string) $conversation->user_id,
            'task' => 'Summarize the attached report.',
            'context' => 'Report covers Q1 2026 sales figures.',
            'depth' => 1,
            'status' => 'completed',
            'parent_run_id' => $runId,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $stepId = $recorder->openStep($runId, 1, (string) \Illuminate\Support\Str::uuid());

        $handler = new AgentLoopStreamHandler(null, null, $recorder);
        $handler->toolCalls = [];
        $handler->reply = 'Here is your summary.';
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 2,
            'run_id' => $runId,
            'step_id' => $stepId,
        ]);

        $handler->finish($data, 2);

        $handler->message->refresh();

        $this->assertStringContainsString(
            $agentB->name,
            $handler->message->content,
            'the streamed plain-reply branch must name the helper the same way run()\'s own branch does (composeDelegationDisclosure() must be called)',
        );
        $this->assertStringEndsWith(
            'Here is your summary.',
            $handler->message->content,
            'the original reply must still follow the disclosure, unmodified',
        );
    }

    // =================================================================
    // 2. Streaming path -- delegate_to_helper's own write must carry the
    //    correct parent_run_id (FR-012), on both a freshly-minted run AND
    //    a continuation iteration whose run_id came from the payload.
    // =================================================================

    #[Test]
    public function a_delegation_made_on_the_very_first_streamed_iteration_records_the_freshly_minted_run_id(): void
    {
        $agentA = $this->makeAgent('parent-agent-stream-fresh');
        $agentB = $this->makeAgent('helper-agent-stream-fresh');
        $this->assignHelper($agentA->id, $agentB->id);

        $conversation = $this->makeConversation($agentA);
        $conversation->update(['is_processing' => true]);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Helper found the answer.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        // No run_id/step_id in the payload -- finish() must mint a fresh
        // run itself (openRun() sets the ambient Context as a side effect).
        $this->runStreamedDelegation($conversation->fresh(), $agentB->id, null, null);

        $mintedRun = DB::table('agent_runs')->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($mintedRun, 'fixture sanity: finish() must have minted a fresh run for this conversation');

        $delegationRow = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegationRow, 'fixture sanity: the delegation must have written its own row');
        $this->assertNotNull(
            $delegationRow->parent_run_id,
            'a delegation made on the run-opening iteration must record a non-null parent_run_id (FR-012)',
        );
        $this->assertSame(
            $mintedRun->id,
            $delegationRow->parent_run_id,
            'the recorded parent_run_id must equal the run finish() itself just minted',
        );
    }

    #[Test]
    public function a_delegation_made_on_a_later_continuation_iteration_still_records_the_established_run_id(): void
    {
        $agentA = $this->makeAgent('parent-agent-stream-continuation');
        $agentB = $this->makeAgent('helper-agent-stream-continuation');
        $this->assignHelper($agentA->id, $agentB->id);

        $conversation = $this->makeConversation($agentA);
        $conversation->update(['is_processing' => true]);

        // A run/step already established by a PRIOR iteration -- exactly
        // the shape the payload carries forward across iterations
        // (contracts §3.1). openRun() sets the ambient Context as a side
        // effect of minting it, same as any other iteration -- but the
        // whole premise of a continuation iteration is that it runs
        // without that ambient state (a very plausible separate
        // process/job invocation), so it is explicitly cleared here to
        // reproduce that boundary rather than accidentally relying on
        // this same PHP process's leftover state.
        $recorder = app(RunTraceRecorder::class);
        $establishedRunId = $recorder->openRun(
            RunKind::Interactive,
            (string) $conversation->user_id,
            $conversation->id,
            streamed: true,
            model: $conversation->model,
            agentId: $conversation->character ?? $conversation->id,
        );
        $this->assertNotNull($establishedRunId);
        $establishedStepId = $recorder->openStep($establishedRunId, 1, (string) \Illuminate\Support\Str::uuid());
        Context::forget('run_id');

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Helper found the answer.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $this->runStreamedDelegation($conversation->fresh(), $agentB->id, $establishedRunId, $establishedStepId);

        $delegationRow = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegationRow, 'fixture sanity: the delegation must have written its own row');
        $this->assertNotNull(
            $delegationRow->parent_run_id,
            'a delegation made on a continuation iteration (run_id already established, not freshly minted) must still record a non-null parent_run_id (FR-012) -- finish() must set the ambient Context itself whenever it resolves a non-null run_id, not only when it mints one',
        );
        $this->assertSame(
            $establishedRunId,
            $delegationRow->parent_run_id,
            'the recorded parent_run_id must equal the run_id carried forward in the payload, not null and not some other run',
        );
    }
}
