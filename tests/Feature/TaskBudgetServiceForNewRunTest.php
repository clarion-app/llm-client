<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\ValueObjects\CeilingOrigin;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which task a piece of work spends from.
 *
 * A new request starts a new task, with the ceilings the user stated or the
 * installation defaults. Work done on that request's behalf — a helper it
 * delegated to, a helper's helper, a managed task's manager steps — joins
 * the task its conversation points at, whatever state that task is in: a
 * helper never opens a budget of its own, because a helper that could would
 * let delegated work escape a paused or halted task with a fresh default
 * ceiling. The gate then refuses any step on a task that is not running.
 *
 * A conversation that reaches a boundary with no task at all (a run paused
 * before this feature existed) gets a default one opened lazily, with the
 * usage and steps that run already recorded attached to it.
 *
 * None of this may throw: when the task store cannot be used, forNewRun()
 * returns null and leaves no pointer, so the first boundary fails closed.
 */
class TaskBudgetServiceForNewRunTest extends TestCase
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
        foreach (['task_budget_events', 'task_budgets', 'usage_records', 'agent_run_steps', 'agent_questions', 'agent_delegations', 'conversations'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    private function service(): TaskBudgetService
    {
        return app(TaskBudgetService::class);
    }

    private function conversation(array $attributes = []): Conversation
    {
        return Conversation::create($attributes + [
            'user_id' => (string) Str::uuid(),
            'channel' => 'web',
            'title' => 'Task budget',
        ]);
    }

    private function pointAt(Conversation $conversation, ?string $taskBudgetId): Conversation
    {
        DB::table('conversations')->where('id', $conversation->id)->update(['active_task_budget_id' => $taskBudgetId]);

        return $conversation->fresh();
    }

    private function setState(TaskBudget $task, string $state): void
    {
        DB::table('task_budgets')->where('id', $task->id)->update(['state' => $state]);
    }

    private function delegate(Conversation $parent, Conversation $helper): void
    {
        DB::table('agent_delegations')->insert([
            'id' => (string) Str::uuid(),
            'parent_conversation_id' => $parent->id,
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => $helper->id,
            'owner_user_id' => (string) $parent->user_id,
            'task' => 'Help.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    /** @return list<array{kind: string, payload: array}> */
    private function events(string $taskBudgetId): array
    {
        return DB::table('task_budget_events')
            ->where('task_budget_id', $taskBudgetId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($row) => ['kind' => $row->kind, 'payload' => json_decode($row->payload, true)])
            ->all();
    }

    private function warningsOrWorse(): array
    {
        return array_values(array_filter($this->logged, fn (MessageLogged $e) => in_array($e->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)));
    }

    // ---------------------------------------------------------------
    // A new request opens a new task
    // ---------------------------------------------------------------

    #[Test]
    public function a_request_with_nothing_stated_opens_a_default_task(): void
    {
        $conversation = $this->conversation();
        $runId = (string) Str::uuid();

        $task = $this->service()->forNewRun($conversation, null, false, $runId);

        $this->assertInstanceOf(TaskBudget::class, $task);
        $task = TaskBudget::find($task->id);
        $this->assertSame('request', $task->scope);
        $this->assertSame(TaskBudgetState::Running, $task->state);
        $this->assertSame(20, (int) $task->max_steps);
        $this->assertSame(20, (int) $task->original_max_steps);
        $this->assertSame('1.0000000000', $task->max_spend);
        $this->assertSame('1.0000000000', $task->original_max_spend);
        $this->assertSame(CeilingOrigin::Default, $task->max_steps_origin);
        $this->assertSame(CeilingOrigin::Default, $task->max_spend_origin);
        $this->assertSame(0, (int) $task->steps_used);
        $this->assertSame('0.8000', $task->approach_threshold);
        $this->assertFalse((bool) $task->unattended);
        $this->assertSame($conversation->id, $task->root_conversation_id);
        $this->assertSame($runId, $task->root_run_id);
        $this->assertSame($conversation->user_id, $task->user_id);
        $this->assertNull($task->managed_task_id);
        $this->assertNull($task->prompt_question_id);

        $this->assertSame($task->id, $conversation->active_task_budget_id, 'the given instance points at the task');
        $this->assertSame($task->id, $conversation->fresh()->active_task_budget_id, 'and so does the stored row');
    }

    #[Test]
    public function the_task_records_whether_the_request_is_unattended_and_who_owns_it(): void
    {
        $scheduled = $this->service()->forNewRun($this->conversation(), null, true, null);
        $this->assertTrue((bool) TaskBudget::find($scheduled->id)->unattended);
        $this->assertNull(TaskBudget::find($scheduled->id)->root_run_id, 'tracing disabled: no run id');

        $eval = $this->service()->forNewRun($this->conversation(['user_id' => null]), null, false, null);
        $this->assertNull(TaskBudget::find($eval->id)->user_id, 'a conversation with no user gives a task with no owner');
    }

    #[Test]
    public function stated_ceilings_are_used_with_user_origin(): void
    {
        $task = $this->service()->forNewRun($this->conversation(), new StatedCeilings(maxSteps: 5, maxSpend: '2.00'), false, null);

        $task = TaskBudget::find($task->id);
        $this->assertSame(5, (int) $task->max_steps);
        $this->assertSame(5, (int) $task->original_max_steps);
        $this->assertSame('2.0000000000', $task->max_spend);
        $this->assertSame('2.0000000000', $task->original_max_spend);
        $this->assertSame(CeilingOrigin::User, $task->max_steps_origin);
        $this->assertSame(CeilingOrigin::User, $task->max_spend_origin);
    }

    #[Test]
    public function stated_ceilings_above_the_maximum_are_reduced(): void
    {
        $task = $this->service()->forNewRun($this->conversation(), new StatedCeilings(maxSteps: 1000), false, null);

        $task = TaskBudget::find($task->id);
        $this->assertSame(200, (int) $task->max_steps);
        $this->assertSame(CeilingOrigin::UserReducedToMaximum, $task->max_steps_origin);
        $this->assertSame(CeilingOrigin::Default, $task->max_spend_origin);
    }

    #[Test]
    public function opening_a_task_appends_an_opened_event_with_its_ceilings(): void
    {
        $task = $this->service()->forNewRun($this->conversation(), new StatedCeilings(maxSteps: 7), false, null);

        $events = $this->events($task->id);
        $this->assertCount(1, $events);
        $this->assertSame('opened', $events[0]['kind']);
        $this->assertSame('request', $events[0]['payload']['scope']);
        $this->assertSame(7, $events[0]['payload']['max_steps']);
        $this->assertSame('user', $events[0]['payload']['max_steps_origin']);
        $this->assertSame(0, bccomp($events[0]['payload']['max_spend'], '1', 10));
        $this->assertSame('default', $events[0]['payload']['max_spend_origin']);
    }

    #[Test]
    public function a_new_request_supersedes_a_still_running_predecessor(): void
    {
        $conversation = $this->conversation();
        $first = $this->service()->forNewRun($conversation, null, false, null);

        $second = $this->service()->forNewRun($conversation->fresh(), null, false, null);

        $this->assertNotSame($first->id, $second->id);
        $predecessor = TaskBudget::find($first->id);
        $this->assertSame(TaskBudgetState::Ended, $predecessor->state);
        $this->assertSame('superseded', $predecessor->end_reason);
        $this->assertNotNull($predecessor->ended_at);
        $this->assertSame(TaskBudgetState::Running, TaskBudget::find($second->id)->state);
        $this->assertSame($second->id, $conversation->fresh()->active_task_budget_id);

        $ended = array_values(array_filter($this->events($first->id), fn ($e) => $e['kind'] === 'ended'));
        $this->assertCount(1, $ended);
        $this->assertSame('superseded', $ended[0]['payload']['reason']);
    }

    public static function nonRunningStates(): array
    {
        return [
            'paused' => ['paused'],
            'concluded' => ['concluded'],
            'completed' => ['completed'],
            'halted' => ['halted'],
            'ended' => ['ended'],
        ];
    }

    /**
     * Only a running predecessor is superseded. A paused task's prompt holds
     * the conversation's question latch, so in production every new message
     * is taken by the answer intake and never reaches forNewRun(); calling it
     * directly here pins the rule, not a reachable path.
     */
    #[Test]
    #[DataProvider('nonRunningStates')]
    public function a_predecessor_in_any_other_state_is_left_exactly_as_it_is(string $state): void
    {
        $conversation = $this->conversation();
        $first = $this->service()->forNewRun($conversation, null, false, null);
        $this->setState($first, $state);
        $before = (array) DB::table('task_budgets')->where('id', $first->id)->first();

        $second = $this->service()->forNewRun($conversation->fresh(), null, false, null);

        $this->assertNotSame($first->id, $second->id, 'a web conversation\'s new request opens a new task');
        $this->assertSame($before, (array) DB::table('task_budgets')->where('id', $first->id)->first(), "a {$state} predecessor is untouched");
    }

    // ---------------------------------------------------------------
    // Joining: helpers, managed tasks, other joined conversations
    // ---------------------------------------------------------------

    public static function everyState(): array
    {
        return [
            'running' => ['running'],
            'paused' => ['paused'],
            'concluded' => ['concluded'],
            'completed' => ['completed'],
            'halted' => ['halted'],
            'ended' => ['ended'],
        ];
    }

    #[Test]
    #[DataProvider('everyState')]
    public function a_helper_joins_its_pointed_task_in_every_state_and_never_opens_its_own(string $state): void
    {
        $root = $this->conversation();
        $task = $this->service()->forNewRun($root, null, false, null);
        $this->setState($task, $state);

        $helper = $this->conversation(['user_id' => $root->user_id, 'channel' => 'agent-delegation']);
        $this->delegate($root, $helper);
        $helper = $this->pointAt($helper, $task->id);

        $joined = $this->service()->forNewRun($helper, null, false, (string) Str::uuid());

        $this->assertNotNull($joined);
        $this->assertSame($task->id, $joined->id);
        $this->assertSame(1, DB::table('task_budgets')->count(), 'no second task');
        $this->assertSame($state, DB::table('task_budgets')->where('id', $task->id)->value('state'), 'joining changes nothing');
        $this->assertSame($task->id, $helper->fresh()->active_task_budget_id);
    }

    #[Test]
    public function a_helper_ignores_stated_ceilings_and_the_unattended_flag_when_joining(): void
    {
        $root = $this->conversation();
        $task = $this->service()->forNewRun($root, null, false, null);
        $helper = $this->pointAt($this->conversation(['user_id' => $root->user_id, 'channel' => 'agent-delegation']), $task->id);

        $joined = $this->service()->forNewRun($helper, new StatedCeilings(maxSteps: 3), true, null);

        $this->assertSame($task->id, $joined->id);
        $this->assertSame(20, (int) TaskBudget::find($task->id)->max_steps);
        $this->assertFalse((bool) TaskBudget::find($task->id)->unattended);
    }

    #[Test]
    public function a_helper_with_no_pointer_joins_its_chain_roots_current_task(): void
    {
        $root = $this->conversation();
        $task = $this->service()->forNewRun($root, null, false, null);

        $middle = $this->conversation(['user_id' => $root->user_id, 'channel' => 'agent-delegation']);
        $helper = $this->conversation(['user_id' => $root->user_id, 'channel' => 'agent-delegation']);
        $this->delegate($root, $middle);
        $this->delegate($middle, $helper);

        $joined = $this->service()->forNewRun($helper, null, false, null);

        $this->assertSame($task->id, $joined->id);
        $this->assertSame(1, DB::table('task_budgets')->count());
    }

    #[Test]
    #[DataProvider('nonRunningStates')]
    public function a_helper_with_no_pointer_joins_the_roots_task_whatever_its_state(string $state): void
    {
        $root = $this->conversation();
        $task = $this->service()->forNewRun($root, null, false, null);
        $this->setState($task, $state);

        $helper = $this->conversation(['user_id' => $root->user_id, 'channel' => 'agent-delegation']);
        $this->delegate($root, $helper);

        $joined = $this->service()->forNewRun($helper, null, false, null);

        $this->assertSame($task->id, $joined->id);
        $this->assertSame(1, DB::table('task_budgets')->count(), 'the helper does not escape a non-running task with a fresh budget');
    }

    #[Test]
    public function a_helper_whose_root_has_no_task_opens_one_lazily_on_the_root(): void
    {
        $root = $this->conversation();
        $helper = $this->conversation(['user_id' => $root->user_id, 'channel' => 'agent-delegation']);
        $this->delegate($root, $helper);

        $joined = $this->service()->forNewRun($helper, null, false, null);

        $this->assertNotNull($joined);
        $task = TaskBudget::find($joined->id);
        $this->assertSame($root->id, $task->root_conversation_id, 'the task belongs to the root, not the helper');
        $this->assertSame($task->id, $root->fresh()->active_task_budget_id);
        $this->assertSame(20, (int) $task->max_steps);
        $this->assertSame(1, DB::table('task_budgets')->count());
    }

    #[Test]
    #[DataProvider('everyState')]
    public function a_managed_task_conversation_joins_its_managed_task_in_any_state(string $state): void
    {
        $manager = $this->conversation(['channel' => 'managed-task']);
        $managedTaskId = (string) Str::uuid();
        $task = $this->service()->openForRequest($manager, 'managed_task', null, $managedTaskId, true);
        $this->setState($task, $state);

        $joined = $this->service()->forNewRun($manager->fresh(), null, false, (string) Str::uuid());

        $this->assertSame($task->id, $joined->id);
        $this->assertSame(1, DB::table('task_budgets')->count());
    }

    #[Test]
    public function any_other_conversation_pointed_at_a_task_rooted_elsewhere_joins_it(): void
    {
        $root = $this->conversation();
        $task = $this->service()->forNewRun($root, null, false, null);
        $other = $this->pointAt($this->conversation(['user_id' => $root->user_id]), $task->id);

        $joined = $this->service()->forNewRun($other, null, false, null);

        $this->assertSame($task->id, $joined->id);
        $this->assertSame(1, DB::table('task_budgets')->count());
    }

    // ---------------------------------------------------------------
    // Requests with no root agent loop
    // ---------------------------------------------------------------

    public static function requestScopes(): array
    {
        return [
            'managed task' => ['managed_task', 'managed-task'],
            'sequence run' => ['sequence_run', 'sequence-run'],
        ];
    }

    #[Test]
    #[DataProvider('requestScopes')]
    public function open_for_request_opens_an_unattended_task_and_points_the_conversation(string $scope, string $channel): void
    {
        $conversation = $this->conversation(['channel' => $channel]);
        $managedTaskId = $scope === 'managed_task' ? (string) Str::uuid() : null;

        $task = $this->service()->openForRequest($conversation, $scope, null, $managedTaskId, true);

        $task = TaskBudget::find($task->id);
        $this->assertSame($scope, $task->scope);
        $this->assertTrue((bool) $task->unattended);
        $this->assertSame($managedTaskId, $task->managed_task_id);
        $this->assertSame($conversation->id, $task->root_conversation_id);
        $this->assertSame(20, (int) $task->max_steps);
        $this->assertSame(TaskBudgetState::Running, $task->state);
        $this->assertSame($task->id, $conversation->active_task_budget_id);
        $this->assertSame($task->id, $conversation->fresh()->active_task_budget_id);
        $this->assertSame('opened', $this->events($task->id)[0]['kind']);
    }

    #[Test]
    public function a_consensus_task_leaves_the_users_conversation_pointer_alone(): void
    {
        $conversation = $this->conversation();
        $earlier = $this->service()->forNewRun($conversation, null, false, null);
        $this->setState($earlier, 'paused');
        $conversation = $conversation->fresh();

        $task = $this->service()->openForRequest($conversation, 'consensus', null, null, false);

        $this->assertNotSame($earlier->id, $task->id);
        $this->assertSame('consensus', TaskBudget::find($task->id)->scope);
        $this->assertTrue((bool) TaskBudget::find($task->id)->unattended);
        $this->assertSame($earlier->id, $conversation->active_task_budget_id, 'the instance still points at the earlier task');
        $this->assertSame($earlier->id, $conversation->fresh()->active_task_budget_id);
        $this->assertSame('paused', DB::table('task_budgets')->where('id', $earlier->id)->value('state'));
    }

    // ---------------------------------------------------------------
    // Lazy opening (a run that predates the feature)
    // ---------------------------------------------------------------

    #[Test]
    public function open_lazily_attaches_the_runs_unattributed_usage_and_seeds_its_steps(): void
    {
        $conversation = $this->conversation();
        $run = (string) Str::uuid();
        $otherRun = (string) Str::uuid();
        $otherTask = (string) Str::uuid();

        foreach ([[$run, null], [$run, null], [$run, $otherTask], [$otherRun, null]] as [$runId, $taskId]) {
            DB::table('usage_records')->insert([
                'id' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'user_id' => (string) $conversation->user_id,
                'attempt_group_id' => (string) Str::uuid(),
                'run_id' => $runId,
                'task_budget_id' => $taskId,
                'total_cost' => '0.0100000000',
                'created_at' => now(),
            ]);
        }
        foreach ([$run, $run, $run, $otherRun] as $position => $runId) {
            DB::table('agent_run_steps')->insert([
                'id' => (string) Str::uuid(),
                'run_id' => $runId,
                'position' => $position,
                'started_at' => now(),
            ]);
        }

        $task = $this->service()->openLazily($conversation, $run);

        $task = TaskBudget::find($task->id);
        $this->assertSame(3, (int) $task->steps_used, "seeded from the run's recorded steps");
        $this->assertSame(20, (int) $task->max_steps);
        $this->assertSame(CeilingOrigin::Default, $task->max_steps_origin);
        $this->assertSame($run, $task->root_run_id);
        $this->assertSame($task->id, $conversation->active_task_budget_id);
        $this->assertSame($task->id, $conversation->fresh()->active_task_budget_id);
        $this->assertSame(2, DB::table('usage_records')->where('task_budget_id', $task->id)->count(), "the run's unattributed rows are attached");
        $this->assertSame(1, DB::table('usage_records')->where('task_budget_id', $otherTask)->count(), 'a row already attributed elsewhere is left alone');
        $this->assertSame(1, DB::table('usage_records')->where('run_id', $otherRun)->whereNull('task_budget_id')->count(), "another run's rows are left alone");
        $this->assertSame('opened', $this->events($task->id)[0]['kind']);
    }

    #[Test]
    public function open_lazily_with_tracing_off_starts_at_zero(): void
    {
        $conversation = $this->conversation();
        DB::table('agent_run_steps')->insert(['id' => (string) Str::uuid(), 'run_id' => (string) Str::uuid(), 'position' => 0, 'started_at' => now()]);

        $task = $this->service()->openLazily($conversation, null);

        $this->assertSame(0, (int) TaskBudget::find($task->id)->steps_used);
        $this->assertNull(TaskBudget::find($task->id)->root_run_id);
    }

    // ---------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------

    #[Test]
    public function current_for_returns_the_pointed_task_or_null(): void
    {
        $conversation = $this->conversation();
        $this->assertNull($this->service()->currentFor($conversation));

        $task = $this->service()->forNewRun($conversation, null, false, null);
        $this->assertSame($task->id, $this->service()->currentFor($conversation->fresh())->id);

        $this->assertNull($this->service()->currentFor($this->pointAt($conversation, (string) Str::uuid())), 'a pointer to nothing is no task');
    }

    #[Test]
    public function standing_reads_spend_fresh_and_derives_the_sub_state_from_the_prompt(): void
    {
        $conversation = $this->conversation();
        $task = $this->service()->forNewRun($conversation, null, false, null);

        DB::table('usage_records')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'user_id' => (string) $conversation->user_id,
            'attempt_group_id' => (string) Str::uuid(),
            'task_budget_id' => $task->id,
            'total_cost' => '0.4120000000',
            'created_at' => now(),
        ]);
        DB::table('usage_records')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'user_id' => (string) $conversation->user_id,
            'attempt_group_id' => (string) Str::uuid(),
            'task_budget_id' => $task->id,
            'total_cost' => null,
            'cost_unpriced' => true,
            'created_at' => now(),
        ]);

        $standing = $this->service()->standing(TaskBudget::find($task->id));
        $this->assertSame('0.4120000000', $standing->spendUsed);
        $this->assertSame(1, $standing->unpricedRequestCount);
        $this->assertSame('USD', $standing->currency);
        $this->assertSame($conversation->id, $standing->conversationId);
        $this->assertNull($standing->subState());

        $promptId = (string) Str::uuid();
        DB::table('agent_questions')->insert([
            'id' => $promptId,
            'conversation_id' => $conversation->id,
            'user_id' => (string) $conversation->user_id,
            'pending_conversation_id' => $conversation->id,
            'paused_message_id' => (string) Str::uuid(),
            'tool_call_id' => 'task_budget',
            'channel' => 'web',
            'resume_mode' => 'stream',
            'question' => "This task's budget ceiling was reached. What should happen next?",
            'choices' => json_encode(['Continue', 'Accept', 'Stop']),
            'state' => 'pending',
            'asked_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        DB::table('task_budgets')->where('id', $task->id)->update(['state' => 'paused', 'prompt_question_id' => $promptId]);

        $standing = $this->service()->standing(TaskBudget::find($task->id));
        $this->assertSame('awaiting_decision', $standing->subState());
        $this->assertSame($promptId, $standing->toArray()['prompt_question_id']);

        DB::table('agent_questions')->where('id', $promptId)->update(['state' => 'stopped']);
        $this->assertSame('stopped', $this->service()->standing(TaskBudget::find($task->id))->subState());
    }

    // ---------------------------------------------------------------
    // Never throws
    // ---------------------------------------------------------------

    #[Test]
    public function an_event_write_failure_is_logged_and_never_blocks_the_open(): void
    {
        Schema::drop('task_budget_events');
        $conversation = $this->conversation();

        $task = $this->service()->forNewRun($conversation, null, false, null);

        $this->assertNotNull($task);
        $this->assertSame(TaskBudgetState::Running, TaskBudget::find($task->id)->state);
        $this->assertSame($task->id, $conversation->fresh()->active_task_budget_id);
        $this->assertNotEmpty($this->warningsOrWorse(), 'the lost event is logged');
    }

    #[Test]
    public function append_event_is_best_effort(): void
    {
        $task = $this->service()->forNewRun($this->conversation(), null, false, null);
        Schema::drop('task_budget_events');

        $this->service()->appendEvent(TaskBudget::find($task->id), 'ceiling_changed', ['max_steps' => 30], null);

        $this->assertNotEmpty($this->warningsOrWorse(), 'logged, not thrown');
    }

    #[Test]
    public function with_the_task_store_broken_for_new_run_returns_null_and_leaves_no_usable_pointer(): void
    {
        Schema::drop('task_budgets');
        $conversation = $this->conversation();

        $task = $this->service()->forNewRun($conversation, null, false, (string) Str::uuid());

        $this->assertNull($task);
        $this->assertNull($conversation->active_task_budget_id);
        $this->assertNull($conversation->fresh()->active_task_budget_id);
        $this->assertNotEmpty($this->warningsOrWorse());

        $decision = app(TaskBudgetGate::class)->beforeStep($conversation, null);
        $this->assertTrue($decision->isStop(), 'the first boundary fails closed');
        $this->assertSame('unavailable', $decision->reason);
    }
}
