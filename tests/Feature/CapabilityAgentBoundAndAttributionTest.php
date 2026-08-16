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
use ClarionApp\LlmClient\Services\DelegationQuery;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 109-agent-as-capability, Phase 6 (US4), tasks.md T039.
 *
 * Confirms the time/cost of a capability-agent call are bounded and
 * attributed (data-model.md §8, research.md D4):
 *
 * (a) `capability_agent.max_seconds` genuinely bounds a capability-agent
 *     call's own nested run() -- an offered agent engineered to never
 *     finish is cut off at that ceiling, translated to the caller as the
 *     ordinary `{"error": "..."}` shape naming what remained incomplete --
 *     and `capability_agent.*`/`delegation.*` are independently tunable,
 *     never cross-contaminating each other's own entry point.
 * (b) `DelegationQuery::costForRun()` rolls up cost incurred at every hop
 *     of a chain regardless of which entry point (delegate_to_helper vs a
 *     capability offering) produced it, attributed to the real
 *     originating user (Grounding note 15 -- costForRun() is already
 *     origin-agnostic, T041 exists to confirm this holds, not to build it).
 *
 * Driven end-to-end through the real, unmocked call chain -- only the
 * LlmProvider collaborator is scripted -- mirroring
 * DelegationBoundExhaustionTest.php's (098) own established time-ceiling
 * pattern and DelegationDepthLimitTest.php's (098) own established
 * costForRun() pattern, both now exercised against a capability-agent call
 * instead of (or alongside) an ordinary delegate_to_helper one. Belongs in
 * tests/Feature/, never tests/Integration/ (Grounding note 18).
 */
class CapabilityAgentBoundAndAttributionTest extends TestCase
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
        Carbon::setTestNow();

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
    // T039(a) -- capability_agent.max_seconds genuinely bounds a
    // capability-agent call, independently of delegation.max_seconds
    // (quickstart scenarios 7+8, US4 AC1/AC2).
    // =================================================================

    #[Test]
    public function a_capability_agent_call_exhausting_capability_agent_max_seconds_stops_at_the_bound_and_the_caller_sees_an_ordinary_error_naming_what_remained_incomplete(): void
    {
        // A generous iteration allowance on both axes -- only the tight
        // 1-second capability_agent deadline may stop this nested run.
        config(['llm-client.capability_agent.max_iterations' => 50]);
        config(['llm-client.capability_agent.max_seconds' => 1]);
        config(['llm-client.agent_loop.max_iterations' => 20]);

        $callerA = $this->makeAgent('t039a1-caller-a');
        $offeredB = $this->makeAgent('t039a1-offered-b');

        $offering = $this->offerCapability(
            $offeredB,
            $callerA,
            'run_long_task',
            'Runs a task engineered to never finish on its own.',
            'What to work on.',
        );

        $conversationA = $this->makeConversation($callerA);

        Carbon::setTestNow(Carbon::now());

        $callCount = 0;
        $service = $this->serviceWithScriptedProvider(function () use (&$callCount, $offering) {
            $callCount++;

            if ($callCount === 1) {
                return $this->toolCallReply([$this->executeOperationCall($offering->id, 'Keep working without ever finishing.', 'call_a_invoke_b')]);
            }

            if ($callCount <= 6) {
                // Every one of these calls advances the faked clock well
                // past the 1-second capability_agent deadline -- a correct
                // implementation's nested offered-agent run must stop after
                // just one or two of these, long before this generous cap
                // of 5 further calls is ever reached.
                Carbon::setTestNow(Carbon::now()->addSeconds(2));

                return $this->toolCallReply([$this->toolCall('list_applications', [], 'call_b_'.$callCount)]);
            }

            return $this->plainReply('A received B\'s partial outcome and is reporting it.');
        });
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversationA->fresh(), 'Please have B run a long task.');
        $this->assertSame('completed', $result['status'], 'the top-level turn itself must still resolve normally even though the capability call was cut off');

        $delegation = Delegation::where('parent_conversation_id', $conversationA->id)->first();
        $this->assertNotNull($delegation, 'fixture sanity: the capability call must have written a Delegation row');
        $this->assertSame('capability_offering', $delegation->origin);
        $this->assertSame('exhausted', $delegation->status, 'the Delegation row must record the exhausted status once capability_agent.max_seconds is reached');
        $this->assertSame('partial', $delegation->result_status);
        $this->assertSame('bound_exceeded', $delegation->result_reason);

        $aToolResult = $this->firstToolResult($conversationA);
        $this->assertNotNull($aToolResult, 'fixture sanity: A must actually have invoked the capability');
        $this->assertSame(
            ['error'],
            array_keys($aToolResult),
            'A must receive the ordinary plain {"error": "..."} shape -- never the raw six-field delegation envelope (FR-016/FR-017)',
        );
        $this->assertStringContainsString(
            'time_limit',
            $aToolResult['error'],
            'the error must name what remained incomplete -- the capability_agent.max_seconds wall-clock ceiling, distinct from an iteration-ceiling stop',
        );
    }

    #[Test]
    public function capability_agent_max_seconds_and_delegation_max_seconds_are_independently_tunable_and_never_cross_contaminate(): void
    {
        // delegation.max_seconds is configured far TIGHTER than what this
        // capability-agent call will actually take -- if invokeAsCapability()
        // were still silently bounding its nested run() with
        // delegation.max_seconds (T040's own regression target), this call
        // would be cut off exhausted; a correct implementation, bounded
        // instead by the far larger capability_agent.max_seconds, must
        // complete normally.
        config(['llm-client.delegation.max_seconds' => 1]);
        config(['llm-client.capability_agent.max_seconds' => 180]);
        config(['llm-client.capability_agent.max_iterations' => 50]);
        config(['llm-client.agent_loop.max_iterations' => 20]);

        $callerA = $this->makeAgent('t039a2-caller-a');
        $offeredB = $this->makeAgent('t039a2-offered-b');

        $offering = $this->offerCapability(
            $offeredB,
            $callerA,
            'run_slow_but_bounded_task',
            'Runs a task that takes a few wall-clock seconds but always finishes.',
            'What to work on.',
        );

        $conversationA = $this->makeConversation($callerA);

        Carbon::setTestNow(Carbon::now());

        $callCount = 0;
        $service = $this->serviceWithScriptedProvider(function () use (&$callCount, $offering) {
            $callCount++;

            if ($callCount === 1) {
                return $this->toolCallReply([$this->executeOperationCall($offering->id, 'Do the slow task, then report back.', 'call_a_invoke_b_slow')]);
            }

            if ($callCount <= 3) {
                // Three rounds, each advancing the faked clock by 2 seconds
                // -- 6 seconds total, comfortably past delegation.max_seconds
                // (1s) but nowhere near capability_agent.max_seconds (180s).
                Carbon::setTestNow(Carbon::now()->addSeconds(2));

                return $this->toolCallReply([$this->toolCall('list_applications', [], 'call_b_slow_'.$callCount)]);
            }

            if ($callCount === 4) {
                return $this->delegationResultReply('success', 'Completed the slow task.', ['summary' => 'Completed the slow task.']);
            }

            return $this->plainReply('B finished the slow task successfully.');
        });
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversationA->fresh(), 'Please have B run the slow-but-bounded task.');
        $this->assertSame('completed', $result['status']);

        $delegation = Delegation::where('parent_conversation_id', $conversationA->id)->first();
        $this->assertNotNull($delegation, 'fixture sanity: the capability call must have written a Delegation row');
        $this->assertSame('capability_offering', $delegation->origin);
        $this->assertSame(
            'completed',
            $delegation->status,
            'the capability-agent call must complete normally, proving capability_agent.max_seconds (180) -- not delegation.max_seconds (1) -- bounds this entry point',
        );
        $this->assertSame('success', $delegation->result_status);

        $aToolResult = $this->firstToolResult($conversationA);
        $this->assertNotNull($aToolResult);
        $this->assertArrayNotHasKey('error', $aToolResult, 'a genuinely completed capability call must never surface an error shape to the caller');
        $this->assertSame(['summary' => 'Completed the slow task.'], $aToolResult, 'a successful capability call returns its raw output, unwrapped');
    }

    // =================================================================
    // T039(b) -- DelegationQuery::costForRun() rolls up cost incurred at
    // EVERY hop of a chain regardless of entry point, attributed to the
    // real originating user (US4 AC3, FR-015, Grounding note 15).
    // =================================================================

    #[Test]
    public function cost_for_run_includes_cost_incurred_at_both_a_delegate_to_helper_hop_and_a_capability_offering_hop_attributed_to_the_real_originating_user(): void
    {
        $agentA = $this->makeAgent('t039b-agent-a');
        $helperH = $this->makeAgent('t039b-helper-h');
        $offeredC = $this->makeAgent('t039b-offered-c');

        // Hop 1: an ordinary delegate_to_helper edge, A -> H.
        $this->assignHelper($agentA->id, $helperH->id);

        // Hop 2: a capability-offering edge, H -> C (H is the caller of
        // C's offered capability).
        $offering = $this->offerCapability(
            $offeredC,
            $helperH,
            'consult_c',
            'Consults C on behalf of whoever delegated to H.',
            'What to ask C.',
        );

        $conversationA = $this->makeConversation($agentA);

        $service = $this->serviceWithScriptedProvider([
            // A's own turn: delegate to H (hop 1).
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => $helperH->id,
                    'task' => 'Handle this, consulting C as needed.',
                    'context' => null,
                ], 'call_a_to_h'),
            ]),
            // H's own nested turn: invoke C's offered capability (hop 2).
            $this->toolCallReply([$this->executeOperationCall($offering->id, 'What should we do here?', 'call_h_to_c')]),
            // C's own finishing reply -- a schema-valid delegation_result,
            // since every nested run() (regardless of which entry point
            // started it) is bound to the same preset.
            $this->delegationResultReply('success', 'Consulted successfully.', ['advice' => 'Proceed as planned.']),
            // H's own finishing reply, after receiving C's translated
            // output -- H's own nested run is itself bound to the
            // delegation_result preset (it was started via
            // delegate_to_helper).
            $this->delegationResultReply('success', 'Handled the task after consulting C.', ['result' => 'done']),
            // A's own finishing reply.
            $this->plainReply('A\'s final answer, relying on H\'s own outcome.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversationA->fresh(), 'Please handle this, consulting C via H as needed.');
        $this->assertSame('completed', $result['status'], 'fixture sanity: the top-level turn must complete');

        $parentRun = DB::table('agent_runs')->where('conversation_id', $conversationA->id)->first();
        $this->assertNotNull($parentRun, 'fixture sanity: the top-level turn must have opened its own run trace');

        $delegationAH = Delegation::where('parent_conversation_id', $conversationA->id)->first();
        $this->assertNotNull($delegationAH, 'fixture sanity: hop 1 (A to H, delegate_to_helper) must have succeeded structurally');
        $this->assertSame('delegate_to_helper', $delegationAH->origin);
        $this->assertSame((string) $this->user->id, (string) $delegationAH->owner_user_id, 'hop 1 must be attributed to the real originating user, never a system pseudo-owner');

        $delegationHC = Delegation::where('parent_conversation_id', $delegationAH->helper_conversation_id)->first();
        $this->assertNotNull($delegationHC, 'fixture sanity: hop 2 (H to C, capability_offering) must have succeeded structurally -- made from inside H\'s own nested run');
        $this->assertSame('capability_offering', $delegationHC->origin);
        $this->assertSame((string) $this->user->id, (string) $delegationHC->owner_user_id, 'hop 2 must ALSO be attributed to the real originating user, even though it was produced by a capability-offering entry point rather than delegate_to_helper');

        // The link the transitive walk actually follows: the
        // capability-offering hop's parent_run_id is the enclosing
        // delegate_to_helper hop's own helper_run_id -- costForRun() has
        // no origin-based branching anywhere in that walk (Grounding note
        // 15), so it reaches this hop regardless of which entry point
        // produced it.
        $this->assertNotNull($delegationAH->helper_run_id);
        $this->assertSame(
            $delegationAH->helper_run_id,
            $delegationHC->parent_run_id,
            'the capability-offering hop must record the enclosing delegate_to_helper hop\'s own helper run as its own parent run -- this is the edge DelegationQuery::costForRun() walks',
        );

        $rollup = app(DelegationQuery::class)->costForRun((string) $this->user->id, $parentRun->id);

        $this->assertSame(
            2,
            $rollup['delegation_count'],
            'both hops must be counted regardless of which entry point produced them -- the direct A-to-H delegate_to_helper hop and the transitively-reached H-to-C capability-offering hop',
        );

        $expectedTokens = (int) DB::table('usage_records')
            ->whereIn('conversation_id', [$delegationAH->helper_conversation_id, $delegationHC->helper_conversation_id])
            ->sum('total_tokens');
        $this->assertGreaterThan(0, $expectedTokens, 'fixture sanity: both nested runs must have recorded real usage');

        $this->assertSame(
            $expectedTokens,
            $rollup['total_tokens'],
            'the rollup must sum cost incurred at EVERY hop regardless of which entry point produced it (FR-014/FR-015) -- not only the directly-delegated delegate_to_helper hop, and not skipping the capability-offering hop',
        );
    }
}
