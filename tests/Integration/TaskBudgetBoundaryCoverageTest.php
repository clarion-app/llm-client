<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Jobs\RunSchedulerTriggerJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\SchedulerTrigger;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * No model call without a claimed step, on every path a root agent's model
 * call can start from.
 *
 * Each case below drives one entry path end to end — a request through its
 * real controller or job, a continuation through its real resume — and
 * checks two things: the task's steps_used equals the number of model
 * requests made for the task on that path, and once the task is at its
 * ceiling that path makes no further request. The cases are keyed by the
 * path's number in the list of boundaries below, so a check missing at one
 * boundary turns exactly the rows that pass through it red:
 *
 *   B1 (the synchronous loop head)      rows 1, 2, 3, 7
 *   B2 (a synchronous continuation)     rows 8, 9
 *   B3 (start of a streamed request)    rows 11, 12, 13
 *   B4 (a streamed continuation)        rows 14, 15
 *
 * (A continuation row's first request starts the ordinary way, so it also
 * crosses B1 or B3 before reaching its own boundary.)
 *
 * Helper rows (4–6, 17, 18) and the continue-after-the-ceiling rows (10,
 * 16) are added with the stories that introduce them.
 */
class TaskBudgetBoundaryCoverageTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    private const CLEAR = 'clearStaleNotes';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();

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
        $this->serveAsTheHostDocumentation($document);
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

    /** @return array<string, array{0: int}> */
    public static function rows(): array
    {
        return [
            'row 1: POST agent → run()' => [1],
            'row 2: scheduler trigger job → run(unattended)' => [2],
            'row 3: eval case → run()' => [3],
            'row 7: schema-validation retry inside a sync run' => [7],
            'row 8: resumeSync() after a confirmation' => [8],
            'row 9: resumeQuestionSync() after a question' => [9],
            'row 11: POST message → start()' => [11],
            'row 12: next streaming iteration' => [12],
            'row 13: unprocessed-message pickup' => [13],
            'row 14: stream continuation after a confirmation' => [14],
            'row 15: stream continuation after a question' => [15],
        ];
    }

    #[Test]
    #[DataProvider('rows')]
    public function every_model_request_on_this_path_claimed_a_step_and_none_is_made_past_the_ceiling(int $row): void
    {
        $this->scenario = 'task_budget_boundary_row_'.$row;
        $this->entryPath = in_array($row, [11, 12, 13, 14, 15], true) ? 'stream' : 'sync';

        $this->{'row'.$row}();
    }

    // ---------------------------------------------------------------
    // B1 — the synchronous loop head
    // ---------------------------------------------------------------

    private function row1(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $conversation = $this->conversationFor('sync');
        $this->scriptSteps(1, 2);

        $this->send('sync', $conversation, 'Store notes.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $task = $this->taskRowOf($conversation);
        $this->assertSame(2, $this->modelRequests('sync'));
        $this->assertSame(2, (int) $task->steps_used, 'one claimed step per model request');
        $this->assertSame('paused', $task->state, 'at the ceiling the path made no further request');
    }

    private function row2(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);
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
            'defined_work' => 'Store notes.', 'retry_limit' => 0, 'is_active' => true,
        ]);
        $conversation = Conversation::create([
            'user_id' => $fixture->user->id, 'server_id' => $fixture->server->id, 'model' => 'gpt-4', 'title' => 'Scheduled work',
            'character' => 'Clarion', 'channel' => 'scheduler-trigger', 'agent_id' => $agent->id,
            'agent_version_id' => $version->id, 'scheduler_trigger_id' => $trigger->id,
        ]);
        $this->scriptSteps(1, 2);

        $this->app->call([new RunSchedulerTriggerJob($trigger->id, 'fire-'.Str::uuid()), 'handle']);

        $task = $this->taskRowOf($conversation);
        $this->assertNotNull($task);
        $this->assertSame(2, $this->modelRequests('sync'));
        $this->assertSame(2, (int) $task->steps_used);
        $this->assertSame('halted', $task->state, 'unattended: halted at the ceiling, no further request');
    }

    private function row3(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);
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
        $this->scriptSteps(1, 2);

        foreach (Bus::dispatched(RunEvalCaseJob::class) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }

        $conversation = Conversation::where('title', 'like', 'eval-run-case:%')->firstOrFail();
        $this->assertNull($conversation->user_id, 'precondition: an eval conversation has no user');
        $task = $this->taskRowOf($conversation);
        $this->assertNotNull($task);
        $this->assertSame(2, $this->modelRequests('sync'));
        $this->assertSame(2, (int) $task->steps_used);
        $this->assertSame('halted', $task->state, 'nobody to ask: halted at the ceiling, no further request');
    }

    private function row7(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $conversation = $this->conversationFor('stream');
        // Never valid against the schema: every response asks for a correction round.
        $this->script()->finalAnswer('Prose, not JSON.')->finalAnswer('Still prose.');

        $result = app(AgentLoopService::class)->run($conversation, 'Answer in the required shape.', [
            'schema' => ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']], 'required' => ['answer']],
            'retry_on_validation_failure' => true,
            'max_schema_retries' => 5,
        ]);

        $this->assertSame('awaiting_user', $result['status']);
        $task = $this->taskRowOf($conversation);
        $this->assertSame(2, $this->modelRequests('sync'), 'the retry\'s model request was claimed like any other');
        $this->assertSame(2, (int) $task->steps_used);
        $this->assertSame('paused', $task->state);
    }

    // ---------------------------------------------------------------
    // B2 — a synchronous continuation
    // ---------------------------------------------------------------

    private function assertNoStepLeftOpen(?string $runId): void
    {
        $this->assertNotNull($runId);
        $this->assertSame(0, DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', 'in_progress')->count(), 'no step is left open');
    }

    private function row8(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $conversation = $this->conversationFor('sync');
        $this->script()->toolRequests([['id' => 'call_clear', 'name' => 'execute_operation', 'arguments' => ['operationId' => self::CLEAR, 'parameters' => []]]]);
        $this->scriptSteps(2, 2);

        $this->send('sync', $conversation, 'Clear the stale notes, then store more.')->assertStatus(202)->assertJson(['status' => 'confirmation_required']);
        $paused = Message::where('conversation_id', $conversation->id)->get()->first(fn (Message $m) => is_array($m->tool_data['pending_confirmation'] ?? null));
        $this->assertNotNull($paused, 'precondition: paused for the user\'s confirmation');

        // No production caller resumes a confirmation synchronously; it is
        // driven directly, as the budget entry-path coverage does.
        $result = app(AgentLoopService::class)->resumeSync(Conversation::findOrFail($conversation->id), $paused, true);

        $this->assertSame('awaiting_user', $result['status']);
        $task = $this->taskRowOf($conversation);
        $this->assertSame(2, $this->modelRequests('sync'));
        $this->assertSame(2, (int) $task->steps_used, 'the continuation\'s request was claimed');
        $this->assertSame('paused', $task->state, 'and at the ceiling it made no further request');
        $this->assertNoStepLeftOpen($this->latestRunIdOf($conversation));
    }

    private function row9(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $conversation = $this->conversationFor('sync');
        $this->script()->toolRequests([['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => ['question' => 'Which notes?', 'choices' => ['New', 'Old']]]]);
        $this->scriptSteps(2, 2);

        $this->send('sync', $conversation, 'Ask me which notes, then store them.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $question = AgentQuestion::where('conversation_id', $conversation->id)->firstOrFail();

        $this->send('sync', $conversation, '1')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $this->assertSame(QuestionState::Answered, $question->fresh()->state);
        $task = $this->taskRowOf($conversation);
        $this->assertSame(2, $this->modelRequests('sync'));
        $this->assertSame(2, (int) $task->steps_used);
        $this->assertSame('paused', $task->state);
        $this->assertNotNull($this->promptOf($conversation), 'the ceiling prompt is what the answer\'s reply waits on');
        $this->assertNoStepLeftOpen($question->run_id);
    }

    // ---------------------------------------------------------------
    // B3 — the start of a streamed request
    // ---------------------------------------------------------------

    private function row11(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $conversation = $this->conversationFor('stream');

        $this->send('stream', $conversation, 'Store notes.')->assertStatus(201);

        $task = $this->taskRowOf($conversation);
        $this->assertNotNull($task, 'the request opened its task before its first model request');
        $this->assertSame(1, $this->modelRequests('stream'));
        $this->assertSame(1, (int) $task->steps_used, 'the first streamed request was claimed before it was dispatched');
        $this->assertSame('running', $task->state);
    }

    private function row12(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $conversation = $this->conversationFor('stream');
        $this->send('stream', $conversation, 'Store notes.')->assertStatus(201);
        $taskId = Conversation::findOrFail($conversation->id)->active_task_budget_id;

        $this->playSteps(1, 2);

        $this->assertSame($taskId, Conversation::findOrFail($conversation->id)->active_task_budget_id, 'every iteration stays in the one task');
        $task = $this->taskRowOf($conversation);
        $this->assertSame(2, $this->modelRequests('stream'));
        $this->assertSame(2, (int) $task->steps_used);
        $this->assertSame('paused', $task->state, 'the next iteration was not dispatched');
        $this->assertSame(1, TaskBudget::count());
    }

    private function row13(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 1]);
        $conversation = $this->conversationFor('stream');
        $this->send('stream', $conversation, 'First request.')->assertStatus(201);
        $first = $this->taskOf($conversation);

        // The first reply is streaming when the user sends a second message.
        $stream = $this->stream()->extractDispatchedJobs();
        $stream->setCurrentSlot(0);
        $stream->emit($this->turnChunks('First answer.', []));
        $this->travel(1)->seconds();
        $this->send('stream', $conversation, 'Second request.')->assertStatus(201);
        // Counted without re-reading the stream harness, which would discard the handler mid-reply.
        $this->assertSame(1, Queue::pushed(SendHttpStreamRequest::class)->count(), 'precondition: the second message waits while the first is processing');
        $stream->finish();

        // Picked up as a new request: a new task, claimed before it was dispatched.
        $second = $this->taskOf($conversation);
        $this->assertNotSame($first->id, $second->id, 'the picked-up message is a new request with its own task');
        $this->assertSame('completed', $first->fresh()->state->value);
        $this->assertSame(2, $this->modelRequests('stream'));
        $this->assertSame(1, (int) $this->taskRowOf($conversation)->steps_used);

        $this->playTurn(1, '', $this->stepCalls(1));

        $this->assertSame(2, $this->modelRequests('stream'), 'at its ceiling the picked-up task made no further request');
        $this->assertSame(1, (int) $this->taskRowOf($conversation)->steps_used);
        $this->assertSame('paused', $this->taskRowOf($conversation)->state);
    }

    // ---------------------------------------------------------------
    // B4 — a streamed continuation
    // ---------------------------------------------------------------

    private function row14(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 1]);
        $conversation = $this->conversationFor('stream');
        $this->send('stream', $conversation, 'Clear the stale notes.')->assertStatus(201);
        $this->playTurn(0, '', [['id' => 'call_clear', 'name' => 'execute_operation', 'arguments' => ['operationId' => self::CLEAR, 'parameters' => []]]]);
        $paused = Message::where('conversation_id', $conversation->id)->get()->first(fn (Message $m) => is_array($m->tool_data['pending_confirmation'] ?? null));
        $this->assertNotNull($paused, 'precondition: paused for the user\'s confirmation');

        $this->actingAs($this->fixture()->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/confirm-api-call", ['approved' => true, 'message_id' => $paused->id])
            ->assertStatus(200);

        $task = $this->taskRowOf($conversation);
        $this->assertSame(1, $this->modelRequests('stream'), 'the continuation was refused at the ceiling, not dispatched');
        $this->assertSame(1, (int) $task->steps_used);
        $this->assertSame('paused', $task->state);
        $this->assertNoStepLeftOpen($this->latestRunIdOf($conversation));
    }

    private function row15(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 1]);
        $conversation = $this->conversationFor('stream');
        $this->send('stream', $conversation, 'Ask me which notes.')->assertStatus(201);
        $this->playTurn(0, '', [['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => ['question' => 'Which notes?', 'choices' => ['New', 'Old']]]]);
        $question = AgentQuestion::where('conversation_id', $conversation->id)->firstOrFail();

        $this->send('stream', $conversation, '1')->assertStatus(201);
        $this->workQueuedResumes();

        $this->assertSame(QuestionState::Answered, $question->fresh()->state);
        $task = $this->taskRowOf($conversation);
        $this->assertSame(1, $this->modelRequests('stream'), 'the continuation was refused at the ceiling, not dispatched');
        $this->assertSame(1, (int) $task->steps_used);
        $this->assertSame('paused', $task->state);
        $this->assertNotNull($this->promptOf($conversation));
        $this->assertNoStepLeftOpen($question->run_id);
    }
}
