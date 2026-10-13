<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;

/**
 * With run tracing switched off, the task budget is enforced exactly as
 * with it on.
 *
 * No run is recorded, so nothing carries a run id: a streamed request
 * passes a null run id to every one of its iterations, the usage records
 * carry no run, and the pause has no run to name. The task is still one
 * task per request — keyed on the conversation, minted at a request's first
 * iteration, never at each null run id — its steps are claimed on both
 * paths, its spend is read from usage records stamped with the task, and a
 * helper's work counts against it. The prompt is raised with no run, and a
 * "continue" resumes the task once on either path. A task left running when
 * its request finished (no run close ends it with tracing off) is ended as
 * superseded by the conversation's next request.
 */
class TaskBudgetTracingDisabledJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetHelperJourneys;

    /** 10 completion tokens at 4000 per million: 0.04 per response. */
    private const USAGE = [10, 10];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
        config(['llm-client.run_trace.enabled' => false, 'llm-client.agent_loop.max_iterations' => 3]);
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    private function assertNoRunRecorded(): void
    {
        $this->assertSame(0, DB::table('agent_runs')->count(), 'precondition: tracing is off');
    }

    #[Test]
    #[DataProvider('paths')]
    public function the_step_ceiling_holds_across_iterations_of_one_task_and_continue_resumes_it_once(string $path): void
    {
        $this->scenario = 'task_budget_tracing_off_steps_'.$path;
        $this->entryPath = $path;
        $conversation = $this->conversationFor($path);

        if ($path === 'sync') {
            $this->scriptSteps(1, 3);
            $this->send('sync', $conversation, 'Store notes.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        } else {
            $this->send('stream', $conversation, 'Store notes.')->assertStatus(201);
            $this->playSteps(1, 3);
        }

        $this->assertNoRunRecorded();
        $this->assertSame(1, TaskBudget::count(), 'one task for the whole request, not one per iteration');
        $task = $this->taskOf($conversation);
        $this->assertSame(3, $this->modelRequests($path), 'no fourth model request');
        $this->assertSame(3, (int) $task->steps_used);
        $this->assertSame('paused', $task->state->value);
        $this->assertNull($task->paused_run_id);
        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt);
        $this->assertNull($prompt->run_id, 'the prompt names no run');
        $this->assertSame(QuestionState::Pending, $prompt->state);

        // Continue: three more steps (the original ceiling again), then it pauses once more.
        if ($path === 'sync') {
            $this->scriptSteps(4, 6);
            $this->send('sync', $conversation, 'continue')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        } else {
            $this->send('stream', $conversation, 'continue')->assertStatus(201);
            $this->workQueuedResumes();
            $this->playSteps(4, 6);
        }

        $this->assertSame(QuestionState::Answered, $prompt->fresh()->state);
        $this->assertSame(1, TaskBudget::count(), 'still the one task');
        $task->refresh();
        $this->assertSame(6, $this->modelRequests($path), 'resumed once: three more requests');
        $this->assertSame(6, (int) $task->steps_used);
        $this->assertSame(6, (int) $task->max_steps);
        $this->assertSame('paused', $task->state->value);
        $this->assertSame(2, AgentQuestion::where('origin', AgentQuestion::ORIGIN_TASK_BUDGET)->count(), 'a second prompt at the raised ceiling');
        $this->assertCount(6, $this->noteWrites, 'nothing repeated');
    }

    #[Test]
    #[DataProvider('paths')]
    public function the_spend_ceiling_holds_on_usage_stamped_with_the_task(string $path): void
    {
        $this->scenario = 'task_budget_tracing_off_spend_'.$path;
        $this->entryPath = $path;
        config(['llm-client.agent_loop.max_iterations' => 10, 'llm-client.task_budget.default_max_spend' => '0.10']);
        $this->priceModel();
        $conversation = $this->conversationFor($path);

        if ($path === 'sync') {
            $this->scriptSteps(1, 3, self::USAGE);
            $this->send('sync', $conversation, 'Store notes.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        } else {
            $this->send('stream', $conversation, 'Store notes.')->assertStatus(201);
            $this->playSteps(1, 3, self::USAGE);
        }

        $this->assertNoRunRecorded();
        $task = $this->taskOf($conversation);
        $this->assertSame(3, $this->modelRequests($path), 'the step that crossed the ceiling completed; no fourth request');
        $this->assertSame(3, DB::table('usage_records')->where('task_budget_id', $task->id)->whereNull('run_id')->count(), 'every request\'s usage is the task\'s, with no run');
        $this->assertSame('paused', $task->state->value);
        $this->assertSame('spend', $task->pause_reason);
    }

    #[Test]
    public function a_helpers_work_counts_against_the_task(): void
    {
        $this->scenario = 'task_budget_tracing_off_helper';
        $this->entryPath = 'sync';
        $this->enableHelpers();
        $this->fixtureWithModel();
        $root = $this->agent('root-agent');
        $helper = $this->agent('solo-helper');
        $this->assignHelper($root, $helper);
        $conversation = $this->rootConversation('sync', $root);
        $this->script()->toolRequests([$this->delegateCall('call_solo', $helper, 'Store notes.')]);
        $this->scriptNoteSteps(['solo 1', 'solo 2']);

        $this->send('sync', $conversation, 'Have the helper store notes.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $this->assertNoRunRecorded();
        $task = $this->onlyTask();
        $this->assertSame(3, $this->agentRequests(), 'the agent 1, the helper 2 — then nothing, anywhere');
        $this->assertSame(3, (int) $task->steps_used);
        $this->assertSame('paused', $task->state->value);
        $this->assertSame('task_budget', $this->delegationTo($helper)->result_reason);
        $this->assertSame(3, DB::table('usage_records')->where('task_budget_id', $task->id)->count(), 'the helper\'s usage is the task\'s too');
    }

    #[Test]
    public function a_task_left_running_is_superseded_by_the_next_request(): void
    {
        $this->scenario = 'task_budget_tracing_off_superseded';
        $this->entryPath = 'stream';
        $conversation = $this->conversationFor('stream');
        $this->send('stream', $conversation, 'Hello.')->assertStatus(201);
        $this->playTurn(0, 'Hello to you.');
        $first = $this->taskOf($conversation);
        $this->assertSame('running', $first->state->value, 'precondition: with no run to close, the finished request leaves its task running');

        $this->travel(1)->seconds();
        $this->send('stream', $conversation, 'Store notes.')->assertStatus(201);

        $second = $this->taskOf($conversation);
        $this->assertNotSame($first->id, $second->id);
        $first->refresh();
        $this->assertSame('ended', $first->state->value);
        $this->assertSame('superseded', $first->end_reason);
        $this->assertSame(1, (int) $second->steps_used);
        $this->assertSame(2, TaskBudget::count());
    }
}
