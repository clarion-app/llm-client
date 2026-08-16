<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\CapabilityOfferingService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 109-agent-as-capability, Phase 4 (US2), tasks.md T029.
 *
 * Confirms -- with dedicated tests, per tasks.md's own "Ordering
 * rationale" -- that EffectiveBoundResolver::check()'s existing ancestor
 * walk (100-subagent-tool-restrictions), reused UNMODIFIED inside a
 * capability-agent call's own nested run() (DelegationService::
 * invokeAsCapability(), Phase 3), already bounds what an offered agent can
 * do to the intersection of every caller above it in the chain, and that
 * installation-wide confirmation rules still apply. This file adds no new
 * production seam of its own -- Phase 3 already had to build the
 * eligibility check inside invokeAsCapability() to make a call function at
 * all -- its job is to PROVE the existing composition holds, not to build
 * it (tasks.md's own "Ordering rationale" section).
 *
 * Driven end-to-end through the real, unmocked call chain --
 * AgentLoopService::run() (real) -> handleExecuteOperation() (real) ->
 * DelegationService::invokeAsCapability() (real) -> a nested run() for the
 * offered agent (real) -- with only the LlmProvider collaborator scripted,
 * mirroring CapabilityAgentCallJourneyTest.php's and
 * SubagentToolRestrictionRuntimeJourneyTest.php's own established
 * research.md D1 convention. Belongs in tests/Feature/, never
 * tests/Integration/ (Grounding note 18).
 */
class CapabilityAgentPermissionCompositionTest extends TestCase
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

        DB::table('agent_delegations')->delete();
        DB::table('agent_capability_offerings')->delete();
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
    // Operation-catalog scaffolding (DelegationJourneyTest's own
    // established precedent)
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

    private function seedXyzOperationCatalog(): void
    {
        $this->seedOperationCatalog([
            'x.operation' => ['path' => '/api/x', 'method' => 'get', 'summary' => 'X operation'],
            'y.operation' => ['path' => '/api/y', 'method' => 'get', 'summary' => 'Y operation'],
            'z.operation' => ['path' => '/api/z', 'method' => 'get', 'summary' => 'Z operation'],
            'op.confirm' => ['path' => '/api/op-confirm', 'method' => 'delete', 'summary' => 'Confirm-required op'],
        ]);
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function makeAgentPermitting(string $name, array $operationIds): Agent
    {
        $allowLines = implode("\n", array_map(fn (string $id) => "    - {$id}", $operationIds));

        $yaml = <<<YAML
name: {$name}
instructions: I am {$name}.
tools:
  allow:
{$allowLines}
YAML;

        return app(AgentService::class)->create($this->user->id, $yaml);
    }

    private function offerCapability(Agent $offered, Agent $caller, string $name, string $description, string $inputDescription): CapabilityOffering
    {
        return app(CapabilityOfferingService::class)->offer(
            $this->user->id,
            $offered->id,
            $caller->id,
            $name,
            $description,
            $inputDescription,
        );
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
    // Scripted-provider scaffolding (DelegationJourneyTest's own
    // established precedent, research.md D1)
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
            presetRegistry: app(\ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry::class),
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

    private function delegationResultReply(string $status, string $summary, array $output, string $undone = ''): array
    {
        // 'output' must serialize as a JSON OBJECT ({}), never an array
        // ([]) -- DelegationResultPreset's own schema declares it
        // `type: object`, and json_encode() renders an empty PHP array as
        // `[]`, which fails schema validation and burns extra scripted
        // retry turns.
        return $this->plainReply(json_encode([
            'status' => $status,
            'summary' => $summary,
            'output' => (object) $output,
            'undone' => $undone,
        ]));
    }

    private function executeOperationCall(string $operationId, string $input, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => $operationId,
            'parameters' => ['input' => $input],
        ], $callId);
    }

    /**
     * @return Collection<int, Message> assistant messages carrying
     *   tool_data (one per tool-call round), in the order they were
     *   written.
     */
    private function toolCallMessages(Conversation $conversation): Collection
    {
        return Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->whereNotNull('tool_data')
            ->orderBy('id')
            ->get();
    }

    private function firstToolResultContent(Message $message): ?array
    {
        $content = $message->tool_data['tool_results'][0]['content'] ?? null;

        return $content !== null ? json_decode($content, true) : null;
    }

    // =================================================================
    // T029(a) -- a caller with a narrower bound than an offered agent's
    // own definition still restricts what that offered agent can do
    // (quickstart scenarios 2+3, US2 AC1).
    // =================================================================

    #[Test]
    public function an_offered_agents_nested_attempt_beyond_the_callers_own_bound_is_rejected_and_the_caller_sees_only_the_ordinary_error_shape(): void
    {
        $this->seedXyzOperationCatalog();

        // A permits {X} only. B is entirely helper-unrelated to A (no
        // AgentHelperAssignment anywhere) -- the only relationship is the
        // capability offering itself. B's own definition is deliberately
        // broader than A's ({X, Y, Z}), per data-model.md §1's own
        // deliberate no-subset-check asymmetry (Grounding note 6): B may
        // be offered to A even though B could do more than A itself can.
        $callerA = $this->makeAgentPermitting('t029a-caller-a', ['x.operation']);
        $offeredB = $this->makeAgentPermitting('t029a-offered-b', ['x.operation', 'y.operation', 'z.operation']);

        $offering = $this->offerCapability(
            $offeredB,
            $callerA,
            'do_z_capability',
            'Attempts operation Z on behalf of the caller.',
            'What to attempt.',
        );

        $conversationA = $this->makeConversation($callerA);

        $service = $this->serviceWithScriptedProvider([
            // A's own turn: invoke B's capability.
            $this->toolCallReply([$this->executeOperationCall($offering->id, 'Please attempt operation Z.', 'call_a_invoke_b')]),
            // B's own nested turn: attempt Z -- permitted by B's OWN
            // definition, but not by A, its one ancestor in this chain.
            $this->toolCallReply([$this->toolCall('execute_operation', ['operationId' => 'z.operation', 'parameters' => []], 'call_b_attempt_z')]),
            // B's own finishing reply, after its blocked attempt -- must
            // still produce a schema-valid delegation_result.
            $this->delegationResultReply('failure', 'Could not attempt Z: rejected by an ancestor agent.', [], 'Everything -- Z was never attempted.'),
            // A's own finishing reply, after receiving B's translated
            // failure.
            $this->plainReply('B was unable to complete the task.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversationA->fresh(), 'Please have B attempt operation Z.');
        $this->assertSame('completed', $result['status'], 'fixture sanity: the top-level turn must complete regardless of what happened inside the capability call');

        $delegation = Delegation::where('parent_conversation_id', $conversationA->id)->first();
        $this->assertNotNull($delegation, 'fixture sanity: the capability call must have written a Delegation row');
        $this->assertSame('capability_offering', $delegation->origin);
        $helperConversationB = Conversation::find($delegation->helper_conversation_id);
        $this->assertNotNull($helperConversationB);

        // B's nested attempt at Z must be rejected -- the ancestor-chain
        // check (EffectiveBoundResolver::check(), reused unmodified inside
        // B's own nested run()) walks up to A, finds Z outside A's current
        // bound, and rejects.
        $bToolMessage = $this->toolCallMessages($helperConversationB)->first();
        $this->assertNotNull($bToolMessage, 'fixture sanity: B must actually have attempted execute_operation(z.operation)');
        $bToolResult = $this->firstToolResultContent($bToolMessage);
        $this->assertArrayHasKey('error', (array) $bToolResult, 'B\'s own nested attempt at Z must be rejected -- A, its one ancestor, does not permit Z (FR-006/FR-008)');
        $this->assertStringContainsString('ancestor agent', $bToolResult['error'] ?? '');
        $this->assertStringContainsString('z.operation', $bToolResult['error'] ?? '');
        $this->assertStringContainsString($callerA->name, $bToolResult['error'] ?? '', 'the rejection must name A specifically, the blocking ancestor');

        // A must receive the ordinary {"error": "..."} shape -- never the
        // raw internal ancestor-rejection text, never the six-field
        // delegation envelope (FR-005/FR-016/FR-017, contracts/
        // capability-agent-call.md).
        $aToolMessage = $this->toolCallMessages($conversationA)->first();
        $this->assertNotNull($aToolMessage, 'fixture sanity: A must have actually invoked the capability');
        $aToolResult = $this->firstToolResultContent($aToolMessage);
        $this->assertIsArray($aToolResult);
        $this->assertArrayHasKey('error', $aToolResult, 'A must receive the ordinary capability-failure shape');
        $this->assertSame(
            ['error'],
            array_keys($aToolResult),
            'A\'s own result must be EXACTLY the plain {"error": "..."} shape -- no status/helper/delegation_id/reason envelope field leaking through (never a raw internal rejection)',
        );
    }

    // =================================================================
    // T029(b) -- the installation's confirmation-required rule still
    // applies to an action reached through a capability-agent call
    // (US2 AC2, a lighter-weight variant of quickstart scenario 6 --
    // confirming the RULE still fires, distinct from Phase 7's own
    // dedicated proof that the result SHAPE is ordinary).
    // =================================================================

    #[Test]
    public function an_installation_confirm_method_operation_still_pauses_for_confirmation_when_reached_through_a_capability_agent_call(): void
    {
        $this->seedXyzOperationCatalog();
        config(['llm-client.confirm_methods' => ['DELETE']]);

        $callerA = $this->makeAgentPermitting('t029b-caller-a', ['op.confirm']);
        $offeredB = $this->makeAgentPermitting('t029b-offered-b', ['op.confirm']);

        $offering = $this->offerCapability(
            $offeredB,
            $callerA,
            'do_confirm_op_capability',
            'Attempts the confirm-required operation on behalf of the caller.',
            'What to attempt.',
        );

        $conversationA = $this->makeConversation($callerA);

        $service = $this->serviceWithScriptedProvider([
            // A's own turn: invoke B's capability.
            $this->toolCallReply([$this->executeOperationCall($offering->id, 'Please perform the confirm-required operation.', 'call_a_invoke_b_confirm')]),
            // B's own nested turn: attempt the confirm-required operation
            // -- must pause for confirmation, never execute. Exactly ONE
            // scripted response is ever consumed for B's own run() (it
            // returns immediately with status 'confirmation_required'),
            // mirroring SubagentToolRestrictionRuntimeJourneyTest's own
            // scenario 7 precedent for the delegate_to_helper path.
            $this->toolCallReply([$this->toolCall('execute_operation', ['operationId' => 'op.confirm', 'parameters' => []], 'call_b_attempt_confirm')]),
            // A's own finishing reply, after receiving B's translated
            // confirmation-required failure.
            $this->plainReply('B could not complete the task without confirmation.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversationA->fresh(), 'Please have B perform the confirm-required operation.');
        $this->assertSame('completed', $result['status'], 'fixture sanity: the top-level turn itself must complete');

        $delegation = Delegation::where('parent_conversation_id', $conversationA->id)->first();
        $this->assertNotNull($delegation, 'fixture sanity: the capability call must have written a Delegation row');
        $this->assertSame('capability_offering', $delegation->origin);

        $helperConversationB = Conversation::find($delegation->helper_conversation_id);
        $this->assertNotNull($helperConversationB);

        // The confirm-required rule genuinely fired inside B's own nested
        // run -- the operation was paused, never executed.
        $pausedMessage = $this->toolCallMessages($helperConversationB)->first();
        $this->assertNotNull($pausedMessage, 'fixture sanity: B must actually have attempted the confirm-required operation');
        $this->assertNotNull(
            $pausedMessage->tool_data['pending_confirmation'] ?? null,
            'the installation confirm_methods rule must still fire for an operation reached through a capability-agent call, exactly as it would for a direct or delegate_to_helper attempt (FR-009)',
        );
        $this->assertArrayHasKey('tool_results', $pausedMessage->tool_data ?? []);
        $this->assertNull(
            $pausedMessage->tool_data['tool_results'],
            'the confirm-gated operation must never have produced a tool result -- it was never executed',
        );

        // The Delegation row records the pause distinctly.
        $this->assertSame('failed', $delegation->status);
        $this->assertSame('confirmation_required', $delegation->result_reason);
        $this->assertSame('failure', $delegation->result_status);
        $this->assertNull($delegation->result_output);

        // A receives the ordinary capability-failure shape.
        $aToolMessage = $this->toolCallMessages($conversationA)->first();
        $this->assertNotNull($aToolMessage);
        $aToolResult = $this->firstToolResultContent($aToolMessage);
        $this->assertSame(
            ['error'],
            array_keys((array) $aToolResult),
            'A must receive the ordinary plain {"error": "..."} shape, never a raw confirmation marker or delegation envelope',
        );
        $this->assertSame(
            'This action requires your explicit confirmation and could not be completed automatically.',
            $aToolResult['error'] ?? null,
        );
    }

    // =================================================================
    // T029(c) -- a 3-level capability-agent chain is bounded by the
    // TOP-level agent, not merely the immediate caller (US2 AC3, FR-009,
    // explicit test requirement). Mirrors
    // SubagentToolRestrictionRuntimeJourneyTest's own proven
    // delegate_to_helper 3-level precedent, with both hops now
    // capability-offering edges instead.
    // =================================================================

    #[Test]
    public function a_three_level_capability_agent_chain_is_blocked_by_the_top_level_caller_even_though_the_immediate_ancestor_still_permits_it(): void
    {
        $this->seedXyzOperationCatalog();

        // A permits {X} only. B (offered to A) and C (offered to B) both
        // permit {X, Y} -- so the ancestor walk passes cleanly through B
        // (levels_up 1) and is blocked only at A (levels_up 2), naming A,
        // not B, as the blocker.
        $agentA = $this->makeAgentPermitting('t029c-agent-a', ['x.operation']);
        $agentB = $this->makeAgentPermitting('t029c-agent-b', ['x.operation', 'y.operation']);
        $agentC = $this->makeAgentPermitting('t029c-agent-c', ['x.operation', 'y.operation']);

        $offeringAB = $this->offerCapability(
            $agentB,
            $agentA,
            'hop_to_b',
            'Hands off the first hop of a chained task to B.',
            'The first-hop task.',
        );
        $offeringBC = $this->offerCapability(
            $agentC,
            $agentB,
            'hop_to_c',
            'Hands off the second hop of a chained task to C.',
            'The second-hop task.',
        );

        $conversationA = $this->makeConversation($agentA);

        $service = $this->serviceWithScriptedProvider([
            // A's own turn: invoke B's capability (depth 1).
            $this->toolCallReply([$this->executeOperationCall($offeringAB->id, 'Handle the first hop of this chain.', 'call_a_to_b')]),
            // B's own nested turn: invoke C's capability (depth 2).
            $this->toolCallReply([$this->executeOperationCall($offeringBC->id, 'Handle the second hop of this chain.', 'call_b_to_c')]),
            // C's own nested turn: attempt operation Y -- permitted by
            // both B's own bound and C's own bound, but not by A, two
            // levels further up this specific capability-agent chain.
            $this->toolCallReply([$this->toolCall('execute_operation', ['operationId' => 'y.operation', 'parameters' => []], 'call_c_op_y')]),
            // C's own finishing reply, after its blocked attempt.
            $this->delegationResultReply('failure', 'Could not attempt Y: rejected by an ancestor agent.', [], 'Everything -- Y was never attempted.'),
            // B's own finishing reply, after receiving C's translated
            // failure.
            $this->delegationResultReply('failure', 'C was unable to complete the second hop.', [], 'Everything -- the chain could not proceed.'),
            // A's own finishing reply.
            $this->plainReply("A's final answer, relying on B's own outcome."),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversationA->fresh(), 'Please handle this chained task, which requires operation Y.');
        $this->assertSame('completed', $result['status'], 'the top-level turn itself must complete normally regardless of what happened several levels down');

        $delegationAB = Delegation::where('parent_conversation_id', $conversationA->id)->first();
        $this->assertNotNull($delegationAB, 'fixture sanity: the first hop (A to B) must have succeeded structurally');
        $this->assertSame('capability_offering', $delegationAB->origin);
        $this->assertSame(1, $delegationAB->depth);

        $delegationBC = Delegation::where('parent_conversation_id', $delegationAB->helper_conversation_id)->first();
        $this->assertNotNull($delegationBC, 'fixture sanity: the second hop (B to C) must have succeeded structurally -- offering C to B never itself requires B to already permit Y (no-subset-check asymmetry)');
        $this->assertSame('capability_offering', $delegationBC->origin);
        $this->assertSame(2, $delegationBC->depth);

        $helperCConversation = Conversation::find($delegationBC->helper_conversation_id);
        $this->assertNotNull($helperCConversation);

        $cToolMessage = $this->toolCallMessages($helperCConversation)->first();
        $this->assertNotNull($cToolMessage, 'fixture sanity: C must actually have attempted execute_operation(y.operation)');
        $cToolResult = $this->firstToolResultContent($cToolMessage);

        $this->assertArrayHasKey(
            'error',
            (array) $cToolResult,
            'C\'s attempt at Y must be blocked -- Y is outside A\'s current bound, two levels up this specific capability-agent chain',
        );
        $this->assertSame(
            'Operation not permitted: ancestor agent "t029c-agent-a" (2 level(s) up in this delegation chain) does not permit "y.operation".',
            $cToolResult['error'] ?? null,
            'the rejection must name A specifically, at exactly 2 levels up (contracts §3) -- even though both B\'s own bound and C\'s own bound still include Y, since neither of their own definitions is ever edited, proving the composition rejects at the correct ancestor and NOT at B',
        );
    }

    // =================================================================
    // T029(d) -- an offering is never invokable by a caller it was not
    // offered to (mutation-testing checklist row 7's own explicit target:
    // DelegationService::invokeAsCapability()'s eligibility check must
    // re-confirm caller_agent_id against a FRESH lookup, not trust the
    // conversation's own claim). Simulates an uninvolved agent that
    // somehow already knows an offering's synthetic operationId (it would
    // never be discoverable to it via "Known Operations"/search_operations
    // in the first place, since CapabilityOfferingQuery::eligibleFor()
    // already scopes by caller_agent_id) attempting to invoke it directly.
    // =================================================================

    #[Test]
    public function an_offering_is_never_invokable_by_a_caller_it_was_not_offered_to(): void
    {
        $this->seedXyzOperationCatalog();

        $offeredX = $this->makeAgentPermitting('t029d-offered-x', ['x.operation']);
        $intendedCallerA = $this->makeAgentPermitting('t029d-intended-caller-a', ['x.operation']);
        $uninvolvedCallerZ = $this->makeAgentPermitting('t029d-uninvolved-caller-z', ['x.operation']);

        // X is offered to A ONLY -- Z is a completely uninvolved agent,
        // never named as this offering's caller_agent_id.
        $offering = $this->offerCapability(
            $offeredX,
            $intendedCallerA,
            'do_x_capability',
            'Attempts operation X on behalf of the caller.',
            'What to attempt.',
        );

        $conversationZ = $this->makeConversation($uninvolvedCallerZ);

        $service = $this->serviceWithScriptedProvider([
            // Z attempts execute_operation directly against A's own
            // offering id -- something Z could never have discovered
            // through its own "Known Operations"/search_operations (both
            // scoped by caller_agent_id), simulating an uninvolved caller
            // that already knows the synthetic operationId some other way.
            $this->toolCallReply([$this->executeOperationCall($offering->id, 'Please attempt operation X.', 'call_z_invoke_x')]),
            $this->plainReply('Z could not invoke that capability.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversationZ->fresh(), 'Please attempt operation X via this capability id.');
        $this->assertSame('completed', $result['status'], 'fixture sanity: the top-level turn must complete regardless of the rejected invocation');

        // No Delegation row of any kind may be created -- the eligibility
        // check must refuse before createDelegationRow() is ever reached.
        $this->assertSame(
            0,
            Delegation::where('parent_conversation_id', $conversationZ->id)->count(),
            'an uninvolved caller\'s attempt must never create a Delegation row -- the eligibility check must refuse before createDelegationRow() is ever reached',
        );

        $zToolMessage = $this->toolCallMessages($conversationZ)->first();
        $this->assertNotNull($zToolMessage, 'fixture sanity: Z must actually have attempted execute_operation against the offering id');
        $zToolResult = $this->firstToolResultContent($zToolMessage);
        $this->assertIsArray($zToolResult);
        $this->assertSame(
            ['error'],
            array_keys($zToolResult),
            'an uninvolved caller must receive the ordinary plain {"error": "..."} shape, never the offered agent\'s own raw output (FR-005/data-model.md §6 eligibility re-check, mutation-testing checklist row 7)',
        );
    }
}
