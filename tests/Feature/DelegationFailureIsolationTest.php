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
use ClarionApp\LlmClient\Services\DelegationService;
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
 * 098-delegation-protocol, Phase 6 (US5), tasks.md T046.
 *
 * Mirrors DelegationJourneyTest.php's / DelegationBoundExhaustionTest.php's
 * own established scripted-`LlmProvider` driving pattern (research.md D1 --
 * never Http::fake()). Three independent scenarios:
 *
 * (a) Quickstart scenario 8 (US5 AC1) -- the helper's own nested run()
 *     call throws (a provider error). Driven over the REAL
 *     `POST /agent` HTTP endpoint (AgentController), since the scenario
 *     is specifically about the PARENT's own HTTP response staying 200
 *     -- DelegationJourneyTest's own convention of calling
 *     `$service->run()` directly would not actually prove that. Written
 *     before delegate() catches the nested run()'s exception (T047) --
 *     today it propagates straight through delegate() and
 *     handleDelegateToHelper(), out through executeMetaTool(), into
 *     AgentLoopService::run()'s OWN top-level catch (\Throwable $e)
 *     (Grounding note item 1, L1279-1309), which closes the PARENT's own
 *     run as Failed and rethrows -- AgentController's own outer catch
 *     then turns that into a 500. Expected to FAIL: `assertStatus(200)`
 *     will see 500 instead.
 *
 * (b) Quickstart scenario 9 (US5 AC2, research.md D11) -- the helper's
 *     own mocked model response explicitly states a missing-information
 *     gap. This exercises the ORDINARY SUCCESS PATH (Phase 3, already
 *     implemented) with a specific model output, not a new code path --
 *     proving the passthrough guarantee (D11) holds. Expected to PASS
 *     already: nothing in delegate()'s existing success branch alters,
 *     censors, or post-processes the helper's own final content in any
 *     way before it lands in the tool result's `result` field.
 *
 * (c) Quickstart scenario 11 (Edge Case) -- a delegation's nested run is
 *     still in progress when the helper Agent row is deactivated
 *     DIRECTLY (bypassing AgentService::deactivate()'s own
 *     last-active-agent guard, simulating "some other process retired
 *     this helper concurrently"). The in-flight delegation's own
 *     AgentVersion binding was already frozen onto the helper
 *     Conversation at delegation start (data-model.md, T018 step 3), and
 *     nothing in AgentLoopService::run() re-checks the bound agent's
 *     own is_active mid-loop (confirmed by grep -- the only is_active
 *     check anywhere in AgentLoopService.php belongs to
 *     handleHandoffToAgent(), an entirely different code path) -- so
 *     this scenario is expected to PASS already too: it does not depend
 *     on T047's new catch block at all, only on the pre-existing,
 *     already-implemented "resolve once, freeze, never re-check
 *     mid-flight" design from Phase 3. The SECOND half of this scenario
 *     -- a subsequent, fresh delegate_to_helper call against the same,
 *     now-deactivated helper being refused `not_an_assigned_helper` --
 *     also already passes, since that re-check (T018's own
 *     `$helperAgent->is_active === false` guard) was never conditional
 *     on this feature's failure-catching at all.
 */
class DelegationFailureIsolationTest extends TestCase
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

    private function assignHelper(string $parentAgentId, string $helperAgentId): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson($this->helpersUrl($parentAgentId), ['helper_agent_id' => $helperAgentId])
            ->assertStatus(201);
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
    // Scripted-provider scaffolding (DelegationJourneyTest's /
    // DelegationBoundExhaustionTest's own established precedent,
    // research.md D1)
    // -----------------------------------------------------------------

    private function serviceWithScriptedProvider(array|callable $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);

        if (is_array($responses)) {
            $provider->shouldReceive('chat')->andReturnUsing(function () use (&$responses) {
                return array_shift($responses);
            });
        } else {
            $provider->shouldReceive('chat')->andReturnUsing($responses);
        }

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

    private function firstToolResult(Conversation $conversation): ?array
    {
        $toolResultMessage = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data')
            ->orderBy('created_at')
            ->first();

        if ($toolResultMessage === null) {
            return null;
        }

        $toolResults = $toolResultMessage->tool_data['tool_results'] ?? [];
        if (empty($toolResults)) {
            return null;
        }

        return json_decode($toolResults[0]['content'] ?? '', true);
    }

    // =================================================================
    // (a) Quickstart scenario 8 (US5 AC1) -- the helper's own nested
    // run() call throws; the PARENT's own HTTP response must still be
    // 200, and the turn must continue normally with a delegate_to_helper
    // tool result of status: "failed".
    // =================================================================

    #[Test]
    public function a_helpers_thrown_exception_is_caught_the_parents_http_response_stays_200_and_the_delegate_tool_result_reports_status_failed(): void
    {
        $parent = $this->makeAgent('parent-agent-failure-a');
        $helper = $this->makeAgent('helper-agent-failure-a');
        $this->assignHelper($parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        $callCount = 0;
        $service = $this->serviceWithScriptedProvider(function () use (&$callCount, $helper) {
            $callCount++;

            if ($callCount === 1) {
                // Parent's own first iteration: delegate immediately.
                return $this->toolCallReply([
                    $this->toolCall('delegate_to_helper', [
                        'helper_agent_id' => $helper->id,
                        'task' => 'Do something that will fail partway through.',
                        'context' => null,
                    ], 'call_delegate_failure_a'),
                ]);
            }

            if ($callCount === 2) {
                // The helper's own nested run() call -- a genuine
                // provider error, mid-delegation.
                throw new \RuntimeException('The provider connection was reset unexpectedly.');
            }

            // Parent's own next iteration, after the delegate_to_helper
            // tool result comes back reporting the failure.
            return $this->plainReply('The helper ran into a problem, but here is what I can tell you anyway.');
        });
        $this->app->instance(AgentLoopService::class, $service);

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Please delegate this task.',
            'conversation_id' => $conversation->id,
        ]);

        $response->assertStatus(200);
        $this->assertSame(
            'completed',
            $response->json('status'),
            'a helper\'s own thrown exception must not crash the parent\'s own turn -- it must still resolve normally (FR-008/SC-004)',
        );

        $decoded = $this->firstToolResult($conversation);
        $this->assertNotNull($decoded, 'fixture sanity: the delegating iteration must have produced a tool result');
        $this->assertSame(
            'failed',
            $decoded['status'] ?? null,
            'the delegate_to_helper tool result must report status: failed when the helper\'s own nested run throws (research.md D5)',
        );
        $this->assertSame($helper->name, $decoded['helper'] ?? null);
        $this->assertArrayHasKey('error', $decoded);

        $delegationRow = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegationRow, 'a failed delegation must still write a Delegation row');
        $this->assertSame('failed', $delegationRow->status);
    }

    // =================================================================
    // (b) Quickstart scenario 9 (US5 AC2, research.md D11) -- the
    // helper's own stated missing-information gap must survive
    // completely untouched to the delegate_to_helper tool result's own
    // result field. Ordinary success path -- expected to PASS already.
    // =================================================================

    #[Test]
    public function a_helpers_stated_missing_information_gap_survives_completely_untouched_to_the_delegate_tool_results_result_field(): void
    {
        $parent = $this->makeAgent('parent-agent-gap-b');
        $helper = $this->makeAgent('helper-agent-gap-b');
        $this->assignHelper($parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        $gapStatement = 'I am missing the exact invoice number needed to complete this extraction '
            .'-- please provide it so I can proceed rather than guessing.';

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $helper->id,
                    'task' => 'Extract the invoice line items.',
                    'context' => 'A scanned invoice image is attached.',
                ], 'call_delegate_gap_b'),
            ]),
            $this->plainReply($gapStatement),
            $this->plainReply('The helper reported it is missing information: '.$gapStatement),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Please extract the invoice line items.');
        $this->assertSame('completed', $result['status'], 'fixture sanity: the parent turn itself must complete');

        $decoded = $this->firstToolResult($conversation);
        $this->assertNotNull($decoded, 'fixture sanity: the delegating iteration must have produced a tool result');
        $this->assertSame(
            'completed',
            $decoded['status'] ?? null,
            'fixture sanity: the delegation itself completes -- the helper CHOOSING to report a gap is ordinary model content, not a distinct code path (research.md D11)',
        );
        $this->assertSame(
            $gapStatement,
            $decoded['result'] ?? null,
            'the helper\'s own stated missing-information gap must survive completely untouched through to the delegate_to_helper tool result\'s result field -- no post-processing, truncation, or rewriting may alter it (FR-009, research.md D11)',
        );
    }

    // =================================================================
    // (c) Quickstart scenario 11 (Edge Case) -- an in-flight delegation
    // whose helper is deactivated mid-run must still complete/exhaust/
    // fail normally (no hang, no crash), and a SUBSEQUENT delegation
    // attempt to the same, now-retired helper is refused the same way
    // as scenario 10 (Phase 3).
    // =================================================================

    #[Test]
    public function an_in_flight_delegation_whose_helper_is_deactivated_mid_run_still_completes_and_a_subsequent_delegation_is_then_refused(): void
    {
        $parent = $this->makeAgent('parent-agent-retired-c');
        $helper = $this->makeAgent('helper-agent-retired-c');
        $this->assignHelper($parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        $callCount = 0;
        $service = $this->serviceWithScriptedProvider(function () use (&$callCount, $helper) {
            $callCount++;

            if ($callCount === 1) {
                // Parent's own first iteration: delegate immediately.
                return $this->toolCallReply([
                    $this->toolCall('delegate_to_helper', [
                        'helper_agent_id' => $helper->id,
                        'task' => 'Do a multi-step task.',
                        'context' => null,
                    ], 'call_delegate_retired_c'),
                ]);
            }

            if ($callCount === 2) {
                // The helper's own nested run, still mid-flight -- its
                // AgentVersion binding was already frozen onto the
                // helper Conversation when delegate() created it, so
                // deactivating the Agent row DIRECTLY here (bypassing
                // AgentService::deactivate()'s own guard, simulating a
                // concurrent retirement) must not disrupt an
                // already-started delegation (research.md D8's own
                // scoping -- only a FRESH delegate_to_helper call
                // re-checks is_active). is_active is intentionally not
                // in Agent::$fillable (mass assignment is never how this
                // column is meant to be written), so this must be a
                // direct attribute set + save(), matching
                // AgentService::deactivate()'s own internal pattern --
                // not update(), which would silently no-op it.
                $helper->is_active = false;
                $helper->save();

                return $this->toolCallReply([$this->toolCall('list_applications', [], 'call_helper_mid_c')]);
            }

            if ($callCount === 3) {
                // Helper's own final answer, completing the nested run
                // despite having been retired mid-flight.
                return $this->plainReply('Task completed despite being retired mid-flight.');
            }

            // Parent's own next iteration, after the tool result comes back.
            return $this->plainReply('Here is the outcome of the delegated work.');
        });
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Please delegate a multi-step task.');

        $this->assertSame(
            'completed',
            $result['status'],
            'the parent turn itself must resolve normally -- no hang, no crash -- even though the helper was deactivated mid-flight',
        );

        $decoded = $this->firstToolResult($conversation);
        $this->assertNotNull($decoded, 'fixture sanity: the delegating iteration must have produced a tool result');
        $this->assertContains(
            $decoded['status'] ?? null,
            ['completed', 'exhausted', 'failed'],
            'an in-flight delegation whose helper is deactivated mid-run must still reach ONE of its ordinary terminal outcomes -- never hang, never crash',
        );

        $delegationRow = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegationRow);
        $this->assertContains($delegationRow->status, ['completed', 'exhausted', 'failed']);

        // A SUBSEQUENT delegation attempt to the same, now-retired
        // helper must be refused identically to quickstart scenario 10
        // (Phase 3) -- is_active is re-checked fresh on every NEW
        // delegate_to_helper call, regardless of what any earlier,
        // already-in-flight delegation did.
        $secondResult = app(DelegationService::class)->delegate(
            $conversation->fresh(),
            $helper->id,
            'Try to delegate again to the now-retired helper.',
            null,
        );

        $this->assertSame(
            'not_an_assigned_helper',
            $secondResult['error'] ?? null,
            'a subsequent delegation attempt to the same, now-deactivated helper must be refused the same way as an unassigned helper (research.md D8)',
        );
    }

    // =================================================================
    // (d) A nested run() that returns WITHOUT throwing and WITHOUT
    // completing -- run()'s own 'No response from LLM' early return (a
    // provider reply carrying no choices), which has no `code` and so
    // matches neither the completion branch nor either ceiling branch.
    // It is still a non-completion and must reach the parent as one
    // (FR-008/SC-004), with the Delegation row reaching a terminal state
    // (data-model.md §1) rather than being left in_progress forever.
    // =================================================================

    #[Test]
    public function a_helper_run_that_returns_a_non_completion_without_throwing_is_reported_failed_and_the_row_reaches_a_terminal_state(): void
    {
        $parent = $this->makeAgent('parent-agent-noresult-d');
        $helper = $this->makeAgent('helper-agent-noresult-d');
        $this->assignHelper($parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        $callCount = 0;
        $service = $this->serviceWithScriptedProvider(function () use (&$callCount, $helper) {
            $callCount++;

            if ($callCount === 1) {
                // Parent's own first iteration: delegate immediately.
                return $this->toolCallReply([
                    $this->toolCall('delegate_to_helper', [
                        'helper_agent_id' => $helper->id,
                        'task' => 'Do something the provider will answer emptily.',
                        'context' => null,
                    ], 'call_delegate_noresult_d'),
                ]);
            }

            if ($callCount === 2) {
                // The helper's own nested run(): a provider reply with no
                // `choices` at all. run() returns ['status' => 'error',
                // 'content' => 'No response from LLM'] -- no exception, and
                // no `code` either.
                return ['choices' => []];
            }

            // Parent's own next iteration, after the tool result comes back.
            return $this->plainReply('The helper came back empty-handed, but here is what I can say.');
        });
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Please delegate this task.');
        $this->assertSame('completed', $result['status'], 'the parent\'s own turn must still resolve normally');

        $decoded = $this->firstToolResult($conversation);
        $this->assertNotNull($decoded, 'fixture sanity: the delegating iteration must have produced a tool result');
        $this->assertSame(
            'failed',
            $decoded['status'] ?? null,
            'a helper run that ends without producing a result must be reported to the parent as a failure -- never dressed up as status: completed with the failure text as its result (FR-008/SC-004)',
        );
        $this->assertArrayNotHasKey(
            'result',
            $decoded,
            'a non-completion must never carry a `result` field -- the contract\'s failure shape carries `error`',
        );

        $delegationRow = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegationRow);
        $this->assertSame(
            'failed',
            $delegationRow->status,
            'the Delegation row must reach a terminal state -- data-model.md §1 allows no path that leaves it in_progress once delegate() has returned',
        );
        $this->assertNotNull(
            $delegationRow->completed_at,
            'a terminal Delegation row must carry completed_at',
        );
    }

    // =================================================================
    // (e) A delegation whose helper run THREW must still link the run it
    // opened -- data-model.md §1 scopes helper_run_id's own nullability
    // to "run tracing is disabled", not to "the helper failed", and
    // FR-012 wants a failed delegation just as recoverable as a
    // completed one.
    // =================================================================

    #[Test]
    public function a_failed_delegation_still_links_the_helper_run_it_opened(): void
    {
        if (!config('llm-client.run_trace.enabled', false)) {
            $this->markTestSkipped('run tracing is disabled in this environment, so there is no helper run id to link');
        }

        $parent = $this->makeAgent('parent-agent-failedlink-e');
        $helper = $this->makeAgent('helper-agent-failedlink-e');
        $this->assignHelper($parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        $callCount = 0;
        $service = $this->serviceWithScriptedProvider(function () use (&$callCount, $helper) {
            $callCount++;

            if ($callCount === 1) {
                return $this->toolCallReply([
                    $this->toolCall('delegate_to_helper', [
                        'helper_agent_id' => $helper->id,
                        'task' => 'Do something that will fail partway through.',
                        'context' => null,
                    ], 'call_delegate_failedlink_e'),
                ]);
            }

            if ($callCount === 2) {
                throw new \RuntimeException('The provider connection was reset unexpectedly.');
            }

            return $this->plainReply('The helper ran into a problem.');
        });
        $this->app->instance(AgentLoopService::class, $service);

        $service->run($conversation->fresh(), 'Please delegate this task.');

        $delegationRow = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegationRow);
        $this->assertSame('failed', $delegationRow->status);

        $helperRun = DB::table('agent_runs')
            ->where('conversation_id', $delegationRow->helper_conversation_id)
            ->first();
        $this->assertNotNull($helperRun, 'fixture sanity: the helper\'s own run must have been opened before it threw');

        $this->assertSame(
            $helperRun->id,
            $delegationRow->helper_run_id,
            'a failed delegation must still point at the helper run it opened, so the failure is as recoverable after the fact as a success is (FR-012)',
        );
    }
}
