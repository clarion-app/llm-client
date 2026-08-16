<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 109-agent-as-capability, Phase 3 (US1 MVP), tasks.md T022.
 *
 * US1's own Independent Test / quickstart scenario 1: agent B is offered as
 * a capability ("summarize_document") to agent A. Driven end-to-end through
 * the real, unmodified AgentLoopService::run() against a scripted
 * LlmProvider double (DelegationJourneyTest's own established convention,
 * research.md D1) -- never Http::fake(), since the point is to exercise the
 * real "Known Operations" build, search_operations, execute_operation
 * dispatch, and the nested DelegationService::invokeAsCapability() call
 * without a live provider.
 *
 * Belongs in tests/Feature/, never tests/Integration/, per this project's
 * NoMocksGuardTest convention (Grounding note 18).
 *
 * Written before CapabilityCatalogMerger/invokeAsCapability() exist --
 * every test below is expected to FAIL red.
 */
class CapabilityAgentCallJourneyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        $server = \ClarionApp\LlmClient\Models\Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
        $this->server = $server;

        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $server->id, 'test-model');

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

    private \ClarionApp\LlmClient\Models\Server $server;

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
        return $this->plainReply(json_encode([
            'status' => $status,
            'summary' => $summary,
            'output' => $output,
            'undone' => $undone,
        ]));
    }

    // =================================================================
    // T022 -- discovery on the very first turn (no prior search needed)
    // =================================================================

    #[Test]
    public function an_offered_agent_appears_in_known_operations_on_the_very_first_turn_with_no_prior_search(): void
    {
        $caller = $this->makeAgent('caller-agent-first-turn');
        $offered = $this->makeAgent('summarizer-agent-first-turn');

        $offering = $this->offerCapability(
            $offered,
            $caller,
            'summarize_document',
            'Produces a concise summary of a supplied document or block of text.',
            'The document text to summarize.',
        );

        $conversation = $this->makeConversation($caller);

        // buildKnownOperationsSection() is private; drive it the same way
        // the real system prompt build does -- through a real run() call --
        // and assert on the SYSTEM message actually sent to the provider.
        $capturedMessages = null;
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$capturedMessages) {
            $capturedMessages ??= $messages;
            return $this->plainReply('Hello! How can I help?');
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
            metricsRecorder: new \ClarionApp\LlmClient\Services\MetricsRecorder(),
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Hi there.');
        $this->assertSame('completed', $result['status'], 'fixture sanity: the turn must complete');

        $this->assertNotNull($capturedMessages, 'fixture sanity: the provider must have been called with a messages payload');
        $systemMessage = collect($capturedMessages)->firstWhere('role', 'system');
        $this->assertNotNull($systemMessage, 'fixture sanity: a system message must exist');

        $this->assertStringContainsString(
            '## Known Operations',
            $systemMessage['content'],
            'a "Known Operations" section must exist even though the operationCache has never been populated by a real search/execution (research.md D2)',
        );
        $this->assertStringContainsString(
            $offering->id,
            $systemMessage['content'],
            'the offered agent\'s synthetic operationId must appear in Known Operations on the very FIRST turn, with no prior search_operations call (Acceptance Scenario 1)',
        );
        $this->assertStringContainsString(
            'Produces a concise summary of a supplied document or block of text.',
            $systemMessage['content'],
        );
    }

    // =================================================================
    // T022 -- search_operations also returns the offering
    // =================================================================

    #[Test]
    public function search_operations_also_returns_the_offered_agent(): void
    {
        $caller = $this->makeAgent('caller-agent-search');
        $offered = $this->makeAgent('summarizer-agent-search');

        $offering = $this->offerCapability(
            $offered,
            $caller,
            'summarize_document',
            'Produces a concise summary of a supplied document or block of text.',
            'The document text to summarize.',
        );

        $conversation = $this->makeConversation($caller);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('search_operations', ['query' => 'summarize'], 'call_search_1'),
            ]),
            $this->plainReply('Found it.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Find something that can summarize documents.');
        $this->assertSame('completed', $result['status'], 'fixture sanity: the turn must complete');

        $toolResultMessage = \ClarionApp\LlmClient\Models\Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data')
            ->orderBy('created_at')
            ->first();
        $this->assertNotNull($toolResultMessage);
        $toolResults = $toolResultMessage->tool_data['tool_results'] ?? [];
        $this->assertNotEmpty($toolResults, 'fixture sanity: the search_operations call must have produced a tool result');

        $decoded = json_decode($toolResults[0]['content'] ?? '', true);
        $this->assertIsArray($decoded);
        $results = $decoded['results'] ?? [];

        $ids = collect($results)->pluck('operationId')->all();
        $this->assertContains($offering->id, $ids, 'search_operations must also return the offered agent (data-model.md §5)');

        $matched = collect($results)->firstWhere('operationId', $offering->id);
        $this->assertIsArray($matched);
        $this->assertSame('AGENT', $matched['method'] ?? null);
        $this->assertArrayHasKey('path', $matched);
        $this->assertNull($matched['path']);
    }

    // =================================================================
    // T022 -- execute_operation invokes the offered agent, unwrapped result
    // =================================================================

    #[Test]
    public function invoking_the_offered_agent_via_execute_operation_returns_its_raw_output_unwrapped(): void
    {
        $caller = $this->makeAgent('caller-agent-invoke');
        $offered = $this->makeAgent('summarizer-agent-invoke');

        $offering = $this->offerCapability(
            $offered,
            $caller,
            'summarize_document',
            'Produces a concise summary of a supplied document or block of text.',
            'The document text to summarize.',
        );

        $conversation = $this->makeConversation($caller);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('execute_operation', [
                    'operationId' => $offering->id,
                    'parameters' => ['input' => 'Please summarize this quarterly report.'],
                ], 'call_execute_1'),
            ]),
            // The offered agent's own nested run() -- its scripted final
            // answer, matching the mandatory delegation_result schema.
            $this->delegationResultReply(
                'success',
                'Summarized the quarterly report.',
                ['summary' => 'Q3 was strong across every region.'],
            ),
            $this->plainReply('Here is the summary you asked for.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation->fresh(), 'Please summarize this quarterly report.');
        $this->assertSame('completed', $result['status'], 'fixture sanity: the turn must complete');

        $toolResultMessage = \ClarionApp\LlmClient\Models\Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data')
            ->orderBy('created_at')
            ->first();
        $this->assertNotNull($toolResultMessage);
        $toolResults = $toolResultMessage->tool_data['tool_results'] ?? [];
        $this->assertNotEmpty($toolResults, 'fixture sanity: the execute_operation call must have produced a tool result');

        $decoded = json_decode($toolResults[0]['content'] ?? '', true);

        $this->assertIsArray($decoded, 'the tool result must be the offered agent\'s raw output, decodable JSON');
        $this->assertSame(
            ['summary' => 'Q3 was strong across every region.'],
            $decoded,
            'execute_operation must return the offered agent\'s raw output, unwrapped -- byte-shape-identical to any other capability\'s successful result (FR-005)',
        );

        foreach (['status', 'helper', 'delegation_id', 'reason'] as $forbiddenField) {
            $this->assertArrayNotHasKey(
                $forbiddenField,
                $decoded,
                "no delegation envelope field (\"{$forbiddenField}\") may appear anywhere in the tool result (contracts/capability-agent-call.md)",
            );
        }

        // A Delegation row still exists underneath, for after-the-fact
        // reconstruction (FR-020) -- but it must never leak back into the
        // calling agent's own turn content.
        $delegationRow = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($delegationRow, 'a Delegation row must still exist underneath for FR-020 reconstruction');
        $this->assertSame('capability_offering', $delegationRow->origin);
        $this->assertSame('completed', $delegationRow->status);

        $this->assertStringNotContainsString(
            $offered->name,
            $result['content'],
            'the caller\'s own final answer must never name the offered agent -- FR-003 forbids any tell, including the auto-generated delegation-disclosure sentence',
        );
        $this->assertStringNotContainsString(
            'delegated',
            $result['content'],
            'the caller\'s own final answer must never mention delegation at all',
        );
    }
}
