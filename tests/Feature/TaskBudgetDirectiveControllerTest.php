<?php

namespace Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * Stating and changing this task's budget from the two message entries:
 * POST message (the web screen) and POST agent (the messaging channel).
 *
 * A request may carry ceilings in a `task_budget` body field, or in a
 * first-line `/budget steps=N spend=X` directive, which is stripped before
 * the request is stored; with both, the body field wins key by key and the
 * directive fills only the keys the body leaves out. A ceiling above the
 * installation maximum is cut to it, and the user is told — in the reply,
 * and on the conversation's channel, where a chat user sees it.
 *
 * A message that is only a directive is not a request: it changes the
 * current task's ceilings (running or paused) and is answered with the new
 * standing — nothing is stored as the user's turn, no run starts, and the
 * change is recorded in the conversation as a system notice. It is handled
 * ahead of everything that would otherwise claim the message: the busy
 * check (a running task's conversation is busy, and that is exactly when a
 * user wants to change its ceiling) and the ceiling prompt's answer intake
 * (a directive sent while the prompt waits is a change of ceiling, never an
 * attempted decision). A bare `/budget` shows the standing and changes
 * nothing. On the messaging entry, with no conversation named, the
 * directive goes to the user's most recently active conversation on that
 * channel whose task is running or paused.
 *
 * A directive that cannot be read is answered with the accepted form, and
 * nothing is stored, changed or run. Someone else's conversation is refused
 * exactly as it always was, and its task is untouched.
 */
class TaskBudgetDirectiveControllerTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    private TaskBudgetText $text;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
        $this->text = new TaskBudgetText();
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> POST message on the web, POST agent on Telegram */
    public static function controllers(): array
    {
        return ['message' => ['message'], 'agent' => ['agent']];
    }

    private static function pathOf(string $controller): string
    {
        return $controller === 'agent' ? 'sync' : 'stream';
    }

    private function begin(string $controller, string $scenario): Conversation
    {
        $this->scenario = 'task_budget_directive_'.$scenario.'_'.$controller;
        $this->entryPath = self::pathOf($controller);

        return $this->conversationFor(self::pathOf($controller));
    }

    /** POST $content to $controller's entry; $conversation null = POST agent with no conversation named. */
    private function postTo(string $controller, ?Conversation $conversation, string $content, ?array $taskBudget = null, ?User $as = null): TestResponse
    {
        $as ??= $this->fixture()->user;
        if ($controller === 'agent') {
            $body = ['message' => $content, 'channel' => 'telegram'];
            if ($conversation !== null) {
                $body['conversation_id'] = $conversation->id;
            }
        } else {
            $body = ['conversation_id' => $conversation->id, 'content' => $content];
        }
        if ($taskBudget !== null) {
            $body['task_budget'] = $taskBudget;
        }
        $path = $controller === 'agent' ? 'agent' : 'message';

        return $this->actingAs($as, 'api')->postJson("/api/clarion-app/llm-client/{$path}", $body);
    }

    /** Send a request expected to start a run, and let its first model call answer at once. */
    private function sendRequest(string $controller, Conversation $conversation, string $content, ?array $taskBudget = null): TestResponse
    {
        if ($controller === 'agent') {
            $this->script()->finalAnswer('Done.');

            return $this->postTo('agent', $conversation, $content, $taskBudget)->assertStatus(200)->assertJson(['status' => 'completed']);
        }

        return $this->postTo('message', $conversation, $content, $taskBudget)->assertStatus(201);
    }

    /** A running task on $conversation, its conversation busy with it. */
    private function runningTask(Conversation $conversation): TaskBudget
    {
        $task = app(TaskBudgetService::class)->forNewRun($conversation, null, false, null);
        $this->assertNotNull($task, 'precondition: the task opened');
        DB::table('conversations')->where('id', $conversation->id)->update(['is_processing' => true]);

        return $task;
    }

    /** Run a request on $path to a two-step ceiling: the ceiling prompt is then pending. */
    private function pausedAtTheCeiling(string $path, Conversation $conversation): AgentQuestion
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);
        if ($path === 'sync') {
            $this->scriptSteps(1, 2);
            $this->send('sync', $conversation, 'Store notes.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        } else {
            $this->send('stream', $conversation, 'Store notes.')->assertStatus(201);
            $this->playSteps(1, 2);
        }
        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt, 'precondition: the ceiling prompt is pending');
        $this->assertSame(QuestionState::Pending, $prompt->state);
        $this->assertSame('paused', $this->taskRowOf($conversation)->state);

        return $prompt;
    }

    private function standingOf(TaskBudget|string $task): \ClarionApp\LlmClient\ValueObjects\TaskBudgetStanding
    {
        $id = is_string($task) ? $task : $task->id;

        return app(TaskBudgetService::class)->standing(TaskBudget::findOrFail($id));
    }

    /** @return list<Message> */
    private function messagesOf(Conversation $conversation, ?string $role = null): array
    {
        $query = Message::where('conversation_id', $conversation->id)->orderBy('created_at');
        if ($role !== null) {
            $query->where('role', $role);
        }

        return $query->get()->all();
    }

    private function noticesOf(Conversation $conversation): array
    {
        return array_values(array_filter(
            $this->messagesOf($conversation),
            fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_NOTICE,
        ));
    }

    private function deliveredOn(string $controller, Conversation $conversation): array
    {
        return $controller === 'agent' ? $this->telegram->textsFor($conversation->id) : $this->web->textsFor($conversation->id);
    }

    private function events(string $taskId, string $kind): array
    {
        return DB::table('task_budget_events')->where('task_budget_id', $taskId)->where('kind', $kind)->orderBy('created_at')->get()->all();
    }

    /** Everything a directive must leave alone, to compare before and after. */
    private function snapshot(Conversation $conversation): array
    {
        return [
            'tasks' => DB::table('task_budgets')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'events' => DB::table('task_budget_events')->count(),
            'messages' => DB::table('messages')->count(),
            'runs' => DB::table('agent_runs')->count(),
            'conversations' => DB::table('conversations')->count(),
        ];
    }

    // ---------------------------------------------------------------
    // Ceilings stated with a request
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('controllers')]
    public function a_task_budget_body_field_opens_the_task_with_the_stated_ceiling(string $controller): void
    {
        $conversation = $this->begin($controller, 'body_field');

        $this->sendRequest($controller, $conversation, 'Store notes.', ['max_steps' => 5]);

        $task = $this->taskRowOf($conversation);
        $this->assertNotNull($task);
        $this->assertSame(5, (int) $task->max_steps);
        $this->assertSame('user', $task->max_steps_origin);
        $this->assertSame(0, bccomp('1.00', (string) $task->max_spend, 10), 'a ceiling not stated keeps its default');
        $this->assertSame('default', $task->max_spend_origin);
        $this->assertSame(1, $this->modelRequests(self::pathOf($controller)), 'the request runs as usual');
        $this->assertSame(['Store notes.'], array_map(fn (Message $m) => $m->content, $this->messagesOf($conversation, 'user')));
    }

    #[Test]
    #[DataProvider('controllers')]
    public function a_spend_ceiling_can_be_stated_too(string $controller): void
    {
        $conversation = $this->begin($controller, 'body_spend');

        $this->sendRequest($controller, $conversation, 'Store notes.', ['max_spend' => '2.50']);

        $task = $this->taskRowOf($conversation);
        $this->assertSame(0, bccomp('2.50', (string) $task->max_spend, 10));
        $this->assertSame('user', $task->max_spend_origin);
        $this->assertSame(20, (int) $task->max_steps);
        $this->assertSame('default', $task->max_steps_origin);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function controllersAndForms(): array
    {
        $cases = [];
        foreach (['message', 'agent'] as $controller) {
            foreach (['body', 'directive'] as $form) {
                $cases["{$controller}, {$form}"] = [$controller, $form];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('controllersAndForms')]
    public function a_ceiling_above_the_maximum_is_cut_to_it_and_the_user_is_told(string $controller, string $form): void
    {
        $conversation = $this->begin($controller, 'reduced_'.$form);

        $response = $form === 'body'
            ? $this->sendRequest($controller, $conversation, 'Store notes.', ['max_steps' => 1000, 'max_spend' => '100.00'])
            : $this->sendRequest($controller, $conversation, "/budget steps=1000 spend=100.00\nStore notes.");

        $task = $this->taskRowOf($conversation);
        $this->assertSame(200, (int) $task->max_steps);
        $this->assertSame('user_reduced_to_maximum', $task->max_steps_origin);
        $this->assertSame(0, bccomp('25.00', (string) $task->max_spend, 10));
        $this->assertSame('user_reduced_to_maximum', $task->max_spend_origin);

        $line = $this->text->reducedToMaximum(1000, 200, '100.00', '25.00', 'USD');
        $this->assertSame($line, $response->json('task_budget_notice'), 'the reply says the stated ceiling was reduced');

        $notices = $this->noticesOf($conversation);
        $this->assertCount(1, $notices, 'and so does the conversation');
        $this->assertSame('assistant', $notices[0]->role);
        $this->assertSame($line, $notices[0]->content);
        $this->assertSame(['kind' => 'task_budget_notice', 'task_budget_id' => $task->id, 'notice' => 'reduced_to_maximum'], $notices[0]->tool_data);
        $this->assertSame([$line], $this->deliveredOn($controller, $conversation), 'delivered once on the conversation\'s channel');
    }

    #[Test]
    #[DataProvider('controllers')]
    public function a_ceiling_within_the_maximum_tells_the_user_nothing_extra(string $controller): void
    {
        $conversation = $this->begin($controller, 'not_reduced');

        $response = $this->sendRequest($controller, $conversation, 'Store notes.', ['max_steps' => 200, 'max_spend' => '25.00']);

        $this->assertArrayNotHasKey('task_budget_notice', $response->json());
        $this->assertSame([], $this->noticesOf($conversation));
        $this->assertSame([], $this->deliveredOn($controller, $conversation));
        $this->assertSame('user', $this->taskRowOf($conversation)->max_steps_origin);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function invalidBodies(): array
    {
        $bodies = [
            'zero steps' => ['max_steps' => 0],
            'negative steps' => ['max_steps' => -2],
            'fractional steps' => ['max_steps' => 2.5],
            'steps not a number' => ['max_steps' => 'five'],
            'zero spend' => ['max_spend' => '0'],
            'negative spend' => ['max_spend' => '-1.00'],
            'spend not a number' => ['max_spend' => 'lots'],
            'not an object' => 'five',
        ];
        $cases = [];
        foreach (['message', 'agent'] as $controller) {
            foreach ($bodies as $name => $body) {
                $cases["{$controller}, {$name}"] = [$controller, $body];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('invalidBodies')]
    public function an_invalid_task_budget_field_is_refused_before_anything_is_stored(string $controller, mixed $taskBudget): void
    {
        $conversation = $this->begin($controller, 'invalid_body');
        $before = $this->snapshot($conversation);

        $response = $controller === 'agent'
            ? $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/agent', ['message' => 'Store notes.', 'channel' => 'telegram', 'conversation_id' => $conversation->id, 'task_budget' => $taskBudget])
            : $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/message', ['conversation_id' => $conversation->id, 'content' => 'Store notes.', 'task_budget' => $taskBudget]);

        $response->assertStatus(422);
        $this->assertSame($before, $this->snapshot($conversation));
        $this->assertSame(0, $this->modelRequests(self::pathOf($controller)));
    }

    #[Test]
    #[DataProvider('controllers')]
    public function a_first_line_directive_states_the_ceiling_and_is_not_stored_with_the_request(string $controller): void
    {
        $conversation = $this->begin($controller, 'directive_request');

        $this->sendRequest($controller, $conversation, "/budget steps=5\nStore notes.");

        $task = $this->taskRowOf($conversation);
        $this->assertSame(5, (int) $task->max_steps);
        $this->assertSame('user', $task->max_steps_origin);
        $this->assertSame(['Store notes.'], array_map(fn (Message $m) => $m->content, $this->messagesOf($conversation, 'user')), 'the stored request has no directive line');
        $this->assertSame(1, $this->modelRequests(self::pathOf($controller)));
    }

    #[Test]
    #[DataProvider('controllers')]
    public function with_a_body_field_and_a_directive_the_body_wins_key_by_key(string $controller): void
    {
        $conversation = $this->begin($controller, 'precedence');

        $this->sendRequest($controller, $conversation, "/budget steps=5 spend=2.00\nStore notes.", ['max_steps' => 7]);

        $task = $this->taskRowOf($conversation);
        $this->assertSame(7, (int) $task->max_steps, 'the body field\'s steps');
        $this->assertSame('user', $task->max_steps_origin);
        $this->assertSame(0, bccomp('2.00', (string) $task->max_spend, 10), 'the directive fills the key the body left out');
        $this->assertSame('user', $task->max_spend_origin);
        $this->assertSame(['Store notes.'], array_map(fn (Message $m) => $m->content, $this->messagesOf($conversation, 'user')));
    }

    // ---------------------------------------------------------------
    // A directive on its own changes the current task
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string, 1: ?array}> */
    public static function statedWhileBusy(): array
    {
        return [
            'a body field' => ["Store the notes.", ['max_steps' => 5]],
            'a directive line' => ["/budget steps=5\nStore the notes.", null],
        ];
    }

    #[Test]
    #[DataProvider('statedWhileBusy')]
    public function a_request_stating_its_ceilings_while_the_conversation_is_busy_is_refused_never_queued_without_them(string $content, ?array $taskBudget): void
    {
        $conversation = $this->begin('message', 'stated_while_busy');
        $running = $this->runningTask($conversation);

        $response = $this->postTo('message', $conversation, $content, $taskBudget);

        // A queued message is picked up later as a new request with the
        // defaults: the ceiling the user stated would be silently lost.
        $response->assertStatus(409);
        $this->assertSame(['code' => 'processing', 'message' => $this->text->limitsWhileProcessing()], $response->json());
        $this->assertSame([], $this->messagesOf($conversation, 'user'), 'nothing is stored to be picked up later');
        $this->assertSame('running', DB::table('task_budgets')->where('id', $running->id)->value('state'), 'the running task is untouched');
        $this->assertSame(1, TaskBudget::count());
        $this->assertSame(0, $this->modelRequests('stream'));
    }

    #[Test]
    public function a_request_stating_nothing_while_the_conversation_is_busy_is_still_queued_as_before(): void
    {
        $conversation = $this->begin('message', 'nothing_stated_while_busy');
        $this->runningTask($conversation);

        $this->postTo('message', $conversation, 'Store the notes.')->assertStatus(201);

        $this->assertCount(1, $this->messagesOf($conversation, 'user'), 'stored, to be picked up when the reply finishes');
        $this->assertSame(0, $this->modelRequests('stream'));
    }

    #[Test]
    #[DataProvider('controllers')]
    public function a_directive_alone_changes_the_running_tasks_ceiling_even_while_the_conversation_is_busy(string $controller): void
    {
        $conversation = $this->begin($controller, 'directive_only_running');
        $task = $this->runningTask($conversation);

        $response = $this->postTo($controller, $conversation, '/budget steps=40');

        $standing = $this->standingOf($task);
        $content = $this->text->budgetUpdated($standing);
        $response->assertStatus(200);
        if ($controller === 'agent') {
            $response->assertExactJson(['status' => 'budget_updated', 'content' => $content, 'conversation_id' => $conversation->id]);
        } else {
            $this->assertSame($standing->toArray() + ['reduced' => ['steps' => false, 'spend' => false]], $response->json());
        }

        $row = DB::table('task_budgets')->where('id', $task->id)->first();
        $this->assertSame(40, (int) $row->max_steps);
        $this->assertSame('user', $row->max_steps_origin);
        $this->assertSame('running', $row->state);

        // Not a request: nothing stored as the user's turn, nothing run.
        $this->assertSame([], $this->messagesOf($conversation, 'user'));
        $this->assertSame(0, DB::table('agent_runs')->count());
        $this->assertSame(0, $this->modelRequests(self::pathOf($controller)));
        $this->assertTrue((bool) Conversation::findOrFail($conversation->id)->is_processing, 'the task\'s own work is left as it was');

        // Recorded: a system notice in the conversation, and the change itself.
        $notices = $this->noticesOf($conversation);
        $this->assertCount(1, $notices);
        $this->assertSame('assistant', $notices[0]->role);
        $this->assertSame($content, $notices[0]->content);
        $this->assertSame(['kind' => 'task_budget_notice', 'task_budget_id' => $task->id, 'notice' => 'ceiling_changed'], $notices[0]->tool_data);
        $this->assertSame([], $this->deliveredOn($controller, $conversation), 'the reply carries it; nothing is delivered twice');
        $changes = $this->events($task->id, 'ceiling_changed');
        $this->assertCount(1, $changes);
        $this->assertSame((string) $this->fixture()->user->id, $changes[0]->actor_user_id);
        $this->assertSame(['max_steps' => 40, 'max_steps_origin' => 'user', 'max_spend' => '1.0000000000', 'max_spend_origin' => 'default'], json_decode($changes[0]->payload, true)['after']);
    }

    #[Test]
    #[DataProvider('controllers')]
    public function a_directive_above_the_maximum_changes_the_ceiling_to_the_maximum_and_says_so(string $controller): void
    {
        $conversation = $this->begin($controller, 'directive_only_reduced');
        $task = $this->runningTask($conversation);

        $response = $this->postTo($controller, $conversation, '/budget steps=1000')->assertStatus(200);

        $standing = $this->standingOf($task);
        $content = $this->text->budgetUpdated($standing, $this->text->reducedToMaximum(1000, 200, null, null, 'USD'));
        if ($controller === 'agent') {
            $this->assertSame($content, $response->json('content'));
        } else {
            $this->assertSame(['steps' => true, 'spend' => false], $response->json('reduced'));
        }
        $this->assertSame(200, $standing->maxSteps);
        $this->assertSame('user_reduced_to_maximum', $standing->stepsOrigin->value);
        $this->assertSame($content, $this->noticesOf($conversation)[0]->content);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function promptEntries(): array
    {
        return [
            'message' => ['message', false],
            'agent, conversation named' => ['agent', false],
            'agent, no conversation named' => ['agent', true],
        ];
    }

    #[Test]
    #[DataProvider('promptEntries')]
    public function a_directive_sent_while_the_ceiling_prompt_waits_changes_the_ceiling_and_is_never_taken_as_a_decision(string $controller, bool $implicit): void
    {
        $conversation = $this->begin($controller, 'directive_while_prompt'.($implicit ? '_implicit' : ''));
        $prompt = $this->pausedAtTheCeiling(self::pathOf($controller), $conversation);
        $task = $this->taskOf($conversation);
        $requests = $this->modelRequests(self::pathOf($controller));
        $userMessages = count($this->messagesOf($conversation, 'user'));

        $response = $this->postTo($controller, $implicit ? null : $conversation, '/budget steps=40');

        $response->assertStatus(200);
        $this->assertNotSame('decision_required', $response->json('status'));
        $this->assertNotSame('decision_required', $response->json('code'));
        $standing = $this->standingOf($task);
        if ($controller === 'agent') {
            $response->assertExactJson(['status' => 'budget_updated', 'content' => $this->text->budgetUpdated($standing), 'conversation_id' => $conversation->id]);
        } else {
            $this->assertSame($standing->toArray() + ['reduced' => ['steps' => false, 'spend' => false]], $response->json());
        }

        $row = DB::table('task_budgets')->where('id', $task->id)->first();
        $this->assertSame(40, (int) $row->max_steps, 'the ceiling changed');
        $this->assertSame('paused', $row->state, 'and the task stays paused: only a decision resumes it');
        $this->assertSame($prompt->id, $row->prompt_question_id);

        $prompt->refresh();
        $this->assertSame(QuestionState::Pending, $prompt->state, 'the prompt still waits for its decision');
        $this->assertNull($prompt->answer_raw);
        $this->assertSame($prompt->id, AgentQuestion::where('pending_conversation_id', $conversation->id)->value('id'));
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
        $this->assertSame($requests, $this->modelRequests(self::pathOf($controller)), 'nothing ran');
        $this->assertCount($userMessages, $this->messagesOf($conversation, 'user'), 'the directive is not stored as a reply');
    }

    #[Test]
    #[DataProvider('controllers')]
    public function a_bare_budget_shows_the_standing_and_changes_nothing(string $controller): void
    {
        $conversation = $this->begin($controller, 'bare');
        $task = $this->runningTask($conversation);
        $before = $this->snapshot($conversation);

        $response = $this->postTo($controller, $conversation, '/budget')->assertStatus(200);

        $standing = $this->standingOf($task);
        if ($controller === 'agent') {
            $response->assertExactJson(['status' => 'budget_updated', 'content' => $this->text->standing($standing), 'conversation_id' => $conversation->id]);
        } else {
            $this->assertSame($standing->toArray(), $response->json());
        }
        $this->assertSame($before, $this->snapshot($conversation), 'nothing stored, changed or run');
        $this->assertSame([], $this->deliveredOn($controller, $conversation));
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function noTaskInProgress(): array
    {
        $cases = [];
        foreach (['message', 'agent'] as $controller) {
            foreach (['no task' => null, 'completed' => 'completed', 'concluded' => 'concluded', 'halted' => 'halted', 'ended' => 'ended'] as $name => $state) {
                $cases["{$controller}, {$name}"] = [$controller, $state];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('noTaskInProgress')]
    public function with_no_task_running_or_paused_a_directive_changes_nothing_and_says_so(string $controller, ?string $state): void
    {
        $conversation = $this->begin($controller, 'no_task_'.($state ?? 'none'));
        if ($state !== null) {
            $task = $this->runningTask($conversation);
            DB::table('task_budgets')->where('id', $task->id)->update(['state' => $state, 'ended_at' => now()->format('Y-m-d H:i:s.u')]);
            DB::table('conversations')->where('id', $conversation->id)->update(['is_processing' => false]);
        }

        foreach (['/budget steps=40', '/budget'] as $directive) {
            $before = $this->snapshot($conversation);

            $response = $this->postTo($controller, $conversation, $directive);

            if ($controller === 'agent') {
                $response->assertStatus(200)->assertJson(['status' => 'budget_updated', 'content' => $this->text->noTaskInProgress()]);
            } else {
                $response->assertStatus(409)->assertExactJson(['code' => 'no_active_task', 'message' => $this->text->noTaskInProgress()]);
            }
            $this->assertSame($before, $this->snapshot($conversation), "'{$directive}': nothing stored, opened, changed or run");
        }
        $this->assertSame(0, $this->modelRequests(self::pathOf($controller)));
    }

    // ---------------------------------------------------------------
    // The messaging entry with no conversation named
    // ---------------------------------------------------------------

    #[Test]
    public function with_no_conversation_named_the_directive_goes_to_the_most_recent_conversation_on_the_channel_with_a_task_in_progress(): void
    {
        $this->scenario = 'task_budget_directive_implicit_target';
        $this->entryPath = 'sync';
        $target = $this->conversationFor('sync');
        $user = $this->fixture()->user;
        $make = fn (string $channel, ?string $userId = null) => Conversation::create([
            'user_id' => $userId ?? $user->id,
            'server_id' => $this->fixture()->server->id,
            'model' => 'gpt-4',
            'title' => 'Other',
            'character' => 'Clarion',
            'channel' => $channel,
        ]);

        $targetTask = $this->runningTask($target);
        // More recently active, but not eligible: a finished task, no task,
        // another channel, another user.
        $finished = $make('telegram');
        $finishedTask = $this->runningTask($finished);
        DB::table('task_budgets')->where('id', $finishedTask->id)->update(['state' => 'completed']);
        $noTask = $make('telegram');
        $web = $make('web');
        $webTask = $this->runningTask($web);
        $stranger = User::create(['id' => (string) Str::uuid(), 'name' => 'Someone Else', 'email' => 'someone-else@example.com', 'password' => 'x']);
        $theirs = $make('telegram', $stranger->id);
        $theirTask = $this->runningTask($theirs);
        $minute = 0;
        foreach ([$target, $finished, $noTask, $web, $theirs] as $c) {
            DB::table('conversations')->where('id', $c->id)->update(['updated_at' => now()->addMinutes(++$minute)]);
        }

        $response = $this->postTo('agent', null, '/budget steps=40');

        $response->assertStatus(200)->assertJson(['status' => 'budget_updated', 'conversation_id' => $target->id]);
        $this->assertSame(40, (int) DB::table('task_budgets')->where('id', $targetTask->id)->value('max_steps'), 'the busy conversation\'s running task changed');
        foreach ([$finishedTask, $webTask, $theirTask] as $other) {
            $this->assertSame(20, (int) DB::table('task_budgets')->where('id', $other->id)->value('max_steps'));
        }
        $this->assertSame(0, DB::table('agent_runs')->count());
        $this->assertSame(0, DB::table('messages')->where('role', 'user')->count());
    }

    #[Test]
    public function with_no_conversation_named_and_no_task_in_progress_nothing_is_created(): void
    {
        $this->scenario = 'task_budget_directive_implicit_none';
        $this->entryPath = 'sync';
        $conversation = $this->conversationFor('sync');
        $before = $this->snapshot($conversation);

        foreach (['/budget steps=40', '/budget'] as $directive) {
            $this->postTo('agent', null, $directive)
                ->assertStatus(200)
                ->assertJson(['status' => 'budget_updated', 'content' => $this->text->noTaskInProgress()]);
        }

        $this->assertSame($before, $this->snapshot($conversation), 'no conversation, task, message or run is created');
        $this->assertSame(0, $this->modelRequests('sync'));
    }

    // ---------------------------------------------------------------
    // A directive that cannot be read; someone else's conversation
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformedDirectives(): array
    {
        $cases = [];
        foreach (['message', 'agent'] as $controller) {
            foreach (['alone' => '/budget steps=0', 'before a request' => "/budget steps=5 steps=6\nStore notes.", 'unknown key' => '/budget tokens=100'] as $name => $content) {
                $cases["{$controller}, {$name}"] = [$controller, $content];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('malformedDirectives')]
    public function a_directive_that_cannot_be_read_is_answered_with_the_accepted_form_and_nothing_runs(string $controller, string $content): void
    {
        $conversation = $this->begin($controller, 'malformed');
        $this->runningTask($conversation);
        DB::table('conversations')->where('id', $conversation->id)->update(['is_processing' => false]);
        $before = $this->snapshot($conversation);

        $response = $this->postTo($controller, $conversation, $content);

        if ($controller === 'agent') {
            $response->assertStatus(200)->assertExactJson(['status' => 'directive_malformed', 'content' => $this->text->directiveMalformed(), 'conversation_id' => $conversation->id]);
        } else {
            $response->assertStatus(422)->assertExactJson(['code' => 'directive_malformed', 'message' => $this->text->directiveMalformed()]);
        }
        $this->assertSame($before, $this->snapshot($conversation), 'nothing stored, changed or run');
        $this->assertSame(0, $this->modelRequests(self::pathOf($controller)));
    }

    #[Test]
    #[DataProvider('controllers')]
    public function someone_elses_conversation_is_refused_as_before_and_its_task_is_untouched(string $controller): void
    {
        $conversation = $this->begin($controller, 'foreign');
        $task = $this->runningTask($conversation);
        $stranger = User::create(['id' => (string) Str::uuid(), 'name' => 'Someone Else', 'email' => 'someone-else@example.com', 'password' => 'x']);
        $before = $this->snapshot($conversation);

        foreach (['/budget steps=40', '/budget', "/budget steps=40\nStore notes."] as $directive) {
            $this->postTo($controller, $conversation, $directive, null, $stranger)->assertStatus(403);
        }
        $this->postTo($controller, $conversation, 'Store notes.', ['max_steps' => 40], $stranger)->assertStatus(403);

        $this->assertSame($before, $this->snapshot($conversation));
        $this->assertSame(20, (int) DB::table('task_budgets')->where('id', $task->id)->value('max_steps'));
        $this->assertSame(0, $this->modelRequests(self::pathOf($controller)));
    }
}
