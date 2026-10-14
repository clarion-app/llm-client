<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
use ClarionApp\LlmClient\Jobs\RunSchedulerTriggerJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\SchedulerTrigger;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use ClarionApp\LlmClient\Services\ManagerService;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;

/**
 * A task nobody can be asked about stops at its ceiling instead of waiting
 * for an answer that cannot come.
 *
 * Three kinds of work run with nobody present: a scheduler-triggered run, an
 * eval case (its conversation has no user) and a managed task (its manager
 * conversation is on a channel nobody listens on). Each reaches the default
 * step ceiling and halts: no prompt is raised, the task is halted, its
 * report is in the conversation's record, the run is closed stopped early
 * naming the task budget, and the event log shows the ceiling reached and
 * the halt. The owner sees the stop on the run. Nothing is left queued to
 * resume it.
 */
class TaskBudgetUnattendedJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetHelperJourneys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
        config(['llm-client.agent_loop.max_iterations' => 3]);
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    private function serveAsTheHostDocumentation(array $document): void
    {
        $this->app->instance(Generator::class, new class($document) extends Generator
        {
            public function __construct(private array $document)
            {
            }

            public function __invoke(?GeneratorConfig $config = null)
            {
                return $this->document;
            }
        });
    }

    /** The halt as every unattended kind records it, checked from the task, its conversation and its run. */
    private function assertHaltedWithoutAsking(TaskBudget $task, Conversation $conversation, string $runId): void
    {
        $task->refresh();
        $this->assertSame('halted', $task->state->value, 'nobody to ask: the task halts');
        $this->assertSame('Task budget ceiling reached', $task->end_reason);
        $this->assertNull($task->prompt_question_id);
        $this->assertSame(3, (int) $task->steps_used);
        $this->assertSame(0, AgentQuestion::count(), 'no prompt is raised');

        $report = Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_REPORT);
        $this->assertCount(1, $report, 'the report is in the conversation\'s record');
        $this->assertStringStartsWith("This task's budget ceiling was reached: steps (3 of 3).", $report->first()->content);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame('Task budget ceiling reached', $run->end_reason);
        $this->assertSame(0, DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', 'in_progress')->count(), 'no step left open');

        $kinds = DB::table('task_budget_events')->where('task_budget_id', $task->id)->orderBy('created_at')->pluck('kind')->all();
        $this->assertSame(['opened', 'ceiling_reached', 'halted'], $kinds);

        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
    }

    /** The owner sees the halt on the run, through the run endpoints. */
    private function assertOwnerSeesTheHalt(string $runId): void
    {
        $user = $this->fixture()->user;
        $summary = $this->actingAs($user, 'api')->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}")->assertStatus(200)->json();
        $this->assertSame('halted', $summary['task_budget']['state'] ?? null);
        $this->assertNull($summary['task_budget']['sub_state']);

        $record = $this->actingAs($user, 'api')->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/task-budget")->assertStatus(200)->json();
        $this->assertSame('halted', $record['standing']['state']);
        $this->assertContains('halted', array_column($record['events'], 'kind'));
    }

    #[Test]
    public function a_scheduler_triggered_task_halts_at_the_default_ceiling(): void
    {
        $this->scenario = 'task_budget_unattended_scheduler';
        $this->entryPath = 'sync';
        $this->serveAsTheHostDocumentation(self::taskBudgetHostApiDocument());
        $fixture = $this->fixture()->build();
        $yaml = "name: note-keeper\ninstructions: Keep notes.\ntools:\n  allow:\n    - storeNote\n"
            ."safety:\n  confirmation_required: []\n  unattended_authorized:\n    - storeNote\n";
        $agent = Agent::create(['user_id' => $fixture->user->id, 'name' => 'note-keeper']);
        $version = AgentVersion::create([
            'agent_id' => $agent->id, 'version_number' => 1, 'raw_definition' => $yaml, 'content_hash' => hash('sha256', $yaml),
            'source' => AgentChangeSource::Created->value, 'changed_by_user_id' => $fixture->user->id,
        ]);
        $agent->update(['current_version_id' => $version->id]);
        $trigger = SchedulerTrigger::create([
            'user_id' => $fixture->user->id, 'agent_id' => $agent->id, 'name' => 'Nightly notes',
            'kind' => SchedulerTrigger::KIND_SCHEDULE, 'schedule_expression' => '0 3 * * *',
            'defined_work' => 'Store notes until told to stop.', 'retry_limit' => 0, 'is_active' => true,
        ]);
        $conversation = Conversation::create([
            'user_id' => $fixture->user->id, 'server_id' => $fixture->server->id, 'model' => 'gpt-4', 'title' => 'Scheduled work',
            'character' => 'Clarion', 'channel' => 'scheduler-trigger', 'agent_id' => $agent->id,
            'agent_version_id' => $version->id, 'scheduler_trigger_id' => $trigger->id,
        ]);
        $this->scriptSteps(1, 3);

        $this->app->call([new RunSchedulerTriggerJob($trigger->id, 'fire-'.Str::uuid()), 'handle']);

        $task = $this->taskOf($conversation);
        $this->assertNotNull($task);
        $this->assertTrue((bool) $task->unattended);
        $this->assertSame(3, $this->modelRequests('sync'), 'no fourth model request');
        $runId = $this->latestRunIdOf($conversation);
        $this->assertSame($runId, $task->root_run_id);
        $this->assertHaltedWithoutAsking($task, $conversation, $runId);
        $this->assertOwnerSeesTheHalt($runId);
        $this->assertSame([], Queue::pushedJobs(), 'nothing is queued');
    }

    #[Test]
    public function an_eval_case_halts_at_the_default_ceiling(): void
    {
        $this->scenario = 'task_budget_unattended_eval';
        $this->entryPath = 'sync';
        $fixture = $this->fixture()->build();
        config(['llm-client.cost.operator_user_ids' => [$fixture->user->id]]);
        RoleAssignment::create([
            'role' => 'inference', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $fixture->server->id, 'model' => 'gpt-4',
        ]);
        $api = '/api/clarion-app/llm-client/agent-eval-suites';
        $suite = $this->actingAs($fixture->user, 'api')->postJson($api, ['name' => 'Budget suite', 'agent_identifier' => 'note-keeper'])->assertStatus(200)->json();
        $this->actingAs($fixture->user, 'api')->postJson("{$api}/{$suite['id']}/cases", [
            'given' => 'Store notes.',
            'expected_behavior' => 'Stores notes.',
            'expectations' => [['kind' => 'text_match', 'expected_text' => 'stored']],
        ])->assertStatus(200);
        Bus::fake([RunEvalCaseJob::class]);
        $this->actingAs($fixture->user, 'api')->postJson("{$api}/{$suite['id']}/runs")->assertStatus(201);
        $this->scriptSteps(1, 3);

        foreach (Bus::dispatched(RunEvalCaseJob::class) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }

        $conversation = Conversation::where('title', 'like', 'eval-run-case:%')->firstOrFail();
        $this->assertNull($conversation->user_id, 'precondition: an eval conversation has no user');
        $task = $this->taskOf($conversation);
        $this->assertNotNull($task);
        $this->assertSame(3, $this->modelRequests('sync'), 'no fourth model request');
        $this->assertHaltedWithoutAsking($task, $conversation, $this->latestRunIdOf($conversation));
        $this->assertSame(1, DB::table('eval_case_results')->count(), 'the case was still graded');
    }

    #[Test]
    public function a_managed_task_halts_at_the_default_ceiling(): void
    {
        $this->scenario = 'task_budget_unattended_managed';
        $this->entryPath = 'sync';
        Queue::fake([AgentStreamStepJob::class, ResumeAfterQuestionJob::class, RunManagedTaskStepJob::class]);
        $this->enableHelpers();
        $this->fixtureWithModel();
        $manager = $this->agent('manager-agent');
        $this->assignHelper($manager, $this->agent('part-helper'));
        $response = $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/managed-tasks', [
            'agent_id' => $manager->id,
            'request' => 'Store notes for the report.',
        ])->assertStatus(202);
        $managed = ManagedTask::findOrFail($response->json('managed_task_id'));
        app(ManagerService::class)->planParts($managed, ['The notes.']);
        $this->scriptSteps(1, 3);

        $rounds = Queue::pushed(RunManagedTaskStepJob::class)->all();
        $this->assertCount(1, $rounds);
        $this->app->forgetScopedInstances();
        $this->app->call([$rounds[0], 'handle']);

        $conversation = Conversation::findOrFail($managed->conversation_id);
        $task = TaskBudget::where('managed_task_id', $managed->id)->firstOrFail();
        $this->assertTrue((bool) $task->unattended);
        $this->assertSame(3, $this->agentRequests(), 'no fourth model request');
        $runId = $this->latestRunIdOf($conversation);
        $this->assertSame($runId, $task->paused_run_id, 'the halted round\'s run is the one the task stopped on');
        $this->assertHaltedWithoutAsking($task, $conversation, $runId);
        $this->assertOwnerSeesTheHalt($runId);
        $this->assertCount(2, Queue::pushed(RunManagedTaskStepJob::class), 'only the next round is queued, which finalizes');
    }
}
