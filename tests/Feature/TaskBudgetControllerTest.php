<?php

namespace Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The conversation's task budget, viewable on demand by its owner.
 *
 * GET conversation/{id}/task-budget answers the standing of the
 * conversation's current task — the one its pointer names, in whatever
 * state: running, paused waiting for a decision, paused and stopped, or
 * finished — and `{"task_budget": null}` when it has none. A conversation
 * that does not exist and one that belongs to someone else get the same
 * 404, status and body alike, so the endpoint never tells a caller which
 * conversations exist.
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
}
