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
use ClarionApp\LlmClient\Services\AgentQuery;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 100-subagent-tool-restrictions, Phase 4 (US2), tasks.md T019.
 *
 * The headline runtime guarantee (quickstart.md scenario 3) and Edge
 * Case 1 (quickstart.md scenario 4), both driven end-to-end through the
 * REAL, unmocked call chain — DelegationService::delegate() (real) ->
 * AgentLoopService::run() (real, only its LlmProvider collaborator is a
 * scripted double, mirroring DelegationJourneyTest.php's own established
 * research.md D1 convention) -> AgentLoopService::handleExecuteOperation()
 * (real) -> the not-yet-built EffectiveBoundResolver::check() (real, once
 * Phase 4 Implementation wires it in). This is deliberately NOT a
 * reflection-based or Mockery-mocked-collaborator unit test — the prior
 * feature in this same package (099-result-aggregation, T093
 * reconciliation) found that a mandatory check wired only into a mocked
 * call chain can silently fail to reach the real production code path,
 * so this file exists specifically to prove the check is reachable from
 * the actual execution flow a real delegated tool call takes.
 *
 * `Context::add('eval_run_simulating_tools', true)` (093-agent-handoff's
 * own established precedent, AgentHandoffJourneyTest.php) makes a
 * PERMITTED execute_operation call simulate its response instead of
 * minting a real Passport token and dispatching an actual HTTP call —
 * only the permission gate itself is under test here, never the HTTP
 * dispatch machinery. A REJECTED call never reaches that branch at all
 * (handleExecuteOperation() returns the rejection JSON before
 * executeApiCall() is ever invoked), so this flag has no bearing on
 * whether a rejection is correctly produced.
 *
 * Written before EffectiveBoundResolver exists and before
 * AgentLoopService::handleExecuteOperation() has any ancestor-chain
 * check wired in (Phase 4 Implementation, T020/T021, not yet done) —
 * every case below is expected to fail RED for that reason: the second
 * (post-narrowing) attempt currently still succeeds, since nothing in
 * today's code checks anything beyond the conversation's own
 * boundDefinition (which never changes in either scenario below — only
 * the PARENT's definition is ever edited, and H's own bound already,
 * always, permits the operation under test).
 */
class SubagentToolRestrictionRuntimeJourneyTest extends TestCase
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

        // DelegationJourneyTest.php's own established precedent — needed
        // by any call into the real AgentLoopService::run() funnel, even
        // though the execute_operation calls under test here never
        // actually reach getOrCreateSession() (eval_run_simulating_tools
        // short-circuits executeApiCall() before that point).
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
        Context::forget('eval_run_simulating_tools');

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

    private function seedAbOperationCatalog(): void
    {
        $this->seedOperationCatalog([
            'a.operation' => ['path' => '/api/a', 'method' => 'get', 'summary' => 'A operation'],
            'b.operation' => ['path' => '/api/b', 'method' => 'get', 'summary' => 'B operation'],
        ]);
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function agentsUrl(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function helpersUrl(string $agentId): string
    {
        return $this->agentsUrl().'/'.$agentId.'/helpers';
    }

    /**
     * The real, unmodified 097 HTTP endpoint — the exact same precedent
     * DelegationJourneyTest.php's own assignHelper() uses.
     */
    private function assignHelper(string $parentAgentId, string $helperAgentId): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson($this->helpersUrl($parentAgentId), ['helper_agent_id' => $helperAgentId])
            ->assertStatus(201);
    }

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

    private function narrowAgentTo(Agent $agent, string $name, array $operationIds): Agent
    {
        $allowLines = implode("\n", array_map(fn (string $id) => "    - {$id}", $operationIds));

        $yaml = <<<YAML
name: {$name}
instructions: I am {$name}.
tools:
  allow:
{$allowLines}
YAML;

        return app(AgentService::class)->update($agent->fresh(), $this->user->id, $yaml);
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

    /**
     * A DelegationService instance built directly (never via the
     * container's singleton-cached AgentLoopService::class binding), so
     * each scenario below can hand it its own freshly-scripted
     * AgentLoopService without any risk of an earlier round's already-
     * resolved DelegationService having captured a stale collaborator.
     */
    private function delegationServiceWith(AgentLoopService $agentLoopService): DelegationService
    {
        return new DelegationService(
            app(AgentQuery::class),
            $agentLoopService,
            app(RunTraceRecorder::class),
        );
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
        );
    }

    /**
     * Identical to serviceWithScriptedProvider() except a side effect
     * fires just before the SECOND chat() call returns its response —
     * i.e. after the first tool call's own result has already been
     * appended to the transcript, and before the model's next tool call
     * request is dispatched, entirely within the same nested run()
     * invocation (Edge Case 1, quickstart.md scenario 4).
     */
    private function serviceWithInterleavedScriptedProvider(array $responses, callable $beforeSecondCallReturns): AgentLoopService
    {
        $callIndex = 0;

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses, &$callIndex, $beforeSecondCallReturns) {
            $callIndex++;
            if ($callIndex === 2) {
                $beforeSecondCallReturns();
            }

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

    /**
     * @return array<string, mixed>|null the decoded JSON content of the
     *   FIRST tool result on a tool_data-carrying assistant message
     *   (used only where a round issues exactly one tool call).
     */
    private function firstToolResultContent(Message $message): ?array
    {
        $content = $message->tool_data['tool_results'][0]['content'] ?? null;

        return $content !== null ? json_decode($content, true) : null;
    }

    /**
     * Like firstToolResultContent(), but locates the result by its own
     * tool_call_id — needed where a single round's own tool_calls batch
     * carries more than one call (Edge Case 1's own round 1, which pairs
     * execute_operation with an unrelated call solely to defeat
     * allExecuteOperationsSucceeded()'s auto-stop optimization, see
     * below).
     *
     * @return array<string, mixed>|null
     */
    private function toolResultForCallId(Message $message, string $toolCallId): ?array
    {
        foreach ($message->tool_data['tool_results'] ?? [] as $result) {
            if (($result['tool_call_id'] ?? null) === $toolCallId) {
                $content = $result['content'] ?? null;

                return $content !== null ? json_decode($content, true) : null;
            }
        }

        return null;
    }

    // =================================================================
    // T019 — headline case (quickstart.md scenario 3)
    // =================================================================

    #[Test]
    public function narrowing_the_parent_after_a_successful_delegated_attempt_blocks_the_helpers_very_next_identical_attempt_with_no_re_save_needed(): void
    {
        $this->seedAbOperationCatalog();

        $parent = $this->makeAgentPermitting('rt-parent', ['a.operation', 'b.operation']);
        $helper = $this->makeAgentPermitting('rt-helper', ['a.operation']);
        $this->assignHelper($parent->id, $helper->id);

        $parentConversation = $this->makeConversation($parent);

        Context::add('eval_run_simulating_tools', true);

        // Round 1: delegate from the conversation bound to P to H, have H
        // attempt operation A while P still permits {A, B} — succeeds
        // (already true today via the existing, unmodified boundDefinition
        // check alone, since H's own definition already permits A; this
        // round is not expected to newly fail).
        $round1Service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([$this->toolCall('execute_operation', ['operationId' => 'a.operation', 'parameters' => []], 'call_a_round1')]),
            $this->plainReply('Task A completed.'),
        ]);
        $round1Result = $this->delegationServiceWith($round1Service)->delegate(
            $parentConversation->fresh(),
            $helper->id,
            'Attempt operation A.',
            null,
        );

        $round1Delegation = Delegation::find($round1Result['delegation_id']);
        $this->assertNotNull($round1Delegation, 'fixture sanity: round 1 must have written a Delegation row');
        $round1HelperConversation = Conversation::find($round1Delegation->helper_conversation_id);

        $round1ToolMessage = $this->toolCallMessages($round1HelperConversation)->first();
        $this->assertNotNull($round1ToolMessage, 'fixture sanity: H must actually have attempted execute_operation in round 1');
        $round1ToolResult = $this->firstToolResultContent($round1ToolMessage);
        $this->assertArrayNotHasKey(
            'error',
            (array) $round1ToolResult,
            'H\'s attempt at A must succeed while P still permits it (quickstart scenario 3, first half)',
        );

        // Narrow P: permit only {B} now — removing A — without ever
        // touching H's own definition or the assignment row.
        $this->narrowAgentTo($parent, 'rt-parent', ['b.operation']);

        // Round 2: delegate again, have H attempt operation A again — must
        // now be blocked, with no re-save of H or the assignment row in
        // between (the headline guarantee under test, FR-004/FR-005/
        // FR-006, SC-002/SC-003).
        $round2Service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([$this->toolCall('execute_operation', ['operationId' => 'a.operation', 'parameters' => []], 'call_a_round2')]),
            $this->plainReply('Task A completed again.'),
        ]);
        $round2Result = $this->delegationServiceWith($round2Service)->delegate(
            $parentConversation->fresh(),
            $helper->id,
            'Attempt operation A again.',
            null,
        );

        $round2Delegation = Delegation::find($round2Result['delegation_id']);
        $this->assertNotNull($round2Delegation, 'fixture sanity: round 2 must have written a Delegation row');
        $round2HelperConversation = Conversation::find($round2Delegation->helper_conversation_id);

        $round2ToolMessage = $this->toolCallMessages($round2HelperConversation)->first();
        $this->assertNotNull($round2ToolMessage, 'fixture sanity: H must have attempted execute_operation again in round 2');
        $round2ToolResult = $this->firstToolResultContent($round2ToolMessage);

        $this->assertArrayHasKey(
            'error',
            (array) $round2ToolResult,
            'H\'s second attempt at A must now be rejected — P no longer permits it, and this rejection can only come from the new ancestor-chain check (H\'s own bound is unchanged, and the assignment row was never touched)',
        );
        $this->assertStringContainsString(
            'ancestor agent',
            $round2ToolResult['error'] ?? '',
            'the rejection reason must be the ancestor-chain check\'s own wording (contracts §3) — distinguishing it from the pre-existing boundDefinition rejection, which never fires here since H\'s own bound never changed',
        );
    }

    // =================================================================
    // T019 — Edge Case 1 (quickstart.md scenario 4)
    // =================================================================

    #[Test]
    public function a_narrowing_between_two_tool_calls_in_the_same_nested_run_leaves_the_first_result_unaffected_and_blocks_only_the_second(): void
    {
        $this->seedAbOperationCatalog();

        $parent = $this->makeAgentPermitting('edge1-parent', ['a.operation']);
        $helper = $this->makeAgentPermitting('edge1-helper', ['a.operation']);
        $this->assignHelper($parent->id, $helper->id);

        $parentConversation = $this->makeConversation($parent);

        Context::add('eval_run_simulating_tools', true);

        $service = $this->serviceWithInterleavedScriptedProvider(
            [
                // Round 1 pairs execute_operation(A) with an unrelated,
                // deliberately-unknown call: AgentLoopService::run()'s own
                // allExecuteOperationsSucceeded() optimization stops the
                // loop immediately (without ever asking the model again)
                // once a turn's tool_calls are ALL execute_operation and
                // ALL succeed — which round 1's execute_operation(A) call
                // alone would trigger, ending the nested run before a
                // second round could ever be requested. Pairing it with a
                // non-execute_operation call defeats that check (the
                // second call's own name alone disqualifies the batch)
                // without touching whether execute_operation(A) itself
                // succeeds.
                $this->toolCallReply([
                    $this->toolCall('execute_operation', ['operationId' => 'a.operation', 'parameters' => []], 'call_edge_1'),
                    $this->toolCall('probe_noop', [], 'call_edge_1_probe'),
                ]),
                $this->toolCallReply([$this->toolCall('execute_operation', ['operationId' => 'a.operation', 'parameters' => []], 'call_edge_2')]),
                $this->plainReply('Finished — the second attempt should have been blocked.'),
            ],
            function () use ($parent) {
                // Fires between the first call's completion (already
                // processed and appended to the transcript) and the
                // model's next tool call request — entirely within this
                // one nested run() invocation.
                $this->narrowAgentTo($parent, 'edge1-parent', ['b.operation']);
            },
        );

        $result = $this->delegationServiceWith($service)->delegate(
            $parentConversation->fresh(),
            $helper->id,
            'Attempt operation A twice.',
            null,
        );

        $delegation = Delegation::find($result['delegation_id']);
        $this->assertNotNull($delegation, 'fixture sanity: the delegation must have written a Delegation row');
        $helperConversation = Conversation::find($delegation->helper_conversation_id);

        $toolMessages = $this->toolCallMessages($helperConversation)->values();
        $this->assertCount(
            2,
            $toolMessages,
            'fixture sanity: H must have attempted execute_operation exactly twice, in two separate rounds, within this single nested run',
        );

        $firstResult = $this->toolResultForCallId($toolMessages[0], 'call_edge_1');
        $this->assertArrayNotHasKey(
            'error',
            (array) $firstResult,
            'the first call completed under the OLD, wider bound and must be unaffected by the narrowing that happens strictly after it (Edge Case 1) — a delegated task already in progress is not interrupted mid-action',
        );

        $secondResult = $this->firstToolResultContent($toolMessages[1]);
        $this->assertArrayHasKey(
            'error',
            (array) $secondResult,
            'the second call, requested only after P was narrowed to exclude A, must be blocked — the very next action is bound by the new, tighter permissions (Edge Case 1, quickstart.md scenario 4)',
        );
    }

    // =================================================================
    // T029 (US4, 100-subagent-tool-restrictions) — quickstart.md
    // Scenario 6: installation-wide denial/confirmation rules apply to a
    // helper's own delegated attempt exactly as they would to a
    // directly-acting conversation (FR-009/FR-010, SC-006). Proof-only
    // per research.md D5 — AgentDefinition::isOperationPermitted()/
    // ::isConfirmationRequired() already union the installation ceiling
    // into every definition either the direct or the delegated path
    // reads, so no production code change is expected to make these
    // pass; they are expected to already be GREEN today.
    // =================================================================

    #[Test]
    public function an_installation_denylisted_operation_is_rejected_identically_for_a_direct_attempt_and_a_delegated_helpers_nested_attempt(): void
    {
        config(['llm-client.api_denylist' => ['/api/scenario6-denylisted']]);

        $this->seedOperationCatalog([
            'scenario6.denylisted' => ['path' => '/api/scenario6-denylisted', 'method' => 'get', 'summary' => 'Denylisted op'],
        ]);

        $parent = $this->makeAgentPermitting('scenario6-parent-denylist', ['scenario6.denylisted']);
        $helper = $this->makeAgentPermitting('scenario6-helper-denylist', ['scenario6.denylisted']);
        $this->assignHelper($parent->id, $helper->id);

        Context::add('eval_run_simulating_tools', true);

        // Direct attempt: a conversation bound directly to P, no
        // delegation involved at all — the baseline scenario 6 compares
        // the delegated attempt against.
        $directConversation = $this->makeConversation($parent);
        $directResult = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => 'scenario6.denylisted', 'parameters' => []],
                $directConversation,
            ),
            true,
        );

        $this->assertSame(
            'Path is denylisted: /api/scenario6-denylisted',
            $directResult['error'] ?? null,
            'fixture sanity: a direct attempt at a denylisted path must be rejected (086-agent-yaml-schema, unchanged) — establishes the baseline this test compares the delegated attempt against',
        );

        // Delegated attempt: the byte-identical operation, attempted by
        // H acting on delegated work from P.
        $parentConversation = $this->makeConversation($parent);
        $delegatedService = $this->serviceWithScriptedProvider([
            $this->toolCallReply([$this->toolCall('execute_operation', ['operationId' => 'scenario6.denylisted', 'parameters' => []], 'call_denylist')]),
            $this->plainReply('Attempt finished.'),
        ]);
        $delegatedResult = $this->delegationServiceWith($delegatedService)->delegate(
            $parentConversation->fresh(),
            $helper->id,
            'Attempt the denylisted operation.',
            null,
        );

        $delegation = Delegation::find($delegatedResult['delegation_id']);
        $this->assertNotNull($delegation, 'fixture sanity: the delegation must have written a Delegation row');
        $helperConversation = Conversation::find($delegation->helper_conversation_id);

        $toolMessage = $this->toolCallMessages($helperConversation)->first();
        $this->assertNotNull($toolMessage, 'fixture sanity: H must have actually attempted the denylisted operation');
        $delegatedToolResult = $this->firstToolResultContent($toolMessage);

        $this->assertSame(
            $directResult['error'] ?? null,
            $delegatedToolResult['error'] ?? null,
            'the installation denylist must reject a helper\'s own delegated attempt with the byte-identical message it would give a directly-acting conversation (US4, FR-009, SC-006)',
        );
    }

    #[Test]
    public function an_installation_confirm_method_operation_pauses_identically_for_a_direct_attempt_and_a_delegated_helpers_nested_attempt(): void
    {
        config(['llm-client.confirm_methods' => ['DELETE']]);

        $this->seedOperationCatalog([
            'scenario6.confirm' => ['path' => '/api/scenario6-confirm', 'method' => 'delete', 'summary' => 'Confirm-required op'],
        ]);

        $parent = $this->makeAgentPermitting('scenario6-parent-confirm', ['scenario6.confirm']);
        $helper = $this->makeAgentPermitting('scenario6-helper-confirm', ['scenario6.confirm']);
        $this->assignHelper($parent->id, $helper->id);

        Context::add('eval_run_simulating_tools', true);

        // Direct attempt — the baseline scenario 6 compares against.
        $directConversation = $this->makeConversation($parent);
        $directResult = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => 'scenario6.confirm', 'parameters' => []],
                $directConversation,
            ),
            true,
        );

        $this->assertTrue(
            $directResult['__requires_confirmation'] ?? false,
            'fixture sanity: a direct attempt at a confirm-method operation must pause for confirmation (086-agent-yaml-schema, unchanged) — establishes the baseline this test compares the delegated attempt against',
        );
        $this->assertSame('scenario6.confirm', $directResult['operationId'] ?? null);
        $this->assertSame('DELETE', $directResult['method'] ?? null);
        $this->assertSame('/api/scenario6-confirm', $directResult['path'] ?? null);

        // Delegated attempt: the byte-identical operation, attempted
        // from H's own nested run.
        $parentConversation = $this->makeConversation($parent);
        $delegatedService = $this->serviceWithScriptedProvider([
            $this->toolCallReply([$this->toolCall('execute_operation', ['operationId' => 'scenario6.confirm', 'parameters' => []], 'call_confirm')]),
        ]);
        $delegatedResult = $this->delegationServiceWith($delegatedService)->delegate(
            $parentConversation->fresh(),
            $helper->id,
            'Attempt the confirm-method operation.',
            null,
        );

        $delegation = Delegation::find($delegatedResult['delegation_id']);
        $this->assertNotNull($delegation, 'fixture sanity: the delegation must have written a Delegation row');
        $helperConversation = Conversation::find($delegation->helper_conversation_id);

        $pausedMessage = $this->toolCallMessages($helperConversation)->first();
        $this->assertNotNull($pausedMessage, 'fixture sanity: H must have actually attempted the confirm-method operation');
        $pendingConfirmation = $pausedMessage->tool_data['pending_confirmation'] ?? null;
        $this->assertNotNull(
            $pendingConfirmation,
            'H\'s own nested run must have paused for confirmation exactly as the direct attempt did — never silently auto-approved by the parent (D5)',
        );

        $this->assertSame(
            $directResult['operationId'] ?? null,
            $pendingConfirmation['operationId'] ?? null,
            'the confirmation pause must name the byte-identical operationId a direct attempt would (US4, FR-010, SC-006)',
        );
        $this->assertSame($directResult['method'] ?? null, $pendingConfirmation['method'] ?? null);
        $this->assertSame($directResult['path'] ?? null, $pendingConfirmation['path'] ?? null);
    }

    // =================================================================
    // T029 (US4, 100-subagent-tool-restrictions) — quickstart.md
    // Scenario 7: a helper's confirmation-required action is never
    // silently approved by the parent, and is diagnosable as such (D5).
    // Drives T028's unit-level mapping through the real, unmocked
    // execution path end-to-end — DelegationService::delegate() (real)
    // -> AgentLoopService::run() (real, scripted provider only) ->
    // handleExecuteOperation()'s existing STATUS_CONFIRM pause (real,
    // unchanged) -> delegate()'s not-yet-built confirmation_required
    // branch (Phase 6 Implementation, T030, not yet done).
    //
    // Written before that branch exists — expected to FAIL on the
    // result_reason assertion (still 'no_output' from the generic
    // fallback), not on the earlier structural assertions, which already
    // hold today.
    // =================================================================

    #[Test]
    public function a_delegated_tasks_only_viable_next_operation_requiring_confirmation_is_never_executed_and_fails_the_delegation_as_confirmation_required(): void
    {
        config(['llm-client.confirm_methods' => ['DELETE']]);

        $this->seedOperationCatalog([
            'scenario7.confirm' => ['path' => '/api/scenario7-confirm', 'method' => 'delete', 'summary' => 'Confirm-required op'],
        ]);

        $parent = $this->makeAgentPermitting('scenario7-parent', ['scenario7.confirm']);
        $helper = $this->makeAgentPermitting('scenario7-helper', ['scenario7.confirm']);
        $this->assignHelper($parent->id, $helper->id);

        $parentConversation = $this->makeConversation($parent);

        Context::add('eval_run_simulating_tools', true);

        // The task's only viable next operation requires confirmation —
        // the nested run() call pauses on its very first tool call, so
        // exactly one scripted response is ever consumed.
        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([$this->toolCall('execute_operation', ['operationId' => 'scenario7.confirm', 'parameters' => []], 'call_scenario7')]),
        ]);

        $result = $this->delegationServiceWith($service)->delegate(
            $parentConversation->fresh(),
            $helper->id,
            'Delete the thing — the only viable next step requires confirmation.',
            null,
        );

        $this->assertSame('failure', $result['status'] ?? null);
        $this->assertSame(
            'confirmation_required',
            $result['reason'] ?? null,
            'a helper blocked on confirmation must be diagnosable as such, not indistinguishable from producing nothing at all (D5, contracts §4)',
        );
        $this->assertArrayHasKey('output', $result);
        $this->assertNull($result['output'], 'the operation requiring confirmation was never executed, so no output can exist');

        $delegation = Delegation::find($result['delegation_id']);
        $this->assertNotNull($delegation, 'fixture sanity: the delegation must have written a Delegation row');
        $this->assertSame('failed', $delegation->status);
        $this->assertSame('confirmation_required', $delegation->result_reason);
        $this->assertSame('failure', $delegation->result_status);
        $this->assertNull($delegation->result_output);

        // No side effect: the confirm-gated operation itself was paused
        // before ever reaching executeApiCall() (contracts §3's
        // existing, unchanged STATUS_CONFIRM handling in
        // handleExecuteOperation() — the '__requires_confirmation'
        // marker is returned in place of dispatching the call at all).
        // Proven here by the helper conversation's own paused assistant
        // message still carrying pending_confirmation with no
        // tool_results ever recorded for this call.
        $helperConversation = Conversation::find($delegation->helper_conversation_id);
        $pausedMessage = $this->toolCallMessages($helperConversation)->first();
        $this->assertNotNull($pausedMessage);
        $this->assertNotNull(
            $pausedMessage->tool_data['pending_confirmation'] ?? null,
            'fixture sanity: H\'s attempt must genuinely have paused for confirmation, never executed',
        );
        $this->assertArrayHasKey('tool_results', $pausedMessage->tool_data ?? []);
        $this->assertNull(
            $pausedMessage->tool_data['tool_results'],
            'the confirm-gated call must never have produced a tool result — it was never executed',
        );
    }
}
