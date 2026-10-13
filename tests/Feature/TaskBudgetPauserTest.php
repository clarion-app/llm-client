<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\Services\TaskBudgetPauser;
use ClarionApp\LlmClient\Services\TaskBudgetReport;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetDecision;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * What happens to the task itself when its root agent meets the ceiling at a
 * step boundary.
 *
 * With someone to ask, the task pauses: it records exactly where to pick up
 * again (the run, the next iteration, the path, the consumption at that
 * moment), the run stays open, and the user is asked what to do. With nobody
 * to ask — an unattended run, a conversation on a channel nobody reads, a
 * conversation with no user — the task halts instead: the report is posted,
 * the run closes as stopped early, and nothing waits for a reply that can
 * never come. Either way the conversation is no longer processing and no
 * step is left open.
 *
 * Two pausers can race for one task (a helper and its root, two workers):
 * exactly one pauses it, and the loser changes nothing about the task — it
 * only tidies its own open step and reports the outcome the winner set up.
 */
class TaskBudgetPauserTest extends TestCase
{
    private RecordingChannelDelivery $web;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'UTC'));
        config(['llm-client.run_trace.enabled' => true, 'llm-client.cost.currency' => 'USD']);

        $this->web = new RecordingChannelDelivery('web');
        app(ConversationChannelRegistry::class)->register($this->web);
    }

    protected function tearDown(): void
    {
        foreach (['task_budget_events', 'task_budgets', 'agent_questions', 'agent_run_actions', 'agent_run_steps', 'agent_runs', 'usage_records', 'messages', 'conversations'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function conversation(string $channel = 'web', ?string $userId = 'user'): Conversation
    {
        return Conversation::create([
            'user_id' => $userId === null ? null : (string) Str::uuid(),
            'channel' => $channel,
            'title' => 'Pauser',
            'character' => 'Clarion',
            'is_processing' => true,
        ]);
    }

    /** A run with one open step, as the loop has at a boundary. */
    private function runWithOpenStep(Conversation $conversation): array
    {
        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, (string) $conversation->user_id, $conversation->id, streamed: false);
        $this->assertNotNull($runId, 'precondition: tracing is on');
        $stepId = $recorder->openStep($runId, 1);

        return [$runId, $stepId];
    }

    /** A task whose step ceiling the gate has just refused. */
    private function taskAtStepCeiling(Conversation $conversation, ?string $runId, int $steps = 2, bool $unattended = false): array
    {
        $task = app(TaskBudgetService::class)->forNewRun($conversation, new StatedCeilings(maxSteps: $steps), $unattended, $runId);
        $this->assertNotNull($task);
        $gate = app(TaskBudgetGate::class);
        for ($i = 0; $i < $steps; $i++) {
            $this->assertTrue($gate->beforeStep($conversation, $runId)->isAllow());
        }
        $decision = $gate->beforeStep($conversation, $runId);
        $this->assertTrue($decision->isStop());
        $this->assertSame(TaskBudgetDecision::REASON_STEPS, $decision->reason);

        return [$task->fresh(), $decision];
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
            'cost_unpriced' => false,
            'cost_estimated' => false,
            'created_at' => now(),
        ]);
    }

    private function pauser(): TaskBudgetPauser
    {
        return app(TaskBudgetPauser::class);
    }

    private function row(TaskBudget $task): object
    {
        return DB::table('task_budgets')->where('id', $task->id)->first();
    }

    /** @return list<string> */
    private function eventKinds(TaskBudget $task): array
    {
        return DB::table('task_budget_events')->where('task_budget_id', $task->id)->orderBy('created_at')->pluck('kind')->all();
    }

    private function event(TaskBudget $task, string $kind): ?array
    {
        $payload = DB::table('task_budget_events')->where('task_budget_id', $task->id)->where('kind', $kind)->value('payload');

        return $payload === null ? null : json_decode($payload, true);
    }

    private function stepRow(string $stepId): object
    {
        return DB::table('agent_run_steps')->where('id', $stepId)->first();
    }

    private function runRow(string $runId): object
    {
        return DB::table('agent_runs')->where('id', $runId)->first();
    }

    private function prompts(Conversation $conversation)
    {
        return AgentQuestion::where('conversation_id', $conversation->id)->get();
    }

    // ---------------------------------------------------------------
    // Someone to ask: the task pauses
    // ---------------------------------------------------------------

    public static function resumeModes(): array
    {
        return ['sync' => ['sync'], 'stream' => ['stream']];
    }

    #[Test]
    #[DataProvider('resumeModes')]
    public function a_reachable_root_pauses_records_where_to_resume_and_asks(string $mode): void
    {
        $conversation = $this->conversation();
        [$runId, $stepId] = $this->runWithOpenStep($conversation);
        [$task, $decision] = $this->taskAtStepCeiling($conversation, $runId);
        $this->spend($task, '0.3000000000');
        $this->travel(5)->seconds();

        $result = $this->pauser()->pause($conversation, $task, $decision, $runId, $stepId, 3, $mode);

        $prompts = $this->prompts($conversation);
        $this->assertCount(1, $prompts, 'the user is asked');
        $prompt = $prompts->first();
        $this->assertTrue($prompt->isCeilingPrompt());
        $this->assertSame(QuestionState::Pending, $prompt->state);
        $this->assertSame($mode, $prompt->resume_mode);
        $this->assertSame($runId, $prompt->run_id);

        if ($mode === 'sync') {
            $this->assertSame([
                'status' => 'awaiting_user',
                'conversation_id' => $conversation->id,
                'message_id' => $prompt->display_message_id,
                'question_id' => $prompt->id,
                'content' => '',
            ], $result, 'the synchronous caller is told the run waits on its user');
        } else {
            $this->assertNull($result, 'the streamed path returns nothing: the prompt was delivered on the channel');
        }

        $row = $this->row($task);
        $this->assertSame('paused', $row->state);
        $this->assertSame('2026-10-12 09:00:05', Carbon::parse($row->paused_at)->format('Y-m-d H:i:s'));
        $this->assertSame('steps', $row->pause_reason);
        $this->assertSame($runId, $row->paused_run_id);
        $this->assertSame(3, (int) $row->paused_iteration, 'resume starts at the iteration that never ran');
        $this->assertSame($mode, $row->resume_mode);
        $this->assertSame(2, (int) $row->paused_steps_used);
        $this->assertSame(0, bccomp('0.3', (string) $row->paused_spend, 10));
        $this->assertSame($prompt->id, $row->prompt_question_id);
        $this->assertNull($row->ended_at);
        $this->assertNull($row->end_reason);

        $this->assertSame(['opened', 'ceiling_reached', 'prompt_raised'], $this->eventKinds($task));
        $reached = $this->event($task, 'ceiling_reached');
        $this->assertSame('steps', $reached['reason'] ?? null);
        $this->assertSame(2, $reached['standing']['steps']['used'] ?? null, 'the consumption at the pause is recorded with it');
        $this->assertSame(['steps'], $reached['standing']['reached'] ?? null);
        $this->assertSame($prompt->id, $this->event($task, 'prompt_raised')['question_id'] ?? null);

        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'waiting on the user is not processing');
        $this->assertSame('stopped_early', $this->stepRow($stepId)->end_state, 'the open step is closed, never left in progress');
        $this->assertSame('Task budget ceiling reached', $this->stepRow($stepId)->end_reason);
        $this->assertSame('in_progress', $this->runRow($runId)->end_state, 'the run stays open while the task is paused');
        $this->assertSame(0, DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', 'in_progress')->count());

        $this->assertSame(
            app(TaskBudgetReport::class)->compose(TaskBudget::find($task->id), app(TaskBudgetService::class)->standing(TaskBudget::find($task->id)), TaskBudgetReport::OCCASION_PAUSED),
            $prompt->preamble,
            'the report is the prompt\'s preamble',
        );
        $this->assertSame([Message::find($prompt->display_message_id)->content], $this->web->textsFor($conversation->id), 'delivered once on the conversation\'s channel');
    }

    #[Test]
    public function the_offered_allowance_is_the_original_ceiling_of_each_reached_ceiling(): void
    {
        $conversation = $this->conversation();
        [$runId, $stepId] = $this->runWithOpenStep($conversation);
        [$task, $decision] = $this->taskAtStepCeiling($conversation, $runId, 4);

        $this->pauser()->pause($conversation, $task, $decision, $runId, $stepId, 5, 'sync');

        $this->assertSame(
            (new TaskBudgetText())->choices(4, null, 'USD'),
            $this->prompts($conversation)->first()->choices,
            'steps reached: continue offers the original 4 steps again; spend was not reached so none is offered',
        );
    }

    #[Test]
    public function both_ceilings_reached_offer_both_allowances_and_pause_for_both(): void
    {
        $conversation = $this->conversation();
        [$runId, $stepId] = $this->runWithOpenStep($conversation);
        $task = app(TaskBudgetService::class)->forNewRun($conversation, new StatedCeilings(maxSteps: 1, maxSpend: '0.10'), false, $runId);
        $gate = app(TaskBudgetGate::class);
        $this->assertTrue($gate->beforeStep($conversation, $runId)->isAllow());
        $this->spend($task, '0.1200000000');
        $decision = $gate->beforeStep($conversation, $runId);
        $this->assertSame(TaskBudgetDecision::REASON_BOTH, $decision->reason);

        $this->pauser()->pause($conversation, $task->fresh(), $decision, $runId, $stepId, 2, 'sync');

        $this->assertSame('both', $this->row($task)->pause_reason);
        $prompt = $this->prompts($conversation)->first();
        $this->assertSame((new TaskBudgetText())->choices(1, '0.1000000000', 'USD'), $prompt->choices);
        $this->assertStringStartsWith(
            "This task's budget ceiling was reached: steps (1 of 1) and spend (0.12 of 0.10 USD — 0.02 over, from the step that was already running).",
            $prompt->preamble,
        );
    }

    #[Test]
    public function at_the_installation_maximum_continue_is_offered_as_not_available(): void
    {
        config(['llm-client.task_budget.maximum_steps' => 3]);
        $conversation = $this->conversation();
        [$runId, $stepId] = $this->runWithOpenStep($conversation);
        [$task, $decision] = $this->taskAtStepCeiling($conversation, $runId, 3);

        $this->pauser()->pause($conversation, $task, $decision, $runId, $stepId, 4, 'sync');

        $this->assertSame((new TaskBudgetText())->choices(0, null, 'USD'), $this->prompts($conversation)->first()->choices);
    }

    #[Test]
    public function with_tracing_off_the_task_pauses_with_no_run(): void
    {
        config(['llm-client.run_trace.enabled' => false]);
        $conversation = $this->conversation();
        [$task, $decision] = $this->taskAtStepCeiling($conversation, null);

        $result = $this->pauser()->pause($conversation, $task, $decision, null, null, 3, 'sync');

        $prompt = $this->prompts($conversation)->first();
        $this->assertNotNull($prompt);
        $this->assertNull($prompt->run_id);
        $this->assertSame('awaiting_user', $result['status']);
        $this->assertSame($prompt->id, $result['question_id']);
        $row = $this->row($task);
        $this->assertSame('paused', $row->state);
        $this->assertNull($row->paused_run_id);
        $this->assertSame(3, (int) $row->paused_iteration);
    }

    // ---------------------------------------------------------------
    // Nobody to ask: the task halts
    // ---------------------------------------------------------------

    public static function unreachable(): array
    {
        return [
            'an unattended run' => ['web', 'user', true],
            'a managed-task conversation' => ['managed-task', 'user', false],
            'a conversation with no user' => ['web', null, false],
        ];
    }

    #[Test]
    #[DataProvider('unreachable')]
    public function with_nobody_to_ask_the_task_halts_with_its_report_and_no_prompt(string $channel, ?string $user, bool $unattended): void
    {
        $conversation = $this->conversation($channel, $user);
        [$runId, $stepId] = $this->runWithOpenStep($conversation);
        [$task, $decision] = $this->taskAtStepCeiling($conversation, $runId, 2, $unattended);

        $result = $this->pauser()->pause($conversation, $task, $decision, $runId, $stepId, 3, 'sync');

        $this->assertCount(0, $this->prompts($conversation), 'nobody can answer, so nobody is asked');

        $row = $this->row($task);
        $this->assertSame('halted', $row->state);
        $this->assertSame('Task budget ceiling reached', $row->end_reason);
        $this->assertNotNull($row->ended_at);
        $this->assertNull($row->prompt_question_id);

        $reports = Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_REPORT)
            ->values();
        $this->assertCount(1, $reports, 'the report is posted in the conversation');
        $this->assertSame('assistant', $reports[0]->role);
        $this->assertSame($task->id, $reports[0]->tool_data['task_budget_id'] ?? null);
        $halted = TaskBudget::find($task->id);
        $expected = app(TaskBudgetReport::class)->compose($halted, app(TaskBudgetService::class)->standing($halted), TaskBudgetReport::OCCASION_HALTED);
        $this->assertSame($expected, $reports[0]->content);

        $this->assertSame(['status' => 'stopped', 'code' => 'task_budget_reached', 'content' => $expected], $result);

        $this->assertSame('stopped_early', $this->runRow($runId)->end_state);
        $this->assertSame('Task budget ceiling reached', $this->runRow($runId)->end_reason);
        $this->assertSame('stopped_early', $this->stepRow($stepId)->end_state);
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
        $this->assertSame(['opened', 'ceiling_reached', 'halted'], $this->eventKinds($task));
    }

    #[Test]
    public function a_halted_stream_returns_nothing(): void
    {
        $conversation = $this->conversation('web', null);
        [$runId] = $this->runWithOpenStep($conversation);
        [$task, $decision] = $this->taskAtStepCeiling($conversation, $runId);

        $this->assertNull($this->pauser()->pause($conversation, $task, $decision, $runId, null, 3, 'stream'));
        $this->assertSame('halted', $this->row($task)->state);
        $this->assertSame('stopped_early', $this->runRow($runId)->end_state);
    }

    // ---------------------------------------------------------------
    // The budget could not be checked
    // ---------------------------------------------------------------

    #[Test]
    public function an_unavailable_budget_pauses_a_reachable_root_naming_what_happened(): void
    {
        $conversation = $this->conversation();
        [$runId, $stepId] = $this->runWithOpenStep($conversation);
        $task = app(TaskBudgetService::class)->forNewRun($conversation, null, false, $runId);

        $result = $this->pauser()->pause($conversation, $task, TaskBudgetDecision::unavailable(), $runId, $stepId, 1, 'sync');

        $this->assertSame('awaiting_user', $result['status']);
        $row = $this->row($task);
        $this->assertSame('paused', $row->state);
        $this->assertSame('unavailable', $row->pause_reason);
        $this->assertStringStartsWith((new TaskBudgetText())->unavailable(), $this->prompts($conversation)->first()->preamble);
    }

    #[Test]
    public function an_unavailable_budget_halts_an_unreachable_root_naming_what_happened(): void
    {
        $conversation = $this->conversation('web', null);
        [$runId, $stepId] = $this->runWithOpenStep($conversation);
        $task = app(TaskBudgetService::class)->forNewRun($conversation, null, false, $runId);

        $result = $this->pauser()->pause($conversation, $task, TaskBudgetDecision::unavailable(), $runId, $stepId, 1, 'sync');

        $this->assertSame('stopped', $result['status']);
        $this->assertSame('task_budget_reached', $result['code']);
        $this->assertStringStartsWith((new TaskBudgetText())->unavailable(), $result['content']);
        $this->assertSame('halted', $this->row($task)->state);
    }

    #[Test]
    public function with_no_task_at_all_the_run_stops_and_nothing_is_written(): void
    {
        $conversation = $this->conversation();
        [$runId, $stepId] = $this->runWithOpenStep($conversation);

        $result = $this->pauser()->pause($conversation, null, TaskBudgetDecision::unavailable(), $runId, $stepId, 1, 'sync');

        $this->assertSame(['status' => 'stopped', 'code' => 'task_budget_reached', 'content' => (new TaskBudgetText())->unavailable()], $result);
        $this->assertSame('stopped_early', $this->runRow($runId)->end_state);
        $this->assertSame('Task budget ceiling reached', $this->runRow($runId)->end_reason);
        $this->assertSame('stopped_early', $this->stepRow($stepId)->end_state);
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
        $this->assertCount(0, $this->prompts($conversation));
        $this->assertSame(0, DB::table('task_budgets')->count());
    }

    #[Test]
    public function a_pause_that_cannot_read_the_task_never_throws_and_stops_the_run(): void
    {
        $conversation = $this->conversation();
        [$runId, $stepId] = $this->runWithOpenStep($conversation);
        [$task, $decision] = $this->taskAtStepCeiling($conversation, $runId);
        $this->app->bind(\ClarionApp\LlmClient\Services\CostRollupQuery::class, fn () => new class extends \ClarionApp\LlmClient\Services\CostRollupQuery {
            public function taskTotal(string $taskBudgetId): array
            {
                throw new \RuntimeException('usage store unavailable');
            }
        });
        $this->app->forgetScopedInstances();

        $result = $this->pauser()->pause($conversation, $task, $decision, $runId, $stepId, 3, 'sync');

        $this->assertSame(['status' => 'stopped', 'code' => 'task_budget_reached', 'content' => (new TaskBudgetText())->unavailable()], $result);
        $this->assertSame('stopped_early', $this->runRow($runId)->end_state);
        $this->assertSame('Task budget ceiling reached', $this->runRow($runId)->end_reason);
        $this->assertSame('stopped_early', $this->stepRow($stepId)->end_state);
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
        $this->assertCount(0, $this->prompts($conversation));
    }

    // ---------------------------------------------------------------
    // Racing pausers: one winner, the loser changes nothing
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_pause_of_a_paused_task_writes_nothing_and_reports_the_existing_prompt(): void
    {
        $conversation = $this->conversation();
        [$runId, $stepId] = $this->runWithOpenStep($conversation);
        [$task, $decision] = $this->taskAtStepCeiling($conversation, $runId);
        $first = $this->pauser()->pause($conversation, $task, $decision, $runId, $stepId, 3, 'sync');
        $before = (array) $this->row($task);
        $events = $this->eventKinds($task);

        // The loser had its own step open.
        $conversation->update(['is_processing' => true]);
        $loserStep = app(RunTraceRecorder::class)->openStep($runId, 2);
        $this->travel(3)->seconds();

        $second = $this->pauser()->pause($conversation, $task, $decision, $runId, $loserStep, 3, 'sync');

        $this->assertSame($first, $second, 'the same waiting outcome, for the same prompt');
        $this->assertSame($before, (array) $this->row($task), 'nothing is written to the task');
        $this->assertSame($events, $this->eventKinds($task), 'no second ceiling_reached');
        $this->assertCount(1, $this->prompts($conversation), 'no second prompt');
        $this->assertSame('stopped_early', $this->stepRow($loserStep)->end_state, 'the loser\'s own step is closed');
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
        $this->assertCount(1, $this->web->textsFor($conversation->id), 'the prompt is not delivered twice');
    }

    #[Test]
    public function a_second_pause_of_a_paused_task_with_no_prompt_raises_it(): void
    {
        $conversation = $this->conversation();
        [$runId, $stepId] = $this->runWithOpenStep($conversation);
        [$task, $decision] = $this->taskAtStepCeiling($conversation, $runId);
        // Paused by a winner that could not raise its prompt.
        DB::table('task_budgets')->where('id', $task->id)->update([
            'state' => 'paused', 'paused_at' => now()->format('Y-m-d H:i:s.u'), 'pause_reason' => 'steps',
            'paused_run_id' => $runId, 'paused_iteration' => 3, 'resume_mode' => 'sync', 'paused_steps_used' => 2,
            'paused_spend' => '0', 'prompt_question_id' => null,
        ]);

        $result = $this->pauser()->pause($conversation, TaskBudget::find($task->id), TaskBudgetDecision::stop(TaskBudgetDecision::REASON_NOT_RUNNING), $runId, $stepId, 3, 'sync');

        $prompt = $this->prompts($conversation)->first();
        $this->assertNotNull($prompt, 'the prompt is raised now');
        $this->assertSame('awaiting_user', $result['status']);
        $this->assertSame($prompt->id, $result['question_id']);
        $this->assertSame($prompt->id, $this->row($task)->prompt_question_id);
        $this->assertSame('sync', $prompt->resume_mode);
        $this->assertSame('stopped_early', $this->stepRow($stepId)->end_state);
    }

    #[Test]
    public function raise_prompt_asks_for_a_paused_task_with_no_prompt(): void
    {
        $conversation = $this->conversation();
        [$runId] = $this->runWithOpenStep($conversation);
        [$task] = $this->taskAtStepCeiling($conversation, $runId);
        DB::table('task_budgets')->where('id', $task->id)->update([
            'state' => 'paused', 'paused_at' => now()->format('Y-m-d H:i:s.u'), 'pause_reason' => 'steps',
            'paused_run_id' => $runId, 'paused_iteration' => 3, 'resume_mode' => 'stream', 'paused_steps_used' => 2, 'paused_spend' => '0',
        ]);

        $this->assertNull($this->pauser()->raisePrompt($conversation, TaskBudget::find($task->id), 'stream'));

        $prompt = $this->prompts($conversation)->first();
        $this->assertNotNull($prompt);
        $this->assertSame($prompt->id, $this->row($task)->prompt_question_id);
        $this->assertSame('stream', $prompt->resume_mode);
    }

    public static function terminalStates(): array
    {
        return [
            'halted' => ['halted'],
            'concluded' => ['concluded'],
            'completed' => ['completed'],
            'ended' => ['ended'],
        ];
    }

    #[Test]
    #[DataProvider('terminalStates')]
    public function a_pause_that_finds_the_task_already_finished_stops_the_run_and_writes_nothing_to_the_task(string $state): void
    {
        $conversation = $this->conversation();
        [$runId, $stepId] = $this->runWithOpenStep($conversation);
        $task = app(TaskBudgetService::class)->forNewRun($conversation, null, false, $runId);
        DB::table('task_budgets')->where('id', $task->id)->update(['state' => $state, 'end_reason' => 'already over']);
        $before = (array) $this->row($task);

        $result = $this->pauser()->pause($conversation, TaskBudget::find($task->id), TaskBudgetDecision::stop(TaskBudgetDecision::REASON_NOT_RUNNING), $runId, $stepId, 2, 'sync');

        $this->assertSame(['status' => 'stopped', 'code' => 'task_budget_reached', 'content' => (new TaskBudgetText())->unavailable()], $result);
        $this->assertSame($before, (array) $this->row($task));
        $this->assertSame('stopped_early', $this->stepRow($stepId)->end_state);
        $this->assertSame('stopped_early', $this->runRow($runId)->end_state);
        $this->assertSame('Task budget ceiling reached', $this->runRow($runId)->end_reason);
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
        $this->assertCount(0, $this->prompts($conversation));
    }

    #[Test]
    public function the_task_state_after_a_pause_is_resumable(): void
    {
        $conversation = $this->conversation();
        [$runId, $stepId] = $this->runWithOpenStep($conversation);
        [$task, $decision] = $this->taskAtStepCeiling($conversation, $runId);

        $this->pauser()->pause($conversation, $task, $decision, $runId, $stepId, 3, 'sync');

        $paused = TaskBudget::find($task->id);
        $this->assertSame(TaskBudgetState::Paused, $paused->state);
        $this->assertFalse($paused->state->isTerminal());
    }
}
