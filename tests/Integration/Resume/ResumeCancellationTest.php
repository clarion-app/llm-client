<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\RunResumer;
use ClarionApp\LlmClient\Services\TaskResumeText;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
 * Cancellation and the resume (FR-021, lease contract §6): a cancelled or
 * deleted conversation is never resumed; a cancellation detected mid-resume
 * closes the run at the next boundary without another model call; a task
 * budget that was stopped or concluded wins over the resume; and a resume
 * never reopens a session the idle sweep ended (quickstart M13). Ending a
 * conversation stops a running task at the next step (D12).
 */
class ResumeCancellationTest extends AssembledSystemTestCase
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

    /** @return object the run row for the conversation's single run */
    private function runRowOf(Conversation $conversation): object
    {
        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderByDesc('started_at')->first();
        $this->assertNotNull($row, 'the run exists');

        return $row;
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

    /**
     * Detect the interruption: expire the lease and run the sweep, which
     * marks the run interrupted and pushes its resume job. The job is
     * returned unrun, so the test can cancel while the run is interrupted.
     */
    private function sweepAndCapture(Conversation $conversation): ResumeInterruptedRunJob
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
        $this->assertSame('interrupted', $this->runRowOf($conversation)->resume_state, 'precondition: the run is interrupted');

        return $captured;
    }

    /** The refusal a cancelled run gets: closed stopped_early, refused once, never resumed. */
    private function assertRefusedAsCancelled(Conversation $conversation, string $cause): void
    {
        $row = $this->runRowOf($conversation);
        $this->assertSame('stopped_early', $row->end_state, 'a cancelled run is closed stopped_early, never resumed');
        $this->assertSame(app(TaskResumeText::class)->cancelledReason(), $row->end_reason, 'the end reason names the cancellation');

        $refusals = DB::table('agent_run_resume_events')->where('run_id', $row->id)->where('kind', 'resume_refused_cancelled')->get();
        $this->assertCount(1, $refusals, 'one resume_refused_cancelled event is recorded');
        $this->assertSame($cause, json_decode((string) $refusals->first()->payload, true)['cause'] ?? null, 'the refusal names its cause');
        $this->assertSame(
            0,
            DB::table('agent_run_resume_events')->where('run_id', $row->id)->where('kind', 'resume_started')->count(),
            'no resume_started event for a cancelled run',
        );
        $this->assertFalse(
            (bool) Conversation::withTrashed()->whereKey($conversation->id)->value('is_processing'),
            'is_processing is false after the refusal',
        );
    }

    /**
     * Fake the host API so a ledger write also ends the conversation through
     * the real endpoint — a cancellation that lands while the step's tool is
     * running.
     */
    private function endConversationDuringTheWrite(Conversation $conversation): void
    {
        (new OperationCatalogue())->fakeHostApi([
            '/ledger/write' => function () use ($conversation) {
                $this->effectLedger->record('ledger.write');
                $this->actingAs($this->fixture()->user, 'api')
                    ->postJson('/api/clarion-app/llm-client/conversation/'.$conversation->id.'/end')
                    ->assertStatus(200);

                return Http::response(['ok' => true, 'operation' => 'ledger.write'], 200, ['Content-Type' => 'application/json']);
            },
            '*' => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);
    }

    /** A write and an effect-free listing in one step, then a reply. */
    private function scriptWriteAndListThenReply(): void
    {
        $this->script()->toolRequests([
            ['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']],
            ['id' => 'call_list', 'name' => 'list_applications', 'arguments' => []],
        ])->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);
    }

    // ---------------------------------------------------------------
    // end / delete while interrupted
    // ---------------------------------------------------------------

    #[Test]
    public function end_while_interrupted_never_resumed(): void
    {
        $this->scenario = 'cancel_end_while_interrupted';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->startAndInterrupt($conversation);
        $ledgerAfterKill = $this->effectLedger->count('ledger.write');
        $job = $this->sweepAndCapture($conversation);

        // The user ends the conversation while the run is interrupted.
        $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/conversation/'.$conversation->id.'/end')->assertStatus(200);
        $this->assertNotNull($this->runRowOf($conversation)->cancelled_at, 'ending the conversation cancels its run');

        $job->handle(app(RunResumer::class));

        $this->assertRefusedAsCancelled($conversation, 'conversation_ended');
        $this->assertSame($ledgerAfterKill, $this->effectLedger->count('ledger.write'), 'the ledger is unchanged (the effect is not re-run)');
        $this->assertSame(1, $this->modelRequests('sync'), 'no model call after the cancellation');

        // A later sweep never picks the run up again.
        Carbon::setTestNow(now()->addSeconds((int) config('llm-client.resume.detection_window_seconds', 300) * 3));
        Queue::fake([AgentStreamStepJob::class, ResumeInterruptedRunJob::class]);
        Artisan::call('llm-client:resume-interrupted-runs');
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);
    }

    #[Test]
    public function delete_while_interrupted_never_resumed(): void
    {
        $this->scenario = 'cancel_delete_while_interrupted';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->startAndInterrupt($conversation);
        $job = $this->sweepAndCapture($conversation);

        $this->actingAs($this->fixture()->user, 'api')->deleteJson('/api/clarion-app/llm-client/conversation/'.$conversation->id)->assertStatus(204);

        $job->handle(app(RunResumer::class));

        $this->assertRefusedAsCancelled($conversation, 'conversation_deleted');
        $this->assertSame(0, $this->effectLedger->count('ledger.write'), 'nothing ran');
    }

    // ---------------------------------------------------------------
    // M9 — a cancellation during the resumed step closes the run at the
    // next boundary without another model call
    // ---------------------------------------------------------------

    #[Test]
    public function resume_in_progress_stops_at_next_boundary(): void
    {
        $this->scenario = 'cancel_mid_resume_m9';
        $this->setupJourney();
        $conversation = $this->syncConversation();

        // Killed before the write started: the resume executes it.
        $this->scriptWriteAndListThenReply();
        $this->expectInterruptedRun = true;
        $this->injector->arm('before_tool_started', 1, ['agent_runs', 'agent_run_steps']);
        try {
            $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.');
            $this->fail('the fault at before_tool_started was reached');
        } catch (InjectedFault) {
            $this->injector->restore();
        }

        // The conversation is ended while the resumed step's write runs.
        $this->endConversationDuringTheWrite($conversation);
        $this->resumeRun($conversation);

        $row = $this->runRowOf($conversation);
        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the resumed step\'s write ran once');
        $this->assertSame(1, DB::table('agent_run_resume_events')->where('run_id', $row->id)->where('kind', 'resume_started')->count(), 'the resume started before the cancellation');
        $this->assertSame('stopped_early', $row->end_state, 'the next loop head closes the cancelled run');
        $this->assertSame(app(TaskResumeText::class)->cancelledReason(), $row->end_reason);
        $this->assertSame(1, $this->modelRequests('sync'), 'no model call after the cancellation is detected');
        $this->assertFalse((bool) Conversation::whereKey($conversation->id)->value('is_processing'));
    }

    // ---------------------------------------------------------------
    // A task budget that was stopped or concluded wins over the resume
    // ---------------------------------------------------------------

    #[Test]
    public function budget_stop_wins(): void
    {
        $this->scenario = 'cancel_budget_stop_wins';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->startAndInterrupt($conversation);

        // The task budget was stopped (the ceiling prompt's "stop" choice):
        // it wins over the resume.
        $taskId = Conversation::whereKey($conversation->id)->value('active_task_budget_id');
        $this->assertNotNull($taskId, 'precondition: the run has a task budget');
        DB::table('task_budgets')->where('id', $taskId)->update(['state' => 'halted']);

        $this->resumeRun($conversation);

        $this->assertRefusedAsCancelled($conversation, 'task_budget_halted');

        $this->assertSame(
            0,
            DB::table('agent_run_resume_events')->where('run_id', $this->runRowOf($conversation)->id)->where('kind', 'resume_started')->count(),
            'a stopped task budget wins over the resume: the run is never resumed',
        );
    }

    #[Test]
    public function budget_accept_wins(): void
    {
        $this->scenario = 'cancel_budget_accept_wins';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->startAndInterrupt($conversation);

        // The task budget was concluded (the ceiling prompt's "accept"
        // choice): it wins over the resume.
        $taskId = Conversation::whereKey($conversation->id)->value('active_task_budget_id');
        $this->assertNotNull($taskId, 'precondition: the run has a task budget');
        DB::table('task_budgets')->where('id', $taskId)->update(['state' => 'concluded']);

        $this->resumeRun($conversation);

        $this->assertRefusedAsCancelled($conversation, 'task_budget_concluded');

        $this->assertSame(
            0,
            DB::table('agent_run_resume_events')->where('run_id', $this->runRowOf($conversation)->id)->where('kind', 'resume_started')->count(),
            'a concluded task budget wins over the resume: the run is never resumed',
        );
    }

    // ---------------------------------------------------------------
    // M13 — a resume never reopens a session the idle sweep ended
    // ---------------------------------------------------------------

    #[Test]
    public function resume_never_reopens_session(): void
    {
        $this->scenario = 'cancel_never_reopens_session_m13';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->startAndInterrupt($conversation);

        // The idle sweep ended the session (ended_at set — not a
        // cancellation).
        $endedAt = now()->format('Y-m-d H:i:s');
        Conversation::whereKey($conversation->id)->update(['ended_at' => $endedAt]);

        $this->resumeRun($conversation);

        $actualEndedAt = Conversation::whereKey($conversation->id)->value('ended_at');
        $actualEndedAt = $actualEndedAt instanceof \Illuminate\Support\Carbon
            ? $actualEndedAt->format('Y-m-d H:i:s')
            : (string) $actualEndedAt;
        $this->assertSame(
            $endedAt,
            $actualEndedAt,
            'the resume never reopens a session the idle sweep ended (M13)',
        );
    }

    // ---------------------------------------------------------------
    // D12 — ending a conversation stops a running task at the next step
    // ---------------------------------------------------------------

    #[Test]
    public function ending_conversation_stops_a_running_task_at_next_step(): void
    {
        $this->scenario = 'cancel_end_stops_running_task_d12';
        $this->setupJourney();
        $conversation = $this->syncConversation();

        // A live run (no interruption): the conversation is ended while its
        // first step's write runs. The next step boundary stops it.
        $this->scriptWriteAndListThenReply();
        $this->expectInterruptedRun = true;
        $this->endConversationDuringTheWrite($conversation);
        $result = $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.');

        $row = $this->runRowOf($conversation);
        $this->assertSame('stopped_early', $row->end_state, 'ending the conversation stops the running task at the next step (D12)');
        $this->assertSame(app(TaskResumeText::class)->cancelledReason(), $row->end_reason);
        $this->assertSame('cancelled', $result['code'] ?? null);
        $this->assertSame(1, $this->modelRequests('sync'), 'no model call after the cancellation');
        $this->assertSame(1, $this->effectLedger->count('ledger.write'));
    }
}
