<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
use ClarionApp\LlmClient\Jobs\RunSchedulerTriggerJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\ConsensusRequest;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\SchedulerTrigger;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\CapabilityOfferingService;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use ClarionApp\LlmClient\Services\ManagerService;
use ClarionApp\LlmClient\Services\SequenceService;
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
use Tests\Integration\Harness\TaskBudgetHelperJourneys;

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
 *   B1, in a helper's own run           rows 4, 5, 6, 17, 18
 *   B2 (a synchronous continuation)     rows 8, 9
 *   B3 (start of a streamed request)    rows 11, 12, 13
 *   B4 (a streamed continuation)        rows 14, 15, 16
 *   B2, after a ceiling decision        row 10
 *
 * (A continuation row's first request starts the ordinary way, so it also
 * crosses B1 or B3 before reaching its own boundary.) In the helper rows the
 * requests counted are those of the whole tree — the delegating agent and
 * every helper — since all of them claim against the one task.
 *
 * Rows 10 and 16 continue a task past its ceiling after the user's
 * decision: the continuation's own request is claimed against the raised
 * ceiling, and once that allowance is used up the task pauses again.
 */
class TaskBudgetBoundaryCoverageTest extends AssembledSystemTestCase
{
    use TaskBudgetHelperJourneys;

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

    /** @return array<string, array{0: int|string}> */
    public static function rows(): array
    {
        return [
            'row 1: POST agent → run()' => [1],
            'row 2: scheduler trigger job → run(unattended)' => [2],
            'row 3: eval case → run()' => [3],
            'row 4: solo helper → run()' => ['4'],
            'row 4: capability-agent call → run()' => ['4c'],
            'row 5: batch member job → run()' => [5],
            'row 6: managed-task step job → run()' => [6],
            'row 7: schema-validation retry inside a sync run' => [7],
            'row 8: resumeSync() after a confirmation' => [8],
            'row 9: resumeQuestionSync() after a question' => [9],
            'row 10: resumeAfterTaskBudgetSync() after a continue' => [10],
            'row 11: POST message → start()' => [11],
            'row 12: next streaming iteration' => [12],
            'row 13: unprocessed-message pickup' => [13],
            'row 14: stream continuation after a confirmation' => [14],
            'row 15: stream continuation after a question' => [15],
            'row 16: resumeAfterTaskBudget() after a continue' => [16],
            'row 17: sequence stage job → helper run()' => [17],
            'row 18: consensus request → helper run()' => [18],
        ];
    }

    #[Test]
    #[DataProvider('rows')]
    public function every_model_request_on_this_path_claimed_a_step_and_none_is_made_past_the_ceiling(int|string $row): void
    {
        $this->scenario = 'task_budget_boundary_row_'.$row;
        $this->entryPath = in_array($row, [11, 12, 13, 14, 15, 16], true) ? 'stream' : 'sync';

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

    private function assertPausedStepLeftOpen(?string $runId): void
    {
        $this->assertNotNull($runId);
        // The pause sits on the step boundary: the step is left open and
        // marked paused (the run is resumable until its resumer claims it),
        // never closed.
        $this->assertSame(1, DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', 'in_progress')->count(), 'the open step is left open');
        $this->assertSame('paused', DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', 'in_progress')->value('checkpoint_state'), 'the step is marked paused');
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
        $this->assertPausedStepLeftOpen($this->latestRunIdOf($conversation));
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
        $this->assertPausedStepLeftOpen($question->run_id);
    }

    private function row10(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $conversation = $this->conversationFor('sync');
        $this->scriptSteps(1, 2);
        $this->send('sync', $conversation, 'Store notes.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $first = $this->promptOf($conversation);
        $this->assertNotNull($first, 'precondition: paused at the ceiling, the prompt waiting');
        $runId = $this->latestRunIdOf($conversation);

        // One more step: it runs, and the task pauses again at the next loop head.
        $this->scriptSteps(3, 3);
        $this->send('sync', $conversation, 'continue steps=1')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $task = $this->taskRowOf($conversation);
        $this->assertSame(3, $this->modelRequests('sync'));
        $this->assertSame(3, (int) $task->steps_used, 'the continuation\'s request claimed a step');
        $this->assertSame(3, (int) $task->max_steps);
        $this->assertSame('paused', $task->state, 'its allowance used, the task paused again');
        $second = $this->promptOf($conversation);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(QuestionState::Pending, $second->state);
        $this->assertSame($runId, $second->run_id, 'still the same run');
        $this->assertPausedStepLeftOpen($runId);
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
        $this->assertPausedStepLeftOpen($this->latestRunIdOf($conversation));
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
        $this->assertPausedStepLeftOpen($question->run_id);
    }

    private function row16(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 1]);
        $conversation = $this->conversationFor('stream');
        $this->send('stream', $conversation, 'Store notes.')->assertStatus(201);
        $this->playSteps(1, 1);
        $first = $this->promptOf($conversation);
        $this->assertNotNull($first, 'precondition: paused at the ceiling, the prompt waiting');
        $runId = $this->latestRunIdOf($conversation);

        $this->send('stream', $conversation, 'continue steps=1')->assertStatus(201);

        $task = $this->taskRowOf($conversation);
        $this->assertSame(2, $this->modelRequests('stream'), 'the continuation was dispatched');
        $this->assertSame(2, (int) $task->steps_used, 'and claimed before it was dispatched');
        $this->assertSame('running', $task->state);

        $this->playSteps(2, 2);

        $task = $this->taskRowOf($conversation);
        $this->assertSame(2, $this->modelRequests('stream'), 'its allowance used, no further request');
        $this->assertSame(2, (int) $task->steps_used);
        $this->assertSame('paused', $task->state);
        $second = $this->promptOf($conversation);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($runId, $second->run_id, 'still the same run');
        $this->assertPausedStepLeftOpen($runId);
    }

    // ---------------------------------------------------------------
    // B1, in a helper's own run
    // ---------------------------------------------------------------

    /** The fixture's Telegram conversation, bound to an agent with the given helpers assigned. */
    private function delegatingRoot(array $helperNames): array
    {
        $this->enableHelpers();
        $this->fixtureWithModel();
        $root = $this->agent('root-agent');
        $helpers = [];
        foreach ($helperNames as $name) {
            $helpers[$name] = $this->agent($name);
            $this->assignHelper($root, $helpers[$name]);
        }

        return [$this->rootConversation('sync', $root), $root, $helpers];
    }

    /** Usage records — one per model request — of every conversation but the reconciliation's. */
    private function requestsInTheTree(): int
    {
        return DB::table('usage_records')
            ->join('conversations', 'conversations.id', '=', 'usage_records.conversation_id')
            ->where('conversations.channel', '!=', 'consensus-reconciliation')
            ->count();
    }

    private function assertStoppedByTheTaskBudget(Delegation $delegation): void
    {
        $this->assertSame('task_budget', $delegation->result_reason, 'the helper was stopped by the task\'s budget, at its own boundary');
    }

    private function row4(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 3]);
        [$conversation, , $helpers] = $this->delegatingRoot(['solo-helper']);
        $this->script()->toolRequests([$this->delegateCall('call_solo', $helpers['solo-helper'], 'Store notes.')]);
        $this->scriptNoteSteps(['solo 1', 'solo 2']);

        $this->send('sync', $conversation, 'Have the helper store notes.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $task = $this->taskRowOf($conversation);
        $this->assertSame(3, $this->agentRequests(), 'the agent 1, the helper 2 — then nothing, anywhere');
        $this->assertSame(3, $this->requestsInTheTree());
        $this->assertSame(3, (int) $task->steps_used, 'the helper\'s requests were claimed against the task');
        $this->assertSame('paused', $task->state);
        $this->assertStoppedByTheTaskBudget($this->delegationTo($helpers['solo-helper']));
    }

    private function row4c(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 3]);
        [$conversation, $root, $helpers] = $this->delegatingRoot([]);
        $offered = $this->agent('offered-agent');
        $offering = app(CapabilityOfferingService::class)->offer(
            $this->fixture()->user->id,
            $offered->id,
            $root->id,
            'draft_report',
            'Drafts a report.',
            'What to report on.',
        );
        $this->script()->toolRequests([['id' => 'call_capability', 'name' => 'execute_operation', 'arguments' => ['operationId' => $offering->id, 'parameters' => ['input' => 'Q3']]]]);
        $this->scriptNoteSteps(['offered 1', 'offered 2']);

        $this->send('sync', $conversation, 'Have a report drafted.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $task = $this->taskRowOf($conversation);
        $this->assertSame(3, $this->agentRequests(), 'the agent 1, the offered agent 2 — then nothing, anywhere');
        $this->assertSame(3, (int) $task->steps_used);
        $this->assertSame('paused', $task->state);
        $this->assertStoppedByTheTaskBudget(Delegation::where('origin', 'capability_offering')->firstOrFail());
    }

    private function row5(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 3]);
        [$conversation, , $helpers] = $this->delegatingRoot(['member-a', 'member-b']);
        $this->script()->toolRequests([
            $this->delegateCall('call_member_a', $helpers['member-a'], 'Store notes.'),
            $this->delegateCall('call_member_b', $helpers['member-b'], 'Store notes.'),
        ]);
        $this->scriptNoteSteps(['member a 1', 'member a 2']);

        $this->send('sync', $conversation, 'Have both helpers store notes.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $task = $this->taskRowOf($conversation);
        $this->assertSame(3, $this->agentRequests(), 'the agent 1, member A 2, member B none');
        $this->assertSame(3, (int) $task->steps_used);
        $this->assertSame('paused', $task->state);
        $this->assertStoppedByTheTaskBudget($this->delegationTo($helpers['member-a']));
        $this->assertStoppedByTheTaskBudget($this->delegationTo($helpers['member-b']));
        $this->assertSame([], $this->usageOf($this->helperConversationOf($helpers['member-b'])->id), 'a member starting at the ceiling makes no request');
    }

    private function row6(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 3]);
        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class, RunManagedTaskStepJob::class]);
        [, $manager, $helpers] = $this->delegatingRoot(['part-helper']);
        $response = $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/managed-tasks', [
            'agent_id' => $manager->id,
            'request' => 'Write the report.',
        ])->assertStatus(202);
        $managed = ManagedTask::findOrFail($response->json('managed_task_id'));
        [$part] = app(ManagerService::class)->planParts($managed, ['The report.']);
        $this->script()->toolRequests([['id' => 'call_assign', 'name' => 'assign_part', 'arguments' => ['part_id' => $part->id, 'helper_agent_id' => $helpers['part-helper']->id, 'task' => 'Write it.']]]);
        $this->scriptNoteSteps(['part 1', 'part 2']);

        $rounds = Queue::pushed(RunManagedTaskStepJob::class)->all();
        $this->assertCount(1, $rounds);
        $this->app->forgetScopedInstances();
        $this->app->call([$rounds[0], 'handle']);

        $task = TaskBudget::where('managed_task_id', $managed->id)->firstOrFail();
        $this->assertSame(3, $this->agentRequests(), 'the manager 1, its helper 2 — then nothing');
        $this->assertSame(3, (int) $task->steps_used);
        $this->assertSame('halted', $task->state->value);
        $this->assertStoppedByTheTaskBudget($this->delegationTo($helpers['part-helper']));

        $next = Queue::pushed(RunManagedTaskStepJob::class)->all();
        $this->assertCount(2, $next, 'precondition: the round queued the next one');
        $this->app->forgetScopedInstances();
        $this->app->call([$next[1], 'handle']);
        $this->assertSame(3, $this->agentRequests(), 'the next round at the ceiling made no request');
    }

    private function row17(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);
        [, $coordinator, $helpers] = $this->delegatingRoot(['stage-one', 'stage-two']);
        $definition = app(SequenceService::class)->defineSequence($this->fixture()->user->id, 'Pipeline', null, $coordinator->id, [
            ['name' => 'One', 'helper_agent_id' => $helpers['stage-one']->id],
            ['name' => 'Two', 'helper_agent_id' => $helpers['stage-two']->id],
        ]);
        $this->scriptNoteSteps(['stage 1', 'stage 2']);

        $response = $this->actingAs($this->fixture()->user, 'api')
            ->postJson("/api/clarion-app/llm-client/sequence-definitions/{$definition->id}/runs", ['starting_input' => ['topic' => 'Q3']])
            ->assertStatus(202);

        $run = SequenceRun::findOrFail($response->json('sequence_run_id'));
        $task = TaskBudget::where('root_conversation_id', $run->conversation_id)->firstOrFail();
        $this->assertSame(TaskBudget::SCOPE_SEQUENCE_RUN, $task->scope);
        $this->assertSame(2, $this->agentRequests(), 'stage One\'s helper 2 — then nothing, in any stage');
        $this->assertSame(2, (int) $task->steps_used);
        $this->assertStoppedByTheTaskBudget($this->delegationTo($helpers['stage-one']));
        $this->assertSame(1, Delegation::count(), 'stage Two was never started');
    }

    private function row18(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);
        [$conversation, , $helpers] = $this->delegatingRoot(['contributor-1', 'contributor-2', 'contributor-3']);
        RoleAssignment::create([
            'role' => 'judge', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->fixture()->server->id, 'model' => 'gpt-4',
        ]);
        $this->scriptHelperAnswer('Forty-two.');
        $this->scriptHelperAnswer('Forty-two.');
        $this->script()->finalAnswer(json_encode(['classification' => 'agreed', 'reconciled_answer' => 'Forty-two.', 'positions' => []]));

        $response = $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/consensus-requests', [
            'conversation_id' => $conversation->id,
            'question' => 'What is the answer?',
        ])->assertStatus(200);

        $request = ConsensusRequest::findOrFail($response->json('consensus_request_id'));
        $task = TaskBudget::where('scope', TaskBudget::SCOPE_CONSENSUS)->firstOrFail();
        $this->assertSame(2, $this->requestsInTheTree(), 'contributors 1 and 2 — contributor 3 started at the ceiling');
        $this->assertSame(2, (int) $task->steps_used, 'every contributor request claimed against the consensus request\'s task');
        $this->assertStoppedByTheTaskBudget(Delegation::where('batch_id', $request->batch_id)->where('helper_agent_id', $helpers['contributor-3']->id)->firstOrFail());
        $this->assertSame([], $this->usageOf($this->helperConversationOf($helpers['contributor-3'])->id));
    }
}
