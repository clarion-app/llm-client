<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task budgets age out with the run traces.
 *
 * `llm-client:purge-run-traces` also deletes every finished task budget —
 * concluded, completed, halted or ended — last changed longer ago than the
 * task budget retention period (`task_budget.retention_days`, else the run
 * trace retention period), together with its events, in chunks. A task that
 * is still running or paused is never deleted however old it is: a paused
 * task waits for its user's decision until it is decided or its
 * conversation is ended or deleted, and its run is kept with it through its
 * prompt.
 */
class TaskBudgetPurgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'UTC'));
        config(['llm-client.run_trace.retention_days' => 90, 'llm-client.task_budget.retention_days' => null]);
    }

    protected function tearDown(): void
    {
        foreach (['task_budget_events', 'task_budgets', 'agent_questions', 'agent_run_steps', 'agent_runs'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    /** A task last changed $daysAgo days ago, with $events events. */
    private function task(string $state, int $daysAgo, int $events = 2, ?string $pausedRunId = null): string
    {
        $id = (string) Str::uuid();
        $at = now()->subDays($daysAgo)->format('Y-m-d H:i:s.u');
        DB::table('task_budgets')->insert([
            'id' => $id,
            'user_id' => (string) Str::uuid(),
            'root_conversation_id' => (string) Str::uuid(),
            'root_run_id' => $pausedRunId,
            'scope' => 'request',
            'unattended' => false,
            'state' => $state,
            'max_steps' => 20,
            'max_steps_origin' => 'default',
            'original_max_steps' => 20,
            'max_spend' => '1.0000000000',
            'max_spend_origin' => 'default',
            'original_max_spend' => '1.0000000000',
            'steps_used' => 20,
            'approach_threshold' => '0.8000',
            'paused_run_id' => $pausedRunId,
            'paused_at' => $state === 'paused' ? $at : null,
            'ended_at' => in_array($state, ['running', 'paused'], true) ? null : $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        foreach (range(1, $events) as $i) {
            DB::table('task_budget_events')->insert([
                'id' => (string) Str::uuid(),
                'task_budget_id' => $id,
                'kind' => $i === 1 ? 'opened' : 'ended',
                'payload' => '{}',
                'created_at' => $at,
            ]);
        }

        return $id;
    }

    private function purge(array $options = []): void
    {
        $this->assertSame(0, Artisan::call('llm-client:purge-run-traces', $options));
    }

    private function exists(string $taskId): bool
    {
        return DB::table('task_budgets')->where('id', $taskId)->exists();
    }

    private function eventCount(string $taskId): int
    {
        return DB::table('task_budget_events')->where('task_budget_id', $taskId)->count();
    }

    public static function finishedStates(): array
    {
        return ['concluded' => ['concluded'], 'completed' => ['completed'], 'halted' => ['halted'], 'ended' => ['ended']];
    }

    #[Test]
    #[DataProvider('finishedStates')]
    public function a_finished_task_older_than_the_retention_period_is_deleted_with_its_events(string $state): void
    {
        $old = $this->task($state, 91);
        $recent = $this->task($state, 89);

        $this->purge();

        $this->assertFalse($this->exists($old), "a {$state} task past the retention period is deleted");
        $this->assertSame(0, $this->eventCount($old), 'and its events with it');
        $this->assertTrue($this->exists($recent), 'a task inside the retention period is kept');
        $this->assertSame(2, $this->eventCount($recent), 'with its events');
    }

    public static function unfinishedStates(): array
    {
        return ['running' => ['running'], 'paused' => ['paused']];
    }

    #[Test]
    #[DataProvider('unfinishedStates')]
    public function a_running_or_paused_task_is_never_deleted_however_old(string $state): void
    {
        $ancient = $this->task($state, 3650);

        $this->purge();

        $this->assertTrue($this->exists($ancient), "a {$state} task is not finished, so it is never aged out");
        $this->assertSame(2, $this->eventCount($ancient));
    }

    #[Test]
    public function the_task_budget_retention_period_wins_over_the_run_trace_one(): void
    {
        config(['llm-client.task_budget.retention_days' => 30]);
        $old = $this->task('completed', 31);
        $recent = $this->task('completed', 29);

        $this->purge();

        $this->assertFalse($this->exists($old));
        $this->assertTrue($this->exists($recent));
    }

    #[Test]
    public function finished_tasks_age_out_even_when_no_run_trace_has(): void
    {
        $old = $this->task('ended', 120);
        $this->assertSame(0, DB::table('agent_runs')->count(), 'precondition: there are no runs to purge');

        $this->purge();

        $this->assertFalse($this->exists($old));
        $this->assertSame(0, $this->eventCount($old));
    }

    #[Test]
    public function a_dry_run_deletes_no_task(): void
    {
        $old = $this->task('ended', 120);

        $this->purge(['--dry-run' => true]);

        $this->assertTrue($this->exists($old));
        $this->assertSame(2, $this->eventCount($old));
    }

    public static function promptStates(): array
    {
        return ['waiting for a decision' => ['pending'], 'stopped at its ceiling' => ['stopped']];
    }

    #[Test]
    #[DataProvider('promptStates')]
    public function a_paused_task_and_its_run_are_kept_however_old(string $promptState): void
    {
        $longAgo = now()->subDays(400);
        $runId = (string) Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'started_at' => $longAgo->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
            'step_count' => 20,
        ]);
        $task = $this->task('paused', 400, 3, $runId);
        $questionId = (string) Str::uuid();
        DB::table('agent_questions')->insert([
            'id' => $questionId,
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'run_id' => $runId,
            'pending_conversation_id' => $promptState === 'pending' ? (string) Str::uuid() : null,
            'paused_message_id' => (string) Str::uuid(),
            'tool_call_id' => 'task_budget',
            'channel' => 'web',
            'resume_mode' => 'stream',
            'question' => "This task's budget ceiling was reached. What should happen next?",
            'choices' => json_encode(['Continue with more allowance (+20 steps)', 'Accept what has been done', 'Stop']),
            'state' => $promptState,
            'asked_at' => $longAgo->format('Y-m-d H:i:s.u'),
            'expires_at' => $longAgo->copy()->addDay()->format('Y-m-d H:i:s.u'),
            'origin' => 'task_budget',
            'task_budget_id' => $task,
        ]);
        DB::table('task_budgets')->where('id', $task)->update(['prompt_question_id' => $questionId]);

        $this->purge();

        $this->assertTrue($this->exists($task), 'the paused task waits for its user\'s decision');
        $this->assertSame(3, $this->eventCount($task));
        $this->assertTrue(DB::table('agent_runs')->where('id', $runId)->exists(), 'its run is kept through its prompt');
    }

    #[Test]
    public function many_finished_tasks_are_deleted_in_chunks(): void
    {
        $total = 1201;
        $at = now()->subDays(200)->format('Y-m-d H:i:s.u');
        foreach (array_chunk(range(1, $total), 200) as $batch) {
            $tasks = [];
            $events = [];
            foreach ($batch as $_) {
                $id = (string) Str::uuid();
                $tasks[] = [
                    'id' => $id, 'user_id' => null, 'root_conversation_id' => (string) Str::uuid(), 'scope' => 'request',
                    'unattended' => false, 'state' => 'completed', 'max_steps' => 20, 'max_steps_origin' => 'default',
                    'original_max_steps' => 20, 'max_spend' => '1.0000000000', 'max_spend_origin' => 'default',
                    'original_max_spend' => '1.0000000000', 'steps_used' => 3, 'approach_threshold' => '0.8000',
                    'ended_at' => $at, 'created_at' => $at, 'updated_at' => $at,
                ];
                $events[] = ['id' => (string) Str::uuid(), 'task_budget_id' => $id, 'kind' => 'opened', 'payload' => '{}', 'created_at' => $at];
            }
            DB::table('task_budgets')->insert($tasks);
            DB::table('task_budget_events')->insert($events);
        }
        $kept = $this->task('paused', 200);

        $deletes = [];
        DB::listen(function (QueryExecuted $query) use (&$deletes) {
            $sql = strtolower($query->sql);
            if (str_starts_with(ltrim($sql), 'delete') && (str_contains($sql, 'task_budgets') || str_contains($sql, 'task_budget_events'))) {
                $deletes[] = [str_contains($sql, 'task_budget_events') ? 'events' : 'tasks', count($query->bindings)];
            }
        });

        $this->purge();

        $this->assertSame(1, DB::table('task_budgets')->count(), 'every finished task is gone; the paused one stays');
        $this->assertTrue($this->exists($kept));
        $this->assertSame(2, DB::table('task_budget_events')->count(), 'only the paused task\'s events are left');
        $taskDeletes = array_values(array_filter($deletes, fn ($d) => $d[0] === 'tasks'));
        $eventDeletes = array_values(array_filter($deletes, fn ($d) => $d[0] === 'events'));
        $this->assertGreaterThan(1, count($taskDeletes), 'tasks are deleted in more than one statement');
        $this->assertGreaterThan(1, count($eventDeletes), 'their events too');
        foreach ($deletes as [$table, $bindings]) {
            $this->assertLessThanOrEqual(500, $bindings, "no delete of {$table} binds more than a chunk's worth of values");
        }
    }
}
