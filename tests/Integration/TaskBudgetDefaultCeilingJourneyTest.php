<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * A task nobody gave a budget still has one, and it holds.
 *
 * The user states nothing. The model is scripted to keep asking for another
 * state-changing tool call on every response, forever. Without a budget the
 * task would run on; with the default one it gets exactly twenty steps —
 * the installation's long-standing per-request bound, now a budget on the
 * whole task — and then pauses at a step boundary: the twentieth step's
 * model call and every tool call it asked for complete and are stored, no
 * twenty-first model request is made, and the user is asked what to do,
 * on the conversation's own channel, with the report of what was done above
 * the question. Nothing earlier is lost, and the run is still open, waiting
 * for the decision. Both execution paths.
 *
 * Before that, the user is told once that the task is nearing its ceiling:
 * when its sixteenth step has completed (80% of twenty), the system — not
 * the agent — posts one approach notice in the conversation and delivers it
 * on the conversation's channel, ahead of the ceiling prompt.
 */
class TaskBudgetDefaultCeilingJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('paths')]
    public function with_nothing_stated_the_task_pauses_after_its_twentieth_step(string $path): void
    {
        $this->scenario = 'task_budget_default_ceiling_'.$path;
        $this->entryPath = $path;
        $this->assertSame(20, config('llm-client.agent_loop.max_iterations'), 'precondition: the shipped default');

        $conversation = $this->conversationFor($path);

        if ($path === 'sync') {
            // Exactly twenty responses are scripted: a twenty-first request
            // would find the script exhausted.
            $this->scriptSteps(1, 20);
            $response = $this->send('sync', $conversation, 'Keep storing notes until I say stop.');
            $response->assertStatus(202)->assertJson(['status' => 'awaiting_user', 'conversation_id' => $conversation->id]);
        } else {
            $this->send('stream', $conversation, 'Keep storing notes until I say stop.')->assertStatus(201);
            $this->playSteps(1, 20);
        }

        // The task opened with the defaults.
        $task = $this->taskRowOf($conversation);
        $this->assertNotNull($task, 'a task opened for the request although nothing was stated');
        $this->assertSame(20, (int) $task->max_steps);
        $this->assertSame(0, bccomp('1.00', (string) $task->max_spend, 10));
        $this->assertSame('default', $task->max_steps_origin);
        $this->assertSame('default', $task->max_spend_origin);
        $this->assertSame('request', $task->scope);

        // Twenty steps, not one more.
        $this->assertSame(20, $this->modelRequests($path), 'no twenty-first model request');
        $this->assertSame(20, (int) $task->steps_used);

        // The twentieth step completed whole: every tool call it asked for ran, in order, and is stored.
        $this->assertCount(20, $this->noteWrites, 'every step\'s state-changing call ran exactly once');
        $this->assertSame(['text' => 'note 20'], $this->noteWrites[19]);
        $turns = $this->toolTurnsOf($conversation);
        $this->assertCount(20, $turns, 'every step is stored');
        foreach ($turns as $index => $turn) {
            $n = $index + 1;
            $this->assertSame(["call_note_{$n}", "call_apps_{$n}"], array_column($turn->tool_data['tool_calls'], 'id'));
            $this->assertSame(["call_note_{$n}", "call_apps_{$n}"], array_column($turn->tool_data['tool_results'], 'tool_call_id'), "step {$n}'s results are stored in the order they were called");
        }

        // It paused at the ceiling and the user is asked.
        $this->assertSame('paused', $task->state);
        $this->assertSame('steps', $task->pause_reason);
        $this->assertSame(21, (int) $task->paused_iteration);
        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt, 'the user is asked what to do');
        $this->assertSame(QuestionState::Pending, $prompt->state);
        $this->assertSame($path, $prompt->resume_mode);
        $this->assertSame($prompt->id, $task->prompt_question_id);
        $this->assertSame($conversation->id, $prompt->pending_conversation_id);
        $this->assertPromptDeliveredOnce($path, $conversation, $prompt);
        $this->assertStringStartsWith("This task's budget ceiling was reached: steps (20 of 20).", (string) $prompt->preamble);

        // Nothing earlier is lost.
        $user = Message::where('conversation_id', $conversation->id)->where('role', 'user')->get();
        $this->assertSame(['Keep storing notes until I say stop.'], $user->pluck('content')->all());

        // The run waits for the decision; the conversation is free.
        $runId = $this->latestRunIdOf($conversation);
        $this->assertSame($runId, $prompt->run_id);
        $run = $this->actingAs($this->fixture()->user, 'api')->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}")->assertStatus(200)->json();
        $this->assertSame('in_progress', $run['end_state']);
        // The pause sits on the step boundary: the step is left open and
        // marked paused (the run is resumable until its resumer claims it),
        // never closed.
        $this->assertSame(1, DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', 'in_progress')->count(), 'the open step is left open');
        $this->assertSame('paused', DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', 'in_progress')->value('checkpoint_state'), 'the step is marked paused');
        $this->assertFalse((bool) $conversation->fresh()->is_processing);

        // The old per-request bound never fired.
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->where('content', 'like', '%aximum%iterations%')->count());
        $this->assertSame(0, DB::table('agent_runs')->where('end_reason', 'Maximum iterations reached')->count());
        $this->assertSame(0, DB::table('agent_run_steps')->where('end_reason', 'Maximum iterations reached')->count());
    }

    #[Test]
    #[DataProvider('paths')]
    public function the_user_is_told_once_as_the_task_nears_its_ceiling_before_it_pauses(string $path): void
    {
        $this->scenario = 'task_budget_default_ceiling_approach_'.$path;
        $this->entryPath = $path;
        $conversation = $this->conversationFor($path);

        // What had happened by the time each plain text was delivered.
        $deliveredAfter = [];
        $channel = $path === 'sync' ? $this->telegram : $this->web;
        $channel->onDeliver = function ($to, string $text) use (&$deliveredAfter) {
            $deliveredAfter[] = ['text' => $text, 'notes' => count($this->noteWrites)];
        };

        if ($path === 'sync') {
            $this->scriptSteps(1, 20);
            $this->send('sync', $conversation, 'Keep storing notes until I say stop.')->assertStatus(202);
        } else {
            $this->send('stream', $conversation, 'Keep storing notes until I say stop.')->assertStatus(201);
            $this->playSteps(1, 20);
        }

        // The journey's model has no configured price, so the spend figure
        // says it is incomplete — the same figures as the standing.
        $expected = "Heads up — this task's budget is 80% used: 16 of 20 steps, 0.00 of 1.00 USD (incomplete: 16 requests had no price). It will pause for your decision at the ceiling. "
            ."You can raise it now with '/budget steps=40' or from the conversation's limits.";

        // Exactly one notice, written by the system into the conversation.
        $notices = Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_NOTICE)
            ->values();
        $this->assertCount(1, $notices, 'one approach notice for the whole task');
        $this->assertSame('assistant', $notices[0]->role);
        $this->assertSame($expected, $notices[0]->content);
        $task = $this->taskRowOf($conversation);
        $this->assertSame(['kind' => 'task_budget_notice', 'task_budget_id' => $task->id, 'notice' => 'approach'], $notices[0]->tool_data);
        $this->assertNotNull($task->steps_approach_noticed_at);
        $this->assertNull($task->spend_approach_noticed_at, 'nothing was spent');

        // Delivered once on the conversation's channel, once the sixteenth
        // step's state-changing call had run and before the seventeenth's.
        $notice = array_values(array_filter($deliveredAfter, fn (array $d) => $d['text'] === $expected));
        $this->assertSame([['text' => $expected, 'notes' => 16]], $notice, 'delivered once, at the seventeenth step\'s boundary');

        // Ahead of the ceiling prompt.
        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt, 'the task still pauses at its ceiling');
        $display = Message::findOrFail($prompt->display_message_id);
        if ($path === 'sync') {
            $this->assertSame([$expected], $this->telegram->textsFor($conversation->id), 'the notice is the chat\'s only plain delivery');
            $this->assertCount(1, $this->telegram->presentationsFor($conversation->id), 'then the prompt, with its buttons');
        } else {
            $this->assertSame([$expected, $display->content], $this->web->textsFor($conversation->id), 'the notice, then the prompt');
        }
        $this->assertStringContainsString('(20 of 20)', (string) $prompt->preamble);

        $events = DB::table('task_budget_events')->where('task_budget_id', $task->id)->where('kind', 'approach_notice')->get();
        $this->assertCount(1, $events);
        $payload = json_decode((string) $events[0]->payload, true);
        $this->assertSame(['ceilings' => ['steps'], 'message_id' => $notices[0]->id, 'delivered' => true, 'delivery_failure_reason' => null], $payload);
    }
}
