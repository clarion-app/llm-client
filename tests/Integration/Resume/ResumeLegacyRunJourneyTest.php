<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\RunResumer;
use ClarionApp\LlmClient\Services\TaskResumeText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\InterruptedRunJourneys;

/**
 * D15 — tasks in progress at deployment. Every legacy record here is made by
 * running the real loop with `resume.enabled = false` (which writes exactly
 * the pre-feature shape) and then enabling the feature and running the
 * sweep; nothing is hand-written. A record that suffices is adopted and
 * resumed; one that does not is stopped with the "started before this
 * version" report instead of being swept as abandoned.
 */
class ResumeLegacyRunJourneyTest extends AssembledSystemTestCase
{
    use InterruptedRunJourneys;

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        $this->tearDownTaskBudgetJourney();

        parent::tearDown();
    }

    private function deploy(): void
    {
        config(['llm-client.resume.enabled' => true]);
    }

    private function assertLegacyShape(object $run): void
    {
        $this->assertNull($run->checkpoint_version, 'precondition: a pre-feature record');
        $this->assertNull($run->resume_mode);
        $this->assertSame('in_progress', $run->end_state);
    }

    // ---------------------------------------------------------------
    // (a) a stream run stopped between steps
    // ---------------------------------------------------------------

    #[Test]
    public function between_steps_stream_run_is_adopted_and_resumed(): void
    {
        $this->scenario = 'us6_legacy_between_steps';
        config(['llm-client.resume.enabled' => false]);
        $this->setUpTaskBudgetJourney();
        $this->bindInjector();
        $conversation = $this->conversationFor('stream');

        $this->send('stream', $conversation, 'Write a note.')->assertStatus(201);
        // With the feature off the run has no lease, so the worker finishing
        // step 1 stops at the hand-off: the task is left between steps.
        $this->ledger->expect('AgentLoopStreamHandler:*');
        $this->playTurn(0, '', $this->stepCalls(1), self::USAGE);
        $runId = $this->latestRun($conversation)->id;
        $this->assertLegacyShape($this->latestRun($conversation));
        $this->assertSame(1, count($this->noteWrites), 'precondition: step 1 ran');
        $this->assertTrue((bool) Conversation::find($conversation->id)->is_processing);

        // Deployed; the work was lost with the old process.
        $this->deploy();
        $jobs = $this->sweep($conversation);
        $this->assertCount(1, $jobs, 'the sweep adopts the run and hands it to a job');

        $adopted = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame(1, (int) $adopted->checkpoint_version, 'adopted at checkpoint version 1');
        $this->assertSame('stream', $adopted->resume_mode, 'the mode comes from how the run was streamed');
        $this->assertSame('root', $adopted->resume_driver);
        $this->assertCount(1, $this->eventsOf($runId, 'legacy_adopted'));
        $this->assertNotNull($this->eventsOf($runId, 'legacy_adopted')[0]['payload']['assessment'] ?? null, 'the event says which rule made it sufficient');

        $jobs[0]->handle(app(RunResumer::class));

        // The next step's model call is dispatched on the new job; it ends the task.
        $this->stream()->extractDispatchedJobs();
        $this->assertSame(1, $this->stream()->turnCount(), 'step 2 is dispatched');
        $this->playTurn(0, 'All done.');
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $runId)->value('end_state'));
        $this->assertSame(1, count($this->noteWrites), 'step 1 is not repeated');
        $this->assertSame(2, DB::table('agent_run_steps')->where('run_id', $runId)->count());
    }

    // ---------------------------------------------------------------
    // (b) a sync run with only a lost model call
    // ---------------------------------------------------------------

    #[Test]
    public function sync_run_with_only_a_lost_model_call_is_adopted(): void
    {
        $this->scenario = 'us6_legacy_sync_model_call';
        config(['llm-client.resume.enabled' => false]);
        $this->setupLedgerJourney();
        $this->faultOnModelCalls();
        $conversation = $this->syncConversation();

        $this->script()->toolRequests([
            ['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']],
            ['id' => 'call_list', 'name' => 'list_applications', 'arguments' => []],
        ])->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        // Dies inside step 2's model call.
        $this->killAt(
            fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write once.'),
            'during_model_call',
            2,
        );
        $run = $this->latestRun($conversation);
        $this->assertLegacyShape($run);
        $openToolActions = DB::table('agent_run_actions')->join('agent_run_steps', 'agent_run_steps.id', '=', 'agent_run_actions.step_id')
            ->where('agent_run_steps.run_id', $run->id)->where('agent_run_steps.end_state', 'in_progress')
            ->where('agent_run_actions.action_type', 'tool_invocation')->count();
        $this->assertSame(0, $openToolActions, 'precondition: the open step has no tool action, only the lost model call');
        $this->assertSame(1, DB::table('agent_run_steps')->where('run_id', $run->id)->where('end_state', 'in_progress')->count(), 'precondition: one open step');

        $this->deploy();
        $jobs = $this->sweep($conversation);
        $this->assertCount(1, $jobs);
        $jobs[0]->handle(app(RunResumer::class));

        $done = $this->latestRun($conversation);
        $this->assertSame('completed', $done->end_state, 'the adopted run finishes');
        $this->assertSame(1, (int) $done->checkpoint_version);
        $this->assertCount(1, $this->eventsOf($run->id, 'legacy_adopted'));
        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'step 1 is not repeated');
        $started = $this->eventsOf($run->id, 'resume_started');
        $this->assertTrue($started[0]['payload']['model_call_repeated'] ?? false, 'the model call is repeated');
        $replies = Message::where('conversation_id', $conversation->id)->where('role', 'assistant')->get()
            ->filter(fn (Message $m) => empty($m->tool_data['tool_calls']) && ($m->tool_data['kind'] ?? null) === null);
        $this->assertCount(1, $replies, 'one reply');
    }

    // ---------------------------------------------------------------
    // (c) a legacy run stopped while a tool was running
    // ---------------------------------------------------------------

    #[Test]
    public function mid_tool_legacy_run_stopped(): void
    {
        $this->scenario = 'us6_legacy_mid_tool';
        config(['llm-client.resume.enabled' => false]);
        $this->setupLedgerJourney();
        $conversation = $this->syncConversation();
        $this->scriptLedgerSteps(2);

        // Dies after the write's effect, before its result was recorded.
        $this->killAt(
            fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write twice.'),
            'after_tool_effect',
            1,
        );
        $run = $this->latestRun($conversation);
        $this->assertLegacyShape($run);
        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'precondition: the effect fired once');

        $this->deploy();
        $jobs = $this->sweep($conversation);
        foreach ($jobs as $job) {
            $job->handle(app(RunResumer::class));
        }

        $stopped = $this->latestRun($conversation);
        $this->assertSame('failed', $stopped->end_state, 'stopped, not resumed');
        $this->assertSame(app(TaskResumeText::class)->couldNotResumeReason('legacy_insufficient'), $stopped->end_reason);
        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the interrupted write is not repeated');
        $events = $this->eventsOf($run->id, 'stopped_unresumable');
        $this->assertCount(1, $events);
        $this->assertSame('legacy_insufficient', $events[0]['payload']['reason_code'] ?? null);
        $this->assertSame([], $this->eventsOf($run->id, 'legacy_adopted'));
        $reports = $this->reportsOf($conversation);
        $this->assertCount(1, $reports);
        $this->assertStringContainsStringIgnoringCase('before this version', (string) $reports[0]->content);
        $this->assertReportShape((string) $reports[0]->content, 0, 'legacy report');
        $this->assertFalse((bool) Conversation::find($conversation->id)->is_processing);
        $this->assertSame([], $this->sweep($conversation), 'never resumed later');
    }

    // ---------------------------------------------------------------
    // (d) unattended roots and helpers are not adopted
    // ---------------------------------------------------------------

    #[Test]
    public function unattended_legacy_root_is_stopped(): void
    {
        $this->scenario = 'us6_legacy_unattended';
        config(['llm-client.resume.enabled' => false]);
        $this->setupLedgerJourney();
        $this->faultOnModelCalls();
        $conversation = $this->syncConversation();
        $agent = $this->agent('root');
        $conversation->update(['agent_id' => $agent->id, 'agent_version_id' => $agent->current_version_id]);
        $conversation = $conversation->fresh();
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        // Dies in its first model call: an interactive run in this state would be adopted.
        $this->killAt(
            fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Do the round.', ['unattended' => true]),
            'during_model_call',
            1,
        );
        $run = $this->latestRun($conversation);
        $this->assertLegacyShape($run);
        $this->assertSame('system_initiated', $run->kind);

        $this->deploy();
        foreach ($this->sweep($conversation) as $job) {
            $job->handle(app(RunResumer::class));
        }

        $this->assertSame('failed', $this->latestRun($conversation)->end_state);
        $events = $this->eventsOf($run->id, 'stopped_unresumable');
        $this->assertCount(1, $events);
        $this->assertSame('legacy_insufficient', $events[0]['payload']['reason_code'] ?? null);
        $this->assertSame([], $this->eventsOf($run->id, 'legacy_adopted'));
        $reports = $this->reportsOf($conversation);
        $this->assertCount(1, $reports, 'the report is kept in the history');
        $this->assertStringContainsStringIgnoringCase('before this version', (string) $reports[0]->content);
        $this->assertFalse((bool) Conversation::find($conversation->id)->is_processing);
    }

    #[Test]
    public function legacy_helper_is_stopped(): void
    {
        $this->scenario = 'us6_legacy_helper';
        config(['llm-client.resume.enabled' => false]);
        $this->setUpHelperJourney();
        $this->bindInjector();
        $this->expectInterruptedRun = true;
        $this->fixtureWithModel();
        $root = $this->agent('root-agent');
        $helper = $this->agent('solo-helper');
        $this->assignHelper($root, $helper);
        $conversation = $this->rootConversation('sync', $root);

        $injector = $this->injector;
        $this->transport()->handlerStack()->push(fn (callable $next) => function ($request, $options) use ($next, $injector) {
            if (str_contains((string) $request->getUri(), 'chat/completions')) {
                $injector->reach('during_model_call');
            }

            return $next($request, $options);
        });
        $this->script()->toolRequests([$this->delegateCall('call_solo', $helper, 'Say hello.')]);
        $this->scriptHelperAnswer('Hello.');

        // The helper dies in its first model call (the root's is the first request).
        $this->killAt(
            fn () => $this->send('sync', $conversation, 'Ask the helper.'),
            'during_model_call',
            2,
            ['agent_runs', 'agent_run_steps', 'agent_run_actions', 'agent_delegations', 'conversations'],
        );
        $helperConversation = $this->helperConversationOf($helper, $conversation->id);
        $helperRun = DB::table('agent_runs')->where('conversation_id', $helperConversation->id)->first();
        $this->assertNotNull($helperRun, 'the helper has its own run');
        $this->assertLegacyShape($helperRun);

        $this->deploy();
        foreach ($this->sweep() as $job) {
            $job->handle(app(RunResumer::class));
        }

        $stopped = DB::table('agent_runs')->where('id', $helperRun->id)->first();
        $this->assertSame('failed', $stopped->end_state, 'the helper is stopped, not left running');
        $this->assertSame(app(TaskResumeText::class)->couldNotResumeReason('legacy_insufficient'), $stopped->end_reason);
        $events = $this->eventsOf($helperRun->id, 'stopped_unresumable');
        $this->assertCount(1, $events);
        $this->assertSame('legacy_insufficient', $events[0]['payload']['reason_code'] ?? null);
        $this->assertSame([], $this->eventsOf($helperRun->id, 'legacy_adopted'));
    }

    // ---------------------------------------------------------------
    // (e) a legacy queued stream job processed after deployment
    // ---------------------------------------------------------------

    #[Test]
    public function legacy_stream_payload_adopts_the_run_at_the_next_boundary(): void
    {
        $this->scenario = 'us6_legacy_payload';
        config(['llm-client.resume.enabled' => false]);
        $this->setUpTaskBudgetJourney();
        $this->bindInjector();
        $conversation = $this->conversationFor('stream');

        // Started before deployment: step 1's request is already queued.
        $this->send('stream', $conversation, 'Write notes.')->assertStatus(201);
        $runId = $this->latestRun($conversation)->id;
        $this->assertLegacyShape($this->latestRun($conversation));
        // The old code wrote no lease and no dispatch id: the queued
        // request carries neither, and the run row holds neither.
        DB::table('agent_runs')->where('id', $runId)->update(['lease_holder' => null, 'lease_expires_at' => null, 'dispatch_id' => null]);

        // Deployed; the queued request is processed by the new code and its
        // finish() reaches the next step's boundary.
        $this->deploy();
        $this->playTurn(0, '', $this->stepCalls(1), self::USAGE);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame(1, (int) $run->checkpoint_version, 'adopted at the boundary');
        $this->assertSame('stream', $run->resume_mode);
        $this->assertSame('root', $run->resume_driver);
        $this->assertCount(1, $this->eventsOf($runId, 'legacy_adopted'));

        $pushed = Queue::pushed(AgentStreamStepJob::class);
        $this->assertCount(2, $pushed, 'the next iteration is dispatched on the new job');
        $next = $pushed->last();
        $this->assertSame(2, (int) ($next->data['iteration'] ?? 0));
        $this->assertSame((string) $run->dispatch_id, (string) ($next->data['dispatch_id'] ?? ''), 'it carries the adopted run\'s dispatch id');

        // And it runs to the end.
        $this->playTurn(1, 'All done.');
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $runId)->value('end_state'));
        $this->assertSame(1, count($this->noteWrites), 'step 1 ran once');
    }
}
