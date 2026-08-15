<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ManagedTaskQuery;
use ClarionApp\LlmClient\Services\ManagerService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 9 (US7), tasks.md T066.
 *
 * Quickstart scenario 9 (US7, FR-015, SC-010/SC-011): "Cost is attributable
 * across the whole tree, not just the manager's own turns" -- a two-part
 * task where part A's helper itself delegates further to one of its own
 * assigned helpers (a helper-of-helper, nested one level). Seeded, known
 * `usage_records` costs for: the manager's own conversation (2 step-job
 * invocations), part A's direct helper, part A's helper's own
 * sub-delegation, and part B's helper (one correction round, two
 * `Delegation` rows). `ManagedTaskQuery::costForTask()` must report:
 * `total_cost` equal to the exact arithmetic sum of all four cost sources;
 * exactly two `by_part` entries; part A's entry including the
 * sub-delegation's cost (transitive attribution) even though it was never a
 * direct `assign_part` call; part B's entry listing both rounds
 * individually. A SEPARATE call made mid-task -- before every row above
 * exists -- must return a non-zero, accurate running total (SC-011), not
 * only once the task is complete.
 *
 * Written before `ManagedTaskQuery::costForTask()` exists -- every
 * assertion below is expected to FAIL red until T068.
 */
class ManagedTaskCostAttributionJourneyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('usage_records')->delete();
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];

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

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function seedUsage(string $conversationId, ?string $runId, string $cost, int $tokens): void
    {
        DB::table('usage_records')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversationId,
            'user_id' => $this->user->id,
            'attempt_group_id' => (string) Str::uuid(),
            'run_id' => $runId,
            'input_tokens' => $tokens,
            'output_tokens' => 0,
            'total_tokens' => $tokens,
            'total_cost' => $cost,
            'model' => 'test-model',
            'provider_type' => 'openai',
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    #[Test]
    public function cost_is_attributed_exactly_across_a_nested_two_part_tree_and_a_mid_task_call_reports_an_accurate_running_total(): void
    {
        $manager = $this->makeAgent('manager-scenario-9');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A two-part task, one part nested one level.');

        [$partA, $partB] = app(ManagerService::class)->planParts($task, ['Part A -- needs a sub-delegation.', 'Part B -- needs a correction round.']);

        $helperA = $this->makeAgent('helper-a-scenario-9');
        $subHelperA = $this->makeAgent('sub-helper-a-scenario-9');
        $helperB = $this->makeAgent('helper-b-scenario-9');

        // -------------------------------------------------------------
        // Mid-task: only SOME of the eventual rows exist yet. A call here
        // must already report an accurate, non-zero running total
        // (SC-011) -- not merely "available once complete".
        // -------------------------------------------------------------
        $this->seedUsage($task->conversation_id, null, '0.0300000000', 1000); // manager's first step-job invocation

        $partADirectRunId = (string) Str::uuid();
        Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helperA->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'helper_run_id' => $partADirectRunId,
            'owner_user_id' => $this->user->id,
            'task' => 'Do part A.',
            'depth' => 1,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'result_status' => 'success',
            'managed_task_id' => $task->id,
            'part_id' => $partA->id,
        ]);
        $this->seedUsage((string) Str::uuid(), $partADirectRunId, '0.1890000000', 54200);

        $midTaskResult = app(ManagedTaskQuery::class)->costForTask($this->user->id, $task->id);

        $this->assertNotNull($midTaskResult);
        $this->assertNotSame('0.0000000000', $midTaskResult['total_cost'], 'a mid-task call, before every eventual row exists, must still report a non-zero running total');
        $this->assertSame(
            bcadd('0.0300000000', '0.1890000000', 10),
            $midTaskResult['total_cost'],
            'the mid-task running total must accurately reflect ONLY the rows seeded so far',
        );

        // -------------------------------------------------------------
        // Rest of the tree: part A's helper delegates further to its own
        // assigned sub-helper (nested one level -- managed_task_id
        // inherited, part_id stays null); part B gets a correction round
        // (two Delegation rows); manager's second step-job invocation.
        // -------------------------------------------------------------
        $this->seedUsage($task->conversation_id, null, '0.0212000000', 800); // manager's second step-job invocation

        $subDelegationRunId = (string) Str::uuid();
        Delegation::create([
            'parent_conversation_id' => (string) Str::uuid(),
            'parent_run_id' => $partADirectRunId,
            'helper_agent_id' => $subHelperA->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'helper_run_id' => $subDelegationRunId,
            'owner_user_id' => $this->user->id,
            'task' => 'A sub-task helperA delegates on its own initiative.',
            'depth' => 2,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'result_status' => 'success',
            'managed_task_id' => $task->id,
            'part_id' => null,
        ]);
        $this->seedUsage((string) Str::uuid(), $subDelegationRunId, '0.0400000000', 8000);

        $partBRoundOneRunId = (string) Str::uuid();
        Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helperB->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'helper_run_id' => $partBRoundOneRunId,
            'owner_user_id' => $this->user->id,
            'task' => 'Do part B, attempt 1.',
            'depth' => 1,
            'status' => 'completed',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(4),
            'result_status' => 'failure',
            'managed_task_id' => $task->id,
            'part_id' => $partB->id,
        ]);
        $this->seedUsage((string) Str::uuid(), $partBRoundOneRunId, '0.0902000000', 25100);

        $partBRoundTwoRunId = (string) Str::uuid();
        Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helperB->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'helper_run_id' => $partBRoundTwoRunId,
            'owner_user_id' => $this->user->id,
            'task' => 'Do part B, attempt 2 -- correction.',
            'depth' => 1,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'result_status' => 'success',
            'managed_task_id' => $task->id,
            'part_id' => $partB->id,
        ]);
        $this->seedUsage((string) Str::uuid(), $partBRoundTwoRunId, '0.0914500000', 26130);

        // -------------------------------------------------------------
        // Final call: the full tree exists now.
        // -------------------------------------------------------------
        $result = app(ManagedTaskQuery::class)->costForTask($this->user->id, $task->id);

        $this->assertNotNull($result);

        // SC-010: total_cost equals the EXACT arithmetic sum of all four
        // seeded cost sources -- manager's own two invocations, part A's
        // direct helper, part A's helper's own sub-delegation, and part
        // B's two rounds.
        $expectedTotal = array_reduce(
            ['0.0300000000', '0.0212000000', '0.1890000000', '0.0400000000', '0.0902000000', '0.0914500000'],
            fn (string $carry, string $amount) => bcadd($carry, $amount, 10),
            '0',
        );
        $this->assertSame($expectedTotal, $result['total_cost']);
        $this->assertSame('0.4618500000', $result['total_cost']);

        $this->assertSame('0.0512000000', $result['manager_cost'], 'manager_cost must sum only the manager\'s own conversation, across both step-job invocations');

        $this->assertCount(2, $result['by_part'], 'exactly two by_part entries -- A and B, never a third for the sub-delegation\'s own null part_id');
        $byPart = collect($result['by_part'])->keyBy('part_id');

        $this->assertArrayHasKey($partA->id, $byPart);
        $this->assertArrayHasKey($partB->id, $byPart);

        $partAExpectedCost = bcadd('0.1890000000', '0.0400000000', 10);
        $this->assertSame(
            $partAExpectedCost,
            $byPart[$partA->id]['total_cost'],
            'part A\'s entry must include the sub-delegation\'s cost, even though it was never a direct assign_part call (transitive attribution)',
        );
        $this->assertCount(2, $byPart[$partA->id]['rounds'], 'part A\'s own contributing rows: the direct delegation and its own sub-delegation');

        $partBExpectedCost = bcadd('0.0902000000', '0.0914500000', 10);
        $this->assertSame($partBExpectedCost, $byPart[$partB->id]['total_cost']);
        $this->assertCount(2, $byPart[$partB->id]['rounds'], 'part B\'s entry must list both rounds individually');

        // The identity itself, computed independently from the response.
        $sumOfParts = array_reduce(
            $result['by_part'],
            fn (string $carry, array $part) => bcadd($carry, $part['total_cost'], 10),
            '0',
        );
        $this->assertSame(
            bcadd($result['manager_cost'], $sumOfParts, 10),
            $result['total_cost'],
            'total_cost must equal manager_cost plus the exact sum of every by_part[].total_cost',
        );
    }
}
