<?php

namespace Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The conversation's task budget, viewable and changeable on demand by its
 * owner.
 *
 * GET conversation/{id}/task-budget answers the standing of the
 * conversation's current task — the one its pointer names, in whatever
 * state: running, paused waiting for a decision, paused and stopped, or
 * finished — and `{"task_budget": null}` when it has none.
 *
 * PATCH conversation/{id}/task-budget raises or lowers either ceiling of a
 * task that is running or paused, effective from its next step boundary.
 * A ceiling above the installation maximum is cut to it and the reply says
 * so; the ceiling's origin records that the user set it. Raising a ceiling
 * far enough below which the task's consumption sits lets the approach
 * notice fire again against it. Changing a paused task's ceiling never
 * resumes it — only a decision on its prompt does. Every change is logged
 * with what it was before and after, and who made it. A finished task's
 * ceilings cannot be changed.
 *
 * A conversation that does not exist and one that belongs to someone else
 * get the same 404, status and body alike, on both methods, so the endpoint
 * never tells a caller which conversations exist.
 */
class TaskBudgetControllerTest extends TestCase
{
    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.cost.currency' => 'USD']);
        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    protected function tearDown(): void
    {
        foreach (['task_budget_events', 'task_budgets', 'usage_records', 'agent_questions', 'messages', 'conversations', 'users'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    private function conversationOf(User $user): Conversation
    {
        return Conversation::create(['user_id' => $user->id, 'channel' => 'web', 'title' => 'Limits']);
    }

    /** @return array{0: Conversation, 1: TaskBudget} the owner's conversation with a 20-step, 1.00 task */
    private function withTask(): array
    {
        $conversation = $this->conversationOf($this->owner);
        $task = app(TaskBudgetService::class)->forNewRun($conversation, null, false, null);
        $this->assertNotNull($task, 'precondition: the task opened');

        return [$conversation, $task];
    }

    private function usage(TaskBudget $task, ?string $cost, bool $unpriced = false): void
    {
        DB::table('usage_records')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $task->root_conversation_id,
            'user_id' => $this->owner->id,
            'attempt_group_id' => (string) Str::uuid(),
            'task_budget_id' => $task->id,
            'total_cost' => $cost,
            'cost_unpriced' => $unpriced,
            'total_tokens' => 100,
            'created_at' => now(),
        ]);
    }

    /** A ceiling prompt row in $state for the task, which then points at it. */
    private function prompt(Conversation $conversation, TaskBudget $task, string $state): string
    {
        $id = (string) Str::uuid();
        DB::table('agent_questions')->insert([
            'id' => $id,
            'conversation_id' => $conversation->id,
            'user_id' => $this->owner->id,
            'pending_conversation_id' => $conversation->id,
            'paused_message_id' => (string) Str::uuid(),
            'tool_call_id' => 'task_budget',
            'channel' => 'web',
            'resume_mode' => 'stream',
            'question' => "This task's budget ceiling was reached. What should happen next?",
            'choices' => json_encode(['Continue with more allowance (+20 steps)', 'Accept what has been done', 'Stop']),
            'state' => $state,
            'asked_at' => now()->format('Y-m-d H:i:s.u'),
            'expires_at' => now()->addDay()->format('Y-m-d H:i:s.u'),
            'stopped_at' => $state === 'stopped' ? now()->format('Y-m-d H:i:s.u') : null,
            'origin' => 'task_budget',
            'task_budget_id' => $task->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('task_budgets')->where('id', $task->id)->update([
            'state' => 'paused',
            'pause_reason' => 'steps',
            'paused_at' => now()->format('Y-m-d H:i:s.u'),
            'steps_used' => 20,
            'prompt_question_id' => $id,
        ]);

        return $id;
    }

    private function show(User $as, string $conversationId)
    {
        return $this->actingAs($as, 'api')->getJson("/api/clarion-app/llm-client/conversation/{$conversationId}/task-budget");
    }

    private function label(TaskBudget $task): string
    {
        return (new TaskBudgetText())->standing(app(TaskBudgetService::class)->standing(TaskBudget::findOrFail($task->id)));
    }

    #[Test]
    public function the_owner_sees_the_running_tasks_standing(): void
    {
        [$conversation, $task] = $this->withTask();
        DB::table('task_budgets')->where('id', $task->id)->update(['steps_used' => 16, 'steps_approach_noticed_at' => now()->format('Y-m-d H:i:s.u')]);
        $this->usage($task, '0.4100000000');
        $this->usage($task, null, unpriced: true);

        $response = $this->show($this->owner, $conversation->id);

        $response->assertStatus(200);
        $this->assertSame([
            'task_budget_id' => $task->id,
            'conversation_id' => $conversation->id,
            'state' => 'running',
            'sub_state' => null,
            'steps' => ['used' => 16, 'ceiling' => 20, 'origin' => 'default', 'maximum' => 200, 'approach_noticed' => true],
            'spend' => [
                'used' => '0.4100000000',
                'ceiling' => '1.0000000000',
                'currency' => 'USD',
                'origin' => 'default',
                'maximum' => '25.0000000000',
                'approximate' => false,
                'incomplete' => true,
                'unpriced_request_count' => 1,
                'approach_noticed' => false,
            ],
            'approach_threshold' => '0.8000',
            'reached' => [],
            'prompt_question_id' => null,
            'label' => $this->label($task),
        ], $response->json());
        $this->assertStringContainsString('16 of 20 steps, 0.41 of 1.00 USD', $response->json('label'));
    }

    #[Test]
    public function a_ceiling_stated_by_the_user_shows_its_origin(): void
    {
        $conversation = $this->conversationOf($this->owner);
        $task = app(TaskBudgetService::class)->forNewRun($conversation, new StatedCeilings(maxSteps: 50, maxSpend: '2.00'), false, null);

        $response = $this->show($this->owner, $conversation->id)->assertStatus(200);

        $this->assertSame($task->id, $response->json('task_budget_id'));
        $this->assertSame(['used' => 0, 'ceiling' => 50, 'origin' => 'user', 'maximum' => 200, 'approach_noticed' => false], $response->json('steps'));
        $this->assertSame('user', $response->json('spend.origin'));
        $this->assertSame('2.0000000000', $response->json('spend.ceiling'));
    }

    public static function pausedStates(): array
    {
        return [
            'paused, waiting for the decision' => ['pending', 'awaiting_decision'],
            'paused and stopped' => ['stopped', 'stopped'],
        ];
    }

    #[Test]
    #[DataProvider('pausedStates')]
    public function the_owner_sees_a_paused_tasks_standing(string $promptState, string $subState): void
    {
        [$conversation, $task] = $this->withTask();
        $promptId = $this->prompt($conversation, $task, $promptState);

        $response = $this->show($this->owner, $conversation->id)->assertStatus(200);

        $this->assertSame(app(TaskBudgetService::class)->standing(TaskBudget::findOrFail($task->id))->toArray(), $response->json());
        $this->assertSame('paused', $response->json('state'));
        $this->assertSame($subState, $response->json('sub_state'));
        $this->assertSame(['steps'], $response->json('reached'));
        $this->assertSame($promptId, $response->json('prompt_question_id'));
        $this->assertSame(20, $response->json('steps.used'));
    }

    #[Test]
    public function the_owner_sees_a_finished_tasks_standing(): void
    {
        [$conversation, $task] = $this->withTask();
        DB::table('task_budgets')->where('id', $task->id)->update(['state' => 'completed', 'steps_used' => 3, 'ended_at' => now()->format('Y-m-d H:i:s.u')]);

        $response = $this->show($this->owner, $conversation->id)->assertStatus(200);

        $this->assertSame(app(TaskBudgetService::class)->standing(TaskBudget::findOrFail($task->id))->toArray(), $response->json());
        $this->assertSame('completed', $response->json('state'));
        $this->assertNull($response->json('sub_state'));
        $this->assertSame(3, $response->json('steps.used'));
    }

    #[Test]
    public function a_conversation_with_no_task_has_a_null_task_budget(): void
    {
        $conversation = $this->conversationOf($this->owner);

        $this->show($this->owner, $conversation->id)->assertStatus(200)->assertExactJson(['task_budget' => null]);

        // A pointer naming a task that no longer exists is no task either.
        DB::table('conversations')->where('id', $conversation->id)->update(['active_task_budget_id' => (string) Str::uuid()]);
        $this->show($this->owner, $conversation->id)->assertStatus(200)->assertExactJson(['task_budget' => null]);
    }

    #[Test]
    public function an_absent_and_a_foreign_conversation_get_the_identical_404(): void
    {
        [$conversation] = $this->withTask();

        $absent = $this->show($this->owner, (string) Str::uuid());
        $foreign = $this->show($this->stranger, $conversation->id);

        $absent->assertStatus(404)->assertExactJson(['message' => 'Not found.']);
        $foreign->assertStatus(404);
        $this->assertSame($absent->getContent(), $foreign->getContent(), 'no oracle: the bodies are identical');
        $this->assertSame($absent->getStatusCode(), $foreign->getStatusCode());
    }

    #[Test]
    public function viewing_the_standing_changes_nothing(): void
    {
        [$conversation, $task] = $this->withTask();
        $before = (array) DB::table('task_budgets')->where('id', $task->id)->first();
        $events = DB::table('task_budget_events')->count();

        $this->show($this->owner, $conversation->id)->assertStatus(200);

        $this->assertSame($before, (array) DB::table('task_budgets')->where('id', $task->id)->first());
        $this->assertSame($events, DB::table('task_budget_events')->count());
    }

    // ---------------------------------------------------------------
    // PATCH: changing a ceiling
    // ---------------------------------------------------------------

    private function patchBudget(User $as, string $conversationId, array $body)
    {
        return $this->actingAs($as, 'api')->patchJson("/api/clarion-app/llm-client/conversation/{$conversationId}/task-budget", $body);
    }

    /** The fresh standing of $task with the `reduced` flags a change answers with. */
    private function changed(TaskBudget $task, bool $stepsReduced, bool $spendReduced): array
    {
        return app(TaskBudgetService::class)->standing(TaskBudget::findOrFail($task->id))->toArray()
            + ['reduced' => ['steps' => $stepsReduced, 'spend' => $spendReduced]];
    }

    private function row(TaskBudget $task): object
    {
        return DB::table('task_budgets')->where('id', $task->id)->first();
    }

    /** @return list<array{payload: array, actor_user_id: ?string}> */
    private function ceilingChanges(TaskBudget $task): array
    {
        return DB::table('task_budget_events')->where('task_budget_id', $task->id)->where('kind', 'ceiling_changed')->orderBy('created_at')->get()
            ->map(fn ($e) => ['payload' => json_decode((string) $e->payload, true), 'actor_user_id' => $e->actor_user_id])->all();
    }

    public static function changes(): array
    {
        return [
            'steps' => [['max_steps' => 50], 50, 'user', '1.0000000000', 'default'],
            'spend' => [['max_spend' => '2.50'], 20, 'default', '2.5000000000', 'user'],
            'both' => [['max_steps' => 8, 'max_spend' => '0.40'], 8, 'user', '0.4000000000', 'user'],
        ];
    }

    #[Test]
    #[DataProvider('changes')]
    public function the_owner_changes_a_running_tasks_ceiling(array $body, int $steps, string $stepsOrigin, string $spend, string $spendOrigin): void
    {
        [$conversation, $task] = $this->withTask();

        $response = $this->patchBudget($this->owner, $conversation->id, $body);

        $response->assertStatus(200);
        $this->assertSame($this->changed($task, false, false), $response->json());
        $row = $this->row($task);
        $this->assertSame('running', $row->state);
        $this->assertSame($steps, (int) $row->max_steps);
        $this->assertSame($stepsOrigin, $row->max_steps_origin);
        $this->assertSame(0, bccomp($spend, (string) $row->max_spend, 10));
        $this->assertSame($spendOrigin, $row->max_spend_origin);
        $this->assertSame($steps, $response->json('steps.ceiling'));
        $this->assertSame($spend, $response->json('spend.ceiling'));

        $this->assertSame([[
            'payload' => [
                'before' => ['max_steps' => 20, 'max_steps_origin' => 'default', 'max_spend' => '1.0000000000', 'max_spend_origin' => 'default'],
                'after' => ['max_steps' => $steps, 'max_steps_origin' => $stepsOrigin, 'max_spend' => $spend, 'max_spend_origin' => $spendOrigin],
                'reduced' => ['steps' => false, 'spend' => false],
            ],
            'actor_user_id' => (string) $this->owner->id,
        ]], $this->ceilingChanges($task), 'one ceiling_changed event: before, after and who');
    }

    #[Test]
    public function a_ceiling_the_user_does_not_mention_keeps_its_value_and_origin(): void
    {
        $conversation = $this->conversationOf($this->owner);
        $task = app(TaskBudgetService::class)->forNewRun($conversation, new StatedCeilings(maxSteps: 50), false, null);

        $this->patchBudget($this->owner, $conversation->id, ['max_spend' => '3.00'])->assertStatus(200);

        $row = $this->row($task);
        $this->assertSame(50, (int) $row->max_steps);
        $this->assertSame('user', $row->max_steps_origin);
        $this->assertSame(0, bccomp('3', (string) $row->max_spend, 10));
        $this->assertSame('user', $row->max_spend_origin);
    }

    public static function aboveTheMaximum(): array
    {
        return [
            'steps' => [['max_steps' => 1000], 200, 'user_reduced_to_maximum', '1.0000000000', 'default', true, false],
            'spend' => [['max_spend' => '100.00'], 20, 'default', '25.0000000000', 'user_reduced_to_maximum', false, true],
            'both' => [['max_steps' => 1000, 'max_spend' => '100.00'], 200, 'user_reduced_to_maximum', '25.0000000000', 'user_reduced_to_maximum', true, true],
            'one of two' => [['max_steps' => 1000, 'max_spend' => '2.00'], 200, 'user_reduced_to_maximum', '2.0000000000', 'user', true, false],
        ];
    }

    #[Test]
    #[DataProvider('aboveTheMaximum')]
    public function a_ceiling_above_the_installation_maximum_is_cut_to_it_and_the_reply_says_so(array $body, int $steps, string $stepsOrigin, string $spend, string $spendOrigin, bool $stepsReduced, bool $spendReduced): void
    {
        [$conversation, $task] = $this->withTask();

        $response = $this->patchBudget($this->owner, $conversation->id, $body)->assertStatus(200);

        $this->assertSame($this->changed($task, $stepsReduced, $spendReduced), $response->json());
        $this->assertSame(['steps' => $stepsReduced, 'spend' => $spendReduced], $response->json('reduced'));
        $row = $this->row($task);
        $this->assertSame($steps, (int) $row->max_steps);
        $this->assertSame($stepsOrigin, $row->max_steps_origin);
        $this->assertSame(0, bccomp($spend, (string) $row->max_spend, 10));
        $this->assertSame($spendOrigin, $row->max_spend_origin);
        $this->assertSame(['steps' => $stepsReduced, 'spend' => $spendReduced], $this->ceilingChanges($task)[0]['payload']['reduced']);
    }

    public static function invalidBodies(): array
    {
        return [
            'empty' => [[]],
            'both null' => [['max_steps' => null, 'max_spend' => null]],
            'zero steps' => [['max_steps' => 0]],
            'negative steps' => [['max_steps' => -1]],
            'fractional steps' => [['max_steps' => 1.5]],
            'steps not a number' => [['max_steps' => 'abc']],
            'zero spend' => [['max_spend' => '0']],
            'zero spend with places' => [['max_spend' => '0.00']],
            'negative spend' => [['max_spend' => '-1']],
            'spend not a number' => [['max_spend' => 'abc']],
            'one good, one bad' => [['max_steps' => 30, 'max_spend' => '0']],
        ];
    }

    #[Test]
    #[DataProvider('invalidBodies')]
    public function an_invalid_change_is_refused_and_changes_nothing(array $body): void
    {
        [$conversation, $task] = $this->withTask();
        $before = (array) $this->row($task);
        $events = DB::table('task_budget_events')->count();

        $this->patchBudget($this->owner, $conversation->id, $body)->assertStatus(422);

        $this->assertSame($before, (array) $this->row($task));
        $this->assertSame($events, DB::table('task_budget_events')->count());
    }

    public static function terminalStates(): array
    {
        return [
            'concluded' => ['concluded'],
            'completed' => ['completed'],
            'halted' => ['halted'],
            'ended' => ['ended'],
        ];
    }

    #[Test]
    #[DataProvider('terminalStates')]
    public function a_finished_tasks_ceilings_cannot_be_changed(string $state): void
    {
        [$conversation, $task] = $this->withTask();
        DB::table('task_budgets')->where('id', $task->id)->update(['state' => $state, 'ended_at' => now()->format('Y-m-d H:i:s.u')]);
        $before = (array) $this->row($task);
        $events = DB::table('task_budget_events')->count();

        $response = $this->patchBudget($this->owner, $conversation->id, ['max_steps' => 50]);

        $response->assertStatus(409)->assertExactJson(['code' => 'no_active_task', 'message' => (new TaskBudgetText())->noTaskInProgress()]);
        $this->assertSame($before, (array) $this->row($task));
        $this->assertSame($events, DB::table('task_budget_events')->count());
    }

    #[Test]
    public function a_conversation_with_no_task_has_no_ceiling_to_change(): void
    {
        $conversation = $this->conversationOf($this->owner);

        $this->patchBudget($this->owner, $conversation->id, ['max_steps' => 50])
            ->assertStatus(409)
            ->assertExactJson(['code' => 'no_active_task', 'message' => (new TaskBudgetText())->noTaskInProgress()]);
        $this->assertSame(0, DB::table('task_budgets')->count(), 'no task is opened by a change');
    }

    #[Test]
    public function an_absent_and_a_foreign_conversation_get_the_identical_404_and_the_task_is_untouched(): void
    {
        [$conversation, $task] = $this->withTask();
        $before = (array) $this->row($task);
        $events = DB::table('task_budget_events')->count();

        $absent = $this->patchBudget($this->owner, (string) Str::uuid(), ['max_steps' => 50]);
        $foreign = $this->patchBudget($this->stranger, $conversation->id, ['max_steps' => 50]);

        $absent->assertStatus(404)->assertExactJson(['message' => 'Not found.']);
        $foreign->assertStatus(404);
        $this->assertSame($absent->getContent(), $foreign->getContent(), 'no oracle: the bodies are identical');
        $this->assertSame($absent->getStatusCode(), $foreign->getStatusCode());
        $this->assertSame($before, (array) $this->row($task), 'someone else cannot change the owner\'s task');
        $this->assertSame($events, DB::table('task_budget_events')->count());
    }

    #[Test]
    #[DataProvider('pausedStates')]
    public function a_paused_tasks_ceiling_can_be_changed_and_it_stays_paused(string $promptState, string $subState): void
    {
        Queue::fake();
        [$conversation, $task] = $this->withTask();
        $promptId = $this->prompt($conversation, $task, $promptState);
        $prompt = (array) DB::table('agent_questions')->where('id', $promptId)->first();

        $response = $this->patchBudget($this->owner, $conversation->id, ['max_steps' => 40])->assertStatus(200);

        $this->assertSame($this->changed($task, false, false), $response->json());
        $this->assertSame('paused', $response->json('state'));
        $this->assertSame($subState, $response->json('sub_state'));
        $row = $this->row($task);
        $this->assertSame(40, (int) $row->max_steps);
        $this->assertSame('paused', $row->state, 'only a decision on the prompt resumes the task');
        $this->assertSame($promptId, $row->prompt_question_id);
        $this->assertSame('steps', $row->pause_reason);
        $this->assertSame($prompt, (array) DB::table('agent_questions')->where('id', $promptId)->first(), 'the prompt is untouched');
        $this->assertFalse((bool) Conversation::findOrFail($conversation->id)->is_processing);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function raising_a_ceiling_well_above_the_consumption_lets_its_approach_notice_fire_again(): void
    {
        [$conversation, $task] = $this->withTask();
        $noticed = now()->subMinute()->format('Y-m-d H:i:s.u');
        DB::table('task_budgets')->where('id', $task->id)->update(['steps_used' => 17, 'steps_approach_noticed_at' => $noticed, 'spend_approach_noticed_at' => $noticed]);
        $this->usage($task, '0.8500000000');

        // 17 of 30 is below 80%: the steps notice may fire again. Spend was
        // not changed, so its latch stays.
        $this->patchBudget($this->owner, $conversation->id, ['max_steps' => 30])->assertStatus(200);
        $row = $this->row($task);
        $this->assertNull($row->steps_approach_noticed_at);
        $this->assertSame($noticed, (string) $row->spend_approach_noticed_at);

        // 0.85 of 2.00 is below 80%: the spend notice may fire again.
        $this->patchBudget($this->owner, $conversation->id, ['max_spend' => '2.00'])->assertStatus(200);
        $this->assertNull($this->row($task)->spend_approach_noticed_at);
    }

    #[Test]
    public function a_raise_that_leaves_the_consumption_near_the_ceiling_keeps_its_latch(): void
    {
        [$conversation, $task] = $this->withTask();
        $noticed = now()->subMinute()->format('Y-m-d H:i:s.u');
        DB::table('task_budgets')->where('id', $task->id)->update(['steps_used' => 17, 'steps_approach_noticed_at' => $noticed]);

        // 17 of 21 is still above 80%: the user has already been told.
        $this->patchBudget($this->owner, $conversation->id, ['max_steps' => 21])->assertStatus(200);

        $this->assertSame($noticed, (string) $this->row($task)->steps_approach_noticed_at);
    }

    #[Test]
    public function a_change_posts_nothing_in_the_conversation(): void
    {
        [$conversation, $task] = $this->withTask();
        DB::table('task_budgets')->where('id', $task->id)->update(['steps_used' => 10]);

        // Lowered to just above the consumption: no approach notice is
        // written by the change itself (the gate decides that at the next
        // boundary), and the panel that made the change shows the result.
        $this->patchBudget($this->owner, $conversation->id, ['max_steps' => 12])->assertStatus(200);

        $this->assertSame(0, DB::table('messages')->where('conversation_id', $conversation->id)->count());
        $this->assertNull($this->row($task)->steps_approach_noticed_at);
    }

    #[Test]
    public function a_ceiling_lowered_to_the_consumption_stops_the_next_step(): void
    {
        [$conversation, $task] = $this->withTask();
        DB::table('task_budgets')->where('id', $task->id)->update(['steps_used' => 12]);

        $this->patchBudget($this->owner, $conversation->id, ['max_steps' => 10])->assertStatus(200);

        $this->app->forgetScopedInstances();
        $decision = app(TaskBudgetGate::class)->beforeStep(Conversation::findOrFail($conversation->id), null);
        $this->assertTrue($decision->isStop(), 'the next boundary stops exactly as if the ceiling had been reached');
        $this->assertSame(TaskBudgetDecision::REASON_STEPS, $decision->reason);
        $this->assertSame(12, (int) $this->row($task)->steps_used, 'no further step was claimed');
    }
}
