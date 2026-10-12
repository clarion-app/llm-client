<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\Services\TaskBudgetReport;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The report a user reads when a task meets its budget: which ceiling was
 * reached and by how much, what was used, what the task got done, the
 * agent's latest progress, and what can happen next.
 *
 * It is composed from what the task already recorded — the conversation's
 * stored tool results and assistant text since the task opened, and the
 * task's own figures — so writing it costs nothing: no model request is
 * made and no usage is recorded. Work from before the task opened is not
 * this task's work and is left out; messages that exist only for the user
 * (a question, a notice, an earlier report) are never "progress".
 */
class TaskBudgetReportTest extends TestCase
{
    private const OPENED_AT = '2026-10-12 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12 08:59:00', 'UTC'));
        config(['llm-client.cost.currency' => 'USD']);
    }

    protected function tearDown(): void
    {
        foreach (['task_budget_events', 'task_budgets', 'usage_records', 'messages', 'conversations'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    private function conversation(): Conversation
    {
        return Conversation::create(['user_id' => (string) Str::uuid(), 'channel' => 'web', 'title' => 'Report', 'character' => 'Clarion']);
    }

    private function openTask(Conversation $conversation, int $steps = 3, string $spend = '1.00'): TaskBudget
    {
        $this->travelTo(Carbon::parse(self::OPENED_AT, 'UTC'));
        $task = app(TaskBudgetService::class)->forNewRun($conversation, new StatedCeilings(maxSteps: $steps, maxSpend: $spend), false, null);
        $this->assertNotNull($task, 'precondition: the task opened');
        $this->travel(1)->seconds();

        return $task;
    }

    private function claim(Conversation $conversation, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->assertTrue(app(TaskBudgetGate::class)->beforeStep($conversation, null)->isAllow(), 'precondition: claim '.($i + 1));
        }
    }

    private function spend(TaskBudget $task, string $cost, bool $estimated = false): void
    {
        DB::table('usage_records')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $task->root_conversation_id,
            'user_id' => (string) Str::uuid(),
            'attempt_group_id' => (string) Str::uuid(),
            'task_budget_id' => $task->id,
            'total_cost' => $cost,
            'cost_unpriced' => false,
            'cost_estimated' => $estimated,
            'created_at' => now(),
        ]);
    }

    private function unpriced(TaskBudget $task): void
    {
        DB::table('usage_records')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $task->root_conversation_id,
            'user_id' => (string) Str::uuid(),
            'attempt_group_id' => (string) Str::uuid(),
            'task_budget_id' => $task->id,
            'total_cost' => null,
            'total_tokens' => 40,
            'cost_unpriced' => true,
            'cost_estimated' => false,
            'created_at' => now(),
        ]);
    }

    /**
     * An assistant turn that called tools; $completed lists the ids that
     * have a stored result.
     *
     * @param  list<array{0: string, 1: string}>  $calls  [id, tool name]
     * @param  list<string>  $completed
     */
    private function toolTurn(Conversation $conversation, string $content, array $calls, array $completed): Message
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => $content,
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => array_map(fn (array $c) => ['id' => $c[0], 'type' => 'function', 'function' => ['name' => $c[1], 'arguments' => '{}']], $calls),
                'tool_results' => array_map(fn (string $id) => ['tool_call_id' => $id, 'content' => '{"ok":true}'], $completed),
            ],
        ]);
        $this->travel(1)->seconds();

        return $message;
    }

    private function assistant(Conversation $conversation, string $content, ?array $toolData = null): Message
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => $content,
            'responseTime' => 0,
            'tool_data' => $toolData,
        ]);
        $this->travel(1)->seconds();

        return $message;
    }

    /** A conversation with work from before the task, the task's own work, and system messages after it. */
    private function workedTask(int $steps = 3): array
    {
        $conversation = $this->conversation();

        // Before the task opened: an earlier request's work.
        $this->toolTurn($conversation, 'Earlier request.', [['old_1', 'memory_read'], ['old_2', 'execute_operation']], ['old_1', 'old_2']);
        $this->assistant($conversation, 'Old progress from an earlier request.');

        $task = $this->openTask($conversation, $steps);

        $this->toolTurn($conversation, 'Looking at the files.', [['c1', 'execute_operation'], ['c2', 'list_applications']], ['c1', 'c2']);
        $this->toolTurn($conversation, '', [['c3', 'execute_operation']], ['c3']);
        // c5 was requested but never completed: it is not counted.
        $this->toolTurn($conversation, '', [['c4', 'execute_operation'], ['c5', 'memory_read']], ['c4']);
        $this->assistant($conversation, 'Converted two of three files.');
        // Messages that exist only for the user are never "progress".
        $this->assistant($conversation, 'Which file next?', ['kind' => 'agent_question', 'question_id' => (string) Str::uuid(), 'state' => 'answered']);
        $this->assistant($conversation, "Heads up — this task's budget is 80% used.", ['kind' => 'task_budget_notice', 'task_budget_id' => $task->id, 'notice' => 'approach']);
        $this->assistant($conversation, "This task's budget ceiling was reached: steps (1 of 1).", ['kind' => 'task_budget_report', 'task_budget_id' => (string) Str::uuid()]);

        $this->claim($conversation, $steps);
        $this->spend($task, '0.2060000000');
        $this->spend($task, '0.2060000000');

        return [$conversation, $task->fresh()];
    }

    private function compose(TaskBudget $task, string $occasion): string
    {
        $task = $task->fresh();
        $standing = app(TaskBudgetService::class)->standing($task);

        return app(TaskBudgetReport::class)->compose($task, $standing, $occasion);
    }

    /** @return list<string> */
    private function sections(string $report): array
    {
        return explode("\n\n", $report);
    }

    #[Test]
    public function the_paused_report_reads_exactly_like_this(): void
    {
        [, $task] = $this->workedTask();

        $this->assertSame(
            "This task's budget ceiling was reached: steps (3 of 3).\n\n"
            ."Used under this task's budget: 3 of 3 steps, 0.41 of 1.00 USD.\n\n"
            ."Completed under this task's budget: execute_operation (3), list_applications (1).\n\n"
            ."Latest progress under this task's budget: Converted two of three files.\n\n"
            ."Anything the agent had not started yet under this task's budget is not recorded.\n\n"
            ."This task's budget needs your decision: continue with more allowance, accept what has been done, or stop.",
            $this->compose($task, TaskBudgetReport::OCCASION_PAUSED),
        );
    }

    public static function occasions(): array
    {
        return [
            'paused' => [TaskBudgetReport::OCCASION_PAUSED],
            'accepted' => [TaskBudgetReport::OCCASION_ACCEPTED],
            'stopped' => [TaskBudgetReport::OCCASION_STOPPED],
            'expired' => [TaskBudgetReport::OCCASION_EXPIRED],
            'halted' => [TaskBudgetReport::OCCASION_HALTED],
        ];
    }

    #[Test]
    #[DataProvider('occasions')]
    public function every_occasion_ends_with_its_own_what_next_line_and_begins_by_naming_this_tasks_budget(string $occasion): void
    {
        [, $task] = $this->workedTask();

        $report = $this->compose($task, $occasion);
        $sections = $this->sections($report);

        $this->assertStringStartsWith("This task's budget", $report);
        $this->assertSame((new TaskBudgetText())->reportNext($occasion), end($sections));
        $this->assertSame("This task's budget ceiling was reached: steps (3 of 3).", $sections[0], 'the same headline whatever the occasion');
        $this->assertCount(6, $sections);
    }

    #[Test]
    public function the_occasions_are_exactly_these(): void
    {
        $this->assertSame(
            ['paused', 'accepted', 'stopped', 'expired', 'halted'],
            [TaskBudgetReport::OCCASION_PAUSED, TaskBudgetReport::OCCASION_ACCEPTED, TaskBudgetReport::OCCASION_STOPPED, TaskBudgetReport::OCCASION_EXPIRED, TaskBudgetReport::OCCASION_HALTED],
        );
    }

    #[Test]
    public function a_spend_overshoot_is_stated_with_its_amount(): void
    {
        $conversation = $this->conversation();
        $task = $this->openTask($conversation, 20, '1.00');
        $this->claim($conversation, 4);
        $this->spend($task, '0.5000000000');
        $this->spend($task, '0.5600000000');

        $sections = $this->sections($this->compose($task, TaskBudgetReport::OCCASION_PAUSED));

        $this->assertSame("This task's budget ceiling was reached: spend (1.06 of 1.00 USD — 0.06 over, from the step that was already running).", $sections[0]);
        $this->assertSame("Used under this task's budget: 4 of 20 steps, 1.06 of 1.00 USD.", $sections[1]);
    }

    #[Test]
    public function both_ceilings_reached_are_both_named(): void
    {
        $conversation = $this->conversation();
        $task = $this->openTask($conversation, 2, '0.10');
        $this->claim($conversation, 2);
        $this->spend($task, '0.1200000000');

        $this->assertSame(
            "This task's budget ceiling was reached: steps (2 of 2) and spend (0.12 of 0.10 USD — 0.02 over, from the step that was already running).",
            $this->sections($this->compose($task, TaskBudgetReport::OCCASION_PAUSED))[0],
        );
    }

    #[Test]
    public function a_ceiling_lowered_below_what_was_used_is_reported_as_lowered(): void
    {
        $conversation = $this->conversation();
        $task = $this->openTask($conversation, 20);
        $this->claim($conversation, 12);
        DB::table('task_budgets')->where('id', $task->id)->update(['max_steps' => 10]);

        $this->assertSame(
            "This task's budget ceiling was reached: the ceiling was lowered to 10 while 12 steps were already used.",
            $this->sections($this->compose($task, TaskBudgetReport::OCCASION_PAUSED))[0],
        );
    }

    #[Test]
    public function estimated_and_unpriced_usage_are_disclosed(): void
    {
        $conversation = $this->conversation();
        $task = $this->openTask($conversation, 2);
        $this->claim($conversation, 2);
        $this->spend($task, '0.2000000000', estimated: true);
        $this->unpriced($task);
        $this->unpriced($task);

        $this->assertSame(
            "Used under this task's budget: 2 of 2 steps, 0.20 of 1.00 USD. Spend is approximate (some usage was estimated). Spend is incomplete (2 requests had no configured price).",
            $this->sections($this->compose($task, TaskBudgetReport::OCCASION_HALTED))[1],
        );
    }

    #[Test]
    public function a_task_that_has_done_nothing_says_so_and_has_no_progress_section(): void
    {
        $conversation = $this->conversation();
        // Work and text from before the task opened are not this task's.
        $this->toolTurn($conversation, 'Earlier request.', [['old', 'execute_operation']], ['old']);
        $this->assistant($conversation, 'Old progress.');
        $task = $this->openTask($conversation, 1);
        $this->claim($conversation, 1);

        $this->assertSame(
            [
                "This task's budget ceiling was reached: steps (1 of 1).",
                "Used under this task's budget: 1 of 1 steps, 0.00 of 1.00 USD.",
                "Nothing was completed under this task's budget yet.",
                "Anything the agent had not started yet under this task's budget is not recorded.",
                (new TaskBudgetText())->reportNext('halted'),
            ],
            $this->sections($this->compose($task, TaskBudgetReport::OCCASION_HALTED)),
        );
    }

    #[Test]
    public function the_latest_progress_is_cut_to_five_hundred_characters(): void
    {
        $conversation = $this->conversation();
        $task = $this->openTask($conversation, 1);
        $long = str_repeat('abcdefghij', 60);
        $this->assistant($conversation, $long);
        $this->claim($conversation, 1);

        $this->assertSame(
            (new TaskBudgetText())->reportLatestProgress(mb_substr($long, 0, 500)),
            $this->sections($this->compose($task, TaskBudgetReport::OCCASION_PAUSED))[3],
        );
    }

    #[Test]
    public function only_the_tasks_own_conversation_is_read(): void
    {
        [, $task] = $this->workedTask();

        // Another conversation's work, after the task opened.
        $other = $this->conversation();
        $this->toolTurn($other, 'Unrelated.', [['x1', 'execute_operation'], ['x2', 'execute_operation']], ['x1', 'x2']);
        $this->assistant($other, 'Unrelated progress.');

        $sections = $this->sections($this->compose($task, TaskBudgetReport::OCCASION_PAUSED));
        $this->assertSame("Completed under this task's budget: execute_operation (3), list_applications (1).", $sections[2]);
        $this->assertSame("Latest progress under this task's budget: Converted two of three files.", $sections[3]);
    }

    #[Test]
    public function writing_the_report_makes_no_model_request_and_records_no_usage(): void
    {
        [, $task] = $this->workedTask();
        Http::fake();
        $usage = DB::table('usage_records')->count();
        $messages = DB::table('messages')->count();

        $this->compose($task, TaskBudgetReport::OCCASION_PAUSED);

        Http::assertNothingSent();
        $this->assertSame($usage, DB::table('usage_records')->count(), 'no usage recorded');
        $this->assertSame($messages, DB::table('messages')->count(), 'composing stores nothing');
    }
}
