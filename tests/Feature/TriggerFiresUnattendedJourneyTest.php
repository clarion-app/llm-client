<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunSchedulerTriggerJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\SchedulerTrigger;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\SchedulerTriggerEvaluator;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end proof that a defined trigger fires its work unattended and a
 * report is available afterward: a due schedule trigger starts its defined
 * work and its report is readable afterward through the existing,
 * unmodified RunController/ConversationController endpoints, with no
 * pending_confirmation message ever raised along the way; a condition
 * trigger only starts work on its own false -> true transition, never while
 * the condition is merely holding false.
 *
 * Confirm-or-fix: every piece this test drives (SchedulerTriggerEvaluator,
 * the insertOrIgnore dedup latch, RunSchedulerTriggerJob, the unattended
 * AgentLoopService::run() path) already exists. This file's job is to prove
 * the wiring between them, not to add anything new.
 */
class TriggerFiresUnattendedJourneyTest extends TestCase
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

        // RunSchedulerTriggerJob::resolveOrCreateConversation() resolves the
        // dedicated conversation's server/model via RoleResolver, the same
        // recipe DelegationService/ManagerService already use for their own
        // dedicated conversations. Without an assignment the conversation is
        // created with a null server_id, and Conversation's own
        // effective-provider-type accessor refuses to resolve a provider
        // for that — this role assignment is what a real installation would
        // already have in place for its scheduler agent to run at all.
        RoleAssignment::create([
            'role' => ModelRole::Inference->value,
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
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
        DB::table('scheduler_trigger_firings')->delete();
        DB::table('scheduler_triggers')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    /**
     * The real, unattended AgentLoopService::run() path touches these; none
     * exist in the base TestCase schema bootstrap (mirrors
     * ResearchAgentDefinitionTest's own identical note): mcp_sessions for
     * execute_operation's real path and the condition-kind evaluator's own
     * read, condensation_states for ConversationCondenser's post-turn check.
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

    private function schedulerYaml(array $toolsAllow = []): string
    {
        $allow = empty($toolsAllow)
            ? ' []'
            : "\n".implode("\n", array_map(fn (string $op) => "    - {$op}", $toolsAllow));

        return "format_version: \"1.0\"\n"
            ."name: scheduler\n"
            ."version: \"1\"\n"
            ."instructions: |\n"
            ."  Report on the defined work.\n"
            ."capabilities: []\n"
            ."tools:\n"
            ."  allow:{$allow}\n"
            ."  deny: []\n"
            ."safety:\n"
            ."  confirmation_required: []\n"
            ."  unattended_authorized: []\n"
            ."  denylist: []\n";
    }

    private function createSchedulerAgent(array $toolsAllow = []): Agent
    {
        return app(AgentService::class)->create($this->user->id, $this->schedulerYaml($toolsAllow));
    }

    /**
     * Pre-creates the trigger's one dedicated conversation with its title
     * already set, in the exact shape
     * RunSchedulerTriggerJob::resolveOrCreateConversation() would otherwise
     * lazily create — so that method's own
     * `Conversation::where('scheduler_trigger_id', ...)->first()` lookup
     * finds it and reuses it rather than creating a second row.
     *
     * Pre-setting the title sidesteps AgentLoopService::run()'s own,
     * unrelated "generate a title on first exchange" side effect
     * (OpenAIGenerateConversationTitleRequest), which dispatches a real
     * outbound HTTP job under this suite's sync queue connection —
     * mirrors UnattendedConfirmationRefusalJourneyTest's and
     * DelegationJourneyTest's own identical precedent for a fresh
     * conversation driven through the real run() path.
     */
    private function dedicatedConversationFor(SchedulerTrigger $trigger, Agent $agent): Conversation
    {
        return Conversation::create([
            'user_id' => $trigger->user_id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Scheduled work',
            'character' => 'Clarion',
            'channel' => 'scheduler-trigger',
            'agent_id' => $trigger->agent_id,
            'agent_version_id' => $agent->current_version_id,
            'scheduler_trigger_id' => $trigger->id,
        ]);
    }

    /**
     * Installs a scripted LlmProvider double into the real container so
     * RunSchedulerTriggerJob's own container-resolved AgentLoopService uses
     * it, mirroring UnattendedConfirmationRefusalJourneyTest's own
     * scripted-provider double -- bound into app() rather than constructed
     * by hand, since the job itself resolves AgentLoopService, not this
     * test.
     */
    private function installScriptedProvider(array $responses): void
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools) use (&$responses) {
            return array_shift($responses);
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $this->app->instance(ProviderRegistry::class, $registry);
    }

    /** A plain assistant reply carrying no tool call — ends the run. */
    private function textResponse(string $text): array
    {
        return ['choices' => [['message' => ['content' => $text, 'tool_calls' => []]]]];
    }

    // -----------------------------------------------------------------
    // Schedule kind — the trigger's own moment arrives and its report is
    // readable afterward
    // -----------------------------------------------------------------

    #[Test]
    public function a_due_schedule_trigger_runs_unattended_and_its_report_is_readable_afterward(): void
    {
        // AgentDefinitionParser::parse() always resolves the full ApiManager
        // catalog (to validate tools.allow/safety.* patterns against it),
        // even when the patterns list itself is empty — so every parse
        // needs the operation catalog double in place first, matching every
        // other file in this suite that ever creates or resolves an Agent
        // definition.
        $this->seedOperationCatalog([]);

        $agent = $this->createSchedulerAgent();

        $trigger = SchedulerTrigger::create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'name' => 'Daily digest',
            'kind' => SchedulerTrigger::KIND_SCHEDULE,
            'schedule_expression' => '* * * * *',
            'defined_work' => 'Report on the daily digest.',
            'retry_limit' => 3,
            'is_active' => true,
        ]);

        [$due, $fireKey] = app(SchedulerTriggerEvaluator::class)->evaluate($trigger);
        $this->assertTrue($due, 'a cron expression due at the current instant must be reported due');
        $this->assertNotNull($fireKey);

        $won = DB::table('scheduler_trigger_firings')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'trigger_id' => $trigger->id,
            'fire_key' => $fireKey,
            'created_at' => now(),
        ]);
        $this->assertSame(1, $won, 'the first tick to claim this minute must win the latch');

        $conversation = $this->dedicatedConversationFor($trigger, $agent);

        $this->installScriptedProvider([
            $this->textResponse('Daily digest sent successfully.'),
        ]);

        RunSchedulerTriggerJob::dispatchSync($trigger->id, $fireKey);

        $run = DB::table('agent_runs')->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($run, 'a triggered run must exist for the fired event');
        $this->assertSame('system_initiated', $run->kind, 'a trigger-started run must be attributed as system-initiated, never interactive');
        $this->assertSame(RunEndState::Completed->value, $run->end_state);

        $firing = DB::table('scheduler_trigger_firings')
            ->where('trigger_id', $trigger->id)
            ->where('fire_key', $fireKey)
            ->first();
        $this->assertSame($run->id, $firing->run_id, 'the firing row must record which run this event produced');

        $pending = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data->pending_confirmation')
            ->exists();
        $this->assertFalse($pending, 'no pending_confirmation message may ever be created on an unattended run');

        // The run's own summary is readable afterward via the existing,
        // unmodified RunController::show().
        $runResponse = $this->actingAs($this->user)->getJson($this->apiUrl("agent-runs/{$run->id}"));
        $runResponse->assertStatus(200);
        $this->assertSame('completed', $runResponse->json('end_state'));
        $this->assertSame('system_initiated', $runResponse->json('kind'));

        // The report itself -- the run's final assistant message -- is
        // readable afterward via the existing, unmodified conversation
        // message endpoint.
        $conversationResponse = $this->actingAs($this->user)->getJson($this->apiUrl("conversation/{$conversation->id}"));
        $conversationResponse->assertStatus(200);
        $report = collect($conversationResponse->json('messages'))->firstWhere('role', 'assistant');
        $this->assertNotNull($report, 'the run\'s report must be readable as an assistant message afterward');
        $this->assertSame('Daily digest sent successfully.', $report['content']);
    }

    // -----------------------------------------------------------------
    // Condition kind — only the becoming-true transition starts work
    // -----------------------------------------------------------------

    #[Test]
    public function a_condition_trigger_only_starts_work_on_its_false_to_true_transition(): void
    {
        $this->seedOperationCatalog([
            'scheduler.getStatus' => ['path' => '/api/scheduler/status', 'method' => 'get', 'summary' => 'Read status'],
        ]);

        $agent = $this->createSchedulerAgent(['scheduler.getStatus']);

        $trigger = SchedulerTrigger::create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'name' => 'Status watch',
            'kind' => SchedulerTrigger::KIND_CONDITION,
            'condition_operation_id' => 'scheduler.getStatus',
            'condition_path' => 'data.ready',
            'condition_comparator' => 'eq',
            'condition_value' => 'true',
            'defined_work' => 'Report the status change.',
            'retry_limit' => 3,
            'is_active' => true,
        ]);

        $executor = Mockery::mock(McpToolExecutor::class);
        $executor->shouldReceive('executeHttpCall')->andReturn(
            ['content' => [['type' => 'text', 'text' => json_encode(['data' => ['ready' => false]])]], 'isError' => false],
            ['content' => [['type' => 'text', 'text' => json_encode(['data' => ['ready' => true]])]], 'isError' => false],
        );
        $this->app->instance(McpToolExecutor::class, $executor);

        $evaluator = app(SchedulerTriggerEvaluator::class);

        [$due1, $fireKey1] = $evaluator->evaluate($trigger);
        $this->assertFalse($due1, 'a condition observed false must never fire');
        $this->assertNull($fireKey1);
        $this->assertSame(0, DB::table('agent_runs')->count(), 'no run may start while the condition is false');

        [$due2, $fireKey2] = $evaluator->evaluate($trigger);
        $this->assertTrue($due2, 'the false -> true transition must fire');
        $this->assertNotNull($fireKey2);

        $won = DB::table('scheduler_trigger_firings')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'trigger_id' => $trigger->id,
            'fire_key' => $fireKey2,
            'created_at' => now(),
        ]);
        $this->assertSame(1, $won);

        $this->dedicatedConversationFor($trigger, $agent);

        $this->installScriptedProvider([
            $this->textResponse('Status became ready.'),
        ]);

        RunSchedulerTriggerJob::dispatchSync($trigger->id, $fireKey2);

        $this->assertSame(1, DB::table('agent_runs')->count(), 'exactly one run must start for the single becoming-true event');

        $run = DB::table('agent_runs')->first();
        $this->assertSame(RunEndState::Completed->value, $run->end_state);
    }
}
