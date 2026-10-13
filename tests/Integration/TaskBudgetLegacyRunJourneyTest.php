<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * Work that was already waiting when task budgets arrived is bounded from
 * its first step after that.
 *
 * A run paused on a question or a confirmation before deployment has no
 * task: its conversation carries no pointer, its usage records name its run
 * but no task, and its steps were never claimed. When the user answers, the
 * continuation's first boundary opens a default task for it, brings that
 * run's earlier usage into the task and counts the run's earlier steps as
 * used — so the task is bounded by the defaults from there, counting what
 * the run already did. With tracing off there is no run to count, and the
 * task starts from zero.
 */
class TaskBudgetLegacyRunJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    private const CLEAR = 'clearStaleNotes';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
        config(['llm-client.agent_loop.max_iterations' => 4]);

        // The host API also offers a deletion, which needs the user's confirmation.
        $document = self::taskBudgetHostApiDocument();
        $document['paths']['/notes/stale'] = [
            'delete' => [
                'operationId' => self::CLEAR,
                'summary' => 'Delete every stale note',
                'parameters' => [],
                'responses' => ['200' => ['description' => 'Deleted']],
            ],
        ];
        (new OperationCatalogue())->seed($document);
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

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /**
     * Makes the paused work look as it did before task budgets were deployed:
     * no task, no pointer, usage records with no task.
     */
    private function asBeforeDeployment(Conversation $conversation): void
    {
        DB::table('task_budget_events')->delete();
        DB::table('task_budgets')->delete();
        DB::table('conversations')->where('id', $conversation->id)->update(['active_task_budget_id' => null]);
        DB::table('usage_records')->update(['task_budget_id' => null]);
        $this->assertSame(0, TaskBudget::count());
    }

    private function onlyTask(): TaskBudget
    {
        $this->assertSame(1, TaskBudget::count(), 'exactly one task, opened by the continuation');

        return TaskBudget::firstOrFail();
    }

    #[Test]
    public function a_question_answered_after_deployment_is_bounded_counting_the_runs_earlier_steps(): void
    {
        $this->scenario = 'task_budget_legacy_question';
        $this->entryPath = 'sync';
        $conversation = $this->conversationFor('sync');
        $this->script()->toolRequests([['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => ['question' => 'Which notes?', 'choices' => ['New', 'Old']]]]);

        $this->send('sync', $conversation, 'Ask me which notes, then store them.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $question = AgentQuestion::where('conversation_id', $conversation->id)->firstOrFail();
        $runId = $question->run_id;
        $this->assertNotNull($runId);
        $this->assertSame(1, DB::table('agent_run_steps')->where('run_id', $runId)->count(), 'precondition: one step before the pause');
        $this->assertSame(1, DB::table('usage_records')->where('run_id', $runId)->count(), 'precondition: one usage record before the pause');
        $this->asBeforeDeployment($conversation);

        // Three more steps fit under the default ceiling of four.
        $this->scriptSteps(2, 4);
        $this->send('sync', $conversation, '1')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $this->assertSame(QuestionState::Answered, $question->fresh()->state);
        $task = $this->onlyTask();
        $this->assertSame($task->id, Conversation::findOrFail($conversation->id)->active_task_budget_id, 'the conversation now points at it');
        $this->assertSame(4, (int) $task->max_steps, 'the defaults');
        $this->assertSame('default', $task->max_steps_origin->value);
        $this->assertSame($runId, $task->root_run_id, 'the paused run is the task\'s root run');
        $this->assertSame(4, $this->modelRequests('sync'), 'the earlier step counted: three more requests, not four');
        $this->assertSame(4, (int) $task->steps_used);
        $this->assertSame('paused', $task->state->value, 'bounded by the defaults from there');
        $this->assertSame(4, DB::table('usage_records')->where('task_budget_id', $task->id)->count(), 'the earlier usage record was brought into the task');
        $this->assertSame(0, DB::table('usage_records')->whereNull('task_budget_id')->count());
        $this->assertSame(['opened'], DB::table('task_budget_events')->where('task_budget_id', $task->id)->orderBy('created_at')->limit(1)->pluck('kind')->all());
    }

    #[Test]
    public function a_confirmation_given_after_deployment_is_bounded_counting_the_runs_earlier_steps(): void
    {
        $this->scenario = 'task_budget_legacy_confirmation';
        $this->entryPath = 'stream';
        $conversation = $this->conversationFor('stream');
        $this->send('stream', $conversation, 'Clear the stale notes, then store more.')->assertStatus(201);
        $this->playTurn(0, '', [['id' => 'call_clear', 'name' => 'execute_operation', 'arguments' => ['operationId' => self::CLEAR, 'parameters' => []]]]);
        $paused = Message::where('conversation_id', $conversation->id)->get()->first(fn (Message $m) => is_array($m->tool_data['pending_confirmation'] ?? null));
        $this->assertNotNull($paused, 'precondition: paused for the user\'s confirmation');
        $runId = $this->latestRunIdOf($conversation);
        $this->assertSame(1, DB::table('agent_run_steps')->where('run_id', $runId)->count(), 'precondition: one step before the pause');
        $this->asBeforeDeployment($conversation);

        $this->actingAs($this->fixture()->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/confirm-api-call", ['approved' => true, 'message_id' => $paused->id])
            ->assertStatus(200);

        $task = $this->onlyTask();
        $this->assertSame($runId, $task->root_run_id);
        $this->assertSame(2, (int) $task->steps_used, 'the earlier step, plus the continuation\'s claim');
        $this->assertSame(2, $this->modelRequests('stream'));

        $this->playSteps(2, 4);

        $task->refresh();
        $this->assertSame(4, $this->modelRequests('stream'), 'three more requests after the pause, not four');
        $this->assertSame(4, (int) $task->steps_used);
        $this->assertSame('paused', $task->state->value);
        $this->assertNotNull($this->promptOf($conversation));
    }

    #[Test]
    public function with_tracing_off_the_continuation_starts_its_task_from_zero(): void
    {
        $this->scenario = 'task_budget_legacy_tracing_off';
        $this->entryPath = 'sync';
        config(['llm-client.run_trace.enabled' => false]);
        $conversation = $this->conversationFor('sync');
        $this->script()->toolRequests([['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => ['question' => 'Which notes?', 'choices' => ['New', 'Old']]]]);

        $this->send('sync', $conversation, 'Ask me which notes, then store them.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $question = AgentQuestion::where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertNull($question->run_id, 'precondition: no run is recorded');
        $this->asBeforeDeployment($conversation);

        $this->scriptSteps(2, 5);
        $this->send('sync', $conversation, '1')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $task = $this->onlyTask();
        $this->assertNull($task->root_run_id);
        $this->assertSame(5, $this->modelRequests('sync'), 'no run to count: four more requests under a default of four');
        $this->assertSame(4, (int) $task->steps_used);
        $this->assertSame('paused', $task->state->value);
    }
}
