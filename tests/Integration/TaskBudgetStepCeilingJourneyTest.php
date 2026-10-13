<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Jobs\RunSchedulerTriggerJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\SchedulerTrigger;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationAgentDefinitionResolver;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * The step ceiling stops a task at a step boundary, whatever the default is.
 *
 * With a three-step default the task pauses after its third step, with that
 * step's tool calls complete. While it waits for the user's decision its run
 * is still in progress — not failed, not abandoned, however long the wait —
 * and the conversation's one question slot is held by the prompt, while the
 * conversation itself is free (not processing). A task nobody can be asked
 * about — a scheduler-triggered one — halts instead, with no prompt.
 */
class TaskBudgetStepCeilingJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

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

    private function runToTheCeiling(string $path, Conversation $conversation): void
    {
        if ($path === 'sync') {
            $this->scriptSteps(1, 3);
            $this->send('sync', $conversation, 'Store three notes, then more.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        } else {
            $this->send('stream', $conversation, 'Store three notes, then more.')->assertStatus(201);
            $this->playSteps(1, 3);
        }
    }

    #[Test]
    #[DataProvider('paths')]
    public function the_task_pauses_after_its_third_step_and_waits_without_being_swept(string $path): void
    {
        $this->scenario = 'task_budget_step_ceiling_'.$path;
        $this->entryPath = $path;
        $conversation = $this->conversationFor($path);

        $this->runToTheCeiling($path, $conversation);

        $task = $this->taskRowOf($conversation);
        $this->assertSame(3, (int) $task->max_steps, 'the default step ceiling is the configured iteration bound');
        $this->assertSame('default', $task->max_steps_origin);
        $this->assertSame(3, $this->modelRequests($path), 'no fourth model request');
        $this->assertSame(3, (int) $task->steps_used);
        $this->assertCount(3, $this->noteWrites, 'the third step\'s tool calls completed');
        $this->assertSame('paused', $task->state);
        $this->assertSame('steps', $task->pause_reason);

        $runId = $this->latestRunIdOf($conversation);
        $runView = fn () => $this->actingAs($this->fixture()->user, 'api')->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}")->assertStatus(200)->json();
        $this->assertSame('in_progress', $runView()['end_state']);

        // While the prompt waits the slot is held, and the conversation is free.
        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt);
        $this->assertSame(QuestionState::Pending, $prompt->state);
        $this->assertSame($conversation->id, AgentQuestion::where('pending_conversation_id', $conversation->id)->value('conversation_id'));
        $this->assertSame($prompt->id, AgentQuestion::where('pending_conversation_id', $conversation->id)->value('id'), 'the prompt holds the conversation\'s question slot');
        $this->assertFalse((bool) $conversation->fresh()->is_processing);

        // Long past the abandonment threshold, the run is still waiting, not abandoned.
        $this->travel(3)->hours();
        $this->assertSame(0, Artisan::call('llm-client:resolve-abandoned-runs'));
        $this->assertSame('in_progress', $runView()['end_state']);
        $this->assertSame('paused', $this->taskRowOf($conversation)->state);
        $this->assertSame(QuestionState::Pending, $prompt->fresh()->state);
        $this->assertSame(3, $this->modelRequests($path), 'and nothing ran meanwhile');
    }

    // ---------------------------------------------------------------
    // Unattended: nobody to ask
    // ---------------------------------------------------------------

    /** An agent allowed to store notes on its own, bound to a scheduler trigger. */
    private function schedulerTrigger(): array
    {
        $this->serveAsTheHostDocumentation(self::taskBudgetHostApiDocument());
        $fixture = $this->fixture()->build();

        $yaml = "name: note-keeper\n"
            ."instructions: Keep notes.\n"
            ."tools:\n  allow:\n    - storeNote\n"
            ."safety:\n  confirmation_required: []\n  unattended_authorized:\n    - storeNote\n";
        $agent = Agent::create(['user_id' => $fixture->user->id, 'name' => 'note-keeper']);
        $version = AgentVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 1,
            'raw_definition' => $yaml,
            'content_hash' => hash('sha256', $yaml),
            'source' => AgentChangeSource::Created->value,
            'changed_by_user_id' => $fixture->user->id,
        ]);
        $agent->update(['current_version_id' => $version->id]);

        $trigger = SchedulerTrigger::create([
            'user_id' => $fixture->user->id,
            'agent_id' => $agent->id,
            'name' => 'Nightly notes',
            'kind' => SchedulerTrigger::KIND_SCHEDULE,
            'schedule_expression' => '0 3 * * *',
            'defined_work' => 'Store notes until told to stop.',
            'retry_limit' => 0,
            'is_active' => true,
        ]);
        $conversation = Conversation::create([
            'user_id' => $fixture->user->id,
            'server_id' => $fixture->server->id,
            'model' => 'gpt-4',
            'title' => 'Scheduled work',
            'character' => 'Clarion',
            'channel' => 'scheduler-trigger',
            'agent_id' => $agent->id,
            'agent_version_id' => $version->id,
            'scheduler_trigger_id' => $trigger->id,
        ]);
        $this->assertNotNull(app(ConversationAgentDefinitionResolver::class)->effectiveDefinitionFor($conversation), 'precondition: the agent definition resolves');

        return [$trigger, $conversation];
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

    #[Test]
    public function a_scheduler_triggered_task_halts_at_the_ceiling_without_asking_anyone(): void
    {
        $this->scenario = 'task_budget_step_ceiling_unattended';
        $this->entryPath = 'sync';
        [$trigger, $conversation] = $this->schedulerTrigger();
        $this->scriptSteps(1, 3);

        $this->app->call([new RunSchedulerTriggerJob($trigger->id, 'fire-'.Str::uuid()), 'handle']);

        $task = $this->taskRowOf($conversation);
        $this->assertNotNull($task);
        $this->assertTrue((bool) $task->unattended);
        $this->assertSame(3, $this->modelRequests('sync'), 'no fourth model request');
        $this->assertSame(3, (int) $task->steps_used);
        $this->assertCount(3, $this->noteWrites);
        $this->assertSame('halted', $task->state);
        $this->assertSame('Task budget ceiling reached', $task->end_reason);
        $this->assertSame(0, AgentQuestion::where('conversation_id', $conversation->id)->count(), 'nobody is asked');

        $run = DB::table('agent_runs')->where('conversation_id', $conversation->id)->first();
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame('Task budget ceiling reached', $run->end_reason);

        $report = Message::where('conversation_id', $conversation->id)->get()
            ->first(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_REPORT);
        $this->assertNotNull($report, 'the report is in the conversation\'s record');
        $this->assertStringStartsWith("This task's budget ceiling was reached: steps (3 of 3).", $report->content);
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
    }
}
