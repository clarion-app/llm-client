<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunSchedulerTriggerJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
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
 * Integration-level proof that repeated firing of one logical trigger event
 * never produces more than one run, while a genuinely distinct event always
 * starts a new one — the property TriggerFiresUnattendedJourneyTest's own
 * condition-kind scenario already touches once in passing, exercised here
 * across the cases a unit test on SchedulerTriggerEvaluator alone cannot
 * fully cover: two overlapping evaluator ticks racing on the identical
 * fire_key, a condition holding steady across several checks, a
 * true -> false -> true reset, and two independently-defined triggers due at
 * the same instant.
 *
 * Confirm-or-fix: the dedup latch (the unique index on
 * scheduler_trigger_firings(trigger_id, fire_key), won only via
 * insertOrIgnore()) and the evaluator's false -> true transition logic
 * already exist and are already unit-tested. This file's job is to prove
 * the wiring between them holds when driven through the real
 * EvaluateSchedulerTriggersCommand-shaped loop and RunSchedulerTriggerJob,
 * not to add anything new.
 */
class DuplicateTriggerFiringJourneyTest extends TestCase
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
    // Helpers — mirrors TriggerFiresUnattendedJourneyTest's own setup exactly
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
     * Pre-creates a trigger's one dedicated conversation with its title
     * already set, so RunSchedulerTriggerJob::resolveOrCreateConversation()
     * reuses it rather than creating a second row, and so the run doesn't
     * dispatch a real title-generation job. Mirrors
     * TriggerFiresUnattendedJourneyTest's own identical helper.
     */
    private function dedicatedConversationFor(SchedulerTrigger $trigger, Agent $agent, string $title = 'Scheduled work'): Conversation
    {
        return Conversation::create([
            'user_id' => $trigger->user_id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => $title,
            'character' => 'Clarion',
            'channel' => 'scheduler-trigger',
            'agent_id' => $trigger->agent_id,
            'agent_version_id' => $agent->current_version_id,
            'scheduler_trigger_id' => $trigger->id,
        ]);
    }

    /**
     * Installs a scripted LlmProvider double into the real container, one
     * reply per dispatched run, consumed in dispatch order.
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

    /** An McpToolExecutor-shaped condition read reporting `data.ready`. */
    private function readyResult(bool $ready): array
    {
        return [
            'content' => [['type' => 'text', 'text' => json_encode(['data' => ['ready' => $ready]])]],
            'isError' => false,
        ];
    }

    private function conditionTrigger(Agent $agent): SchedulerTrigger
    {
        return SchedulerTrigger::create([
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
    }

    /**
     * Attempts one insertOrIgnore-then-conditionally-dispatch cycle, exactly
     * mirroring EvaluateSchedulerTriggersCommand's own guard: a job is only
     * ever dispatched by the tick whose insertOrIgnore() call actually won
     * the latch row.
     */
    private function claimAndMaybeDispatch(string $triggerId, string $fireKey): int
    {
        $won = DB::table('scheduler_trigger_firings')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'trigger_id' => $triggerId,
            'fire_key' => $fireKey,
            'created_at' => now(),
        ]);

        if ($won === 1) {
            RunSchedulerTriggerJob::dispatchSync($triggerId, $fireKey);
        }

        return $won;
    }

    // -----------------------------------------------------------------
    // Acceptance Scenario 1 — two overlapping evaluator ticks racing on the
    // identical fire_key for one due minute
    // -----------------------------------------------------------------

    #[Test]
    public function schedule_trigger_evaluated_twice_for_the_same_due_minute_produces_exactly_one_run(): void
    {
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
        $this->assertTrue($due);
        $this->assertNotNull($fireKey);

        $conversation = $this->dedicatedConversationFor($trigger, $agent);
        $this->installScriptedProvider([
            $this->textResponse('Daily digest sent successfully.'),
        ]);

        // Two direct insertOrIgnore() calls with the identical, already-
        // computed fire_key, simulating two overlapping evaluator ticks
        // racing on the same due minute.
        $firstTick = $this->claimAndMaybeDispatch($trigger->id, $fireKey);
        $secondTick = $this->claimAndMaybeDispatch($trigger->id, $fireKey);

        $this->assertSame(1, $firstTick, 'the first overlapping tick must win the latch and dispatch the run');
        $this->assertSame(0, $secondTick, 'the second overlapping tick for the identical fire_key must lose the latch and dispatch nothing');

        $this->assertSame(
            1,
            DB::table('scheduler_trigger_firings')->where('trigger_id', $trigger->id)->where('fire_key', $fireKey)->count(),
            'exactly one firing row may exist for one due minute, not one per racing tick'
        );

        $runs = DB::table('agent_runs')->where('conversation_id', $conversation->id)->get();
        $this->assertCount(1, $runs, 'exactly one run may exist for one due minute, not one per racing tick');
        $this->assertSame(RunEndState::Completed->value, $runs->first()->end_state);
    }

    // -----------------------------------------------------------------
    // Acceptance Scenario 2 — a condition holding true across several checks
    // -----------------------------------------------------------------

    #[Test]
    public function condition_trigger_holding_true_across_several_checks_starts_exactly_one_run(): void
    {
        $this->seedOperationCatalog([
            'scheduler.getStatus' => ['path' => '/api/scheduler/status', 'method' => 'get', 'summary' => 'Read status'],
        ]);

        $agent = $this->createSchedulerAgent(['scheduler.getStatus']);
        $trigger = $this->conditionTrigger($agent);

        $executor = Mockery::mock(McpToolExecutor::class);
        $executor->shouldReceive('executeHttpCall')->andReturn(
            $this->readyResult(false), // not due yet
            $this->readyResult(true),  // the one becoming-true event
            $this->readyResult(true),  // steady, already in progress or completed
            $this->readyResult(true),  // steady
            $this->readyResult(true),  // steady
        );
        $this->app->instance(McpToolExecutor::class, $executor);

        $conversation = $this->dedicatedConversationFor($trigger, $agent);
        $this->installScriptedProvider([
            $this->textResponse('Status became ready.'),
        ]);

        $evaluator = app(SchedulerTriggerEvaluator::class);
        $dispatched = 0;

        foreach (range(1, 5) as $tick) {
            [$due, $fireKey] = $evaluator->evaluate($trigger);

            if (!$due) {
                continue;
            }

            if ($this->claimAndMaybeDispatch($trigger->id, $fireKey) === 1) {
                $dispatched++;
            }
        }

        $this->assertSame(1, $dispatched, 'only the false -> true transition may dispatch a run; a steady-true condition must never dispatch a second one');
        $this->assertSame(
            1,
            DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(),
            'exactly one run may exist for the single becoming-true event, whether the condition is checked while that run is in progress or after it has already completed'
        );
    }

    // -----------------------------------------------------------------
    // Acceptance Scenario 3 — true -> false -> true starts a second,
    // distinct run
    // -----------------------------------------------------------------

    #[Test]
    public function condition_trigger_going_true_false_true_starts_a_second_distinct_run(): void
    {
        $this->seedOperationCatalog([
            'scheduler.getStatus' => ['path' => '/api/scheduler/status', 'method' => 'get', 'summary' => 'Read status'],
        ]);

        $agent = $this->createSchedulerAgent(['scheduler.getStatus']);
        $trigger = $this->conditionTrigger($agent);

        $executor = Mockery::mock(McpToolExecutor::class);
        $executor->shouldReceive('executeHttpCall')->andReturn(
            $this->readyResult(false), // baseline
            $this->readyResult(true),  // 1st becoming-true event
            $this->readyResult(false), // goes false again
            $this->readyResult(true),  // 2nd, genuinely distinct becoming-true event
        );
        $this->app->instance(McpToolExecutor::class, $executor);

        $conversation = $this->dedicatedConversationFor($trigger, $agent);
        $this->installScriptedProvider([
            $this->textResponse('Status became ready (first time).'),
            $this->textResponse('Status became ready (second time).'),
        ]);

        $evaluator = app(SchedulerTriggerEvaluator::class);
        $fireKeys = [];

        foreach (range(1, 4) as $tick) {
            [$due, $fireKey] = $evaluator->evaluate($trigger);

            if (!$due) {
                continue;
            }

            $this->claimAndMaybeDispatch($trigger->id, $fireKey);
            $fireKeys[] = $fireKey;
        }

        $this->assertCount(2, $fireKeys, 'the true -> false -> true sequence must produce exactly two becoming-true events');
        $this->assertNotSame($fireKeys[0], $fireKeys[1], 'a genuinely distinct event must never collide with the earlier one on the same latch row');

        $this->assertSame(
            2,
            DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(),
            'a genuinely distinct trigger event must start the defined work again, de-duplication applies only within one logical event'
        );
        $this->assertSame(
            2,
            DB::table('scheduler_trigger_firings')->where('trigger_id', $trigger->id)->count(),
        );
    }

    // -----------------------------------------------------------------
    // Edge Cases — two independently-defined triggers due at the same
    // instant each win their own latch row and both run
    // -----------------------------------------------------------------

    #[Test]
    public function two_independently_defined_triggers_due_at_the_same_instant_both_run(): void
    {
        $this->seedOperationCatalog([]);

        $agent = $this->createSchedulerAgent();

        $triggerA = SchedulerTrigger::create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'name' => 'Trigger A',
            'kind' => SchedulerTrigger::KIND_SCHEDULE,
            'schedule_expression' => '* * * * *',
            'defined_work' => 'Report on trigger A.',
            'retry_limit' => 3,
            'is_active' => true,
        ]);

        $triggerB = SchedulerTrigger::create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'name' => 'Trigger B',
            'kind' => SchedulerTrigger::KIND_SCHEDULE,
            'schedule_expression' => '* * * * *',
            'defined_work' => 'Report on trigger B.',
            'retry_limit' => 3,
            'is_active' => true,
        ]);

        $evaluator = app(SchedulerTriggerEvaluator::class);
        [$dueA, $fireKeyA] = $evaluator->evaluate($triggerA);
        [$dueB, $fireKeyB] = $evaluator->evaluate($triggerB);

        $this->assertTrue($dueA);
        $this->assertTrue($dueB);
        $this->assertNotSame($fireKeyA, $fireKeyB, 'trigger_id is the first component of every fire_key, so two independently-defined triggers due at the same instant never share one latch row');

        $conversationA = $this->dedicatedConversationFor($triggerA, $agent, 'Scheduled work A');
        $conversationB = $this->dedicatedConversationFor($triggerB, $agent, 'Scheduled work B');
        $this->installScriptedProvider([
            $this->textResponse('A done.'),
            $this->textResponse('B done.'),
        ]);

        $wonA = $this->claimAndMaybeDispatch($triggerA->id, $fireKeyA);
        $wonB = $this->claimAndMaybeDispatch($triggerB->id, $fireKeyB);

        $this->assertSame(1, $wonA, 'trigger A must win its own latch row');
        $this->assertSame(1, $wonB, 'trigger B must win its own latch row, independently of trigger A');

        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversationA->id)->count());
        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversationB->id)->count());
        $this->assertSame(2, DB::table('agent_runs')->count(), 'two distinct triggers both firing is two distinct events, not a duplicate of one');
    }
}
