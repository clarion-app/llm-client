<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The boundary check every model call of a task passes first.
 *
 * In order: find the conversation's task (opening a default one when there
 * is none); refuse if it is not running; refuse if its recorded spend has
 * reached the spend ceiling; otherwise claim one step with a single
 * conditional UPDATE that succeeds only while the task is running and below
 * its step ceiling. The claim is the only write; a refused claim writes
 * nothing. Nothing is remembered between calls, so a worker that last
 * looked a moment ago still cannot take a step another worker has since
 * used up.
 */
class TaskBudgetGateTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['task_budget_events', 'task_budgets', 'usage_records', 'conversations'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    private function conversation(): Conversation
    {
        return Conversation::create(['user_id' => (string) Str::uuid(), 'channel' => 'web', 'title' => 'Gate']);
    }

    /** A conversation with a running task of the given ceilings. */
    private function withTask(?int $steps = null, ?string $spend = null): array
    {
        $conversation = $this->conversation();
        $stated = ($steps === null && $spend === null) ? null : new StatedCeilings(maxSteps: $steps, maxSpend: $spend);
        $task = app(TaskBudgetService::class)->forNewRun($conversation, $stated, false, null);

        return [$conversation, $task];
    }

    private function row(TaskBudget $task): object
    {
        return DB::table('task_budgets')->where('id', $task->id)->first();
    }

    private function spend(TaskBudget $task, string $cost): void
    {
        DB::table('usage_records')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $task->root_conversation_id,
            'user_id' => (string) Str::uuid(),
            'attempt_group_id' => (string) Str::uuid(),
            'task_budget_id' => $task->id,
            'total_cost' => $cost,
            'created_at' => now(),
        ]);
    }

    /** @return list<QueryExecuted> */
    private function captureQueries(callable $fn): array
    {
        $queries = [];
        $listening = true;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$listening) {
            if ($listening) {
                $queries[] = $query;
            }
        });

        $fn();
        $listening = false;

        return $queries;
    }

    private function updatesOfTaskBudgets(array $queries): array
    {
        return array_values(array_filter($queries, fn (QueryExecuted $q) => preg_match('/^\s*update\s+"?task_budgets"?/i', $q->sql) === 1));
    }

    #[Test]
    public function the_gate_is_scoped(): void
    {
        $first = app(TaskBudgetGate::class);
        $this->assertSame($first, app(TaskBudgetGate::class), 'one instance within a scope');

        $this->app->forgetScopedInstances();
        $this->assertNotSame($first, app(TaskBudgetGate::class), 'a fresh instance in the next scope, as a queue worker sees between jobs');
    }

    #[Test]
    public function twenty_claims_on_a_twenty_step_task_are_allowed_and_the_twenty_first_is_refused(): void
    {
        [$conversation, $task] = $this->withTask();
        $gate = app(TaskBudgetGate::class);

        for ($i = 1; $i <= 20; $i++) {
            $decision = $gate->beforeStep($conversation, null);
            $this->assertTrue($decision->isAllow(), "claim {$i}");
            $this->assertSame($i, (int) $this->row($task)->steps_used);
        }

        $refused = $gate->beforeStep($conversation, null);
        $this->assertTrue($refused->isStop());
        $this->assertSame('steps', $refused->reason);
        $this->assertNotNull($refused->standing);
        $this->assertSame(20, $refused->standing->stepsUsed);
        $this->assertSame(20, (int) $this->row($task)->steps_used, 'never past the ceiling');
    }

    #[Test]
    public function a_refused_claim_writes_nothing(): void
    {
        [$conversation, $task] = $this->withTask(steps: 2);
        $gate = app(TaskBudgetGate::class);
        $gate->beforeStep($conversation, null);
        $gate->beforeStep($conversation, null);
        $before = (array) $this->row($task);

        $this->travel(5)->seconds();
        $queries = $this->captureQueries(fn () => $gate->beforeStep($conversation, null));

        $this->assertSame($before, (array) $this->row($task), 'updated_at included: nothing changed');
        $this->assertLessThanOrEqual(1, count($this->updatesOfTaskBudgets($queries)), 'at most the one conditional UPDATE, which matched nothing');
    }

    #[Test]
    public function the_claim_is_one_conditional_update(): void
    {
        [$conversation] = $this->withTask();

        $queries = $this->captureQueries(fn () => app(TaskBudgetGate::class)->beforeStep($conversation, null));

        $updates = $this->updatesOfTaskBudgets($queries);
        $this->assertCount(1, $updates);
        $sql = $updates[0]->sql;
        $this->assertMatchesRegularExpression('/"?steps_used"?\s*=\s*"?steps_used"?\s*\+\s*(1|\?)/i', $sql, 'incremented in SQL, not written from a value read in PHP');
        $this->assertMatchesRegularExpression('/"?steps_used"?\s*<\s*"?max_steps"?/i', $sql, 'conditional on room below the ceiling');
        $this->assertMatchesRegularExpression('/"?state"?\s*=\s*(\?|\'running\')/i', $sql, 'conditional on the task running');
        if (preg_match('/"?state"?\s*=\s*\?/i', $sql)) {
            $this->assertContains('running', $updates[0]->bindings);
        }
    }

    #[Test]
    public function a_task_that_is_not_running_is_refused_without_an_update(): void
    {
        foreach (['paused', 'concluded', 'completed', 'halted', 'ended'] as $state) {
            [$conversation, $task] = $this->withTask();
            DB::table('task_budgets')->where('id', $task->id)->update(['state' => $state]);

            $decision = null;
            $queries = $this->captureQueries(function () use ($conversation, &$decision) {
                $decision = app(TaskBudgetGate::class)->beforeStep($conversation, null);
            });

            $this->assertTrue($decision->isStop(), $state);
            $this->assertSame('not_running', $decision->reason, $state);
            $this->assertSame([], $this->updatesOfTaskBudgets($queries), "{$state}: no claim attempted");
            $this->assertSame(0, (int) $this->row($task)->steps_used);
        }
    }

    #[Test]
    public function spend_at_the_ceiling_is_refused_without_a_claim(): void
    {
        [$conversation, $task] = $this->withTask(spend: '0.10');
        $this->spend($task, '0.0600000000');
        $this->spend($task, '0.0400000000');

        $decision = null;
        $queries = $this->captureQueries(function () use ($conversation, &$decision) {
            $decision = app(TaskBudgetGate::class)->beforeStep($conversation, null);
        });

        $this->assertTrue($decision->isStop());
        $this->assertSame('spend', $decision->reason);
        $this->assertSame('0.1000000000', $decision->standing->spendUsed);
        $this->assertSame([], $this->updatesOfTaskBudgets($queries), 'no claim when spend is already at the ceiling');
        $this->assertSame(0, (int) $this->row($task)->steps_used);
    }

    #[Test]
    public function spend_below_the_ceiling_is_allowed(): void
    {
        [$conversation, $task] = $this->withTask(spend: '0.10');
        $this->spend($task, '0.0999999999');

        $this->assertTrue(app(TaskBudgetGate::class)->beforeStep($conversation, null)->isAllow());
        $this->assertSame(1, (int) $this->row($task)->steps_used);
    }

    #[Test]
    public function spend_over_the_ceiling_with_steps_also_used_up_is_both(): void
    {
        [$conversation, $task] = $this->withTask(steps: 1, spend: '0.10');
        app(TaskBudgetGate::class)->beforeStep($conversation, null);
        $this->spend($task, '0.1600000000');

        $decision = app(TaskBudgetGate::class)->beforeStep($conversation, null);

        $this->assertTrue($decision->isStop());
        $this->assertSame('both', $decision->reason);
    }

    #[Test]
    public function spend_recorded_by_another_task_does_not_count(): void
    {
        [$conversation, $task] = $this->withTask(spend: '0.10');
        [, $other] = $this->withTask(spend: '0.10');
        $this->spend($other, '5.0000000000');

        $this->assertTrue(app(TaskBudgetGate::class)->beforeStep($conversation, null)->isAllow());
    }

    #[Test]
    public function a_conversation_with_no_task_gets_one_lazily_and_is_evaluated(): void
    {
        $conversation = $this->conversation();

        $decision = app(TaskBudgetGate::class)->beforeStep($conversation, null);

        $this->assertTrue($decision->isAllow());
        $this->assertNotNull($conversation->active_task_budget_id);
        $task = TaskBudget::find($conversation->active_task_budget_id);
        $this->assertSame(1, (int) $task->steps_used, 'the lazily opened task was claimed against');
        $this->assertSame(20, (int) $task->max_steps);
    }

    #[Test]
    public function a_claim_lost_to_a_state_change_after_the_read_reports_not_running(): void
    {
        [$conversation, $task] = $this->withTask();

        $flipped = false;
        DB::listen(function (QueryExecuted $query) use ($task, &$flipped) {
            if (!$flipped && preg_match('/^\s*select\b.*\bfrom\s+"?task_budgets"?/is', $query->sql)) {
                $flipped = true;
                // Another worker paused the task between this gate's read and its claim.
                DB::table('task_budgets')->where('id', $task->id)->update(['state' => 'paused']);
            }
        });

        $decision = app(TaskBudgetGate::class)->beforeStep($conversation, null);

        $this->assertTrue($flipped, 'precondition: the task row was read before the claim');
        $this->assertTrue($decision->isStop());
        $this->assertSame('not_running', $decision->reason, 'the gate re-read the task and saw why the claim failed');
        $this->assertSame(0, (int) $this->row($task)->steps_used);
    }

    #[Test]
    public function a_ceiling_lowered_below_the_steps_used_refuses_the_next_step(): void
    {
        [$conversation, $task] = $this->withTask(steps: 20);
        for ($i = 0; $i < 12; $i++) {
            app(TaskBudgetGate::class)->beforeStep($conversation, null);
        }

        DB::table('task_budgets')->where('id', $task->id)->update(['max_steps' => 10]);
        $decision = app(TaskBudgetGate::class)->beforeStep($conversation, null);

        $this->assertTrue($decision->isStop());
        $this->assertSame('steps', $decision->reason);
        $this->assertSame(12, (int) $this->row($task)->steps_used);
    }

    #[Test]
    public function a_raised_ceiling_allows_further_steps(): void
    {
        [$conversation, $task] = $this->withTask(steps: 2);
        app(TaskBudgetGate::class)->beforeStep($conversation, null);
        app(TaskBudgetGate::class)->beforeStep($conversation, null);
        $this->assertTrue(app(TaskBudgetGate::class)->beforeStep($conversation, null)->isStop());

        DB::table('task_budgets')->where('id', $task->id)->update(['max_steps' => 3]);

        $this->assertTrue(app(TaskBudgetGate::class)->beforeStep($conversation, null)->isAllow());
        $this->assertSame(3, (int) $this->row($task)->steps_used);
    }

    #[Test]
    public function a_worker_never_decides_on_a_step_count_it_read_earlier(): void
    {
        [$conversation, $task] = $this->withTask(steps: 5);
        $conversationB = Conversation::find($conversation->id);

        // Worker B, in its own scope, looks and is allowed.
        $gateB = app(TaskBudgetGate::class);
        $this->assertTrue($gateB->beforeStep($conversationB, null)->isAllow());

        // Worker A, in a later scope, uses up the rest.
        $this->app->forgetScopedInstances();
        $gateA = app(TaskBudgetGate::class);
        $this->assertNotSame($gateA, $gateB);
        for ($i = 0; $i < 4; $i++) {
            $this->assertTrue($gateA->beforeStep($conversation, null)->isAllow());
        }

        // B's next boundary is refused: nothing B saw earlier is reused.
        $decision = $gateB->beforeStep($conversationB, null);
        $this->assertTrue($decision->isStop());
        $this->assertSame('steps', $decision->reason);
        $this->assertSame(5, (int) $this->row($task)->steps_used);
    }

    #[Test]
    public function a_worker_never_decides_on_spend_it_read_earlier(): void
    {
        [$conversation, $task] = $this->withTask(steps: 50, spend: '0.10');
        $conversationB = Conversation::find($conversation->id);

        $gateB = app(TaskBudgetGate::class);
        $this->assertTrue($gateB->beforeStep($conversationB, null)->isAllow());

        // Worker A records the spend that reaches the ceiling.
        $this->app->forgetScopedInstances();
        $this->assertTrue(app(TaskBudgetGate::class)->beforeStep($conversation, null)->isAllow());
        $this->spend($task, '0.1200000000');

        $decision = $gateB->beforeStep($conversationB, null);
        $this->assertTrue($decision->isStop());
        $this->assertSame('spend', $decision->reason);
    }
}
