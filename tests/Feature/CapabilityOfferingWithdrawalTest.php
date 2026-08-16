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
use ClarionApp\LlmClient\Services\CapabilityCatalogMerger;
use ClarionApp\LlmClient\Services\CapabilityOfferingService;
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
 * 109-agent-as-capability, Phase 9 (Polish), tasks.md T059.
 *
 * spec.md Edge Cases: "What happens when an agent that is currently offered
 * as a capability is later withdrawn (no longer offered)? A caller's
 * in-flight call already underway at the moment of withdrawal is allowed to
 * finish or fail on its own terms; the very next attempt by any caller to
 * discover or invoke it no longer finds it listed." This maps to no single
 * user-story phase (US1-US5) above -- it is its own, dedicated scenario
 * (quickstart.md scenario 10).
 *
 * Driven end-to-end through the real, unmodified AgentLoopService::run()
 * against a scripted LlmProvider double (CapabilityAgentCallJourneyTest's
 * own established convention, research.md D1). The withdrawal is triggered
 * from INSIDE the scripted provider's own callback, at the exact moment the
 * offered agent's own nested run is mid-flight -- the underlying
 * Delegation row already exists with status 'in_progress' (written by
 * invokeAsCapability()'s createDelegationRow() call, before
 * runDelegatedTask()'s own nested run() is ever invoked), simulating a
 * withdrawal that lands while the call is genuinely still underway, not
 * merely "before" or "after" it.
 *
 * Belongs in tests/Feature/, never tests/Integration/ (Grounding note 18).
 */
class CapabilityOfferingWithdrawalTest extends TestCase
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
    // Fixture helpers
    // -----------------------------------------------------------------

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
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
        // `type: object` (CapabilityAgentPermissionCompositionTest's own
        // established precedent for this exact cast).
        return $this->plainReply(json_encode([
            'status' => $status,
            'summary' => $summary,
            'output' => (object) $output,
            'undone' => $undone,
        ]));
    }

    // =================================================================
    // T059 -- quickstart scenario 10 / spec.md Edge Cases: withdrawing an
    // offering mid-flight neither disturbs the already-in-progress call
    // nor leaves it discoverable to a fresh lookup afterward.
    // =================================================================

    #[Test]
    public function withdrawing_an_offering_mid_flight_does_not_disturb_the_in_progress_call_and_removes_it_from_future_discovery(): void
    {
        $caller = $this->makeAgent('withdrawal-caller-a');
        $offered = $this->makeAgent('withdrawal-offered-b');

        $offering = $this->offerCapability(
            $offered,
            $caller,
            'summarize_document',
            'Produces a concise summary of a supplied document or block of text.',
            'The document text to summarize.',
        );

        $conversation = $this->makeConversation($caller);

        $withdrawResult = null;
        $delegationStatusAtWithdrawTime = null;

        $responses = [
            // A's own turn: invoke B's capability.
            $this->toolCallReply([
                $this->toolCall('execute_operation', [
                    'operationId' => $offering->id,
                    'parameters' => ['input' => 'Please summarize this quarterly report.'],
                ], 'call_execute_1'),
            ]),
            // B's own nested run's own final answer -- the withdrawal is
            // triggered from INSIDE this closure, i.e. genuinely WHILE B's
            // call is in progress (the Delegation row already exists with
            // status 'in_progress' by the time this closure runs, since
            // createDelegationRow() writes it before runDelegatedTask()'s
            // nested run() is ever invoked).
            $this->delegationResultReply(
                'success',
                'Summarized the quarterly report.',
                ['summary' => 'Q3 was strong across every region.'],
            ),
            // A's own finishing reply, after receiving B's successful,
            // unwrapped output.
            $this->plainReply('Here is the summary you asked for.'),
        ];

        $provider = Mockery::mock(LlmProvider::class);
        $callIndex = 0;
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses, &$callIndex, &$withdrawResult, &$delegationStatusAtWithdrawTime, $offering, $conversation) {
            $callIndex++;

            // The second chat() call is B's own nested run's own first
            // (and only) response -- withdraw the offering right before
            // returning it, simulating a withdrawal landing while the
            // call is genuinely still underway.
            if ($callIndex === 2) {
                $inFlightDelegation = Delegation::where('parent_conversation_id', $conversation->id)
                    ->latest('started_at')
                    ->first();
                $delegationStatusAtWithdrawTime = $inFlightDelegation?->status;

                $withdrawResult = app(CapabilityOfferingService::class)->withdraw(
                    $this->user->id,
                    $offering->offered_agent_id,
                    $offering->caller_agent_id,
                );
            }

            return array_shift($responses);
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $service = new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            presetRegistry: app(\ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry::class),
            metricsRecorder: new \ClarionApp\LlmClient\Services\MetricsRecorder(),
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Please summarize this quarterly report.');

        // The withdrawal genuinely happened WHILE the call was still
        // in_progress (fixture sanity -- otherwise this test would not be
        // proving what it claims to).
        $this->assertSame('in_progress', $delegationStatusAtWithdrawTime, 'fixture sanity: the withdrawal must land while the Delegation row is still in_progress, not before or after');
        $this->assertTrue($withdrawResult, 'fixture sanity: the withdrawal call itself must have succeeded (an active row existed to remove)');

        // The already-in-progress call is undisturbed: it finishes and
        // reports its outcome normally.
        $this->assertSame('completed', $result['status'], 'the top-level turn must still complete normally despite the mid-flight withdrawal');

        $delegation = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegation);
        $this->assertSame('capability_offering', $delegation->origin);
        $this->assertSame('completed', $delegation->status, 'the in-flight call must finish on its own terms, unaffected by the mid-flight withdrawal');
        $this->assertSame('success', $delegation->result_status);

        $toolResultMessage = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data')
            ->orderBy('created_at')
            ->first();
        $this->assertNotNull($toolResultMessage);
        $toolResults = $toolResultMessage->tool_data['tool_results'] ?? [];
        $decoded = json_decode($toolResults[0]['content'] ?? '', true);
        $this->assertSame(
            ['summary' => 'Q3 was strong across every region.'],
            $decoded,
            'the caller must still receive the offered agent\'s real, successful output -- the mid-flight withdrawal must not turn a completing call into a failure',
        );

        // A FRESH discovery/invocation attempt no longer finds it.
        $freshEligible = app(CapabilityCatalogMerger::class)->entriesFor($conversation->fresh());
        $this->assertEmpty(
            $freshEligible,
            'a fresh "Known Operations" build must no longer include the withdrawn offering',
        );

        $secondConversation = $this->makeConversation($caller);
        $secondService = new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            presetRegistry: app(\ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry::class),
            metricsRecorder: new \ClarionApp\LlmClient\Services\MetricsRecorder(),
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
        $freshCallResponses = [
            $this->toolCallReply([
                $this->toolCall('search_operations', ['query' => 'summarize'], 'call_search_after_withdraw'),
            ]),
            $this->plainReply('Nothing matched.'),
        ];
        $secondProvider = Mockery::mock(LlmProvider::class);
        $secondProvider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$freshCallResponses) {
            return array_shift($freshCallResponses);
        });
        $secondProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));
        $secondRegistry = Mockery::mock(ProviderRegistry::class);
        $secondRegistry->shouldReceive('resolve')->andReturn($secondProvider);
        $secondRegistry->shouldReceive('resolveByType')->andReturn($secondProvider);
        $secondService = new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $secondRegistry,
            presetRegistry: app(\ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry::class),
            metricsRecorder: new \ClarionApp\LlmClient\Services\MetricsRecorder(),
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
        $this->app->instance(AgentLoopService::class, $secondService);

        $secondResult = $secondService->run($secondConversation->fresh(), 'Find something that can summarize documents.');
        $this->assertSame('completed', $secondResult['status']);

        $secondToolResultMessage = Message::where('conversation_id', $secondConversation->id)
            ->whereNotNull('tool_data')
            ->orderBy('created_at')
            ->first();
        $this->assertNotNull($secondToolResultMessage);
        $secondToolResults = $secondToolResultMessage->tool_data['tool_results'] ?? [];
        $secondDecoded = json_decode($secondToolResults[0]['content'] ?? '', true);
        $ids = collect($secondDecoded['results'] ?? [])->pluck('operationId')->all();
        $this->assertNotContains(
            $offering->id,
            $ids,
            'a fresh search_operations listing must no longer include the withdrawn offering (spec.md Edge Cases, quickstart scenario 10)',
        );

        // The row itself is soft-deleted, not hard-deleted (SoftDeletes,
        // Constitution §III), preserving FR-020 reconstructability.
        $this->assertNull(CapabilityOffering::find($offering->id));
        $this->assertNotNull(CapabilityOffering::withTrashed()->find($offering->id));
    }
}
