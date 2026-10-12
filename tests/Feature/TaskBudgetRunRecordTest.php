<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Events\RunUpdated;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\Services\TaskBudgetPauser;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use ClarionApp\LlmClient\ValueObjects\TraceExportConfig;
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
        foreach (['task_budget_events', 'task_budgets', 'agent_questions', 'agent_run_export_queue', 'agent_run_actions', 'agent_run_steps', 'agent_runs', 'usage_records', 'messages', 'conversations'] as $table) {
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
}
