<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
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
 * 103-manager-agent, Phase 9 (US7), tasks.md T065.
 *
 * Unit tests for the not-yet-built `ManagedTaskQuery::costForTask()`
 * (data-model.md §6, research.md D10, contracts/manager-agent-api.md §4).
 *
 * Sums `usage_records` scoped to (a) `conversation_id = $task->conversation_id`
 * (the manager's own reasoning cost) and (b) every `helper_run_id` among
 * `agent_delegations` rows sharing `managed_task_id` -- mutation-checklist
 * row 7 (summing by `parent_run_id` instead of `managed_task_id` must be
 * provably wrong here, since a helper-of-helper's own delegation carries no
 * `parent_run_id` pointing back at the task's own conversation run at all).
 * Returns the shape in contracts §4; `total_cost` equals `manager_cost` plus
 * the sum of every `by_part[].total_cost` EXACTLY (SC-010), asserted as a
 * direct arithmetic identity, not merely "both numbers look plausible".
 * Owner-scoped via `findManagedTask()` first.
 *
 * Written before `ManagedTaskQuery::costForTask()` exists -- every
 * assertion below is expected to FAIL red (method not found) until T068.
 */
class ManagedTaskQueryCostForTaskTest extends TestCase
{
    private User $user;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
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

    private function makeAgent(User $owner, string $name): Agent
    {
        return app(AgentService::class)->create($owner->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    /**
     * A `usage_records` row inserted directly (bypassing Eloquent mass
     * assignment, `run_id` is deliberately not fillable -- matches
     * UsagePreExistingRecordCompatibilityTest's own established precedent
     * for setting `run_id` directly).
     */
    private function seedUsage(string $userId, string $conversationId, ?string $runId, string $cost, int $tokens): void
    {
        DB::table('usage_records')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversationId,
            'user_id' => $userId,
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

    // =================================================================
    // Ownership
    // =================================================================

    #[Test]
    public function returns_null_for_an_unknown_task_id(): void
    {
        $result = app(ManagedTaskQuery::class)->costForTask($this->user->id, (string) Str::uuid());

        $this->assertNull($result);
    }

    #[Test]
    public function returns_null_for_a_task_not_owned_by_the_caller(): void
    {
        $manager = $this->makeAgent($this->otherUser, 'manager-foreign');
        $task = app(ManagerService::class)->createManagedTask($this->otherUser->id, $manager->id, 'Someone else\'s task.');

        $result = app(ManagedTaskQuery::class)->costForTask($this->user->id, $task->id);

        $this->assertNull($result);
    }

    // =================================================================
    // Shape + arithmetic identity
    // =================================================================

    #[Test]
    public function total_cost_equals_manager_cost_plus_the_exact_sum_of_every_by_part_total_cost(): void
    {
        $manager = $this->makeAgent($this->user, 'manager-cost-identity');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A two-part task.');
        $task->round_ceiling = 30;
        $task->rounds_used = 3;
        $task->save();

        [$partA, $partB] = app(ManagerService::class)->planParts($task, ['Part A.', 'Part B.']);

        $helper = $this->makeAgent($this->user, 'helper-cost-identity');

        // Manager's own reasoning cost -- two step-job invocations' worth,
        // scoped to the task's own conversation_id, no run_id required.
        $this->seedUsage($this->user->id, $task->conversation_id, null, '0.0300000000', 1000);
        $this->seedUsage($this->user->id, $task->conversation_id, null, '0.0212000000', 800);

        // Part A: one direct delegation.
        $partARunId = (string) Str::uuid();
        Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'helper_run_id' => $partARunId,
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
        $this->seedUsage($this->user->id, (string) Str::uuid(), $partARunId, '0.1890000000', 54200);

        // Part B: two rounds (a correction), each its own Delegation row.
        $partBRunOne = (string) Str::uuid();
        Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'helper_run_id' => $partBRunOne,
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
        $this->seedUsage($this->user->id, (string) Str::uuid(), $partBRunOne, '0.0902000000', 25100);

        $partBRunTwo = (string) Str::uuid();
        Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'helper_run_id' => $partBRunTwo,
            'owner_user_id' => $this->user->id,
            'task' => 'Do part B, attempt 2.',
            'depth' => 1,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'result_status' => 'failure',
            'managed_task_id' => $task->id,
            'part_id' => $partB->id,
        ]);
        $this->seedUsage($this->user->id, (string) Str::uuid(), $partBRunTwo, '0.0914500000', 26130);

        $result = app(ManagedTaskQuery::class)->costForTask($this->user->id, $task->id);

        $this->assertNotNull($result);
        $this->assertSame(
            ['managed_task_id', 'total_cost', 'total_tokens', 'rounds_used', 'round_ceiling', 'manager_cost', 'by_part'],
            array_keys($result),
            'the top-level response shape must be exactly contracts §4\'s keys',
        );

        $this->assertSame($task->id, $result['managed_task_id']);
        $this->assertSame(3, $result['rounds_used']);
        $this->assertSame(30, $result['round_ceiling']);
        $this->assertSame('0.0512000000', $result['manager_cost'], 'manager_cost must sum only the manager\'s own conversation, 0.03 + 0.0212');

        $this->assertCount(2, $result['by_part']);
        $byPart = collect($result['by_part'])->keyBy('part_id');

        $this->assertSame('0.1890000000', $byPart[$partA->id]['total_cost']);
        $this->assertCount(1, $byPart[$partA->id]['rounds']);

        $this->assertSame('0.1816500000', $byPart[$partB->id]['total_cost'], '0.0902 + 0.09145');
        $this->assertCount(2, $byPart[$partB->id]['rounds'], 'part B\'s entry must list both rounds individually');

        // The arithmetic identity itself (SC-010): computed independently
        // here from the SAME response, not merely eyeballed against the
        // literal seeded numbers above.
        $sumOfParts = array_reduce(
            $result['by_part'],
            fn (string $carry, array $part) => bcadd($carry, $part['total_cost'], 10),
            '0',
        );
        $expectedTotal = bcadd($result['manager_cost'], $sumOfParts, 10);
        $this->assertSame(
            $expectedTotal,
            $result['total_cost'],
            'total_cost must equal manager_cost plus the exact sum of every by_part[].total_cost',
        );
        $this->assertSame('0.4218500000', $result['total_cost']);
    }

    #[Test]
    public function a_helper_of_helper_sub_delegations_cost_is_transitively_attributed_to_its_ancestor_part(): void
    {
        $manager = $this->makeAgent($this->user, 'manager-transitive');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task whose part\'s helper delegates further.');
        [$part] = app(ManagerService::class)->planParts($task, ['The only part.']);

        $helper = $this->makeAgent($this->user, 'helper-transitive');
        $subHelper = $this->makeAgent($this->user, 'sub-helper-transitive');

        // The direct assign_part delegation: part_id set, its own
        // helper_run_id both records usage AND is the run the sub-delegation
        // below is made FROM (its own parent_run_id).
        $directRunId = (string) Str::uuid();
        Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'helper_run_id' => $directRunId,
            'owner_user_id' => $this->user->id,
            'task' => 'Do the only part.',
            'depth' => 1,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'result_status' => 'success',
            'managed_task_id' => $task->id,
            'part_id' => $part->id,
        ]);
        $this->seedUsage($this->user->id, (string) Str::uuid(), $directRunId, '0.1000000000', 10000);

        // The sub-delegation: managed_task_id inherited (T067), part_id
        // null, parent_run_id pointing at the direct delegation's own
        // helper_run_id -- this is the transitive attribution link
        // costForTask() must resolve WITHOUT a recursive query (D10).
        $subRunId = (string) Str::uuid();
        Delegation::create([
            'parent_conversation_id' => (string) Str::uuid(),
            'parent_run_id' => $directRunId,
            'helper_agent_id' => $subHelper->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'helper_run_id' => $subRunId,
            'owner_user_id' => $this->user->id,
            'task' => 'A sub-task the helper delegates on its own.',
            'depth' => 2,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'result_status' => 'success',
            'managed_task_id' => $task->id,
            'part_id' => null,
        ]);
        $this->seedUsage($this->user->id, (string) Str::uuid(), $subRunId, '0.0250000000', 3000);

        $result = app(ManagedTaskQuery::class)->costForTask($this->user->id, $task->id);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['by_part']);
        $onlyPart = $result['by_part'][0];

        $this->assertSame(
            '0.1250000000',
            $onlyPart['total_cost'],
            'the part\'s own total must include the sub-delegation\'s cost transitively, even though the sub-delegation carries no part_id of its own',
        );
        $this->assertCount(2, $onlyPart['rounds'], 'both the direct delegation and its own sub-delegation contribute a round entry');
        $this->assertSame(bcadd($result['manager_cost'], $onlyPart['total_cost'], 10), $result['total_cost']);
    }

    // =================================================================
    // Mid-task running total (SC-011)
    // =================================================================

    #[Test]
    public function returns_an_accurate_nonzero_running_total_before_the_task_reaches_a_terminal_state(): void
    {
        $manager = $this->makeAgent($this->user, 'manager-mid-task');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task still in progress.');
        $this->assertSame('in_progress', $task->status, 'fixture sanity: the task is still in progress');

        [$part] = app(ManagerService::class)->planParts($task, ['The only part.']);
        $helper = $this->makeAgent($this->user, 'helper-mid-task');

        $this->seedUsage($this->user->id, $task->conversation_id, null, '0.0100000000', 500);

        $runId = (string) Str::uuid();
        Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'helper_run_id' => $runId,
            'owner_user_id' => $this->user->id,
            'task' => 'Still working on it.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'managed_task_id' => $task->id,
            'part_id' => $part->id,
        ]);
        $this->seedUsage($this->user->id, (string) Str::uuid(), $runId, '0.0500000000', 5000);

        $result = app(ManagedTaskQuery::class)->costForTask($this->user->id, $task->id);

        $this->assertNotNull($result);
        $this->assertNotSame('0.0000000000', $result['total_cost']);
        $this->assertSame('0.0600000000', $result['total_cost']);
    }

    #[Test]
    public function a_part_with_no_delegations_yet_reports_a_zero_cost_entry_not_an_absent_one(): void
    {
        $manager = $this->makeAgent($this->user, 'manager-zero-part');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task with an unstarted part.');
        [$part] = app(ManagerService::class)->planParts($task, ['Not yet assigned.']);

        $result = app(ManagedTaskQuery::class)->costForTask($this->user->id, $task->id);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['by_part']);
        $this->assertSame($part->id, $result['by_part'][0]['part_id']);
        $this->assertSame('0.0000000000', $result['by_part'][0]['total_cost']);
        $this->assertSame(0, $result['by_part'][0]['total_tokens']);
        $this->assertSame([], $result['by_part'][0]['rounds']);
        $this->assertSame('0.0000000000', $result['total_cost']);
    }
}
