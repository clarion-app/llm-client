<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\CostRollupQuery;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * When the budget cannot be checked, no step is taken.
 *
 * The conversation work gate fails open — a counter it cannot read is not
 * a reason to refuse a user. The task budget is the opposite: its whole
 * purpose is that no task runs unbounded, and a store that cannot be read
 * cannot show the task is within bounds. So every failure the gate can meet
 * — finding the task, opening one lazily, reading the spend, making the
 * claim — ends the same way: STOP(unavailable), logged once with the
 * conversation, never an exception and never an ALLOW.
 */
class TaskBudgetGateUnavailableTest extends TestCase
{
    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event;
        });
    }

    protected function tearDown(): void
    {
        foreach (['task_budget_events', 'task_budgets', 'usage_records', 'conversations'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    private function conversationWithTask(): Conversation
    {
        $conversation = Conversation::create(['user_id' => (string) Str::uuid(), 'channel' => 'web', 'title' => 'Unavailable']);
        $this->assertNotNull(app(TaskBudgetService::class)->forNewRun($conversation, null, false, null), 'precondition: a task is open');
        $this->logged = [];

        return $conversation;
    }

    private function assertUnavailable(Conversation $conversation): void
    {
        $decision = app(TaskBudgetGate::class)->beforeStep($conversation, null);

        $this->assertTrue($decision->isStop(), 'never ALLOW when the budget cannot be checked');
        $this->assertSame('unavailable', $decision->reason);

        $warnings = array_values(array_filter(
            $this->logged,
            fn (MessageLogged $e) => in_array($e->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)
        ));
        $this->assertCount(1, $warnings, 'logged exactly once: '.json_encode(array_map(fn ($e) => $e->message, $warnings)));
        $this->assertStringContainsString(
            (string) $conversation->id,
            $warnings[0]->message.' '.json_encode($warnings[0]->context),
            'the log names the conversation'
        );
    }

    #[Test]
    public function finding_the_task_fails(): void
    {
        $conversation = $this->conversationWithTask();
        Schema::drop('task_budgets');

        $this->assertUnavailable($conversation);
    }

    #[Test]
    public function opening_a_task_lazily_fails(): void
    {
        $conversation = Conversation::create(['user_id' => (string) Str::uuid(), 'channel' => 'web', 'title' => 'No task yet']);
        DB::statement("CREATE TRIGGER task_budgets_refuse_insert BEFORE INSERT ON task_budgets BEGIN SELECT RAISE(ABORT, 'insert refused'); END");

        $this->assertUnavailable($conversation);
        $this->assertNull($conversation->fresh()->active_task_budget_id);
    }

    #[Test]
    public function reading_the_spend_fails(): void
    {
        $conversation = $this->conversationWithTask();
        $this->app->bind(CostRollupQuery::class, fn () => new class extends CostRollupQuery {
            public function taskTotal(string $taskBudgetId): array
            {
                throw new \RuntimeException('usage_records unreadable');
            }
        });
        $this->app->forgetScopedInstances();

        $this->assertUnavailable($conversation);
        $this->assertSame(0, (int) DB::table('task_budgets')->where('id', $conversation->active_task_budget_id)->value('steps_used'), 'no claim without a spend reading');
    }

    #[Test]
    public function the_claim_fails(): void
    {
        $conversation = $this->conversationWithTask();
        DB::statement("CREATE TRIGGER task_budgets_refuse_update BEFORE UPDATE ON task_budgets BEGIN SELECT RAISE(ABORT, 'update refused'); END");

        $this->assertUnavailable($conversation);
    }

    #[Test]
    public function the_task_table_is_missing_altogether(): void
    {
        $conversation = Conversation::create(['user_id' => (string) Str::uuid(), 'channel' => 'web', 'title' => 'No table']);
        Schema::drop('task_budgets');

        $this->assertUnavailable($conversation);
    }
}
