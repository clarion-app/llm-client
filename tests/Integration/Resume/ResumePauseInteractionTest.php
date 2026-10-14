<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\RunResumer;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;
use Tests\Support\EffectLedger;
use Tests\Support\InjectedFault;
use Tests\Support\ScriptedProvider;
use Tests\Support\SnapshotFaultInjector;
use Tests\Support\TravelsPastLease;

/**
 * Pauses and the resume (FR-027): a paused run (an `ask_user` question, a
 * confirmation, or a budget ceiling) is never detected by the sweep — even
 * left for hours, with the sweep run repeatedly. A death during a pause's
 * resume (the `during_pause_resume` fault point) is resumed exactly once:
 * the answer (or the approved call's result) is applied once, and the agent
 * receives it once.
 */
class ResumePauseInteractionTest extends AssembledSystemTestCase
{
    use TaskBudgetHelperJourneys;
    use TravelsPastLease;

    private const USAGE = [10, 10];

    private EffectLedger $effectLedger;

    private ScriptedProvider $provider;

    private SnapshotFaultInjector $injector;

    /** Bind the executor with a fixed token, seed the ledger operations, bind the provider's handler stack, and bind the snapshot injector. */
    private function setupJourney(): void
    {
        // One clock for the whole journey, so a step's measured wait and the
        // run's measured duration agree across the lease-expiry travel.
        Carbon::setTestNow(now()->startOfSecond());
        $this->effectLedger = new EffectLedger();
        $this->app->singleton(
            McpToolExecutor::class,
            fn () => new McpToolExecutor($this->app->make(McpToolRegistry::class), null, fn ($user) => 'test-token-'.$user->id),
        );
        $this->app->instance(Generator::class, new class extends Generator
        {
            public function __construct()
            {
            }

            public function __invoke(?GeneratorConfig $config = null): array
            {
                return ['paths' => []];
            }
        });
        $this->provider = new ScriptedProvider($this->transport, $this->stream);
        $this->provider->seedLedgerOperations($this->effectLedger);
        $this->app->bind('llm-client.http_handler', fn () => $this->provider->handlerStack());

        $this->injector = new SnapshotFaultInjector();
        $this->app->instance(\ClarionApp\LlmClient\Contracts\FaultInjector::class, $this->injector);
    }

    private function syncConversation(): Conversation
    {
        return $this->fixture()->build()->conversation;
    }

    private function scriptWriteThenReply(): void
    {
        $this->script()
            ->toolRequests([['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']]])
            ->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);
    }

    /**
     * Start a run and interrupt it (kill at a wired point): the run is left
     * in progress, so the conversation is processing.
     */
    private function startAndInterrupt(Conversation $conversation): void
    {
        $this->scriptWriteThenReply();
        $this->expectInterruptedRun = true;
        $this->injector->arm('before_tool_started', 1, ['agent_runs', 'agent_run_steps']);
        $thrown = null;
        try {
            $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.');
        } catch (InjectedFault $e) {
            $thrown = $e;
            $this->injector->restore();
        }
        $this->assertNotNull($thrown, 'the fault at before_tool_started was reached (the run is interrupted)');
    }

    /** @return object the run row for the conversation's single run */
    private function runRowOf(Conversation $conversation): object
    {
        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderByDesc('started_at')->first();
        $this->assertNotNull($row, 'the run exists');

        return $row;
    }

    /**
     * Run the interruption sweep once (the command is Phase 5 — the red
     * reason). Returns the number of resume jobs the sweep pushed.
     */
    private function sweepOnce(): int
    {
        Carbon::setTestNow(now()->addSeconds((int) config('llm-client.resume.detection_window_seconds', 300) + 1));

        Queue::fake([AgentStreamStepJob::class, ResumeInterruptedRunJob::class]);
        $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs'), 'the sweep ran');

        return Queue::pushed(ResumeInterruptedRunJob::class)->count();
    }

    /**
     * The resume half: expire the run's lease (travel window + 1 s), run the
     * interruption sweep, and run the job the sweep pushed.
     */
    private function resumeRun(Conversation $conversation): void
    {
        $this->travelPastLease($conversation);

        Queue::fake([AgentStreamStepJob::class, ResumeInterruptedRunJob::class]);
        $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs'), 'the sweep ran');

        $captured = null;
        Queue::assertPushed(ResumeInterruptedRunJob::class, function ($job) use (&$captured) {
            $captured = $job;
            return true;
        });
        $this->assertNotNull($captured, 'the sweep pushed a resume job');

        $captured->handle(app(RunResumer::class));
    }

    // ---------------------------------------------------------------
    // Pauses are never detected (quickstart M10)
    // ---------------------------------------------------------------

    #[Test]
    public function question_pause_never_detected(): void
    {
        $this->scenario = 'pause_question_never_detected_m10';
        $this->setupJourney();
        $conversation = $this->syncConversation();

        // An ask_user pause: the run pauses on the question.
        $this->script()->toolRequests([
            ['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => ['question' => 'Which notes?', 'choices' => ['New', 'Old']]],
        ])->usage(...self::USAGE);
        $this->expectInterruptedRun = true;
        $this->app->make(AgentLoopService::class)->run($conversation, 'Which notes should I use?');

        $this->assertSame('paused', $this->runRowOf($conversation)->resume_state, 'precondition: the run is paused on the question');

        // Left for 2 hours, with the sweep run repeatedly: nothing is pushed.
        Carbon::setTestNow(now()->addHours(2));
        for ($i = 0; $i < 3; $i++) {
            $this->sweepOnce();
        }
        $this->assertSame('paused', $this->runRowOf($conversation)->resume_state, 'a question pause is never detected, even left for 2 hours (M10)');
    }

    #[Test]
    public function confirmation_pause_never_detected(): void
    {
        $this->scenario = 'pause_confirmation_never_detected';
        $this->setupJourney();

        // The host API offers a deletion, which needs the user's confirmation.
        $document = self::taskBudgetHostApiDocument();
        $document['paths']['/notes/stale'] = [
            'delete' => ['operationId' => 'clearStaleNotes', 'summary' => 'Delete every stale note', 'parameters' => [], 'responses' => ['200' => ['description' => 'Deleted']]],
        ];
        (new OperationCatalogue())->seed($document);

        $conversation = $this->syncConversation();
        $this->script()->toolRequests([
            ['id' => 'call_clear', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'clearStaleNotes', 'parameters' => []]],
        ])->usage(...self::USAGE);
        $this->expectInterruptedRun = true;
        $this->app->make(AgentLoopService::class)->run($conversation, 'Clear the stale notes.');

        $this->assertSame('paused', $this->runRowOf($conversation)->resume_state, 'precondition: the run is paused on the confirmation');

        $this->sweepOnce();
        $this->assertSame('paused', $this->runRowOf($conversation)->resume_state, 'a confirmation pause is never detected');
    }

    #[Test]
    public function ceiling_pause_never_detected(): void
    {
        $this->scenario = 'pause_ceiling_never_detected';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->startAndInterrupt($conversation);

        // A budget ceiling pause: the run is paused (resume_state = paused).
        DB::table('agent_runs')->where('conversation_id', $conversation->id)->update(['resume_state' => 'paused']);
        $this->assertSame('paused', $this->runRowOf($conversation)->resume_state, 'precondition: the run is paused at the ceiling');

        $this->sweepOnce();
        $this->assertSame('paused', $this->runRowOf($conversation)->resume_state, 'a ceiling pause is never detected');
    }

    // ---------------------------------------------------------------
    // A death during a pause's resume is resumed exactly once
    // ---------------------------------------------------------------

    #[Test]
    public function death_during_question_resume_is_resumed_once(): void
    {
        $this->scenario = 'pause_death_question_resume';
        $this->setupJourney();
        $conversation = $this->syncConversation();

        // An ask_user pause: the run pauses on the question.
        $this->script()->toolRequests([
            ['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => ['question' => 'Which notes?', 'choices' => ['New', 'Old']]],
        ])->usage(...self::USAGE);
        $this->script()->finalAnswer('Using the new notes.')->usage(...self::USAGE);
        $this->expectInterruptedRun = true;
        $this->app->make(AgentLoopService::class)->run($conversation, 'Which notes should I use?');
        $this->assertSame('paused', $this->runRowOf($conversation)->resume_state, 'precondition: the run is paused on the question');

        // The user answers; the worker carrying the continuation dies right
        // after it took the run.
        $questionId = DB::table('agent_questions')->where('conversation_id', $conversation->id)->value('id');
        $this->injector->arm('during_pause_resume', 1, ['agent_runs', 'agent_run_steps']);
        $this->ledger->expect('Injected fault at during_pause_resume (occurrence 1).');
        $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/question/'.$questionId.'/answer', ['text' => 'New']);
        $this->assertTrue($this->injector->fired(), 'the fault at during_pause_resume was reached (the continuation started)');
        $this->injector->restore();
        $this->assertSame(1, $this->modelRequests('sync'), 'precondition: the continuation made no model call');

        // The sweep re-drives the run: resumed exactly once.
        $this->resumeRun($conversation);
        $this->assertSame(0, $this->sweepOnce(), 'a later sweep resumes nothing more');

        $row = $this->runRowOf($conversation);
        $this->assertSame(1, DB::table('agent_run_resume_events')->where('run_id', $row->id)->where('kind', 'resume_started')->count(), 'the paused run is resumed exactly once');
        $this->assertSame('completed', $row->end_state);
        $this->assertSame(1, DB::table('agent_questions')->where('conversation_id', $conversation->id)->count(), 'the question is not asked again');

        // The answer is in the step's message once, and the agent receives it once.
        $answered = Message::where('conversation_id', $conversation->id)->get()->filter(
            fn (Message $m) => collect($m->tool_data['tool_results'] ?? [])->contains(fn ($r) => ($r['tool_call_id'] ?? null) === 'call_ask')
        );
        $this->assertCount(1, $answered, 'the answer appears once in the step\'s message');
        $payloads = $this->transport()->capturedChatPayloads();
        $this->assertCount(2, $payloads, 'one model call after the resume');
        $answers = 0;
        foreach (end($payloads)->messages ?? [] as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === 'call_ask') {
                $answers++;
            }
        }
        $this->assertSame(1, $answers, 'the agent receives the answer once');
    }

    #[Test]
    public function death_during_ceiling_continue_resume_is_resumed_once(): void
    {
        $this->scenario = 'pause_death_ceiling_continue_resume';
        $this->setupJourney();

        // A one-step allowance: the second step pauses at the ceiling.
        config(['llm-client.agent_loop.max_iterations' => 1]);
        $conversation = $this->syncConversation();
        $this->script()->toolRequests([
            ['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']],
            ['id' => 'call_list', 'name' => 'list_applications', 'arguments' => []],
        ])->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);
        $this->expectInterruptedRun = true;
        $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.');

        $run = $this->runRowOf($conversation);
        $this->assertSame('paused', $run->resume_state, 'precondition: the run is paused at the ceiling');
        $prompt = DB::table('agent_questions')->where('conversation_id', $conversation->id)->where('origin', 'task_budget')->first();
        $this->assertNotNull($prompt, 'precondition: the ceiling prompt waits');

        // The user answers "continue"; the worker carrying the continuation
        // dies right after it took the run.
        $this->injector->arm('during_pause_resume', 1, ['agent_runs', 'agent_run_steps']);
        $this->ledger->expect('Injected fault at during_pause_resume (occurrence 1).');
        $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/question/'.$prompt->id.'/answer', ['text' => '1']);
        $this->assertTrue($this->injector->fired(), 'the fault at during_pause_resume was reached (the continuation started)');
        $this->injector->restore();
        $this->assertSame(1, $this->effectLedger->count('ledger.write'));

        // The sweep re-drives the run: resumed exactly once, finishing it.
        $this->resumeRun($conversation);
        $this->assertSame(0, $this->sweepOnce(), 'a later sweep resumes nothing more');

        $row = $this->runRowOf($conversation);
        $this->assertSame(1, DB::table('agent_run_resume_events')->where('run_id', $run->id)->where('kind', 'resume_started')->count(), 'the run is resumed exactly once');
        $this->assertSame('completed', $row->end_state, 'the continued task finishes');
        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the step before the ceiling is not repeated');
    }

    #[Test]
    public function death_during_confirmation_approval_resume_is_resumed_once(): void
    {
        $this->scenario = 'pause_death_confirmation_approval_resume';
        $this->setupJourney();

        // The host API offers a deletion, which needs the user's confirmation.
        $document = self::taskBudgetHostApiDocument();
        $document['paths']['/notes/stale'] = [
            'delete' => ['operationId' => 'clearStaleNotes', 'summary' => 'Delete every stale note', 'parameters' => [], 'responses' => ['200' => ['description' => 'Deleted']]],
        ];
        (new OperationCatalogue())->seed($document);
        (new OperationCatalogue())->fakeHostApi([
            '/notes/stale' => function () {
                $this->effectLedger->record('clearStaleNotes');

                return \Illuminate\Support\Facades\Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']);
            },
            '*' => \Illuminate\Support\Facades\Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $conversation = $this->syncConversation();
        $this->script()->toolRequests([
            ['id' => 'call_clear', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'clearStaleNotes', 'parameters' => []]],
        ])->usage(...self::USAGE);
        $this->script()->finalAnswer('The stale notes are cleared.')->usage(...self::USAGE);
        $this->expectInterruptedRun = true;
        $this->app->make(AgentLoopService::class)->run($conversation, 'Clear the stale notes.');
        $this->assertSame('paused', $this->runRowOf($conversation)->resume_state, 'precondition: the run is paused on the confirmation');

        // Arm during_pause_resume, approve (the resume starts via
        // AgentLoopService::resume()), restore.
        $this->injector->arm('during_pause_resume', 1, ['agent_runs', 'agent_run_steps']);
        $paused = Message::where('conversation_id', $conversation->id)->get()->first(
            fn (Message $m) => is_array($m->tool_data['pending_confirmation'] ?? null)
        );
        $this->assertNotNull($paused, 'precondition: paused for the user\'s confirmation');
        $thrown = null;
        try {
            app(AgentLoopService::class)->resume(Conversation::findOrFail($conversation->id), $paused, true);
        } catch (InjectedFault $e) {
            $thrown = $e;
            $this->injector->restore();
        }

        // The sweep re-drives the paused run: resumed once, the approved
        // call's result applied once (FR-027).
        $this->resumeRun($conversation);

        $this->assertSame(
            1,
            DB::table('agent_run_resume_events')->where('run_id', $this->runRowOf($conversation)->id)->where('kind', 'resume_started')->count(),
            'the paused run is resumed exactly once',
        );
        $this->assertSame(
            1,
            $this->effectLedger->count('clearStaleNotes'),
            'the approved call\'s result is applied exactly once (FR-027)',
        );
        $this->assertSame(0, $this->sweepOnce(), 'a later sweep resumes nothing more');
        $this->assertSame('completed', $this->runRowOf($conversation)->end_state);
    }

    // ---------------------------------------------------------------
    // A resume that meets its run's own open question defers to it
    // ---------------------------------------------------------------

    /**
     * The worker died between the question's commit and the pause marker
     * that follows it: the question is open, but the run still reads as
     * working on a started `ask_user` call. A resume of that run must not
     * report the question's outcome unknown, ask again or call the model:
     * it leaves the run paused, and the user's answer continues it once.
     */
    #[Test]
    public function a_resume_that_meets_its_runs_open_question_leaves_the_run_paused_for_the_answer(): void
    {
        $this->scenario = 'pause_resume_defers_to_open_question';
        $this->setupJourney();
        $conversation = $this->syncConversation();

        $this->script()->toolRequests([
            ['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => ['question' => 'Which notes?', 'choices' => ['New', 'Old']]],
        ])->usage(...self::USAGE);
        $this->script()->finalAnswer('Using the new notes.')->usage(...self::USAGE);
        $this->expectInterruptedRun = true;
        $this->app->make(AgentLoopService::class)->run($conversation, 'Which notes should I use?');
        $run = $this->runRowOf($conversation);
        $questionId = DB::table('agent_questions')->where('conversation_id', $conversation->id)->value('id');
        $this->assertNotNull($questionId, 'precondition: the question is open');

        // What the death leaves: the call started (durable), no pause marker,
        // and the sweep's hand-off to a resume job.
        $stepId = DB::table('agent_run_steps')->where('run_id', $run->id)->where('end_state', 'in_progress')->value('id');
        $checkpoint = json_decode((string) DB::table('agent_run_steps')->where('id', $stepId)->value('checkpoint'), true);
        $checkpoint['calls']['call_ask']['state'] = 'started';
        $checkpoint['calls']['call_ask']['effect'] = 'durable';
        DB::table('agent_run_steps')->where('id', $stepId)->update(['checkpoint_state' => 'model_recorded', 'checkpoint' => json_encode($checkpoint)]);
        DB::table('agent_runs')->where('id', $run->id)->update([
            'resume_state' => 'interrupted',
            'interrupted_at' => now()->format('Y-m-d H:i:s.u'),
            'dispatch_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
            'lease_holder' => null,
            'consecutive_interruptions' => 1,
        ]);
        Conversation::whereKey($conversation->id)->update(['is_processing' => true]);

        (new ResumeInterruptedRunJob((string) $run->id, 'dddddddd-dddd-dddd-dddd-dddddddddddd'))->handle(app(RunResumer::class));

        $row = $this->runRowOf($conversation);
        $this->assertSame('in_progress', $row->end_state, 'the run is not stopped');
        $this->assertSame('paused', $row->resume_state, 'it is left paused on its question');
        $this->assertNull($row->lease_holder, 'and held by nobody');
        $this->assertFalse((bool) Conversation::whereKey($conversation->id)->value('is_processing'), 'the conversation waits for the answer');
        $this->assertSame(1, $this->modelRequests('sync'), 'no model call');
        $this->assertSame(1, DB::table('agent_questions')->where('conversation_id', $conversation->id)->count(), 'the question is not asked again');
        $this->assertSame('pending', DB::table('agent_questions')->where('id', $questionId)->value('state'));
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->get()->filter(fn (Message $m) => ($m->tool_data['notice'] ?? null) === 'outcome_unknown')->count(), 'the open question is not reported outcome-unknown');
        $this->assertSame(0, $this->sweepOnce(), 'the sweep leaves the paused run alone');

        // The user answers: the run continues once and finishes.
        $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/question/'.$questionId.'/answer', ['text' => 'New']);
        $this->assertSame('completed', $this->runRowOf($conversation)->end_state, 'the answer continues the run');
        $this->assertSame(2, $this->modelRequests('sync'), 'one model call after the answer');
    }
}
