<?php

namespace Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\RunUpdated;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\Services\TaskBudgetPauser;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use ClarionApp\LlmClient\ValueObjects\TraceExportConfig;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * How a task budget shows in the run record, and how the run's end ends the
 * task.
 *
 * While a task is paused at its ceiling its root run stays in progress —
 * the work is not over, it is waiting for a decision — and the cleanup sweep
 * leaves it alone because the prompt is waiting on its user. A task with
 * nobody to ask halts, and its run closes as stopped early, naming the task
 * budget. When the root run of a running task ends on its own, the task ends
 * with it: completed when the run completed, ended otherwise; a task that is
 * paused, halted or concluded already decided its own state and is left
 * alone.
 *
 * Ending the task is never allowed to get in the way of closing the run: if
 * it fails, the run is still closed, forwarded and announced.
 *
 * Every description of a run — the run list, a single run, the live push
 * and the arrangement's runs map — carries `task_budget`: the task whose
 * root run, or paused run, it is (the latest when there are several), as
 * its state, whether it is waiting for a decision or stopped, and its
 * consumption against its ceilings; null for a run that is neither. A list
 * looks the tasks up in one query however many runs it holds. The run's
 * owner can also read the task's standing and its whole record of events,
 * oldest first; a run that does not exist and one that belongs to someone
 * else get the same 404.
 */
class TaskBudgetRunRecordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'UTC'));
        config(['llm-client.run_trace.enabled' => true, 'llm-client.run_trace.abandonment_minutes' => 60]);
        TraceExportConfig::reset();
        app(ConversationChannelRegistry::class)->register(new RecordingChannelDelivery('web'));
    }

    protected function tearDown(): void
    {
        foreach (['task_budget_events', 'task_budgets', 'agent_questions', 'agent_run_export_queue', 'agent_run_actions', 'agent_run_steps', 'agent_runs', 'usage_records', 'messages', 'conversations', 'users'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        TraceExportConfig::reset();

        parent::tearDown();
    }

    private function conversation(?string $userId = 'user'): Conversation
    {
        return Conversation::create([
            'user_id' => $userId === null ? null : (string) Str::uuid(),
            'channel' => 'web',
            'title' => 'Run record',
            'character' => 'Clarion',
            'is_processing' => true,
        ]);
    }

    /** A root run and its running task. */
    private function rootRun(?string $userId = 'user'): array
    {
        $conversation = $this->conversation($userId);
        $runId = app(RunTraceRecorder::class)->openRun(RunKind::Interactive, (string) $conversation->user_id, $conversation->id, streamed: false);
        $task = app(TaskBudgetService::class)->forNewRun($conversation, new StatedCeilings(maxSteps: 2), false, $runId);
        $this->assertSame($runId, $task->root_run_id, 'precondition: the task belongs to the run');

        return [$conversation, $runId, $task];
    }

    private function reachCeiling(Conversation $conversation, string $runId, TaskBudget $task): void
    {
        $gate = app(TaskBudgetGate::class);
        $gate->beforeStep($conversation, $runId);
        $gate->beforeStep($conversation, $runId);
        $decision = $gate->beforeStep($conversation, $runId);
        $this->assertTrue($decision->isStop());
        $stepId = app(RunTraceRecorder::class)->openStep($runId, 3);
        app(TaskBudgetPauser::class)->pause($conversation, $task->fresh(), $decision, $runId, $stepId, 3, 'sync');
    }

    private function runRow(string $runId): object
    {
        return DB::table('agent_runs')->where('id', $runId)->first();
    }

    private function taskRow(TaskBudget $task): object
    {
        return DB::table('task_budgets')->where('id', $task->id)->first();
    }

    private function sweepAbandonedRuns(): void
    {
        $this->assertSame(0, Artisan::call('llm-client:resolve-abandoned-runs'));
    }

    // ---------------------------------------------------------------
    // Paused and halted
    // ---------------------------------------------------------------

    #[Test]
    public function a_paused_tasks_run_stays_in_progress_and_the_abandonment_sweep_leaves_it_alone(): void
    {
        [$conversation, $runId, $task] = $this->rootRun();
        $this->reachCeiling($conversation, $runId, $task);
        $this->assertSame('paused', $this->taskRow($task)->state, 'precondition');

        $this->travel(3)->hours();
        $this->sweepAbandonedRuns();

        $this->assertSame('in_progress', $this->runRow($runId)->end_state, 'waiting on a decision is not abandoned work');
        $this->assertSame('paused', $this->taskRow($task)->state);
    }

    #[Test]
    public function a_halted_tasks_run_is_stopped_early_naming_the_task_budget(): void
    {
        [$conversation, $runId, $task] = $this->rootRun(null);
        $this->reachCeiling($conversation, $runId, $task);

        $this->assertSame('halted', $this->taskRow($task)->state);
        $run = $this->runRow($runId);
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame('Task budget ceiling reached', $run->end_reason);
    }

    // ---------------------------------------------------------------
    // The run's end ends a running task
    // ---------------------------------------------------------------

    #[Test]
    public function a_completed_root_run_completes_its_running_task(): void
    {
        [, $runId, $task] = $this->rootRun();

        app(RunTraceRecorder::class)->closeRun($runId, RunEndState::Completed);

        $row = $this->taskRow($task);
        $this->assertSame('completed', $row->state);
        $this->assertNotNull($row->ended_at);
        $this->assertNull($row->end_reason);
        $ended = DB::table('task_budget_events')->where('task_budget_id', $task->id)->where('kind', 'ended')->value('payload');
        $this->assertSame('completed', json_decode((string) $ended, true)['reason'] ?? null);
    }

    public static function otherEndings(): array
    {
        return [
            'failed' => [RunEndState::Failed, 'Agent loop error: provider unreachable', 'failed'],
            'stopped early' => [RunEndState::StoppedEarly, 'Conversation work ceiling reached', 'stopped_early'],
        ];
    }

    #[Test]
    #[DataProvider('otherEndings')]
    public function any_other_ending_of_the_root_run_ends_its_running_task(RunEndState $endState, string $runReason, string $taskReason): void
    {
        [, $runId, $task] = $this->rootRun();

        app(RunTraceRecorder::class)->closeRun($runId, $endState, $runReason);

        $row = $this->taskRow($task);
        $this->assertSame('ended', $row->state);
        $this->assertSame($taskReason, $row->end_reason);
        $this->assertNotNull($row->ended_at);
        $ended = json_decode((string) DB::table('task_budget_events')->where('task_budget_id', $task->id)->where('kind', 'ended')->value('payload'), true);
        $this->assertSame($taskReason, $ended['reason'] ?? null);
        $this->assertSame($runReason, $ended['run_end_reason'] ?? null);
    }

    #[Test]
    public function an_abandoned_root_run_ends_its_running_task_through_the_real_sweep(): void
    {
        [, $runId, $task] = $this->rootRun();
        app(RunTraceRecorder::class)->openStep($runId, 1);

        $this->travel(3)->hours();
        $this->sweepAbandonedRuns();

        $this->assertSame('abandoned', $this->runRow($runId)->end_state, 'precondition: the sweep abandoned it');
        $row = $this->taskRow($task);
        $this->assertSame('ended', $row->state);
        $this->assertSame('abandoned', $row->end_reason);
    }

    #[Test]
    public function a_paused_halted_or_concluded_task_is_left_alone_when_its_run_closes(): void
    {
        foreach (['paused', 'halted', 'concluded'] as $state) {
            [, $runId, $task] = $this->rootRun();
            DB::table('task_budgets')->where('id', $task->id)->update(['state' => $state]);
            $before = (array) $this->taskRow($task);

            app(RunTraceRecorder::class)->closeRun($runId, RunEndState::Completed);

            $this->assertSame($before, (array) $this->taskRow($task), "a {$state} task decided its own state");
        }
    }

    #[Test]
    public function only_the_tasks_own_root_run_ends_it(): void
    {
        [$conversation, , $task] = $this->rootRun();
        $otherRun = app(RunTraceRecorder::class)->openRun(RunKind::Interactive, (string) $conversation->user_id, $conversation->id);

        app(RunTraceRecorder::class)->closeRun($otherRun, RunEndState::Completed);

        $this->assertSame('running', $this->taskRow($task)->state);
    }

    // ---------------------------------------------------------------
    // Ending the task never gets in the way of closing the run
    // ---------------------------------------------------------------

    #[Test]
    public function a_failure_to_end_the_task_never_prevents_the_run_being_closed_forwarded_and_announced(): void
    {
        config([
            'llm-client.run_trace.export.destinations' => ['internal', 'external'],
            'llm-client.run_trace.export.otlp_endpoint' => 'https://tempo.example.com:4318/v1/traces',
        ]);
        TraceExportConfig::reset();
        [, $runId, $task] = $this->rootRun();

        $this->app->bind(TaskBudgetService::class, fn ($app) => new class($app->make(\ClarionApp\LlmClient\Services\TaskBudgetLimits::class), $app->make(\ClarionApp\LlmClient\Services\CostRollupQuery::class)) extends TaskBudgetService {
            public function rootRunClosed(string $runId, RunEndState $endState, ?string $reason): void
            {
                throw new \RuntimeException('task budget store unavailable');
            }
        });
        Event::fake([RunUpdated::class]);

        app(RunTraceRecorder::class)->closeRun($runId, RunEndState::Completed);

        $this->assertSame('completed', $this->runRow($runId)->end_state, 'the terminal row is written');
        $this->assertSame(1, DB::table('agent_run_export_queue')->where('run_id', $runId)->count(), 'forwarding is still enqueued');
        Event::assertDispatched(RunUpdated::class, fn (RunUpdated $e) => $e->runId === $runId);
        $this->assertSame('running', $this->taskRow($task)->state);
    }

    #[Test]
    public function the_run_close_resolves_the_task_service_when_it_closes(): void
    {
        [, $runId, $task] = $this->rootRun();
        $calls = new \ArrayObject();
        $this->app->bind(TaskBudgetService::class, fn ($app) => new class($app->make(\ClarionApp\LlmClient\Services\TaskBudgetLimits::class), $app->make(\ClarionApp\LlmClient\Services\CostRollupQuery::class), $calls) extends TaskBudgetService {
            public function __construct($limits, $costs, private \ArrayObject $calls)
            {
                parent::__construct($limits, $costs);
            }

            public function rootRunClosed(string $runId, RunEndState $endState, ?string $reason): void
            {
                $this->calls[] = [$runId, $endState, $reason];
            }
        });

        app(RunTraceRecorder::class)->closeRun($runId, RunEndState::StoppedEarly, 'Delegation time bound reached');

        $this->assertSame([[$runId, RunEndState::StoppedEarly, 'Delegation time bound reached']], $calls->getArrayCopy(), 'called once, with the run\'s end state and reason');
    }

    // ---------------------------------------------------------------
    // The task budget in every description of a run
    // ---------------------------------------------------------------

    /** @return array{0: User, 1: Conversation, 2: string, 3: TaskBudget} a run of $user's and its running task */
    private function ownedRootRun(int $maxSteps = 2, bool $reachable = true): array
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $reachable ? $user->id : null,
            'channel' => 'web',
            'title' => 'Run record',
            'character' => 'Clarion',
            'is_processing' => true,
        ]);
        $runId = app(RunTraceRecorder::class)->openRun(RunKind::Interactive, $user->id, $conversation->id, streamed: false);
        $task = app(TaskBudgetService::class)->forNewRun($conversation, new StatedCeilings(maxSteps: $maxSteps), false, $runId);
        $this->assertSame($runId, $task->root_run_id, 'precondition: the task belongs to the run');

        return [$user, $conversation, $runId, $task];
    }

    private function usage(TaskBudget $task, ?string $cost, bool $unpriced = false, bool $estimated = false): void
    {
        DB::table('usage_records')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $task->root_conversation_id,
            'user_id' => $task->user_id,
            'attempt_group_id' => (string) Str::uuid(),
            'task_budget_id' => $task->id,
            'total_cost' => $cost,
            'cost_unpriced' => $unpriced,
            'cost_estimated' => $estimated,
            'total_tokens' => 100,
            'created_at' => now(),
        ]);
    }

    /** The run as each summary producer describes it to $user. */
    private function summariesOf(User $user, string $runId): array
    {
        $listed = collect(
            $this->actingAs($user, 'api')->getJson('/api/clarion-app/llm-client/agent-runs')->assertStatus(200)->json('data'),
        )->firstWhere('id', $runId);
        $this->assertIsArray($listed, 'the run is in its owner\'s run list');
        $arrangement = $this->actingAs($user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/arrangement")
            ->assertStatus(200)
            ->json();
        $this->assertIsArray($arrangement['runs'][$runId] ?? null, 'the arrangement describes the run');

        return [
            'run list' => $listed,
            'single run' => $this->actingAs($user, 'api')->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}")->assertStatus(200)->json(),
            'live push' => (new RunUpdated($runId))->broadcastWith(),
            'arrangement' => $arrangement['runs'][$runId],
        ];
    }

    /** The task as a run summary shows it: the same figures its standing gives. */
    private function summaryBudget(TaskBudget $task): array
    {
        $standing = app(TaskBudgetService::class)->standing($task->fresh())->toArray();

        return [
            'state' => $standing['state'],
            'sub_state' => $standing['sub_state'],
            'steps_used' => $standing['steps']['used'],
            'max_steps' => $standing['steps']['ceiling'],
            'spend_used' => $standing['spend']['used'],
            'max_spend' => $standing['spend']['ceiling'],
            'currency' => $standing['spend']['currency'],
            'approximate' => $standing['spend']['approximate'],
            'incomplete' => $standing['spend']['incomplete'],
        ];
    }

    private function assertEverySummaryShows(User $user, string $runId, ?array $budget, string $when): void
    {
        foreach ($this->summariesOf($user, $runId) as $producer => $summary) {
            $this->assertArrayHasKey('task_budget', $summary, "{$producer} {$when}: the summary says what task budget the run has");
            $this->assertSame($budget, $summary['task_budget'], "{$producer} {$when}: task_budget");
        }
    }

    private function promptOf(TaskBudget $task): AgentQuestion
    {
        $id = $task->fresh()->prompt_question_id;
        $this->assertNotNull($id, 'precondition: the task raised its prompt');

        return AgentQuestion::findOrFail($id);
    }

    #[Test]
    public function every_summary_shows_the_task_of_its_root_run_through_each_state(): void
    {
        config(['llm-client.cost.currency' => 'USD']);
        [$user, $conversation, $runId, $task] = $this->ownedRootRun();
        app(TaskBudgetGate::class)->beforeStep($conversation, $runId);
        $this->usage($task, '0.2500000000');
        $this->usage($task, null, unpriced: true);
        $this->usage($task, '0.1000000000', estimated: true);

        $running = $this->summaryBudget($task);
        $this->assertSame(
            ['state' => 'running', 'sub_state' => null, 'steps_used' => 1, 'max_steps' => 2, 'spend_used' => '0.3500000000', 'max_spend' => '1.0000000000', 'currency' => 'USD', 'approximate' => true, 'incomplete' => true],
            $running,
            'precondition: the expected figures',
        );
        $this->assertEverySummaryShows($user, $runId, $running, 'while the task runs');

        $this->reachCeiling($conversation, $runId, $task);
        $waiting = $this->summaryBudget($task);
        $this->assertSame(['paused', 'awaiting_decision'], [$waiting['state'], $waiting['sub_state']], 'precondition');
        $this->assertEverySummaryShows($user, $runId, $waiting, 'while the prompt waits for a decision');

        app(AgentQuestionService::class)->stopCeilingPrompt($this->promptOf($task), $user->id, 'stop');
        $stopped = $this->summaryBudget($task);
        $this->assertSame(['paused', 'stopped'], [$stopped['state'], $stopped['sub_state']], 'precondition');
        $this->assertEverySummaryShows($user, $runId, $stopped, 'while the task is stopped at its ceiling');

        DB::table('task_budgets')->where('id', $task->id)->update(['state' => 'concluded', 'prompt_question_id' => null]);
        DB::table('agent_questions')->where('task_budget_id', $task->id)->update(['state' => 'answered']);
        $concluded = $this->summaryBudget($task);
        $this->assertSame(['concluded', null], [$concluded['state'], $concluded['sub_state']], 'precondition');
        $this->assertEverySummaryShows($user, $runId, $concluded, 'once the task concluded');
    }

    #[Test]
    public function a_halted_tasks_run_says_so_in_every_summary(): void
    {
        [$user, $conversation, $runId, $task] = $this->ownedRootRun(reachable: false);
        $this->reachCeiling($conversation, $runId, $task);

        $halted = $this->summaryBudget($task);
        $this->assertSame(['halted', null], [$halted['state'], $halted['sub_state']], 'precondition');
        $this->assertEverySummaryShows($user, $runId, $halted, 'once the task halted');
    }

    #[Test]
    public function a_task_with_no_root_run_is_shown_on_the_run_it_paused_on(): void
    {
        [$user, $conversation] = $this->ownedRootRun();
        $task = app(TaskBudgetService::class)->openForRequest($conversation, TaskBudget::SCOPE_SEQUENCE_RUN, new StatedCeilings(maxSteps: 1), null, true);
        $this->assertNull($task->root_run_id, 'precondition: a task opened for a request has no root run');
        $runId = app(RunTraceRecorder::class)->openRun(RunKind::Interactive, $user->id, $conversation->id, streamed: false);
        $gate = app(TaskBudgetGate::class);
        $gate->beforeStep($conversation, $runId);
        $decision = $gate->beforeStep($conversation, $runId);
        $this->assertTrue($decision->isStop(), 'precondition');
        app(TaskBudgetPauser::class)->pause($conversation, $task->fresh(), $decision, $runId, null, 2, 'sync');
        $this->assertSame($runId, $task->fresh()->paused_run_id, 'precondition: the task paused on that run');

        $halted = $this->summaryBudget($task);
        $this->assertSame('halted', $halted['state'], 'precondition: nobody can be asked on an unattended task');
        $this->assertEverySummaryShows($user, $runId, $halted, 'on the run the task paused on');
    }

    #[Test]
    public function a_run_that_is_no_tasks_root_or_paused_run_has_no_task_budget(): void
    {
        [$user, $conversation, $rootRun] = $this->ownedRootRun();
        $otherRun = app(RunTraceRecorder::class)->openRun(RunKind::Interactive, $user->id, $conversation->id, streamed: false);
        $untracked = app(RunTraceRecorder::class)->openRun(RunKind::Interactive, $user->id, null, streamed: false);

        $this->assertEverySummaryShows($user, $otherRun, null, 'for another run of the same conversation');
        $this->assertEverySummaryShows($user, $untracked, null, 'for a run with no conversation');
        $this->assertNotNull($this->summariesOf($user, $rootRun)['single run']['task_budget'], 'precondition: the root run has one');
    }

    #[Test]
    public function a_run_with_several_tasks_shows_the_latest(): void
    {
        [$user, $conversation, $runId, $first] = $this->ownedRootRun();
        DB::table('task_budgets')->where('id', $first->id)->update(['state' => 'ended', 'end_reason' => 'superseded']);
        $this->travel(5)->seconds();
        $latest = app(TaskBudgetService::class)->openLazily($conversation, $runId);
        $this->assertSame($runId, $latest->root_run_id, 'precondition: the second task belongs to the same run');

        $this->assertEverySummaryShows($user, $runId, $this->summaryBudget($latest), 'with two tasks on the run');
        $this->assertSame('running', $this->summariesOf($user, $runId)['single run']['task_budget']['state']);
    }

    /**
     * Listing runs looks their tasks up in one query however many runs are
     * listed, and asks nothing of the question table beyond the two flags
     * the list already looks up.
     */
    #[Test]
    public function a_list_of_runs_looks_up_their_tasks_in_one_query(): void
    {
        $user = User::factory()->create();
        $runs = [];
        foreach (range(1, 4) as $i) {
            $conversation = Conversation::create(['user_id' => $user->id, 'channel' => 'web', 'title' => "Run {$i}", 'character' => 'Clarion']);
            $runId = app(RunTraceRecorder::class)->openRun(RunKind::Interactive, $user->id, $conversation->id, streamed: false);
            $task = app(TaskBudgetService::class)->forNewRun($conversation, new StatedCeilings(maxSteps: 1), false, $runId);
            $this->usage($task, '0.0100000000');
            if ($i % 2 === 0) {
                $gate = app(TaskBudgetGate::class);
                $gate->beforeStep($conversation, $runId);
                $decision = $gate->beforeStep($conversation, $runId);
                app(TaskBudgetPauser::class)->pause($conversation, $task->fresh(), $decision, $runId, null, 2, 'sync');
            }
            $runs[$runId] = $task;
            $this->travel(1)->seconds();
        }

        $counting = false;
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$counting, &$queries) {
            if ($counting) {
                $queries[] = $query->sql;
            }
        });
        $list = function (int $perPage) use (&$counting, &$queries, $user): array {
            $queries = [];
            $counting = true;
            $rows = $this->actingAs($user, 'api')->getJson("/api/clarion-app/llm-client/agent-runs?per_page={$perPage}")->assertStatus(200)->json('data');
            $counting = false;

            return [$queries, collect($rows)->keyBy('id')->all()];
        };
        $mentioning = fn (array $sqls, string $table) => count(array_filter($sqls, fn (string $sql) => str_contains($sql, $table)));

        [$oneRunQueries, $oneRun] = $list(1);
        [$allRunsQueries, $allRuns] = $list(20);

        $this->assertCount(1, $oneRun, 'precondition');
        $this->assertCount(4, $allRuns, 'precondition');
        foreach ($runs as $runId => $task) {
            $this->assertSame($this->summaryBudget($task), $allRuns[$runId]['task_budget'] ?? null, "run {$runId}");
        }
        $this->assertSame(1, $mentioning($oneRunQueries, 'task_budgets'), 'one query for the tasks of one run');
        $this->assertSame(1, $mentioning($allRunsQueries, 'task_budgets'), 'still one query for the tasks of four runs');
        $this->assertSame(count($oneRunQueries), count($allRunsQueries), 'the number of queries does not grow with the runs listed');
        $this->assertSame(2, $mentioning($allRunsQueries, 'agent_questions'), 'the task budget adds no query of the question table');
    }

    // ---------------------------------------------------------------
    // The task's record, read from its run
    // ---------------------------------------------------------------

    private function taskRecord(User $user, string $runId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'api')->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/task-budget");
    }

    /** The task's events as stored, oldest first, in the shape the record shows them. */
    private function storedEvents(TaskBudget $task): array
    {
        return DB::table('task_budget_events')->where('task_budget_id', $task->id)->orderBy('created_at')->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'kind' => $row->kind,
                'payload' => json_decode((string) $row->payload, true),
                'actor_user_id' => $row->actor_user_id,
                'created_at' => Carbon::parse($row->created_at, 'UTC')->toJSON(),
            ])->all();
    }

    #[Test]
    public function the_owner_reads_the_tasks_standing_and_its_events_oldest_first(): void
    {
        // Real time, so each event carries its own instant.
        $this->travelBack();
        [$user, $conversation, $runId, $task] = $this->ownedRootRun();
        $other = $this->ownedRootRun();
        $tasks = app(TaskBudgetService::class);

        $tasks->changeCeilings($task->fresh(), new StatedCeilings(maxSteps: 5), $user->id);
        $gate = app(TaskBudgetGate::class);
        foreach (range(1, 5) as $step) {
            $this->assertFalse($gate->beforeStep($conversation, $runId)->isStop(), "precondition: step {$step} is allowed");
        }
        $decision = $gate->beforeStep($conversation, $runId);
        $this->assertTrue($decision->isStop(), 'precondition');
        app(TaskBudgetPauser::class)->pause($conversation, $task->fresh(), $decision, $runId, null, 6, 'sync');
        app(AgentQuestionService::class)->stopCeilingPrompt($this->promptOf($task), $user->id, 'stop');

        $body = $this->taskRecord($user, $runId)->assertStatus(200)->json();

        $this->assertSame(['standing', 'events'], array_keys($body));
        $this->assertSame($tasks->standing($task->fresh())->toArray(), $body['standing']);
        $this->assertSame(
            ['opened', 'ceiling_changed', 'approach_notice', 'ceiling_reached', 'prompt_raised', 'decision'],
            array_column($body['events'], 'kind'),
            'every event the task recorded, oldest first, and only its own',
        );
        $this->assertSame($this->storedEvents($task), $body['events'], 'each event as stored: id, kind, payload, actor and time');
        foreach ($body['events'] as $event) {
            $this->assertSame(['id', 'kind', 'payload', 'actor_user_id', 'created_at'], array_keys($event));
        }
        $byKind = collect($body['events'])->keyBy('kind');
        $this->assertSame($user->id, $byKind['ceiling_changed']['actor_user_id'], 'who changed the ceiling');
        $this->assertSame($user->id, $byKind['decision']['actor_user_id'], 'who decided');
        $this->assertNull($byKind['opened']['actor_user_id']);
        $this->assertTrue($byKind['approach_notice']['payload']['delivered'], 'the notice\'s delivery result');
        $this->assertSame(['steps'], $byKind['approach_notice']['payload']['ceilings']);
        $this->assertSame('stop', $byKind['decision']['payload']['decision']);
        $this->assertCount(1, DB::table('task_budget_events')->where('task_budget_id', $other[3]->id)->get(), 'precondition: another task has events of its own, not listed here');
    }

    #[Test]
    public function every_kind_of_event_is_listed_with_its_payload_and_actor_as_stored(): void
    {
        $this->travelBack();
        [$user, , $runId, $task] = $this->ownedRootRun();
        $tasks = app(TaskBudgetService::class);
        $question = (string) Str::uuid();
        $tasks->appendEvent($task, 'allowance_granted', ['add_steps' => 20, 'add_spend' => null, 'max_steps' => 22, 'max_spend' => '1.0000000000', 'steps_reduced' => false, 'spend_reduced' => false]);
        $tasks->appendEvent($task, 'prompt_stopped', ['question_id' => $question, 'reason' => 'expired']);
        $tasks->appendEvent($task, 'report', ['occasion' => 'accepted', 'message_id' => (string) Str::uuid()]);
        $tasks->appendEvent($task, 'prompt_withdrawn', ['question_id' => $question]);
        $tasks->appendEvent($task, 'halted', ['reason' => 'steps', 'report_message_id' => (string) Str::uuid()], null);
        $tasks->appendEvent($task, 'ended', ['reason' => 'discarded'], $user->id);

        $body = $this->taskRecord($user, $runId)->assertStatus(200)->json();

        $this->assertSame(
            ['opened', 'allowance_granted', 'prompt_stopped', 'report', 'prompt_withdrawn', 'halted', 'ended'],
            array_column($body['events'], 'kind'),
        );
        $this->assertSame($this->storedEvents($task), $body['events']);
    }

    #[Test]
    public function a_halted_tasks_record_ends_with_its_halt(): void
    {
        $this->travelBack();
        [$user, $conversation, $runId, $task] = $this->ownedRootRun(reachable: false);
        $this->reachCeiling($conversation, $runId, $task);

        $body = $this->taskRecord($user, $runId)->assertStatus(200)->json();

        $this->assertSame('halted', $body['standing']['state']);
        $this->assertSame(['opened', 'ceiling_reached', 'halted'], array_column($body['events'], 'kind'));
        $this->assertSame($this->storedEvents($task), $body['events']);
    }

    #[Test]
    public function a_run_with_no_task_has_an_empty_record(): void
    {
        $user = User::factory()->create();
        $runId = app(RunTraceRecorder::class)->openRun(RunKind::Interactive, $user->id, null, streamed: false);

        $this->taskRecord($user, $runId)->assertStatus(200)->assertExactJson(['standing' => null, 'events' => []]);
    }

    #[Test]
    public function an_absent_and_a_foreign_run_get_the_identical_404(): void
    {
        [$owner, , $runId] = $this->ownedRootRun();
        $stranger = User::factory()->create();
        $absent = (string) Str::uuid();
        $reference = $this->actingAs($stranger, 'api')->getJson("/api/clarion-app/llm-client/agent-runs/{$absent}");
        $reference->assertStatus(404);

        $foreign = $this->taskRecord($stranger, $runId);
        $missing = $this->taskRecord($stranger, $absent);

        $this->assertSame(404, $foreign->status());
        $this->assertSame(404, $missing->status());
        $this->assertSame($reference->getContent(), $missing->getContent(), 'the same body as any other run that is not there');
        $this->assertSame($missing->getContent(), $foreign->getContent(), 'someone else\'s run is indistinguishable from none');
        $this->taskRecord($owner, $runId)->assertStatus(200);
    }
}
