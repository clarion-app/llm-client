<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ManagerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;

/**
 * A managed task runs on one budget, and stops on it with nobody to ask.
 *
 * Starting a managed task opens one task budget for it, and its manager's
 * conversation points at it; every round the manager works and every helper
 * it assigns a part to claims against that one budget. When the ceiling is
 * reached the manager does not wait for an answer nobody can give: its round
 * halts, with the report in its record, and the next round finalizes the
 * managed task with a shortfall that names the task's budget — before it
 * would make any model request at all. A managed task that finishes within
 * its budget ends its budget as completed.
 *
 * The manager's own per-round bound and the round ceiling still apply
 * exactly as before: the task budget bounds the whole managed task, never
 * one round in place of its own bound.
 *
 * Each round runs as its queued job does, one at a time, each in a fresh
 * container scope.
 */
class ManagedTaskBudgetJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetHelperJourneys;

    private Agent $manager;

    private Agent $helper;

    /** @var array<int, true> */
    private array $workedRounds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHelperJourney();
        $this->entryPath = 'sync';
        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class, RunManagedTaskStepJob::class]);

        $this->fixtureWithModel();
        $this->manager = $this->agent('manager-agent');
        $this->helper = $this->agent('part-helper');
        $this->assignHelper($this->manager, $this->helper);
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /**
     * Starts a managed task through its endpoint and plans its two parts.
     *
     * @return array{0: ManagedTask, 1: ManagedTaskPart, 2: ManagedTaskPart}
     */
    private function startManagedTask(): array
    {
        $response = $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/managed-tasks', [
            'agent_id' => $this->manager->id,
            'request' => 'Write the two-part report.',
        ])->assertStatus(202);

        $task = ManagedTask::findOrFail($response->json('managed_task_id'));
        [$one, $two] = app(ManagerService::class)->planParts($task, ['Part one.', 'Part two.']);

        return [$task, $one, $two];
    }

    private function assignCall(string $id, ManagedTaskPart $part): array
    {
        return ['id' => $id, 'name' => 'assign_part', 'arguments' => ['part_id' => $part->id, 'helper_agent_id' => $this->helper->id, 'task' => "Do {$part->description}"]];
    }

    private function acceptCall(string $id, ManagedTaskPart $part): array
    {
        return ['id' => $id, 'name' => 'accept_part', 'arguments' => ['part_id' => $part->id]];
    }

    /** Work the next queued round, as a queue worker would. Returns false when none is queued. */
    private function workNextRound(): bool
    {
        foreach (Queue::pushed(RunManagedTaskStepJob::class)->all() as $job) {
            if (isset($this->workedRounds[spl_object_id($job)])) {
                continue;
            }
            $this->workedRounds[spl_object_id($job)] = true;
            $this->app->forgetScopedInstances();
            $this->app->call([$job, 'handle']);

            return true;
        }

        return false;
    }

    private function managerRequestsIn(ManagedTask $task): int
    {
        return count($this->usageOf($task->conversation_id));
    }

    #[Test]
    public function at_the_ceiling_the_manager_halts_and_its_next_round_finalizes_with_a_shortfall_naming_the_task_budget(): void
    {
        $this->scenario = 'task_budget_managed_task_halt';
        config(['llm-client.agent_loop.max_iterations' => 5]);
        [$managed, $one, $two] = $this->startManagedTask();

        $budget = $this->onlyTask();
        $this->assertSame('managed_task', $budget->scope);
        $this->assertSame($managed->id, $budget->managed_task_id);
        $this->assertTrue((bool) $budget->unattended, 'nobody is present to be asked');
        $this->assertSame($budget->id, Conversation::findOrFail($managed->conversation_id)->active_task_budget_id, 'the manager\'s conversation points at it');
        $this->assertSame(5, (int) $budget->max_steps);

        // Round 1: assign part one (the helper works and answers), accept
        // it, assign part two — the helper finds no step left — and the
        // manager's next step is refused.
        $this->script()->toolRequests([$this->assignCall('call_assign_1', $one)]);
        $this->scriptNoteSteps(['part one']);
        $this->scriptHelperAnswer('Part one is written.');
        $this->script()->toolRequests([$this->acceptCall('call_accept_1', $one)]);
        $this->script()->toolRequests([$this->assignCall('call_assign_2', $two)]);

        $this->assertTrue($this->workNextRound());

        $budget = TaskBudget::findOrFail($budget->id);
        $this->assertSame(5, $this->agentRequests(), 'manager 3, helper 2');
        $this->assertSame(5, (int) $budget->steps_used, 'the manager\'s and the helper\'s requests all claimed against the one budget');
        $this->assertSame('halted', $budget->state->value, 'nobody to ask: the managed task\'s budget halts');
        $this->assertSame(0, AgentQuestion::count(), 'no prompt is raised for a managed task');
        $this->assertSame(
            1,
            Message::where('conversation_id', $managed->conversation_id)->get()->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_REPORT)->count(),
            'the report is in the manager\'s record',
        );
        $this->assertSame(
            'task_budget',
            \ClarionApp\LlmClient\Models\Delegation::findOrFail($two->fresh()->current_delegation_id)->result_reason,
            'the helper assigned part two was stopped by the task\'s budget',
        );
        foreach (\ClarionApp\LlmClient\Models\Delegation::all() as $delegation) {
            $this->assertSame($budget->id, Conversation::findOrFail($delegation->helper_conversation_id)->active_task_budget_id, 'every assigned helper works on the managed task\'s budget');
        }
        $this->assertSame('in_progress', $managed->fresh()->status, 'precondition: finalizing is the next round\'s job');

        // Round 2: finalized with a shortfall before any model request.
        $requestsBefore = $this->agentRequests();
        $runsBefore = DB::table('agent_runs')->count();
        $this->assertTrue($this->workNextRound(), 'precondition: the halted round queued the next one');

        $this->assertSame($requestsBefore, $this->agentRequests(), 'the round made no model request');
        $this->assertSame($runsBefore, DB::table('agent_runs')->count(), 'the round never started the manager\'s loop');

        $managed->refresh();
        $shortfall = "This task's budget ceiling was reached before this part could be completed.";
        $this->assertSame('completed_with_shortfalls', $managed->status);
        $this->assertStringStartsWith($shortfall, (string) $managed->shortfall_note, 'the shortfall names the task\'s budget');
        $this->assertSame('accepted', $one->fresh()->state, 'the accepted part is kept');
        $this->assertSame('reported_as_shortfall', $two->fresh()->state);
        $this->assertStringStartsWith($shortfall, (string) $two->fresh()->shortfall_reason);
        $this->assertSame('Part one is written.', $managed->final_response, 'the result is built from the accepted part');
        $this->assertFalse($this->workNextRound(), 'a finalized managed task queues nothing further');
        $this->assertSame('halted', TaskBudget::findOrFail($budget->id)->state->value, 'the halt stays on the record');
    }

    #[Test]
    public function within_its_budget_a_managed_task_keeps_its_own_round_bound_and_ends_its_budget_as_completed(): void
    {
        $this->scenario = 'task_budget_managed_task_completes';
        config(['llm-client.manager.step_max_iterations' => 4]);
        [$managed, $one, $two] = $this->startManagedTask();
        $budget = $this->onlyTask();
        $this->assertSame(20, (int) $budget->max_steps, 'the default ceiling');

        // Round 1: four manager steps — its own per-round bound — each
        // assignment answered by the helper.
        $this->script()->toolRequests([$this->assignCall('call_assign_1', $one)]);
        $this->scriptNoteSteps(['part one']);
        $this->scriptHelperAnswer('Part one is written.');
        $this->script()->toolRequests([$this->acceptCall('call_accept_1', $one)]);
        $this->script()->toolRequests([$this->assignCall('call_assign_2', $two)]);
        $this->scriptNoteSteps(['part two']);
        $this->scriptHelperAnswer('Part two is written.');
        $this->script()->toolRequests([$this->acceptCall('call_accept_2', $two)]);

        $this->assertTrue($this->workNextRound());

        $this->assertSame(4, $this->managerRequestsIn($managed), 'the round stopped at the manager\'s own bound of 4 steps, not at the task budget');
        $this->assertSame(8, (int) TaskBudget::findOrFail($budget->id)->steps_used);
        $this->assertSame('running', TaskBudget::findOrFail($budget->id)->state->value, 'a round ending at its own bound leaves the budget running');
        $this->assertSame(2, (int) $managed->fresh()->rounds_used);
        $this->assertSame(30, (int) $managed->fresh()->round_ceiling, 'the round ceiling is unchanged');

        // Round 2: finalize.
        $this->script()->toolRequests([['id' => 'call_finalize', 'name' => 'finalize_task', 'arguments' => ['final_response' => 'Both parts of the report are written.']]]);
        $this->script()->finalAnswer('Task finalized.');

        $this->assertTrue($this->workNextRound());

        $managed->refresh();
        $this->assertSame('completed', $managed->status);
        $budget = TaskBudget::findOrFail($budget->id);
        $this->assertSame(10, (int) $budget->steps_used, 'every request of both rounds and both helpers');
        $this->assertSame(10, $this->agentRequests());
        $this->assertSame('completed', $budget->state->value, 'finalizing the managed task ends its budget as completed');
        $this->assertNotNull($budget->ended_at);
        $this->assertFalse($this->workNextRound());
    }
    #[Test]
    public function a_managed_task_already_in_progress_before_task_budgets_keeps_one_budget_across_its_rounds(): void
    {
        $this->scenario = 'task_budget_managed_task_legacy';
        config(['llm-client.manager.step_max_iterations' => 4]);
        [$managed, $one, $two] = $this->startManagedTask();
        // Started before task budgets existed: no budget, and its manager's
        // conversation points at none.
        DB::table('task_budget_events')->delete();
        DB::table('task_budgets')->delete();
        DB::table('conversations')->where('id', $managed->conversation_id)->update(['active_task_budget_id' => null]);

        // Round 1 ends at the manager's own bound of 4 steps.
        $this->script()->toolRequests([$this->assignCall('call_assign_1', $one)]);
        $this->scriptNoteSteps(['part one']);
        $this->scriptHelperAnswer('Part one is written.');
        $this->script()->toolRequests([$this->acceptCall('call_accept_1', $one)]);
        $this->script()->toolRequests([$this->assignCall('call_assign_2', $two)]);
        $this->scriptNoteSteps(['part two']);
        $this->scriptHelperAnswer('Part two is written.');
        $this->script()->toolRequests([$this->acceptCall('call_accept_2', $two)]);
        $this->assertTrue($this->workNextRound());

        $budget = $this->onlyTask();
        $this->assertSame('managed_task', $budget->scope, 'the managed task gets a budget of its own, not one tied to a round');
        $this->assertSame($managed->id, $budget->managed_task_id);
        $this->assertTrue((bool) $budget->unattended);
        $this->assertSame('running', $budget->state->value, 'a round ending leaves the managed task\'s budget running');
        $this->assertSame(8, (int) $budget->steps_used);

        // Round 2 finalizes the managed task, with no shortfall.
        $this->script()->toolRequests([['id' => 'call_finalize', 'name' => 'finalize_task', 'arguments' => ['final_response' => 'Both parts are written.']]]);
        $this->script()->finalAnswer('Task finalized.');
        $this->assertTrue($this->workNextRound());

        $managed->refresh();
        $this->assertSame('completed', $managed->status, 'never finalized with a shortfall the budget did not cause');
        $this->assertSame(1, TaskBudget::count(), 'one budget across every round');
        $this->assertSame('completed', TaskBudget::firstOrFail()->state->value);
    }
}
