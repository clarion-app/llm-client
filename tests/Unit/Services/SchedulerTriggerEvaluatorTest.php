<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\SchedulerTrigger;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\AgentVersionResolver;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\SchedulerTriggerEvaluator;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for SchedulerTriggerEvaluator::evaluate() — schedule-kind
 * (a plain cron due/not-due check) and condition-kind (a permission-gated
 * read followed by a false -> true transition check, remembered on the
 * SchedulerTrigger row itself).
 */
class SchedulerTriggerEvaluatorTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.confirm_methods', []);
        $this->app['config']->set('llm-client.api_denylist', []);

        $this->user = User::factory()->create();

        $this->createSupportingTables();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('scheduler_triggers')->delete();
        DB::table('mcp_sessions')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

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

    private function evaluator(McpToolExecutor $toolExecutor): SchedulerTriggerEvaluator
    {
        return new SchedulerTriggerEvaluator(
            $toolExecutor,
            new AgentVersionResolver(new AgentDefinitionParser()),
        );
    }

    /**
     * A scheduler-shaped agent whose tools.allow permits exactly
     * 'condition.getStatus' -- the operation every condition-kind test in
     * this file reads.
     */
    private function agentPermittingConditionOperation(): \ClarionApp\LlmClient\Models\Agent
    {
        $this->seedOperationCatalog([
            'condition.getStatus' => [
                'path' => '/api/condition/status',
                'method' => 'get',
                'summary' => 'Read the condition status',
            ],
        ]);

        $yaml = <<<YAML
        format_version: "1.0"
        name: scheduler-evaluator-test
        version: "1"
        instructions: |
          test
        capabilities: []
        tools:
          allow:
            - condition.getStatus
          deny: []
        safety:
          confirmation_required: []
          unattended_authorized: []
          denylist: []
        YAML;

        $agentService = new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader());

        return $agentService->create($this->user->id, $yaml);
    }

    private function conditionTrigger(string $agentId, string $comparator = 'gt', string $value = '5'): SchedulerTrigger
    {
        return SchedulerTrigger::create([
            'user_id' => $this->user->id,
            'agent_id' => $agentId,
            'name' => 'condition trigger',
            'kind' => SchedulerTrigger::KIND_CONDITION,
            'condition_operation_id' => 'condition.getStatus',
            'condition_path' => 'data.count',
            'condition_comparator' => $comparator,
            'condition_value' => $value,
            'defined_work' => 'report on the status',
            'retry_limit' => 3,
            'is_active' => true,
        ]);
    }

    /** An McpToolExecutor double returning {"data":{"count": $count}} once per call, in order. */
    private function executorReturningCounts(array $counts): McpToolExecutor
    {
        $executor = Mockery::mock(McpToolExecutor::class);

        $returns = array_map(
            static fn (int $count): array => [
                'content' => [
                    ['type' => 'text', 'text' => json_encode(['data' => ['count' => $count]])],
                ],
                'isError' => false,
            ],
            $counts,
        );

        $executor->shouldReceive('executeHttpCall')->andReturn(...$returns);

        return $executor;
    }

    // ---------------------------------------------------------------
    // Schedule kind
    // ---------------------------------------------------------------

    #[Test]
    public function a_due_cron_expression_returns_true_and_a_schedule_fire_key(): void
    {
        $agent = $this->agentPermittingConditionOperation();

        $trigger = SchedulerTrigger::create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'name' => 'every minute',
            'kind' => SchedulerTrigger::KIND_SCHEDULE,
            'schedule_expression' => '* * * * *',
            'defined_work' => 'do the thing',
            'retry_limit' => 3,
            'is_active' => true,
        ]);

        [$due, $fireKey] = $this->evaluator(Mockery::mock(McpToolExecutor::class))->evaluate($trigger);

        $this->assertTrue($due);
        $this->assertMatchesRegularExpression(
            '/^schedule:' . preg_quote($trigger->id, '/') . ':\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',
            $fireKey,
        );
    }

    #[Test]
    public function a_not_due_cron_expression_returns_false_and_null(): void
    {
        $agent = $this->agentPermittingConditionOperation();

        // A specific minute at least half an hour from now -- guaranteed not
        // to match the current instant.
        $notDueMinute = (int) now()->addMinutes(30)->format('i');

        $trigger = SchedulerTrigger::create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'name' => 'not due yet',
            'kind' => SchedulerTrigger::KIND_SCHEDULE,
            'schedule_expression' => "{$notDueMinute} * * * *",
            'defined_work' => 'do the thing',
            'retry_limit' => 3,
            'is_active' => true,
        ]);

        [$due, $fireKey] = $this->evaluator(Mockery::mock(McpToolExecutor::class))->evaluate($trigger);

        $this->assertFalse($due);
        $this->assertNull($fireKey);
    }

    // ---------------------------------------------------------------
    // Condition kind
    // ---------------------------------------------------------------

    #[Test]
    public function a_first_ever_true_observation_records_state_but_does_not_fire(): void
    {
        $agent = $this->agentPermittingConditionOperation();
        $trigger = $this->conditionTrigger($agent->id);

        $this->assertNull($trigger->last_condition_state, 'a fresh trigger must start with no recorded observation');

        [$due, $fireKey] = $this->evaluator($this->executorReturningCounts([10]))->evaluate($trigger);

        $this->assertFalse($due, 'a condition already true on its very first observation must not itself count as a becoming-true event');
        $this->assertNull($fireKey);
        $this->assertTrue($trigger->fresh()->last_condition_state, 'the observation must still be recorded');
        $this->assertNotNull($trigger->fresh()->last_evaluated_at);
    }

    #[Test]
    public function a_false_to_true_transition_fires_and_updates_the_trigger(): void
    {
        $agent = $this->agentPermittingConditionOperation();
        $trigger = $this->conditionTrigger($agent->id);
        $trigger->update(['last_condition_state' => false, 'last_evaluated_at' => now()->subMinute()]);

        [$due, $fireKey] = $this->evaluator($this->executorReturningCounts([10]))->evaluate($trigger);

        $this->assertTrue($due);
        $this->assertMatchesRegularExpression(
            '/^condition:' . preg_quote($trigger->id, '/') . ':.+$/',
            $fireKey,
        );
        $this->assertTrue($trigger->fresh()->last_condition_state);
    }

    #[Test]
    public function true_held_steady_across_further_evaluations_never_fires_again(): void
    {
        $agent = $this->agentPermittingConditionOperation();
        $trigger = $this->conditionTrigger($agent->id);
        $trigger->update(['last_condition_state' => true, 'last_evaluated_at' => now()->subMinutes(3)]);

        $executor = $this->executorReturningCounts([10, 20, 30]);
        $evaluator = $this->evaluator($executor);

        foreach ([10, 20, 30] as $ignored) {
            [$due, $fireKey] = $evaluator->evaluate($trigger);
            $this->assertFalse($due, 'a steady-true condition must never re-fire on a later evaluation');
            $this->assertNull($fireKey);
        }
    }

    #[Test]
    public function true_false_true_produces_two_distinct_fire_keys(): void
    {
        $agent = $this->agentPermittingConditionOperation();
        $trigger = $this->conditionTrigger($agent->id);
        // Start false (not the very-first-ever-observation case, which is
        // covered separately above) so the first observed-true below is a
        // genuine transition.
        $trigger->update(['last_condition_state' => false, 'last_evaluated_at' => now()->subMinutes(5)]);

        // 10 -> observed true (first transition, fires); 3 -> observed
        // false (no fire); 10 -> observed true again (second, distinct
        // transition, fires again).
        $executor = $this->executorReturningCounts([10, 3, 10]);
        $evaluator = $this->evaluator($executor);

        [$due1, $fireKey1] = $evaluator->evaluate($trigger);
        $this->assertTrue($due1, 'the first false -> true transition must fire');
        $this->assertNotNull($fireKey1);

        [$due2, $fireKey2] = $evaluator->evaluate($trigger);
        $this->assertFalse($due2, 'the true -> false step itself never fires');
        $this->assertNull($fireKey2);

        [$due3, $fireKey3] = $evaluator->evaluate($trigger);
        $this->assertTrue($due3, 'the second false -> true transition must fire again');
        $this->assertNotNull($fireKey3);

        $this->assertNotSame($fireKey1, $fireKey3, 'two distinct becoming-true events must produce two distinct fire keys');
    }
}
