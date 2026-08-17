<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\SchedulerTriggerRunRefused;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
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
 * A destructive (confirmation-required) action never proceeds unattended
 * on the strength of a live confirmation prompt -- there is nobody present
 * to answer one. It proceeds only when the exact operation was granted
 * advance authorization, before the run began, via
 * safety.unattended_authorized. Without that grant, the run stops rather
 * than pausing to wait for an answer nobody will give.
 *
 * Confirm-or-fix, mirroring UnattendedConfirmationRefusalJourneyTest's and
 * SchedulerAgentDefinitionTest's own shape: AgentDefinition::isUnattendedAuthorized()
 * and AgentLoopService::handleExecuteOperation()'s unattended STATUS_CONFIRM
 * branch already exist. This file's job is to prove the two-part guarantee
 * end to end -- refused-then-changed, across a single re-triggered
 * operation -- not to add anything new.
 */
class AdvanceAuthorizationJourneyTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate every assertion to the bound agent definition's own
        // safety.unattended_authorized -- the installation-wide ceiling
        // (api_denylist/confirm_methods) is not this file's concern,
        // mirroring every sibling *JourneyTest's own established
        // convention for isolating exactly what each test means to prove.
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
     * execute_operation's real path (executeApiCall -> getOrCreateSession)
     * touches mcp_sessions, which is not part of the base TestCase schema
     * bootstrap -- mirrors UnattendedConfirmationRefusalJourneyTest's own
     * identical note.
     */
    private function createSupportingTables(): void
    {
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
     * Creates one Agent + one AgentVersion carrying $yaml, bound to a
     * fresh Conversation via the same two fixed columns
     * (agent_id/agent_version_id) UnattendedConfirmationRefusalJourneyTest's
     * own bindConversation() uses.
     *
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
        return ['choices' => [['message' => ['content' => '', 'tool_calls' => [[
            'id' => 'call_'.Str::random(8),
            'type' => 'function',
            'function' => [
                'name' => 'execute_operation',
                'arguments' => json_encode(['operationId' => $operationId, 'parameters' => $parameters]),
            ],
        ]]]]]];
    }

    /**
     * The mocked McpToolExecutor is this suite's boundary standing in for
     * the target application -- fails the test outright if the destructive
     * call is ever dispatched, the same proxy for "target data unchanged"
     * SchedulerAgentDefinitionTest's own installBlockingExecutorDouble()
     * establishes.
     */
    private function installBlockingExecutorDouble(): void
    {
        $executor = Mockery::mock(McpToolExecutor::class);
        $executor->shouldNotReceive('extractArguments');
        $executor->shouldNotReceive('executeHttpCall');
        $this->app->instance(McpToolExecutor::class, $executor);
    }

    /**
     * Asserts the destructive call is dispatched exactly once -- the proxy
     * for "target data changed" on this boundary, the same shape
     * UnattendedConfirmationRefusalJourneyTest's own
     * installPermissiveExecutorDouble() establishes.
     */
    private function installDestructiveExecutorDoubleExpectingOneCall(): void
    {
        $executor = Mockery::mock(McpToolExecutor::class);
        $executor->shouldReceive('extractArguments')->once()->andReturn(['path' => '/api/scheduler/widget', 'query' => [], 'body' => []]);
        $executor->shouldReceive('executeHttpCall')->once()->andReturn([
            'content' => [['type' => 'text', 'text' => 'widget destroyed']],
            'isError' => false,
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

    private function anyPendingConfirmationMessageExists(): bool
    {
        return Message::whereNotNull('tool_data->pending_confirmation')->exists();
    }

    // -----------------------------------------------------------------
    // Story 3, Acceptance Scenario 1/2: not pre-authorized refuses without
    // a prompt; pre-authorized proceeds without ever raising one
    // -----------------------------------------------------------------

    #[Test]
    public function a_destructive_action_proceeds_only_when_pre_authorized_and_never_via_a_live_prompt(): void
    {
        $this->seedOperationCatalog([
            'scheduler.destroy_widget' => ['path' => '/api/scheduler/widget', 'method' => 'delete', 'summary' => 'Destroy a widget'],
        ]);

        // --- Part 1: in tools.allow, confirmation-required, but NOT in
        // safety.unattended_authorized. ---
        $notAuthorizedYaml = "name: not-authorized-agent\ninstructions: Do the defined work.\ntools:\n  allow:\n    - scheduler.destroy_widget\nsafety:\n  confirmation_required:\n    - scheduler.destroy_widget\n  unattended_authorized: []\n";

        [, , $notAuthorizedConversation] = $this->bindConversation($notAuthorizedYaml, 'not-authorized-agent');

        Event::fake([SchedulerTriggerRunRefused::class]);
        $this->installBlockingExecutorDouble();

        $notAuthorizedService = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.destroy_widget'),
            // Only consumed if the refuse-and-stop branch failed to stop
            // the loop outright and instead paused or fed the rejection
            // back to the model.
            $this->textResponse('This must never be produced.'),
        ]);

        $notAuthorizedResult = $notAuthorizedService->run($notAuthorizedConversation, 'Do the defined work.', ['unattended' => true]);

        $this->assertSame(
            'stopped_unauthorized',
            $notAuthorizedResult['status'] ?? null,
            'a destructive action in tools.allow but not in safety.unattended_authorized must stop the run unattended, never proceed and never wait for a live answer; got: '.json_encode($notAuthorizedResult),
        );
        $this->assertSame('scheduler.destroy_widget', $notAuthorizedResult['operation_id'] ?? null);

        $notAuthorizedRun = $this->latestRunFor($notAuthorizedConversation);
        $this->assertNotNull($notAuthorizedRun);
        $this->assertSame(RunEndState::StoppedEarly->value, $notAuthorizedRun->end_state);

        // No pending_confirmation message was ever created on this run --
        // the trace itself proves no prompt was ever raised, not merely
        // that nobody answered one.
        $this->assertFalse(
            $this->anyPendingConfirmationMessageExists(),
            'an unauthorized destructive action must never leave a pending_confirmation message behind -- there is no one present to answer it',
        );

        Event::assertDispatched(SchedulerTriggerRunRefused::class, function ($event) use ($notAuthorizedConversation) {
            return $event->userId === (string) $notAuthorizedConversation->user_id;
        });

        // installBlockingExecutorDouble() above already fails the test
        // outright if executeHttpCall was ever dispatched -- reaching this
        // point is itself the "target data unchanged" proof.

        // --- Part 2: the same operation, now granted advance
        // authorization, re-triggered. ---
        Event::fake([SchedulerTriggerRunRefused::class]);

        $authorizedYaml = "name: authorized-agent\ninstructions: Do the defined work.\ntools:\n  allow:\n    - scheduler.destroy_widget\nsafety:\n  confirmation_required:\n    - scheduler.destroy_widget\n  unattended_authorized:\n    - scheduler.destroy_widget\n";

        [, , $authorizedConversation] = $this->bindConversation($authorizedYaml, 'authorized-agent');

        $this->installDestructiveExecutorDoubleExpectingOneCall();

        $authorizedService = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.destroy_widget'),
            // Only reached once pre-authorization actually lets the call
            // through and the loop continues to a second model turn.
            $this->textResponse('Widget destroyed as instructed.'),
        ]);

        $authorizedResult = $authorizedService->run($authorizedConversation, 'Do the defined work.', ['unattended' => true]);

        $this->assertSame(
            'completed',
            $authorizedResult['status'] ?? null,
            'the same operation, once pre-authorized in safety.unattended_authorized, must complete without ever pausing for confirmation; got: '.json_encode($authorizedResult),
        );

        $authorizedRun = $this->latestRunFor($authorizedConversation);
        $this->assertNotNull($authorizedRun);
        $this->assertSame(RunEndState::Completed->value, $authorizedRun->end_state);

        // installDestructiveExecutorDoubleExpectingOneCall() above already
        // requires executeHttpCall to have been dispatched exactly once --
        // that dispatch is the "target data changed" proof on this
        // boundary.

        // Still, across both parts of this run, no confirmation prompt was
        // ever raised -- pre-authorization means the pause branch is never
        // entered in the first place, not merely answered quickly.
        $this->assertFalse(
            $this->anyPendingConfirmationMessageExists(),
            'pre-authorization means no confirmation prompt is ever raised at any point -- SC-001',
        );

        Event::assertNotDispatched(SchedulerTriggerRunRefused::class);
    }
}
