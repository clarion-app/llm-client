<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\QuestionAnswerIntake;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * Every pause leaves the run resumable, and every resume carries on under a
 * fresh claim.
 *
 * An `ask_user` pause (on the synchronous and the streamed path), a
 * confirmation pause (a streamed `start()` whose operation needs the user's
 * approval), and a task-budget ceiling pause (`TaskBudgetPauser`) each leave
 * the run `resume_state = paused`, its lease released, and a step
 * `checkpoint_state = paused` with the paused call `paused` in the step's
 * checkpoint (the ceiling pauses at the loop head, before a model request, so
 * its paused step carries no call).
 *
 * Answering the question (`QuestionResumer::resume`), continuing the ceiling
 * (routed to `TaskBudgetResumer`) and approving the confirmation
 * (`AgentLoopService::resume`) each resume the same run under a fresh claim:
 * a new dispatch id and a held lease while the continuation runs. The run
 * ends with the resume columns cleared.
 *
 * The continuation is scripted to pause a second time before it ends, so the
 * fresh dispatch id is observable: the resumed run's dispatch id differs from
 * the one it paused under, and the run is paused again (its lease released at
 * the second pause). The held lease is the transient state between the
 * resume's claim and the next pause or close; its observable effect is the
 * continuation running at all, which the step-claim gate admits only under a
 * held lease.
 */
class RunCheckpointPauseWiringTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    private const CLEAR = 'clearStaleNotes';

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

    /** @return object the run row for the conversation's single run */
    private function runRowOf(Conversation $conversation): object
    {
        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderByDesc('started_at')->first();
        $this->assertNotNull($row, 'the run exists');

        return $row;
    }

    /** The run is paused: its state is paused, its lease released, and a step is marked paused. */
    private function assertPaused(Conversation $conversation, ?string $callId = null): void
    {
        $run = $this->runRowOf($conversation);
        $this->assertSame('paused', $run->resume_state, 'the run is paused');
        $this->assertNull($run->lease_holder, 'the lease is released at the pause');
        $this->assertNotNull($run->dispatch_id, 'the run keeps its dispatch id while paused');

        // The just-paused step is the one still open: an earlier pause's
        // step stays checkpoint_state = paused too (it is closed, not
        // reset, when its resume opens a fresh step).
        $paused = DB::table('agent_run_steps')->where('run_id', $run->id)->where('checkpoint_state', 'paused')->where('end_state', 'in_progress')->first();
        $this->assertNotNull($paused, 'a step is marked paused');
        if ($callId !== null) {
            $checkpoint = json_decode((string) $paused->checkpoint, true);
            $this->assertSame('paused', $checkpoint['calls'][$callId]['state'] ?? null, "the paused call {$callId} is marked paused");
        }
    }

    /** The run is terminal: its resume columns are cleared. */
    private function assertCleared(Conversation $conversation): void
    {
        $run = $this->runRowOf($conversation);
        $this->assertNull($run->resume_state, 'the resume state is cleared at close');
        $this->assertNull($run->dispatch_id, 'the dispatch id is cleared at close');
        $this->assertNull($run->lease_holder, 'the lease is cleared at close');
    }

    /** Record $raw as the answer to the conversation's pending question. */
    private function answer(Conversation $conversation, string $raw): AgentQuestion
    {
        $intake = app(QuestionAnswerIntake::class)->accept($conversation->fresh(), (string) $this->fixture()->user->id, $raw);
        $this->assertSame('answered', $intake->kind, 'the answer is recorded');

        return $intake->question;
    }

    /** Resume the conversation's pending question through the one-time claim. */
    private function resumeQuestion(AgentQuestion $question): void
    {
        $this->app->forgetScopedInstances();
        app(QuestionResumer::class)->resume($question->id, ResumeCaller::HttpRequest);
    }

    // ---------------------------------------------------------------
    // ask_user — the synchronous and the streamed path
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function askUserPaths(): array
    {
        return ['sync' => ['sync'], 'stream' => ['stream']];
    }

    #[Test]
    #[DataProvider('askUserPaths')]
    public function an_ask_user_pause_pauses_and_resumes_and_closes_clean(string $path): void
    {
        $this->scenario = 'pause_wiring_ask_user_'.$path;
        $this->entryPath = $path;
        $conversation = $this->conversationFor($path);

        // Two questions, then the reply: the run pauses at each question.
        $ask = fn (string $id, string $q) => ['id' => $id, 'name' => 'ask_user', 'arguments' => ['question' => $q, 'choices' => ['New', 'Old']]];
        if ($path === 'sync') {
            $this->script()->toolRequests([$ask('call_ask_1', 'Which notes?')]);
            $this->script()->toolRequests([$ask('call_ask_2', 'And then?')]);
            $this->script()->finalAnswer('Done.');
            $this->send('sync', $conversation, 'Ask me, then ask me again.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        } else {
            $this->send('stream', $conversation, 'Ask me, then ask me again.')->assertStatus(201);
            $this->playTurn(0, '', [$ask('call_ask_1', 'Which notes?')]);
        }

        $this->assertPaused($conversation, 'call_ask_1');
        $firstDispatch = $this->runRowOf($conversation)->dispatch_id;
        $this->assertNotNull($firstDispatch, 'precondition: the run paused under a dispatch id');
        $question1 = AgentQuestion::where('conversation_id', $conversation->id)->firstOrFail();

        // Answer the first question: the continuation runs to the second.
        $this->answer($conversation, '1');
        $this->resumeQuestion($question1);

        if ($path === 'stream') {
            $this->playTurn(1, '', [$ask('call_ask_2', 'And then?')]);
        }

        // The resumed run is paused again, under a fresh dispatch id.
        $this->assertPaused($conversation, 'call_ask_2');
        $secondDispatch = $this->runRowOf($conversation)->dispatch_id;
        $this->assertNotSame($firstDispatch, $secondDispatch, 'the resume minted a fresh dispatch id');
        $question2 = AgentQuestion::where('conversation_id', $conversation->id)->where('id', '!=', $question1->id)->firstOrFail();

        // Answer the second question: the continuation runs to the reply and ends.
        $this->answer($conversation, '1');
        $this->resumeQuestion($question2);

        if ($path === 'stream') {
            $this->playTurn(2, 'Done.');
        }

        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), 'one run, continued');
        $this->assertSame('completed', DB::table('agent_runs')->where('conversation_id', $conversation->id)->value('end_state'));
        $this->assertCleared($conversation);
    }

    // ---------------------------------------------------------------
    // confirmation — a streamed start() whose operation needs approval
    // ---------------------------------------------------------------

    #[Test]
    public function a_confirmation_pause_pauses_and_resumes_and_closes_clean(): void
    {
        $this->scenario = 'pause_wiring_confirmation_stream';
        $this->entryPath = 'stream';

        // The host API offers a deletion, which needs the user's confirmation.
        $document = self::taskBudgetHostApiDocument();
        $document['paths']['/notes/stale'] = [
            'delete' => ['operationId' => self::CLEAR, 'summary' => 'Delete every stale note', 'parameters' => [], 'responses' => ['200' => ['description' => 'Deleted']]],
        ];
        (new OperationCatalogue())->seed($document);

        $conversation = $this->conversationFor('stream');
        $clear = ['id' => 'call_clear', 'name' => 'execute_operation', 'arguments' => ['operationId' => self::CLEAR, 'parameters' => []]];

        $this->send('stream', $conversation, 'Clear the stale notes.')->assertStatus(201);
        $this->playTurn(0, '', [$clear]);

        $this->assertPaused($conversation, 'call_clear');
        $firstDispatch = $this->runRowOf($conversation)->dispatch_id;
        $this->assertNotNull($firstDispatch, 'precondition: the run paused under a dispatch id');
        $paused = Message::where('conversation_id', $conversation->id)->get()->first(fn (Message $m) => is_array($m->tool_data['pending_confirmation'] ?? null));
        $this->assertNotNull($paused, 'precondition: paused for the user\'s confirmation');

        // Approve: the continuation runs to a second confirmation.
        app(AgentLoopService::class)->resume(Conversation::findOrFail($conversation->id), $paused, true);
        $this->playTurn(1, '', [$clear]);

        // The resumed run is paused again, under a fresh dispatch id.
        $this->assertPaused($conversation, 'call_clear');
        $secondDispatch = $this->runRowOf($conversation)->dispatch_id;
        $this->assertNotSame($firstDispatch, $secondDispatch, 'the resume minted a fresh dispatch id');
        $paused2 = Message::where('conversation_id', $conversation->id)->get()->first(fn (Message $m) => is_array($m->tool_data['pending_confirmation'] ?? null));
        $this->assertNotNull($paused2, 'precondition: paused again for the user\'s confirmation');

        // Approve again: the continuation runs to the reply and ends.
        app(AgentLoopService::class)->resume(Conversation::findOrFail($conversation->id), $paused2, true);
        $this->playTurn(2, 'Done.');

        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), 'one run, continued');
        $this->assertSame('completed', DB::table('agent_runs')->where('conversation_id', $conversation->id)->value('end_state'));
        $this->assertCleared($conversation);
    }

    // ---------------------------------------------------------------
    // task-budget ceiling — TaskBudgetPauser
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function ceilingPaths(): array
    {
        return ['sync' => ['sync'], 'stream' => ['stream']];
    }

    #[Test]
    #[DataProvider('ceilingPaths')]
    public function a_task_budget_ceiling_pause_pauses_and_resumes_and_closes_clean(string $path): void
    {
        $this->scenario = 'pause_wiring_ceiling_'.$path;
        $this->entryPath = $path;
        $conversation = $this->conversationFor($path);

        // The default three-step ceiling: the run pauses at step 3.
        if ($path === 'sync') {
            $this->scriptSteps(1, 3);
            $this->send('sync', $conversation, 'Store notes until you are done.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        } else {
            $this->send('stream', $conversation, 'Store notes until you are done.')->assertStatus(201);
            $this->playSteps(1, 3);
        }

        $this->assertPaused($conversation);
        $firstDispatch = $this->runRowOf($conversation)->dispatch_id;
        $this->assertNotNull($firstDispatch, 'precondition: the run paused under a dispatch id');
        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt, 'precondition: the ceiling prompt is raised');

        // Continue: the ceiling is raised by the original three steps, and the
        // continuation runs steps 4-6, pausing again at the new ceiling.
        $this->answer($conversation, 'continue');
        if ($path === 'sync') {
            $this->scriptSteps(4, 6);
        }
        $this->resumeQuestion($prompt);

        if ($path === 'stream') {
            $this->playSteps(4, 6);
        }

        // The resumed run is paused again, under a fresh dispatch id.
        $this->assertPaused($conversation);
        $secondDispatch = $this->runRowOf($conversation)->dispatch_id;
        $this->assertNotSame($firstDispatch, $secondDispatch, 'the resume minted a fresh dispatch id');
        $second = $this->promptOf($conversation);
        $this->assertNotSame($prompt->id, $second->id, 'a new prompt for the new ceiling');

        // Continue again: the continuation runs to the reply and ends.
        $this->answer($conversation, 'continue');
        if ($path === 'sync') {
            $this->script()->finalAnswer('All notes stored.');
        }
        $this->resumeQuestion($second);

        if ($path === 'stream') {
            $this->playTurn(6, 'All notes stored.');
        }

        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), 'one run, continued');
        $this->assertSame('completed', DB::table('agent_runs')->where('conversation_id', $conversation->id)->value('end_state'));
        $this->assertCleared($conversation);
    }
}
