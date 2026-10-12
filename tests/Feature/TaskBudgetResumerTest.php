<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\DegradationGate;
use ClarionApp\LlmClient\Services\QuestionAnswerIntake;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\DegradationDecision;
use ClarionApp\LlmClient\ValueObjects\IntakeResult;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * Applying a decided ceiling prompt, once.
 *
 * Resuming an answered ceiling prompt takes the same one-time claim any
 * answered question takes, and marks the prompt's message with its state —
 * but there is no paused agent turn behind a ceiling prompt, so nothing is
 * written as a tool result, and the prompt is handed to the task budget
 * before anything could mistake its missing turn for deleted context.
 *
 * Continue raises each reached ceiling by the granted allowance, puts the
 * task back to running (only if it is still paused on this very prompt),
 * resets an approach latch whose ceiling is no longer near, and resumes the
 * paused run — the same run, at the iteration it paused before — through
 * the same re-admission every resumed run goes through, on the path it
 * paused on. A synchronous result goes to whoever is waiting: the request,
 * or, for a background resume, the conversation's channel.
 *
 * Accept concludes the task: its report is stored in the conversation and
 * reaches the user exactly once, and the run closes as completed.
 *
 * A quota refusing the continuation is the quota's refusal, reported as the
 * quota's: the decision and its allowance stand, the refusal closes the
 * inherited run as it does for any refused resume, and the task ends with
 * its run. Any other failure closes the run as failed and frees the
 * conversation. A second resume, or one for a task
 * that is no longer paused on the prompt, does nothing.
 */
class TaskBudgetResumerTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    private TaskBudgetText $text;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $this->text = new TaskBudgetText();
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** Run a request on $path to its 2-step ceiling; the prompt is then pending. */
    private function pausedAtTheCeiling(string $path, int $steps = 2): array
    {
        config(['llm-client.agent_loop.max_iterations' => $steps]);
        $conversation = $this->conversationFor($path);
        if ($path === 'sync') {
            $this->scriptSteps(1, $steps);
            $this->send('sync', $conversation, 'Store notes.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        } else {
            $this->send('stream', $conversation, 'Store notes.')->assertStatus(201);
            $this->playSteps(1, $steps);
        }

        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt, 'precondition: the prompt is raised');
        $this->assertSame(QuestionState::Pending, $prompt->state);
        $this->assertSame($steps, $this->modelRequests($path));

        return [Conversation::findOrFail($conversation->id), $prompt, $this->latestRunIdOf($conversation)];
    }

    private function decide(Conversation $conversation, string $reply): AgentQuestion
    {
        $intake = app(QuestionAnswerIntake::class)->accept($conversation->fresh(), (string) $this->fixture()->user->id, $reply);
        $this->assertSame(IntakeResult::ANSWERED, $intake->kind, 'precondition: the decision is recorded as the prompt\'s answer');

        return $intake->question;
    }

    private function resume(AgentQuestion $prompt, ResumeCaller $caller = ResumeCaller::HttpRequest): ?array
    {
        // A fresh request, as the entry that resumes it would be.
        $this->app->forgetScopedInstances();

        return app(QuestionResumer::class)->resume($prompt->id, $caller);
    }

    private function events(string $taskId, string $kind): array
    {
        return DB::table('task_budget_events')->where('task_budget_id', $taskId)->where('kind', $kind)->orderBy('created_at')->get()
            ->map(fn ($row) => json_decode($row->payload, true))->all();
    }

    private function deliveredOn(string $path, Conversation $conversation): array
    {
        return $path === 'sync' ? $this->telegram->textsFor($conversation->id) : $this->web->textsFor($conversation->id);
    }

    // ---------------------------------------------------------------
    // The claim
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('paths')]
    public function the_claim_marks_the_prompt_but_never_writes_a_paused_turn_or_treats_its_context_as_deleted(string $path): void
    {
        $this->scenario = 'task_budget_resumer_claim_'.$path;
        $this->entryPath = $path;
        [$conversation, $prompt, $runId] = $this->pausedAtTheCeiling($path);
        if ($path === 'sync') {
            $this->script()->finalAnswer('Done.');
        }
        $this->decide($conversation, 'continue');

        $this->resume($prompt);

        $prompt->refresh();
        $this->assertNotNull($prompt->resumed_at, 'the one-time claim is taken');
        $display = Message::findOrFail($prompt->display_message_id);
        $this->assertSame('answered', $display->tool_data['state'] ?? null, 'the prompt\'s message shows its final state');
        $this->assertArrayNotHasKey('tool_results', $display->tool_data, 'no paused turn is consumed: there is none');
        $this->assertNotSame('stopped_early', DB::table('agent_runs')->where('id', $runId)->value('end_state'), 'the missing paused turn is not taken for deleted context');
        $this->assertNotSame('question context deleted', DB::table('agent_runs')->where('id', $runId)->value('end_reason'));
        $this->assertSame(3, $this->modelRequests($path), 'the run continued');

        if ($path === 'stream') {
            $this->playTurn(2, 'Done.');
        }
    }

    // ---------------------------------------------------------------
    // Continue
    // ---------------------------------------------------------------

    #[Test]
    public function continue_on_the_streamed_path_raises_the_ceiling_and_resumes_the_same_run_at_the_paused_iteration(): void
    {
        $this->scenario = 'task_budget_resumer_continue_stream';
        $this->entryPath = 'stream';
        [$conversation, $prompt, $runId] = $this->pausedAtTheCeiling('stream');
        $task = $this->taskRowOf($conversation);
        $this->assertSame(3, (int) $task->paused_iteration, 'precondition: paused before iteration 3');
        $this->decide($conversation, 'continue');

        $this->assertNull($this->resume($prompt), 'a streamed continuation returns nothing');

        $task = $this->taskRowOf($conversation);
        $this->assertSame('running', $task->state);
        $this->assertSame(4, (int) $task->max_steps, 'raised by the offered allowance: the original 2 steps');
        $this->assertSame(3, (int) $task->steps_used, 'the continuation\'s request claimed a step');
        foreach (['paused_at', 'pause_reason', 'paused_run_id', 'paused_iteration', 'resume_mode', 'paused_steps_used', 'paused_spend', 'prompt_question_id'] as $column) {
            $this->assertNull($task->{$column}, "{$column} is cleared");
        }
        $this->assertTrue((bool) $conversation->fresh()->is_processing, 'the conversation is processing the continuation');
        $this->assertSame(3, $this->modelRequests('stream'));

        $granted = $this->events($task->id, 'allowance_granted');
        $this->assertCount(1, $granted);
        $this->assertSame(2, $granted[0]['add_steps'] ?? null);
        $this->assertSame(4, $granted[0]['max_steps'] ?? null);
        $decisions = $this->events($task->id, 'decision');
        $this->assertCount(1, $decisions);
        $this->assertSame('continue', $decisions[0]['decision'] ?? null);
        $this->assertSame($prompt->id, $decisions[0]['question_id'] ?? null);

        // The same run carries on: iteration 3 runs, then 4, then it ends.
        $this->playTurn(2, '', $this->stepCalls(3));
        $this->playTurn(3, 'All notes stored.');

        $this->assertCount(3, $this->noteWrites, 'nothing done before the pause was done again');
        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), 'one run, continued');
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $runId)->value('end_state'));
        $task = $this->taskRowOf($conversation);
        $this->assertSame(4, (int) $task->steps_used);
        $this->assertSame('completed', $task->state);
    }

    #[Test]
    public function continue_on_the_synchronous_path_returns_the_continuations_result_to_the_waiting_request(): void
    {
        $this->scenario = 'task_budget_resumer_continue_sync';
        $this->entryPath = 'sync';
        [$conversation, $prompt, $runId] = $this->pausedAtTheCeiling('sync');
        $this->decide($conversation, 'continue');
        $this->scriptSteps(3, 3);
        $this->script()->finalAnswer('All notes stored.');
        $deliveredBefore = $this->telegram->textsFor($conversation->id);

        $result = $this->resume($prompt, ResumeCaller::HttpRequest);

        $this->assertSame('completed', $result['status'] ?? null);
        $this->assertSame('All notes stored.', $result['content'] ?? null);
        $this->assertSame($deliveredBefore, $this->telegram->textsFor($conversation->id), 'the waiting request carries the reply; nothing else is sent');
        $this->assertSame(4, $this->modelRequests('sync'));
        $this->assertCount(3, $this->noteWrites);
        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), 'one run, continued');
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $runId)->value('end_state'));
        $task = $this->taskRowOf($conversation);
        $this->assertSame(4, (int) $task->max_steps);
        $this->assertSame(4, (int) $task->steps_used);
        $this->assertSame('completed', $task->state);
        $this->assertSame(0, DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', 'in_progress')->count(), 'no step is left open');
    }

    #[Test]
    public function continue_from_a_background_resume_delivers_the_synchronous_result_on_the_channel(): void
    {
        $this->scenario = 'task_budget_resumer_continue_background';
        $this->entryPath = 'sync';
        [$conversation, $prompt] = $this->pausedAtTheCeiling('sync');
        $this->decide($conversation, 'continue');
        $this->script()->finalAnswer('All notes stored.');
        $deliveredBefore = count($this->telegram->textsFor($conversation->id));

        $result = $this->resume($prompt, ResumeCaller::Background);

        $this->assertSame('completed', $result['status'] ?? null);
        $this->assertSame(['All notes stored.'], array_slice($this->telegram->textsFor($conversation->id), $deliveredBefore), 'nobody is waiting, so the reply goes to the chat, once');
    }

    #[Test]
    public function an_approach_latch_is_reset_only_where_the_ceiling_is_no_longer_near(): void
    {
        $this->scenario = 'task_budget_resumer_latches';
        $this->entryPath = 'sync';
        // Paused at 5 of 5; the steps latch was set on the way.
        [$conversation, $prompt] = $this->pausedAtTheCeiling('sync', 5);
        $taskId = $this->taskRowOf($conversation)->id;
        $noticedAt = '2026-10-12 09:00:00.000000';
        DB::table('task_budgets')->where('id', $taskId)->update(['steps_approach_noticed_at' => $noticedAt, 'spend_approach_noticed_at' => $noticedAt]);

        // +1 step: 5 of 6 used is still past the 0.8 threshold; no spend is used.
        $this->decide($conversation, 'continue steps=1');
        $this->scriptSteps(6, 6);
        $result = $this->resume($prompt);

        $task = DB::table('task_budgets')->where('id', $taskId)->first();
        $this->assertSame(6, (int) $task->max_steps);
        $this->assertNotNull($task->steps_approach_noticed_at, 'still near its ceiling: not noticed again');
        $this->assertNull($task->spend_approach_noticed_at, 'no longer near: may be noticed again');
        $this->assertSame('awaiting_user', $result['status'] ?? null, 'the one granted step ran, and the task paused again');
        $this->assertSame('paused', $task->state);
        $this->assertSame(6, (int) $task->steps_used);
        $this->assertSame(6, $this->modelRequests('sync'));

        // +20 steps: 6 of 26 is far from the threshold.
        $second = $this->promptOf($conversation);
        $this->assertNotSame($prompt->id, $second->id, 'a new prompt for the new ceiling');
        DB::table('task_budgets')->where('id', $taskId)->update(['steps_approach_noticed_at' => $noticedAt]);
        $this->decide($conversation, 'continue steps=20');
        $this->script()->finalAnswer('Done.');
        $this->resume($second);

        $this->assertNull(DB::table('task_budgets')->where('id', $taskId)->value('steps_approach_noticed_at'));
    }

    // ---------------------------------------------------------------
    // Accept
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string, 1: ResumeCaller}> */
    public static function acceptCallers(): array
    {
        return [
            'streamed, from the request' => ['stream', ResumeCaller::HttpRequest],
            'synchronous, from the request' => ['sync', ResumeCaller::HttpRequest],
            'synchronous, in the background' => ['sync', ResumeCaller::Background],
        ];
    }

    #[Test]
    #[DataProvider('acceptCallers')]
    public function accept_concludes_the_task_and_its_report_reaches_the_user_exactly_once(string $path, ResumeCaller $caller): void
    {
        $this->scenario = 'task_budget_resumer_accept_'.$path.'_'.$caller->value;
        $this->entryPath = $path;
        [$conversation, $prompt, $runId] = $this->pausedAtTheCeiling($path);
        $this->decide($conversation, 'accept');
        $deliveredBefore = count($this->deliveredOn($path, $conversation));

        $result = $this->resume($prompt, $caller);

        $task = $this->taskRowOf($conversation);
        $this->assertSame('concluded', $task->state);
        $this->assertSame($this->text->concludedReason(), $task->end_reason);
        $this->assertNotNull($task->ended_at);

        $reports = Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_REPORT)->values();
        $this->assertCount(1, $reports, 'the report is stored in the conversation');
        $report = $reports[0];
        $this->assertSame('assistant', $report->role);
        $this->assertSame($task->id, $report->tool_data['task_budget_id'] ?? null);
        $this->assertSame('accepted', $report->tool_data['occasion'] ?? null);
        $this->assertStringStartsWith("This task's budget ceiling was reached", $report->content);
        $this->assertStringContainsString($this->text->reportNext('accepted'), $report->content);

        $this->assertSame(['status' => 'completed', 'content' => $report->content], $result);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame('completed', $run->end_state);
        $this->assertSame($this->text->concludedReason(), $run->end_reason);
        $this->assertSame(2, $this->modelRequests($path), 'no further step');
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
        $decisions = $this->events($task->id, 'decision');
        $this->assertSame('accept', $decisions[0]['decision'] ?? null);

        // Exactly once: a waiting request carries it in its response; a
        // background resume sends it to the chat; the web screen shows the
        // stored message.
        $delivered = array_slice($this->deliveredOn($path, $conversation), $deliveredBefore);
        $expected = $path === 'sync' && $caller === ResumeCaller::Background ? [$report->content] : [];
        $this->assertSame($expected, $delivered);
    }

    // ---------------------------------------------------------------
    // Failures
    // ---------------------------------------------------------------

    #[Test]
    public function a_quota_refusing_the_continuation_is_reported_as_the_quotas_never_as_the_task_budgets(): void
    {
        $this->scenario = 'task_budget_resumer_quota_refusal';
        $this->entryPath = 'sync';
        [$conversation, $prompt, $runId] = $this->pausedAtTheCeiling('sync');
        $prompt = $this->decide($conversation, 'continue');
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => '25.00', 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );
        DB::table('cost_summaries')->updateOrInsert([
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $this->fixture()->user->id,
            'user_id' => $this->fixture()->user->id,
            'period_date' => Carbon::now()->toDateString(),
        ], [
            'id' => (string) Str::uuid(),
            'request_count' => 1,
            'priced_cost_total' => '999.0000000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);

        $messagesBefore = Message::where('conversation_id', $conversation->id)->pluck('id')->all();

        $caught = null;
        try {
            $this->resume($prompt);
        } catch (BudgetExceededException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'the quota\'s own refusal propagates');
        $this->assertStringNotContainsString("task's budget", $caught->getMessage(), 'and it names the quota, not the task budget');
        $task = $this->taskRowOf($conversation);
        $this->assertSame(4, (int) $task->max_steps, 'the allowance had been granted');
        $this->assertCount(1, $this->events($task->id, 'allowance_granted'));
        $this->assertNull($task->prompt_question_id);
        $this->assertSame(QuestionState::Answered, $prompt->fresh()->state, 'the decision stands');
        $this->assertSame(2, $this->modelRequests('sync'), 'no model request');
        $this->assertFalse((bool) $conversation->fresh()->is_processing);

        // The quota's refusal closes the inherited run, as it does for any
        // refused resume, and the task ends with its run — the user's next
        // request starts a new task. Nothing names the task budget.
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertStringNotContainsString("task's budget", (string) $run->end_reason);
        $this->assertSame('ended', $task->state);
        $this->assertSame('stopped_early', $task->end_reason);
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->whereNotIn('id', $messagesBefore)->where('content', 'like', "%task's budget%")->count(), 'no task-budget text is posted');
    }

    #[Test]
    public function any_other_failure_closes_the_run_as_failed_frees_the_conversation_and_ends_the_task(): void
    {
        $this->scenario = 'task_budget_resumer_failure';
        $this->entryPath = 'sync';
        [$conversation, $prompt, $runId] = $this->pausedAtTheCeiling('sync');
        $this->decide($conversation, 'continue');
        $this->ledger->expect('TaskBudgetResumer: the resumed task failed to continue');
        $this->app->forgetScopedInstances();
        $this->app->scoped(DegradationGate::class, fn () => new class extends DegradationGate
        {
            public function __construct()
            {
            }

            public function forRun(?string $runId): DegradationDecision
            {
                throw new \RuntimeException('model server unreachable');
            }
        });

        $caught = null;
        try {
            app(QuestionResumer::class)->resume($prompt->id, ResumeCaller::HttpRequest);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertSame('model server unreachable', $caught?->getMessage(), 'the failure reaches the caller');
        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'the conversation accepts messages again');
        $this->assertSame('failed', DB::table('agent_runs')->where('id', $runId)->value('end_state'));
        $task = $this->taskRowOf($conversation);
        $this->assertSame('ended', $task->state, 'the run\'s close ends its task');
        $this->assertSame('failed', $task->end_reason);
        $this->assertSame(2, $this->modelRequests('sync'));
    }

    // ---------------------------------------------------------------
    // Without tracing; exactly once
    // ---------------------------------------------------------------

    #[Test]
    public function with_tracing_off_continue_still_resumes_the_task(): void
    {
        $this->scenario = 'task_budget_resumer_tracing_off';
        $this->entryPath = 'sync';
        config(['llm-client.run_trace.enabled' => false]);
        [$conversation, $prompt, $runId] = $this->pausedAtTheCeiling('sync');
        $this->assertNull($runId, 'precondition: no run is recorded');
        $this->assertNull($this->taskRowOf($conversation)->paused_run_id);
        $this->decide($conversation, 'continue');
        $this->script()->finalAnswer('All notes stored.');

        $result = $this->resume($prompt);

        $this->assertSame('completed', $result['status'] ?? null);
        $this->assertSame('All notes stored.', $result['content'] ?? null);
        $this->assertSame(3, $this->modelRequests('sync'));
        $this->assertSame(3, (int) $this->taskRowOf($conversation)->steps_used);
    }

    #[Test]
    public function a_second_resume_of_the_same_decision_does_nothing(): void
    {
        $this->scenario = 'task_budget_resumer_redrive';
        $this->entryPath = 'sync';
        [$conversation, $prompt] = $this->pausedAtTheCeiling('sync');
        $this->decide($conversation, 'continue');
        $this->script()->finalAnswer('All notes stored.');
        $this->resume($prompt);
        $taskId = $this->taskRowOf($conversation)->id;
        $events = DB::table('task_budget_events')->where('task_budget_id', $taskId)->count();
        $task = (array) $this->taskRowOf($conversation);

        $this->assertNull($this->resume($prompt, ResumeCaller::Background));

        $this->assertSame(3, $this->modelRequests('sync'), 'no second continuation');
        $this->assertSame($events, DB::table('task_budget_events')->where('task_budget_id', $taskId)->count());
        $this->assertSame($task, (array) $this->taskRowOf($conversation));
    }

    #[Test]
    public function a_task_no_longer_paused_on_the_prompt_is_not_resumed_again(): void
    {
        $this->scenario = 'task_budget_resumer_cas_loser';
        $this->entryPath = 'sync';
        [$conversation, $prompt, $runId] = $this->pausedAtTheCeiling('sync');
        $this->decide($conversation, 'continue');
        // Something else already applied a decision to the task.
        $taskId = $this->taskRowOf($conversation)->id;
        DB::table('task_budgets')->where('id', $taskId)->update(['state' => 'running', 'prompt_question_id' => null, 'max_steps' => 4]);
        $events = DB::table('task_budget_events')->where('task_budget_id', $taskId)->count();

        $this->assertNull($this->resume($prompt));

        $this->assertSame(2, $this->modelRequests('sync'), 'no continuation');
        $this->assertSame($events, DB::table('task_budget_events')->where('task_budget_id', $taskId)->count(), 'nothing recorded');
        $this->assertSame(4, (int) $this->taskRowOf($conversation)->max_steps, 'the ceiling is not raised a second time');
        $this->assertSame('in_progress', DB::table('agent_runs')->where('id', $runId)->value('end_state'));
    }
}
