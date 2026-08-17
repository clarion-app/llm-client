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
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
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
 * The notification half of the unattended refuse-and-stop guarantee:
 * SchedulerTriggerRunRefused fires exactly once per refused run, naming
 * the specific operation and reason; the broadcast attempt is isolated in
 * its own inner try/catch, so a forced broadcaster failure leaves the
 * run's own already-successful closeAction()/closeStep()/closeRun() writes
 * -- and run()'s own return value -- untouched; and the notify attempt
 * itself is recorded as its own ActionType::Notification action (Success
 * when the broadcast succeeds, Failure with the broadcaster's own error
 * message when it does not), distinct from the run's own end_state, which
 * is unaffected either way.
 *
 * Fixture shape mirrors UnattendedConfirmationRefusalJourneyTest's and
 * AdvanceAuthorizationJourneyTest's own bindConversation()/
 * serviceWithScriptedProvider() helpers exactly. The forced-broadcaster-
 * failure technique (Event::listen() registering a throwing listener,
 * with no Event::fake()) mirrors RunTraceRecorderBroadcastTest's and
 * BudgetThresholdNotifierTest's own established pattern for this exact
 * class of isolation proof.
 */
class SchedulerRunRefusalNotificationTest extends TestCase
{
    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

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
            'id' => 'call_' . Str::random(8),
            'type' => 'function',
            'function' => [
                'name' => 'execute_operation',
                'arguments' => json_encode(['operationId' => $operationId, 'parameters' => $parameters]),
            ],
        ]]]]]];
    }

    private function latestRunFor(Conversation $conversation): ?object
    {
        return DB::table('agent_runs')
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('created_at')
            ->first();
    }

    private function notificationActionFor(object $run): ?object
    {
        return DB::table('agent_run_actions')
            ->where('run_id', $run->id)
            ->where('action_type', ActionType::Notification->value)
            ->first();
    }

    private function refusedToolInvocationFor(object $run): ?object
    {
        return DB::table('agent_run_actions')
            ->where('run_id', $run->id)
            ->where('action_type', ActionType::ToolInvocation->value)
            ->where('outcome', ActionOutcome::Failure->value)
            ->first();
    }

    private function narrowAgentYaml(): string
    {
        return "name: refusal-notify-agent\ninstructions: Do only the defined work.\ntools:\n  allow:\n    - scheduler.read_status\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n";
    }

    // -----------------------------------------------------------------
    // Fires exactly once, naming the specific operation and reason
    // -----------------------------------------------------------------

    #[Test]
    public function scheduler_trigger_run_refused_fires_exactly_once_naming_the_operation_and_reason(): void
    {
        $this->seedOperationCatalog([
            'scheduler.read_status' => ['path' => '/api/scheduler/status', 'method' => 'get', 'summary' => 'Read status'],
            'scheduler.forbidden_op' => ['path' => '/api/scheduler/forbidden', 'method' => 'post', 'summary' => 'Not in the allow list'],
        ]);

        [, , $conversation] = $this->bindConversation($this->narrowAgentYaml(), 'refusal-notify-agent-1');

        Event::fake([SchedulerTriggerRunRefused::class]);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.forbidden_op'),
            $this->textResponse('This must never be produced.'),
        ]);

        $result = $service->run($conversation, 'Do the defined work.', ['unattended' => true]);

        $this->assertSame('stopped_unauthorized', $result['status'] ?? null);

        $run = $this->latestRunFor($conversation);
        $this->assertNotNull($run);

        Event::assertDispatchedTimes(SchedulerTriggerRunRefused::class, 1);
        Event::assertDispatched(SchedulerTriggerRunRefused::class, function ($event) use ($conversation, $run) {
            return $event->userId === (string) $conversation->user_id
                && $event->runId === $run->id
                && $event->operationId === 'scheduler.forbidden_op'
                && str_contains($event->reason, 'scheduler.forbidden_op');
        });
    }

    // -----------------------------------------------------------------
    // Broadcast isolation: a forced broadcaster failure never corrupts
    // the run's own already-successful closing writes or return value
    // -----------------------------------------------------------------

    #[Test]
    public function a_forced_broadcaster_failure_leaves_the_runs_own_closing_writes_and_return_value_intact(): void
    {
        $this->seedOperationCatalog([
            'scheduler.read_status' => ['path' => '/api/scheduler/status', 'method' => 'get', 'summary' => 'Read status'],
            'scheduler.forbidden_op' => ['path' => '/api/scheduler/forbidden', 'method' => 'post', 'summary' => 'Not in the allow list'],
        ]);

        [, , $conversation] = $this->bindConversation($this->narrowAgentYaml(), 'refusal-notify-agent-2');

        // Simulates the broadcaster itself being down -- mirrors
        // RunTraceRecorderBroadcastTest's/BudgetThresholdNotifierTest's own
        // technique for this exact isolation proof: no Event::fake(), a
        // real listener registered to throw.
        Event::listen(SchedulerTriggerRunRefused::class, function (): void {
            throw new \RuntimeException('the broadcaster is down');
        });

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.forbidden_op'),
            $this->textResponse('This must never be produced.'),
        ]);

        $result = $service->run($conversation, 'Do the defined work.', ['unattended' => true]);

        // An unwrapped broadcast call would let the thrown RuntimeException
        // propagate out of run() itself, replacing this structured return
        // value with an uncaught exception.
        $this->assertSame('stopped_unauthorized', $result['status'] ?? null);
        $this->assertSame('scheduler.forbidden_op', $result['operation_id'] ?? null);

        $run = $this->latestRunFor($conversation);
        $this->assertNotNull($run);
        $this->assertSame(
            RunEndState::StoppedEarly->value,
            $run->end_state,
            'the run must still close StoppedEarly even though notifying about it failed',
        );

        // The refused tool-call action itself was already closed Failure
        // a moment before the broadcast attempt -- that write must survive
        // the broadcaster blowing up afterward.
        $refused = $this->refusedToolInvocationFor($run);
        $this->assertNotNull($refused);
        $this->assertSame(ActionOutcome::Failure->value, $refused->outcome);
    }

    // -----------------------------------------------------------------
    // The notify attempt is itself recorded, distinctly from end_state
    // -----------------------------------------------------------------

    #[Test]
    public function the_notify_attempt_is_recorded_as_its_own_notification_action_success_when_the_broadcast_succeeds(): void
    {
        $this->seedOperationCatalog([
            'scheduler.read_status' => ['path' => '/api/scheduler/status', 'method' => 'get', 'summary' => 'Read status'],
            'scheduler.forbidden_op' => ['path' => '/api/scheduler/forbidden', 'method' => 'post', 'summary' => 'Not in the allow list'],
        ]);

        [, , $conversation] = $this->bindConversation($this->narrowAgentYaml(), 'refusal-notify-agent-3');

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.forbidden_op'),
            $this->textResponse('This must never be produced.'),
        ]);

        $result = $service->run($conversation, 'Do the defined work.', ['unattended' => true]);
        $this->assertSame('stopped_unauthorized', $result['status'] ?? null);

        $run = $this->latestRunFor($conversation);
        $this->assertNotNull($run);

        $notification = $this->notificationActionFor($run);
        $this->assertNotNull($notification, 'the notify attempt must be recorded as its own Notification action');
        $this->assertSame(ActionOutcome::Success->value, $notification->outcome);

        // Distinct from the run's own end_state, which reflects the
        // refusal itself, not whether notifying about it succeeded.
        $this->assertSame(RunEndState::StoppedEarly->value, $run->end_state);
    }

    #[Test]
    public function the_notify_attempt_is_recorded_as_its_own_notification_action_failure_with_the_broadcasters_own_message_when_it_does_not(): void
    {
        $this->seedOperationCatalog([
            'scheduler.read_status' => ['path' => '/api/scheduler/status', 'method' => 'get', 'summary' => 'Read status'],
            'scheduler.forbidden_op' => ['path' => '/api/scheduler/forbidden', 'method' => 'post', 'summary' => 'Not in the allow list'],
        ]);

        [, , $conversation] = $this->bindConversation($this->narrowAgentYaml(), 'refusal-notify-agent-4');

        Event::listen(SchedulerTriggerRunRefused::class, function (): void {
            throw new \RuntimeException('the broadcaster is down');
        });

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.forbidden_op'),
            $this->textResponse('This must never be produced.'),
        ]);

        $result = $service->run($conversation, 'Do the defined work.', ['unattended' => true]);
        $this->assertSame('stopped_unauthorized', $result['status'] ?? null);

        $run = $this->latestRunFor($conversation);
        $this->assertNotNull($run);

        $notification = $this->notificationActionFor($run);
        $this->assertNotNull($notification, 'the notify attempt must be recorded as its own Notification action even when the broadcast itself failed');
        $this->assertSame(ActionOutcome::Failure->value, $notification->outcome);
        $this->assertSame('the broadcaster is down', $notification->failure_reason);

        // Still distinct from the run's own end_state -- a failed
        // notification never masks or changes what the run itself did.
        $this->assertSame(RunEndState::StoppedEarly->value, $run->end_state);
    }
}
