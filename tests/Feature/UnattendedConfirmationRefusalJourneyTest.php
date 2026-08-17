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
 * The unattended refuse-and-stop guarantee: AgentLoopService::run() called
 * with ['unattended' => true] must never leave a destructive or
 * out-of-list action pending for a human who is not present to answer it,
 * and must never silently fall back to the interactive "no bound
 * instructions" behaviour when the conversation's agent binding cannot be
 * resolved.
 *
 * The decision table this file drives against every unattended-specific
 * row of (the reject row, the permitted/non-confirm row, the
 * confirm-required-not-pre-authorized row, and the confirm-required-and-
 * pre-authorized row), plus the two rows an analysis pass over spec.md's
 * own Edge Cases added: an unresolvable bound agent definition, and a
 * mid-run widening of the underlying agent that must not leak into a run
 * already bound to an earlier, narrower snapshot.
 *
 * Every scenario here is expected to be genuinely RED until the
 * AgentLoopService extension lands: today, run() ignores
 * $options['unattended'] entirely, so a rejected or confirm-required
 * operation is handled exactly as it would be for a live user (the
 * rejection is fed back to the model as tool-result data, or the run
 * pauses awaiting an answer nobody will give) instead of stopping the run
 * outright.
 */
class UnattendedConfirmationRefusalJourneyTest extends TestCase
{
    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate every assertion to the bound agent definition's own
        // tools.allow/safety.* — the installation-wide ceiling
        // (api_denylist/confirm_methods) is not this file's concern,
        // mirroring ResearchAgentDefinitionTest/
        // AgentDefinitionSafetyCeilingJourneyTest's own established
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
     * bootstrap — mirrors ResearchAgentDefinitionTest's own identical
     * note. Created here so the pre-authorized row's eventual real
     * execution has a table to write to once the refuse-and-stop branch
     * exists; today's runs never reach it (the pause happens first).
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
     * (agent_id/agent_version_id) 090-agent-version-binding's own
     * Conversation::agentVersion() docblock says is the only relation
     * ConversationAgentDefinitionResolver ever reads — never
     * agent()->currentVersion, which would defeat the point of the
     * binding being fixed at creation.
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

    private int $chatCallCount = 0;

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $this->chatCallCount = 0;

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools) use (&$responses) {
            $this->chatCallCount++;

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

    /** A permissive executor double for the one row that must actually execute. */
    private function installPermissiveExecutorDouble(): void
    {
        $executor = Mockery::mock(McpToolExecutor::class);
        $executor->shouldReceive('extractArguments')->andReturn(['path' => '/api/scheduler/widget', 'query' => [], 'body' => []]);
        $executor->shouldReceive('executeHttpCall')->andReturn([
            'content' => [['type' => 'text', 'text' => 'widget destroyed']],
            'isError' => false,
        ]);
        $this->app->instance(McpToolExecutor::class, $executor);
    }

    /**
     * Proves the run genuinely stopped rather than skipping ahead or
     * substituting a different action: the refused tool-call action is
     * the last action attempted, with nothing following it except the
     * stop notification itself. Ordered by started_at (microsecond
     * precision), not created_at (second precision only, per
     * tests/TestCase.php's agent_run_actions schema) -- two actions
     * opened within the same run frequently share a created_at second.
     */
    private function assertNoActionFollowsTheRefusedAttemptExceptNotification(object $run): void
    {
        $actions = DB::table('agent_run_actions')
            ->where('run_id', $run->id)
            ->orderBy('started_at')
            ->get();

        $refusedIndex = null;
        foreach ($actions as $index => $action) {
            if ($action->action_type === ActionType::ToolInvocation->value && $action->outcome === ActionOutcome::Failure->value) {
                $refusedIndex = $index;
                break;
            }
        }
        $this->assertNotNull($refusedIndex, 'the refused tool-call action must itself be recorded');

        $after = $actions->slice($refusedIndex + 1)->values();
        $this->assertCount(
            1,
            $after,
            'no action may be attempted after the refused one, other than the stop notification itself -- the run must not skip ahead or substitute a different action',
        );
        $this->assertSame(
            ActionType::Notification->value,
            $after[0]->action_type,
            'the only action after the refused attempt must be the stop notification',
        );
    }

    // -----------------------------------------------------------------
    // Row 1 / Edge Case 1 — unresolvable bound agent definition
    // -----------------------------------------------------------------

    #[Test]
    public function an_unattended_run_stops_before_any_operation_when_the_bound_definition_is_unresolvable(): void
    {
        $this->seedOperationCatalog([
            'scheduler.read_status' => ['path' => '/api/scheduler/status', 'method' => 'get', 'summary' => 'Read status'],
        ]);

        [, $version, $conversation] = $this->bindConversation(
            "name: unresolvable-agent\ninstructions: Do the defined work.\ntools:\n  allow:\n    - scheduler.read_status\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n",
            'unresolvable-agent',
        );

        // The dedicated conversation's own AgentVersion has since gone
        // missing (soft-deleted, or a dangling agent_version_id with no
        // FK) — spec.md's own "the defined work itself no longer exists"
        // Edge Case. ConversationAgentDefinitionResolver::forConversation()
        // degrades this to null by construction (belongsTo() excludes
        // trashed rows by default) — never throws.
        $version->delete();
        $conversation = $conversation->fresh();

        Event::fake([SchedulerTriggerRunRefused::class]);

        // Queued only so today's actual (wrong) behaviour — the model IS
        // consulted, because the top-level guard does not exist yet — has
        // something to consume instead of exhausting the script and
        // producing an unrelated failure. Once the guard lands, this
        // response is never touched: the model must not be called at all.
        $service = $this->serviceWithScriptedProvider([
            $this->textResponse('This must never be produced — the run should refuse before consulting the model at all.'),
        ]);

        $result = $service->run($conversation, 'Do the defined work.', ['unattended' => true]);

        $this->assertSame(
            'stopped_unauthorized',
            $result['status'] ?? null,
            'an unattended run bound to an unresolvable agent definition must refuse before doing anything; got: ' . json_encode($result),
        );
        $this->assertSame(
            0,
            $this->chatCallCount,
            'no model call should ever be made when the bound agent definition cannot be resolved unattended — the guard must fire before the loop starts',
        );
        $this->assertStringContainsString(
            'resolvable',
            $result['reason'] ?? '',
            'the failure reason must name the unresolvable binding',
        );

        $run = $this->latestRunFor($conversation);
        $this->assertNotNull($run);
        $this->assertSame('system_initiated', $run->kind, 'an unattended run must be recorded as system_initiated, not interactive');
        $this->assertSame(RunEndState::StoppedEarly->value, $run->end_state);

        Event::assertDispatched(SchedulerTriggerRunRefused::class, function ($event) use ($conversation) {
            return $event->userId === (string) $conversation->user_id;
        });

        $notification = DB::table('agent_run_actions')
            ->where('run_id', $run->id)
            ->where('action_type', ActionType::Notification->value)
            ->first();
        $this->assertNotNull($notification, 'the refusal must be recorded as a Notification action on the run');
    }

    // -----------------------------------------------------------------
    // Row 5 — not permitted
    // -----------------------------------------------------------------

    #[Test]
    public function an_unattended_run_stops_when_the_model_attempts_an_operation_outside_the_permitted_list(): void
    {
        $this->seedOperationCatalog([
            'scheduler.read_status' => ['path' => '/api/scheduler/status', 'method' => 'get', 'summary' => 'Read status'],
            'scheduler.forbidden_op' => ['path' => '/api/scheduler/forbidden', 'method' => 'post', 'summary' => 'Not in the allow list'],
        ]);

        [, , $conversation] = $this->bindConversation(
            "name: narrow-agent\ninstructions: Do only the defined work.\ntools:\n  allow:\n    - scheduler.read_status\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n",
            'narrow-agent',
        );

        Event::fake([SchedulerTriggerRunRefused::class]);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.forbidden_op'),
            // Only consumed by today's actual behaviour, which feeds the
            // rejection back to the model and continues the loop instead
            // of stopping the run outright.
            $this->textResponse('I was unable to do that.'),
        ]);

        $result = $service->run($conversation, 'Do the defined work.', ['unattended' => true]);

        $this->assertSame(
            'stopped_unauthorized',
            $result['status'] ?? null,
            'an unattended run attempting an out-of-list operation must stop, never hand the rejection back to the model to try something else; got: ' . json_encode($result),
        );
        $this->assertSame('scheduler.forbidden_op', $result['operation_id'] ?? null);

        $run = $this->latestRunFor($conversation);
        $this->assertNotNull($run);
        $this->assertSame(RunEndState::StoppedEarly->value, $run->end_state);

        Event::assertDispatched(SchedulerTriggerRunRefused::class);

        // The run genuinely stopped -- it did not skip ahead to try
        // something else in place of the operation it was not permitted
        // for (Acceptance Scenario 1/3).
        $this->assertNoActionFollowsTheRefusedAttemptExceptNotification($run);
    }

    // -----------------------------------------------------------------
    // Row 6 — permitted, no confirmation required: proceeds
    // -----------------------------------------------------------------

    #[Test]
    public function an_unattended_run_proceeds_normally_for_a_permitted_non_confirming_operation(): void
    {
        $this->seedOperationCatalog([
            'scheduler.read_status' => ['path' => '/api/scheduler/status', 'method' => 'get', 'summary' => 'Read status'],
        ]);

        [, , $conversation] = $this->bindConversation(
            "name: narrow-agent-2\ninstructions: Do only the defined work.\ntools:\n  allow:\n    - scheduler.read_status\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n",
            'narrow-agent-2',
        );

        $this->installPermissiveExecutorDouble();

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.read_status'),
            $this->textResponse('Status read successfully.'),
        ]);

        $result = $service->run($conversation, 'Do the defined work.', ['unattended' => true]);

        $this->assertSame('completed', $result['status'] ?? null, 'a permitted, non-confirming operation must complete the run normally; got: ' . json_encode($result));

        // Ordinary permitted work still needs to be attributed as
        // system-initiated, not interactive — this is the one row where
        // today's status already happens to match ('completed' either
        // way), so the run kind is what proves the mechanism is missing.
        $run = $this->latestRunFor($conversation);
        $this->assertNotNull($run);
        $this->assertSame('system_initiated', $run->kind, 'an unattended run must be recorded as system_initiated, not interactive, even when the operation is ultimately permitted');
    }

    // -----------------------------------------------------------------
    // Row 7 — confirmation required, not pre-authorized: refuses
    // -----------------------------------------------------------------

    #[Test]
    public function an_unattended_run_stops_when_a_confirmation_required_operation_was_not_pre_authorized(): void
    {
        $this->seedOperationCatalog([
            'scheduler.destroy_widget' => ['path' => '/api/scheduler/widget', 'method' => 'delete', 'summary' => 'Destroy a widget'],
        ]);

        [, , $conversation] = $this->bindConversation(
            "name: confirm-not-authorized\ninstructions: Do the defined work.\ntools:\n  allow:\n    - scheduler.destroy_widget\nsafety:\n  confirmation_required:\n    - scheduler.destroy_widget\n  unattended_authorized: []\n",
            'confirm-not-authorized',
        );

        Event::fake([SchedulerTriggerRunRefused::class]);

        // Today's actual behaviour returns the __requires_confirmation
        // marker and the run pauses in a single iteration — no second
        // response is ever consumed.
        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.destroy_widget'),
        ]);

        $result = $service->run($conversation, 'Do the defined work.', ['unattended' => true]);

        $this->assertSame(
            'stopped_unauthorized',
            $result['status'] ?? null,
            'a confirmation-required operation with no advance authorization must stop the run, never pause waiting for an answer nobody will give; got: ' . json_encode($result),
        );

        // What never happens, under this row: no pending_confirmation
        // message is ever created for an unattended run.
        $pending = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data->pending_confirmation')
            ->exists();
        $this->assertFalse($pending, 'an unattended run must never create a pending_confirmation message — there is no one present to answer it');

        Event::assertDispatched(SchedulerTriggerRunRefused::class);

        // Not authorized means stopped, not improvised around -- the run
        // did not attempt some other action in place of the one it was
        // not authorized for.
        $run = $this->latestRunFor($conversation);
        $this->assertNotNull($run);
        $this->assertNoActionFollowsTheRefusedAttemptExceptNotification($run);
    }

    // -----------------------------------------------------------------
    // Row 8 — confirmation required, pre-authorized: proceeds
    // -----------------------------------------------------------------

    #[Test]
    public function an_unattended_run_proceeds_without_pausing_when_the_operation_was_pre_authorized(): void
    {
        $this->seedOperationCatalog([
            'scheduler.destroy_widget' => ['path' => '/api/scheduler/widget', 'method' => 'delete', 'summary' => 'Destroy a widget'],
        ]);

        [, , $conversation] = $this->bindConversation(
            "name: confirm-authorized\ninstructions: Do the defined work.\ntools:\n  allow:\n    - scheduler.destroy_widget\nsafety:\n  confirmation_required:\n    - scheduler.destroy_widget\n  unattended_authorized:\n    - scheduler.destroy_widget\n",
            'confirm-authorized',
        );

        $this->installPermissiveExecutorDouble();

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.destroy_widget'),
            // Only reached once pre-authorization actually lets the call
            // through and the loop continues to a second model turn.
            $this->textResponse('Widget destroyed as instructed.'),
        ]);

        $result = $service->run($conversation, 'Do the defined work.', ['unattended' => true]);

        $this->assertSame(
            'completed',
            $result['status'] ?? null,
            'a pre-authorized confirmation-required operation must proceed without ever pausing for confirmation; got: ' . json_encode($result),
        );

        $pending = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data->pending_confirmation')
            ->exists();
        $this->assertFalse($pending, 'pre-authorization means no confirmation prompt is ever raised in the first place — SC-001');
    }

    // -----------------------------------------------------------------
    // Edge Case 2 — mid-run authorization widening is inert for a run
    // already bound to the original, narrower snapshot
    // -----------------------------------------------------------------

    #[Test]
    public function widening_the_underlying_agent_does_not_affect_a_conversation_already_bound_to_the_earlier_narrow_version(): void
    {
        $this->seedOperationCatalog([
            'scheduler.destroy_widget' => ['path' => '/api/scheduler/widget', 'method' => 'delete', 'summary' => 'Destroy a widget'],
        ]);

        $narrowYaml = "name: rebind-agent\ninstructions: Do the defined work.\ntools:\n  allow: []\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n";

        [$agent, $narrowVersion, $conversation] = $this->bindConversation($narrowYaml, 'rebind-agent');

        // Widen the underlying Agent to a new, broader AgentVersion — the
        // same create-a-new-version-and-repoint-current_version_id
        // mechanism 090-agent-version-binding's own edit path uses.
        // Conversation::agent_version_id is NEVER touched here — it stays
        // pinned to $narrowVersion->id, which is the entire point of the
        // fixed binding this test exists to prove still holds.
        $broadYaml = "name: rebind-agent\ninstructions: Do the defined work.\ntools:\n  allow:\n    - scheduler.destroy_widget\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n";
        $broadVersion = AgentVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 2,
            'raw_definition' => $broadYaml,
            'content_hash' => hash('sha256', $broadYaml),
            'source' => AgentChangeSource::ProductEdit->value,
            'changed_by_user_id' => $this->user->id,
        ]);
        $agent->update(['current_version_id' => $broadVersion->id]);

        $conversation = $conversation->fresh();
        $this->assertSame($narrowVersion->id, $conversation->agent_version_id, 'the conversation must still be pinned to the original version after the agent was widened');

        Event::fake([SchedulerTriggerRunRefused::class]);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.destroy_widget'),
            // Only consumed by today's actual behaviour, which continues
            // the loop after a rejection instead of stopping the run.
            $this->textResponse('I was unable to do that.'),
        ]);

        $result = $service->run($conversation, 'Do the defined work.', ['unattended' => true]);

        $this->assertSame(
            'stopped_unauthorized',
            $result['status'] ?? null,
            'the run must still enforce the ORIGINAL narrow snapshot it was bound to, not the agent\'s now-widened current version — if the widened version had leaked in, this operation would have been permitted and the run would have completed; got: ' . json_encode($result),
        );
        $this->assertSame('scheduler.destroy_widget', $result['operation_id'] ?? null);
    }
}
