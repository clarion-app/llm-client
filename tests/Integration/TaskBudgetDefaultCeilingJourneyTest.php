<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Message;
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
        $this->assertSame(0, DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', 'in_progress')->count(), 'no step is left open');
        $this->assertFalse((bool) $conversation->fresh()->is_processing);

        // The old per-request bound never fired.
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->where('content', 'like', '%aximum%iterations%')->count());
        $this->assertSame(0, DB::table('agent_runs')->where('end_reason', 'Maximum iterations reached')->count());
        $this->assertSame(0, DB::table('agent_run_steps')->where('end_reason', 'Maximum iterations reached')->count());
    }
}
