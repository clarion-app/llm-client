<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whatever a triggered run does -- success or failure -- is completely
 * recorded, and that record survives to be read after the fact rather than
 * only living in the run's own in-memory return value. Built on the
 * existing, unmodified RunTraceRecorder/RunTraceQuery/RunController
 * instrumentation (the same mechanism RunDiagramJourneyTest already proves
 * for interactive runs), driven here through a real unattended
 * AgentLoopService::run() call so it is proven for a scheduler-shaped run
 * specifically.
 *
 * Deliberately built as a small, extensible fixture (one agent bound to
 * one conversation, one scripted provider, one action record read back
 * over HTTP) rather than a single monolithic test -- a later story adds
 * more cases to this same file rather than starting a new one.
 */
class ActionRecordCompletenessJourneyTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate every assertion to the bound agent definition's own
        // tools.allow -- the installation-wide ceiling
        // (api_denylist/confirm_methods) is not this file's concern,
        // mirroring every sibling *JourneyTest's own established
        // convention.
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
    // Helpers -- mirrors AdvanceAuthorizationJourneyTest/
    // SchedulerAgentDefinitionTest's own shape exactly.
    // -----------------------------------------------------------------

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

    /**
     * @return array{0: Agent, 1: AgentVersion, 2: Conversation}
     */
    private function bindConversation(string $yaml, string $agentName): array
    {
        $agent = Agent::create([
            'user_id' => $this->user->id,
            'name' => $agentName,
        ]);

        $version = AgentVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 1,
            'raw_definition' => $yaml,
            'content_hash' => hash('sha256', $yaml),
            'source' => AgentChangeSource::Created->value,
            'changed_by_user_id' => $this->user->id,
        ]);

        $agent->update(['current_version_id' => $version->id]);

        $conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'title' => 'Scheduled work',
            'agent_id' => $agent->id,
            'agent_version_id' => $version->id,
        ]);

        return [$agent, $version, $conversation];
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

    /** A plain assistant reply carrying no tool call -- ends the run. */
    private function textResponse(string $text): array
    {
        return ['choices' => [['message' => ['content' => $text, 'tool_calls' => []]]]];
    }

    /** An assistant turn that calls execute_operation once. */
    private function toolCallResponse(string $operationId, array $parameters = []): array
    {
        return $this->multiToolCallResponse([[$operationId, $parameters]]);
    }

    /**
     * An assistant turn that calls execute_operation once per entry in
     * $calls, in a single batch -- the shape a model produces when it
     * requests more than one operation in the same turn. Necessary here
     * rather than two separate single-call turns: AgentLoopService::
     * allExecuteOperationsSucceeded() ends the run as soon as one turn's
     * worth of execute_operation calls all succeed, so a scripted
     * "succeeds, then fails" pair only both actually run when they are
     * requested together.
     *
     * @param list<array{0: string, 1: array}> $calls
     */
    private function multiToolCallResponse(array $calls): array
    {
        $toolCalls = array_map(fn (array $call) => [
            'id' => 'call_'.Str::random(8),
            'type' => 'function',
            'function' => [
                'name' => 'execute_operation',
                'arguments' => json_encode(['operationId' => $call[0], 'parameters' => $call[1] ?? []]),
            ],
        ], $calls);

        return ['choices' => [['message' => ['content' => '', 'tool_calls' => $toolCalls]]]];
    }

    /**
     * One operation dispatches to a successful response, the other to an
     * application-level error -- the "bad argument" shape a real,
     * permitted operation produces when its target rejects the call
     * without the request-level dispatch itself throwing (the same
     * top-level "error" key AgentLoopService::allExecuteOperationsSucceeded()
     * already treats as a failed tool result).
     */
    private function installTwoOperationExecutorDouble(): void
    {
        $executor = Mockery::mock(McpToolExecutor::class);

        $executor->shouldReceive('extractArguments')
            ->with(Mockery::any(), '/api/scheduler/status')
            ->andReturn(['path' => '/api/scheduler/status', 'query' => [], 'body' => []]);
        $executor->shouldReceive('executeHttpCall')
            ->with('GET', '/api/scheduler/status', [], [], Mockery::any())
            ->andReturn([
                'content' => [['type' => 'text', 'text' => 'status: ok']],
                'isError' => false,
            ]);

        $executor->shouldReceive('extractArguments')
            ->with(Mockery::any(), '/api/scheduler/widget')
            ->andReturn(['path' => '/api/scheduler/widget', 'query' => [], 'body' => []]);
        $executor->shouldReceive('executeHttpCall')
            ->with('GET', '/api/scheduler/widget', [], [], Mockery::any())
            ->andReturn([
                'content' => [['type' => 'text', 'text' => json_encode(['error' => 'Invalid widget id: bad-argument'])]],
                'isError' => true,
            ]);

        $this->app->instance(McpToolExecutor::class, $executor);
    }

    private function latestRunFor(Conversation $conversation): ?object
    {
        return DB::table('agent_runs')
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Reads every action across every step of a run purely through the
     * existing, unmodified RunController HTTP endpoints -- steps() then
     * stepActions() per step -- the same two-call shape
     * RunDiagramJourneyTest already establishes as this package's read
     * path for a run's action record. Nothing here inspects run()'s own
     * return value or any in-memory state the run left behind; this is a
     * cold read against only what was persisted.
     */
    private function readEveryActionViaHttp(string $runId): array
    {
        $client = $this->actingAs($this->user, 'api');

        $stepsResponse = $client->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/steps");
        $stepsResponse->assertStatus(200);

        $actions = [];
        foreach ($stepsResponse->json('data') as $step) {
            $actionsResponse = $client->getJson(
                "/api/clarion-app/llm-client/agent-runs/{$runId}/steps/{$step['id']}/actions"
            );
            $actionsResponse->assertStatus(200);
            foreach ($actionsResponse->json('data') as $action) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    // -----------------------------------------------------------------
    // US4, Acceptance Scenarios 1-3: every action taken during a
    // triggered run is recorded -- success and failure alike -- and the
    // record is readable after the fact through the existing endpoints.
    // -----------------------------------------------------------------

    #[Test]
    public function every_action_success_and_failure_alike_is_recorded_and_readable_after_the_fact(): void
    {
        $this->seedOperationCatalog([
            'scheduler.read_status' => ['path' => '/api/scheduler/status', 'method' => 'get', 'summary' => 'Read status'],
            'scheduler.read_widget' => ['path' => '/api/scheduler/widget', 'method' => 'get', 'summary' => 'Read a widget'],
        ]);

        $yaml = "name: two-action-agent\ninstructions: Do the defined work.\ntools:\n  allow:\n    - scheduler.read_status\n    - scheduler.read_widget\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n";

        [, , $conversation] = $this->bindConversation($yaml, 'two-action-agent');

        $this->installTwoOperationExecutorDouble();

        $service = $this->serviceWithScriptedProvider([
            $this->multiToolCallResponse([
                ['scheduler.read_status', []],
                ['scheduler.read_widget', []],
            ]),
            $this->textResponse('One action succeeded and one failed.'),
        ]);

        $result = $service->run($conversation, 'Do the defined work.', ['unattended' => true]);

        // The run itself is not what US4 is about -- FR-012's run-report
        // wording is US5's own, separate guarantee (Phase 7 extends this
        // file for it). A permitted operation's own failure does not by
        // itself stop the run; what must hold here is that the action
        // record -- read fresh, over HTTP -- shows both outcomes plainly.
        $run = $this->latestRunFor($conversation);
        $this->assertNotNull($run, 'the scripted turns above must have produced exactly one run to read back');

        $actions = $this->readEveryActionViaHttp($run->id);

        $toolInvocations = array_values(array_filter(
            $actions,
            fn (array $action) => $action['action_type'] === 'tool_invocation',
        ));

        $this->assertCount(
            2,
            $toolInvocations,
            'both the successful and the failed operation call must appear in the action record; got: '.json_encode($toolInvocations),
        );

        $outcomes = array_column($toolInvocations, 'outcome');
        sort($outcomes);
        $this->assertSame(
            ['failure', 'success'],
            $outcomes,
            'the failed action must be recorded with its own outcome, exactly as plainly as the successful one -- never both reported as success; got: '.json_encode($toolInvocations),
        );

        $failedAction = array_values(array_filter(
            $toolInvocations,
            fn (array $action) => $action['outcome'] === 'failure',
        ))[0];

        $this->assertNotNull(
            $failedAction['failure_reason'] ?? null,
            'a failed action must carry its own reason, not merely a bare "failure" outcome with nothing to reconstruct what happened; got: '.json_encode($failedAction),
        );
        $this->assertStringContainsString(
            'Invalid widget id',
            (string) $failedAction['failure_reason'],
            'the recorded reason must actually name what went wrong, not a generic placeholder; got: '.json_encode($failedAction),
        );

        $successAction = array_values(array_filter(
            $toolInvocations,
            fn (array $action) => $action['outcome'] === 'success',
        ))[0];
        $this->assertArrayHasKey(
            'failure_reason',
            $successAction,
            'the action summary shape must still carry the key for a successful action; got: '.json_encode($successAction),
        );
        $this->assertNull(
            $successAction['failure_reason'],
            'a successful action must not carry a failure reason; got: '.json_encode($successAction),
        );
    }
}
