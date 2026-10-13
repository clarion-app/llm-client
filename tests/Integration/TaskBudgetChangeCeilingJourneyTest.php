<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * The user sets this task's budget with the request, and changes it while
 * the task runs — and the task obeys the ceiling in force at each step
 * boundary, not the one it started with. Both execution paths.
 *
 *  - A request stating five steps pauses after its fifth step.
 *  - A task raised from twenty to thirty steps part-way through runs past
 *    twenty and pauses at thirty; having been told at sixteen of twenty
 *    that it was nearing its ceiling, the user is told again at
 *    twenty-four of thirty.
 *  - A task lowered to ten steps while its twelfth is under way finishes
 *    that step — every tool call it asked for — and then pauses at the
 *    next boundary, with the report saying the ceiling was lowered below
 *    what had already been used, and the usual prompt.
 *  - A `/budget` line sent in the chat while the task runs raises it the
 *    same way, and is not taken into the task's conversation as a request.
 *
 * Each change is made while a step is in progress — while that step's
 * state-changing call is being executed — exactly when a user watching a
 * long task would make it.
 */
class TaskBudgetChangeCeilingJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    /** @var array<string, \Closure> note text => what happens while that note is being stored */
    private array $noteHooks = [];

    /** @var list<array{text: string, notes: int}> every plain delivery, with the notes stored by then */
    private array $delivered = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();

        (new OperationCatalogue())->fakeHostApi([
            '/notes' => function ($request) {
                $this->noteWrites[] = $request->data();
                $text = (string) ($request->data()['text'] ?? '');
                if (isset($this->noteHooks[$text])) {
                    $hook = $this->noteHooks[$text];
                    unset($this->noteHooks[$text]);
                    $hook();
                }

                return Http::response(['stored' => true], 201, ['Content-Type' => 'application/json']);
            },
        ]);

        foreach ([$this->web, $this->telegram] as $channel) {
            $channel->onDeliver = function ($to, string $text) {
                $this->delivered[] = ['text' => $text, 'notes' => count($this->noteWrites)];
            };
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** Send the request on $path and work its first $steps steps. */
    private function runRequest(string $path, Conversation $conversation, string $content, int $steps, ?array $taskBudget = null): void
    {
        if ($path === 'sync') {
            $this->scriptSteps(1, $steps);
            $body = ['message' => $content, 'channel' => 'telegram', 'conversation_id' => $conversation->id];
            if ($taskBudget !== null) {
                $body['task_budget'] = $taskBudget;
            }
            $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/agent', $body)
                ->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

            return;
        }

        $body = ['conversation_id' => $conversation->id, 'content' => $content];
        if ($taskBudget !== null) {
            $body['task_budget'] = $taskBudget;
        }
        $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/message', $body)->assertStatus(201);
        $this->playSteps(1, $steps);
    }

    private function patchBudget(Conversation $conversation, array $body): TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')
            ->patchJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/task-budget", $body);
    }

    /** @return list<Message> the conversation's approach notices, oldest first */
    private function approachNotices(Conversation $conversation): array
    {
        return Message::where('conversation_id', $conversation->id)->orderBy('created_at')->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_NOTICE
                && ($m->tool_data['notice'] ?? null) === 'approach')
            ->values()->all();
    }

    private function assertPausedAt(string $path, Conversation $conversation, int $ceiling, int $used): object
    {
        $task = $this->taskRowOf($conversation);
        $this->assertSame($used, $this->modelRequests($path), "exactly {$used} model requests");
        $this->assertSame($used, (int) $task->steps_used);
        $this->assertSame($ceiling, (int) $task->max_steps);
        $this->assertCount($used, $this->noteWrites, 'every step\'s state-changing call ran exactly once');
        $this->assertSame('paused', $task->state);
        $this->assertSame('steps', $task->pause_reason);

        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt, 'the user is asked what to do');
        $this->assertSame(QuestionState::Pending, $prompt->state);
        $this->assertSame($prompt->id, $task->prompt_question_id);
        $this->assertPromptDeliveredOnce($path, $conversation, $prompt);

        // The last step completed whole before the pause.
        $turns = $this->toolTurnsOf($conversation);
        $this->assertCount($used, $turns);
        $this->assertSame(["call_note_{$used}", "call_apps_{$used}"], array_column($turns[$used - 1]->tool_data['tool_results'], 'tool_call_id'));

        return $task;
    }

    #[Test]
    #[DataProvider('paths')]
    public function a_request_stating_five_steps_pauses_after_its_fifth(string $path): void
    {
        $this->scenario = 'task_budget_change_stated_'.$path;
        $this->entryPath = $path;
        $conversation = $this->conversationFor($path);

        // The chat states it in the message; the web screen in its limits field.
        $path === 'sync'
            ? $this->runRequest('sync', $conversation, "/budget steps=5\nKeep storing notes.", 5)
            : $this->runRequest('stream', $conversation, 'Keep storing notes.', 5, ['max_steps' => 5]);

        $task = $this->assertPausedAt($path, $conversation, 5, 5);
        $this->assertSame('user', $task->max_steps_origin);
        $this->assertStringStartsWith("This task's budget ceiling was reached: steps (5 of 5).", (string) $this->promptOf($conversation)->preamble);
        $this->assertSame(['Keep storing notes.'], Message::where('conversation_id', $conversation->id)->where('role', 'user')->pluck('content')->all());
    }

    #[Test]
    #[DataProvider('paths')]
    public function a_ceiling_raised_mid_task_lets_it_run_past_the_old_one_to_the_new_one(string $path): void
    {
        $this->scenario = 'task_budget_change_raised_'.$path;
        $this->entryPath = $path;
        $conversation = $this->conversationFor($path);
        $this->assertSame(20, config('llm-client.agent_loop.max_iterations'), 'precondition: the shipped default');

        $raised = null;
        $this->noteHooks['note 18'] = function () use ($conversation, &$raised) {
            $raised = $this->patchBudget($conversation, ['max_steps' => 30]);
        };

        $this->runRequest($path, $conversation, 'Keep storing notes until I say stop.', 30);

        $raised->assertStatus(200);
        $this->assertSame(30, $raised->json('steps.ceiling'));
        $this->assertSame('running', $raised->json('state'));

        $task = $this->assertPausedAt($path, $conversation, 30, 30);
        $this->assertSame('user', $task->max_steps_origin);
        $this->assertStringStartsWith("This task's budget ceiling was reached: steps (30 of 30).", (string) $this->promptOf($conversation)->preamble);
        $this->assertSame(0, DB::table('agent_runs')->where('end_reason', 'Maximum iterations reached')->count(), 'the old per-request bound never fired');

        $this->assertNoticedAt16Of20And24Of30($conversation);
    }

    #[Test]
    #[DataProvider('paths')]
    public function a_ceiling_lowered_below_the_consumption_mid_step_pauses_at_the_next_boundary(string $path): void
    {
        $this->scenario = 'task_budget_change_lowered_'.$path;
        $this->entryPath = $path;
        $conversation = $this->conversationFor($path);

        $lowered = null;
        $this->noteHooks['note 12'] = function () use ($conversation, &$lowered) {
            $lowered = $this->patchBudget($conversation, ['max_steps' => 10]);
        };

        $this->runRequest($path, $conversation, 'Keep storing notes until I say stop.', 12);

        $lowered->assertStatus(200);
        $this->assertSame(10, $lowered->json('steps.ceiling'));

        // The step in progress — the twelfth — completed, then nothing more started.
        $this->assertPausedAt($path, $conversation, 10, 12);
        $prompt = $this->promptOf($conversation);
        $this->assertStringStartsWith("This task's budget ceiling was reached: the ceiling was lowered to 10 while 12 steps were already used.", (string) $prompt->preamble);
        $this->assertCount(3, $prompt->choices, 'the usual prompt');
    }

    #[Test]
    public function a_budget_line_sent_in_the_chat_mid_task_raises_it_the_same_way(): void
    {
        $this->scenario = 'task_budget_change_directive_mid_run';
        $this->entryPath = 'sync';
        $conversation = $this->conversationFor('sync');

        $reply = null;
        $this->noteHooks['note 18'] = function () use (&$reply) {
            // No conversation named: the chat's conversation is busy with
            // the task, and is the one whose task is running.
            $reply = $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
                'message' => '/budget steps=30',
                'channel' => 'telegram',
            ]);
        };

        $this->runRequest('sync', $conversation, 'Keep storing notes until I say stop.', 30);

        $reply->assertStatus(200)->assertJson(['status' => 'budget_updated', 'conversation_id' => $conversation->id]);
        $this->assertStringStartsWith("This task's budget was updated: ", $reply->json('content'));
        $this->assertStringContainsString('of 30 steps', $reply->json('content'));

        $task = $this->assertPausedAt('sync', $conversation, 30, 30);
        $this->assertSame('user', $task->max_steps_origin);
        $this->assertNoticedAt16Of20And24Of30($conversation);

        // The line changed the budget; it never became part of the task.
        $this->assertSame(['Keep storing notes until I say stop.'], Message::where('conversation_id', $conversation->id)->where('role', 'user')->pluck('content')->all());
        foreach ($this->transport()->capturedChatPayloads() as $payload) {
            $this->assertStringNotContainsString('/budget', json_encode($payload->messages), 'no model request saw the directive');
            $this->assertStringNotContainsString("This task's budget was updated", json_encode($payload->messages), 'nor the notice recording it');
        }
    }

    /** One approach notice against twenty, then — after the raise — one against thirty. */
    private function assertNoticedAt16Of20And24Of30(Conversation $conversation): void
    {
        $notices = $this->approachNotices($conversation);
        $this->assertCount(2, $notices, 'told once per ceiling: at 16 of 20, and again against the raised ceiling');
        $this->assertStringContainsString("this task's budget is 80% used: 16 of 20 steps", $notices[0]->content);
        $this->assertStringContainsString("this task's budget is 80% used: 24 of 30 steps", $notices[1]->content);

        $second = array_values(array_filter($this->delivered, fn (array $d) => $d['text'] === $notices[1]->content));
        $this->assertSame([['text' => $notices[1]->content, 'notes' => 24]], $second, 'delivered once, after the 24th step and before the 25th');

        $task = $this->taskRowOf($conversation);
        $changes = DB::table('task_budget_events')->where('task_budget_id', $task->id)->where('kind', 'ceiling_changed')->get();
        $this->assertCount(1, $changes);
        $this->assertSame((string) $this->fixture()->user->id, $changes[0]->actor_user_id);
    }
}
