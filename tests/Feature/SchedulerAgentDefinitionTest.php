<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\SchedulerTriggerRunRefused;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\SchedulerAgentProvisioner;
use ClarionApp\LlmClient\ValueObjects\AgentDefinition;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The scheduler agent's permitted-action set is explicit and narrow by
 * default: scheduler.yaml ships tools.allow: [], so a freshly-provisioned,
 * never-widened scheduler agent performs no operation at all — enforced by
 * the existing, unmodified AgentDefinition::isOperationPermitted() and
 * AgentLoopService::handleExecuteOperation()'s unattended refuse-and-stop
 * branch, the same primitives UnattendedConfirmationRefusalJourneyTest
 * already proves for an arbitrary narrow definition. Widening tools.allow
 * lets through exactly the widened operation; every other operation still
 * refuses.
 *
 * Confirm-or-fix, mirroring ResearchAgentDefinitionTest/
 * DataAgentDefinitionTest/CodingAgentDefinitionTest's own shape: every
 * mechanism this file drives already exists.
 */
class SchedulerAgentDefinitionTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate every assertion to the scheduler agent's own tools.allow
        // — the installation-wide ceiling (api_denylist/confirm_methods)
        // is not this file's concern, mirroring every sibling
        // *AgentDefinitionTest's own established convention.
        $this->app['config']->set('llm-client.confirm_methods', []);
        $this->app['config']->set('llm-client.api_denylist', []);
        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.budget.on_unpriced_model', 'admit_untracked');

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->createSupportingTables();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The real, unattended AgentLoopService::run() path touches these;
     * neither is part of the base TestCase schema bootstrap — mirrors
     * TriggerFiresUnattendedJourneyTest's own identical note.
     */
    private function createSupportingTables(): void
    {
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

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
    }

    /**
     * @param array<string, array{path: string, method: string, summary: string}> $operations
     */
    private function seedOperationCatalog(array $operations): void
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

    private function schedulerDefinition(): AgentDefinition
    {
        return (new AgentDefinitionParser())->parse(
            (string) file_get_contents(__DIR__.'/../../src/Templates/scheduler.yaml'),
        );
    }

    /**
     * Binds a fresh Conversation to a specific AgentVersion via the same
     * two fixed columns (agent_id/agent_version_id)
     * ConversationAgentDefinitionResolver ever reads, mirroring
     * UnattendedConfirmationRefusalJourneyTest's own bindConversation().
     */
    private function conversationBoundTo(Agent $agent, AgentVersion $version): Conversation
    {
        return Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'title' => 'Scheduled work',
            'agent_id' => $agent->id,
            'agent_version_id' => $version->id,
        ]);
    }

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools) use (&$responses) {
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
            runTraceRecorder: app(RunTraceRecorder::class),
        );
    }

    /** A plain assistant reply carrying no tool call — ends the run. */
    private function textResponse(string $text): array
    {
        return ['choices' => [['message' => ['content' => $text, 'tool_calls' => []]]]];
    }

    /** An assistant turn that calls execute_operation once. */
    private function toolCallResponse(string $operationId, array $parameters = []): array
    {
        return ['choices' => [['message' => ['content' => '', 'tool_calls' => [[
            'id' => 'call_'.Str::random(8),
            'type' => 'function',
            'function' => [
                'name' => 'execute_operation',
                'arguments' => json_encode(['operationId' => $operationId, 'parameters' => $parameters]),
            ],
        ]]]]]];
    }

    /** Installs a McpToolExecutor double that fails the test if the API call is ever dispatched. */
    private function installBlockingExecutorDouble(): void
    {
        $executor = Mockery::mock(McpToolExecutor::class);
        $executor->shouldNotReceive('extractArguments');
        $executor->shouldNotReceive('executeHttpCall');
        $this->app->instance(McpToolExecutor::class, $executor);
    }

    // -----------------------------------------------------------------
    // Default: tools.allow is exactly [] (Acceptance Scenario 1)
    // -----------------------------------------------------------------

    #[Test]
    public function scheduler_yaml_default_tools_allow_is_exactly_empty(): void
    {
        $this->seedOperationCatalog([]);

        $this->assertSame(
            [],
            $this->schedulerDefinition()->toolsAllow,
            'scheduler.yaml must ship an empty tools.allow so a fresh scheduler agent can perform no operation until explicitly widened',
        );
    }

    // -----------------------------------------------------------------
    // A freshly-provisioned, never-widened scheduler agent performs no
    // operation at all (Acceptance Scenario 1/3)
    // -----------------------------------------------------------------

    #[Test]
    public function a_freshly_provisioned_never_widened_scheduler_agent_performs_no_operation(): void
    {
        $this->seedOperationCatalog([
            'scheduler.read_status' => ['path' => '/api/scheduler/status', 'method' => 'get', 'summary' => 'Read status'],
        ]);

        $agent = app(SchedulerAgentProvisioner::class)->ensureForUser($this->user->id);
        $version = AgentVersion::findOrFail($agent->current_version_id);
        $conversation = $this->conversationBoundTo($agent, $version);

        Event::fake([SchedulerTriggerRunRefused::class]);

        // Proves "nothing called, nothing read either" directly, rather
        // than only trusting the run's own reported status: the double
        // fails the test outright if the target operation is ever
        // dispatched.
        $this->installBlockingExecutorDouble();

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.read_status'),
            // Only consumed if the refuse-and-stop branch failed to stop
            // the loop outright.
            $this->textResponse('This must never be produced.'),
        ]);

        $result = $service->run($conversation, 'Report on status.', ['unattended' => true]);

        $this->assertSame(
            'stopped_unauthorized',
            $result['status'] ?? null,
            'a scheduler agent with an unwidened (empty) tools.allow must refuse any operation at all; got: '.json_encode($result),
        );
        $this->assertSame('scheduler.read_status', $result['operation_id'] ?? null);

        Event::assertDispatched(SchedulerTriggerRunRefused::class);
    }

    // -----------------------------------------------------------------
    // Widening tools.allow to one operation lets only that operation
    // through (Acceptance Scenario 2/3)
    // -----------------------------------------------------------------

    #[Test]
    public function widening_tools_allow_to_one_operation_lets_only_that_operation_through(): void
    {
        $this->seedOperationCatalog([
            'scheduler.read_status' => ['path' => '/api/scheduler/status', 'method' => 'get', 'summary' => 'Read status'],
            'scheduler.read_other' => ['path' => '/api/scheduler/other', 'method' => 'get', 'summary' => 'Read something else'],
        ]);

        $agent = app(SchedulerAgentProvisioner::class)->ensureForUser($this->user->id);

        $widenedYaml = "format_version: \"1.0\"\n"
            ."name: scheduler\n"
            ."version: \"1\"\n"
            ."instructions: |\n"
            ."  Report on the defined work.\n"
            ."capabilities: []\n"
            ."tools:\n"
            ."  allow:\n"
            ."    - scheduler.read_status\n"
            ."  deny: []\n"
            ."safety:\n"
            ."  confirmation_required: []\n"
            ."  unattended_authorized: []\n"
            ."  denylist: []\n";

        $agent = app(AgentService::class)->update($agent, $this->user->id, $widenedYaml);
        $widenedVersion = AgentVersion::findOrFail($agent->current_version_id);

        $this->assertSame(
            ['scheduler.read_status'],
            (new AgentDefinitionParser())->parse($widenedYaml)->toolsAllow,
            'the widened definition must carry exactly the one operation added, sanity-checking the fixture before driving the loop',
        );

        // In the widened list: proceeds normally.
        $executor = Mockery::mock(McpToolExecutor::class);
        $executor->shouldReceive('extractArguments')->once()->andReturn(['path' => '/api/scheduler/status', 'query' => [], 'body' => []]);
        $executor->shouldReceive('executeHttpCall')->once()->andReturn([
            'content' => [['type' => 'text', 'text' => 'status: ok']],
            'isError' => false,
        ]);
        $this->app->instance(McpToolExecutor::class, $executor);

        $inListConversation = $this->conversationBoundTo($agent, $widenedVersion);
        $inListService = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.read_status'),
            $this->textResponse('Status is ok.'),
        ]);

        $inListResult = $inListService->run($inListConversation, 'Report on status.', ['unattended' => true]);

        $this->assertSame(
            'completed',
            $inListResult['status'] ?? null,
            'the widened operation must be permitted to run to completion; got: '.json_encode($inListResult),
        );

        // Still out of the (widened) list: still refuses. Widening one
        // operation must not widen any other.
        Event::fake([SchedulerTriggerRunRefused::class]);
        $this->installBlockingExecutorDouble();

        $outOfListConversation = $this->conversationBoundTo($agent, $widenedVersion);
        $outOfListService = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.read_other'),
            $this->textResponse('This must never be produced.'),
        ]);

        $outOfListResult = $outOfListService->run($outOfListConversation, 'Report on something else.', ['unattended' => true]);

        $this->assertSame(
            'stopped_unauthorized',
            $outOfListResult['status'] ?? null,
            'an operation outside the widened list must still refuse; got: '.json_encode($outOfListResult),
        );
        $this->assertSame('scheduler.read_other', $outOfListResult['operation_id'] ?? null);

        Event::assertDispatched(SchedulerTriggerRunRefused::class);
    }
}
