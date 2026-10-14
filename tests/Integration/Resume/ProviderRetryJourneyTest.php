<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\TaskResumeText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\ProviderRetryJourneys;

/**
 * US3 — transient provider failures are ridden through (AC1–AC4, SC-005).
 *
 * The provider is made to fail on the second model call of a task whose
 * first step has already written a note, so "nothing else is repeated" is
 * visible: the note is written exactly once however many attempts the
 * second call takes. The wait is carried by a delayed job and the run row;
 * no worker is held, the conversation stays processing, and the reply
 * arrives on the conversation's own channel.
 */
class ProviderRetryJourneyTest extends AssembledSystemTestCase
{
    use ProviderRetryJourneys;

    private const USAGE = [10, 10];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRetryJourney();
        config(['llm-client.agent_loop.max_iterations' => 6]);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** Step 1 stores a note; then the answer. */
    private function scriptOneStepThenAnswer(): void
    {
        $this->scriptSteps(1, 1, self::USAGE);
        $this->script()->finalAnswer('All done.')->usage(...self::USAGE);
    }

    // ---------------------------------------------------------------
    // AC1/AC2 — two transient failures, then success: no visible failure
    // ---------------------------------------------------------------

    #[Test]
    public function two_transient_failures_then_success_sync(): void
    {
        $this->scenario = 'provider_retry_sync_two_failures';
        $conversation = $this->conversationFor('sync');
        $this->scriptOneStepThenAnswer();
        $this->outage->failRequests([2, 3]);

        $response = $this->send('sync', $conversation, 'Store a note, then tell me when done.');

        // The first failure is not shown: the caller is told the task is
        // still working, with a 202, and nothing says anything went wrong.
        $firstDue = now()->addSeconds(10);
        $response->assertStatus(202);
        $this->assertSame('continuing', $response->json('status'));
        $this->assertSame(app(TaskResumeText::class)->continuingText($firstDue), $response->json('content'));
        $this->assertNoFailureWords((string) $response->json('content'), 'the first response');

        $run = $this->runRowFor($conversation);
        $this->assertSame('in_progress', $run->end_state);
        $this->assertSame('waiting_provider_retry', $run->resume_state);
        $this->assertTrue((bool) $conversation->fresh()->is_processing, 'the task is still running while it waits');
        $this->assertCount(1, $this->pendingJobs(), 'one delayed job carries the retry');
        $this->assertSame([], $this->telegram->textsFor($conversation->id), 'nothing is delivered for a single blip');

        // First retry: fails again (a second failure now shows a waiting
        // notice); second retry: succeeds.
        $this->assertSame(ResumeInterruptedRunJob::class, $this->workNextJob());
        $this->assertSame('waiting_provider_retry', $this->runRowFor($conversation)->resume_state);
        $this->assertCount(1, $this->retryNotices($conversation, 'provider_waiting'), 'the second failure tells the user it is waiting');
        $this->assertTrue((bool) $conversation->fresh()->is_processing, 'still running');

        $this->assertSame(ResumeInterruptedRunJob::class, $this->workNextJob());

        $run = $this->runRowFor($conversation);
        $this->assertSame('completed', $run->end_state, 'the task finishes');
        $this->assertSame(0, (int) $run->provider_attempts, 'the attempts reset on success');
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
        $this->assertCount(1, $this->noteWrites, 'the first step\'s note was written once: nothing was repeated');
        $this->assertSame(2, DB::table('agent_run_steps')->where('run_id', $run->id)->count(), 'the retries opened no extra step');
        $this->assertSame(0, (int) $run->consecutive_interruptions, 'a delayed job claiming on time is not an interruption');
        $this->assertCount(0, $this->resumeEventsOf($run->id, 'interruption_detected'));
        $this->assertCount(2, $this->resumeEventsOf($run->id, 'provider_retry_scheduled'));
        $this->assertNull($this->workNextJob(), 'no job is left');

        $this->assertContains('All done.', $this->telegram->textsFor($conversation->id), 'the answer is delivered on the channel');
        $this->assertCount(1, $this->finalReplies($conversation), 'one reply');
    }

    #[Test]
    public function two_transient_failures_then_success_stream(): void
    {
        $this->scenario = 'provider_retry_stream_two_failures';
        $conversation = $this->conversationFor('stream');
        $this->streamTurns([
            $this->turnChunks('', $this->stepCalls(1), self::USAGE),
            $this->turnChunks('All done.', [], self::USAGE),
        ]);
        $this->outage->failRequests([2, 3]);

        $this->send('stream', $conversation, 'Store a note, then tell me when done.')->assertStatus(201);

        $worked = $this->workUntilRunEnds($conversation);

        $run = $this->runRowFor($conversation);
        $this->assertGreaterThan(0, $worked);
        $this->assertSame('completed', $run->end_state, 'rejected twice, then streamed: the task completes');
        $this->assertSame(0, (int) $run->provider_attempts, 'the attempts reset');
        $this->assertSame(2, $this->outage->failures, 'the provider refused exactly twice');
        $this->assertCount(1, $this->noteWrites, 'the first step\'s note was written once');
        $this->assertSame(2, DB::table('agent_run_steps')->where('run_id', $run->id)->count(), 'no extra step');
        $this->assertCount(2, $this->resumeEventsOf($run->id, 'provider_retry_scheduled'));
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
        $replies = $this->finalReplies($conversation);
        $this->assertCount(1, $replies);
        $this->assertSame('All done.', $replies[0]->content);
    }

    // ---------------------------------------------------------------
    // A request the provider rejects is not waited for
    // ---------------------------------------------------------------

    #[Test]
    public function non_transient_not_retried(): void
    {
        $this->scenario = 'provider_retry_non_transient';
        $this->entryPath = 'sync';
        $this->expectInterruptedRun = true;
        $conversation = $this->conversationFor('sync');
        $this->scriptOneStepThenAnswer();
        $this->outage->withStatus(400)->failRequests([2]);
        $this->ledger->expect('AgentController:*');

        $response = $this->send('sync', $conversation, 'Store a note, then tell me when done.');

        $this->assertNotSame(202, $response->status(), 'a rejection is not a "still working"');
        $this->assertNotSame('continuing', $response->json('status'));
        $run = $this->runRowFor($conversation);
        $this->assertSame('failed', $run->end_state, 'the run fails as it did before');
        $this->assertNotSame('waiting_provider_retry', $run->resume_state);
        $this->assertSame(0, (int) $run->provider_attempts);
        $this->assertCount(0, $this->pendingJobs(), 'nothing is scheduled');
        $this->assertSame(0, count($this->resumeEventsOf($run->id, 'provider_retry_scheduled')));
        $this->assertCount(1, $this->noteWrites, 'the completed first step is intact');
        $this->assertSame(1, DB::table('agent_run_steps')->where('run_id', $run->id)->where('end_state', 'completed')->count());
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
    }

    // ---------------------------------------------------------------
    // AC3 — beyond the allowance the run stops with the report
    // ---------------------------------------------------------------

    #[Test]
    public function exhausted_reports(): void
    {
        $this->scenario = 'provider_retry_exhausted';
        $this->expectInterruptedRun = true;
        $conversation = $this->conversationFor('sync');
        $this->scriptOneStepThenAnswer();
        $this->outage->failRequests(range(2, 30));

        $this->send('sync', $conversation, 'Store a note, then tell me when done.')->assertStatus(202);
        $this->workUntilRunEnds($conversation);

        $text = app(TaskResumeText::class);
        $run = $this->runRowFor($conversation);
        $this->assertSame('failed', $run->end_state, 'five failed attempts end the task');
        $this->assertSame($text->providerUnavailableReason(), $run->end_reason);
        $this->assertSame(5, $this->outage->failures, 'the provider was tried five times');
        $this->assertCount(1, $this->resumeEventsOf($run->id, 'provider_retry_exhausted'));
        $this->assertSame(
            ['unavailable'],
            array_values(array_unique(array_map(fn ($e) => $e['payload']['reason'], $this->resumeEventsOf($run->id, 'provider_retry_scheduled')))),
        );
        $this->assertContains($text->providerUnavailableReport(1), $this->telegram->textsFor($conversation->id), 'the report, with the completed step count, is delivered');
        $this->assertCount(1, $this->noteWrites, 'the completed work is intact and was not repeated');
        $this->assertSame(1, DB::table('agent_run_steps')->where('run_id', $run->id)->where('end_state', 'completed')->count());
        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'the conversation is released');
        $this->assertSame([], $this->pendingJobs(), 'no further attempt is queued');
        $this->assertSame([], $this->finalReplies($conversation), 'no reply was invented');
    }

    #[Test]
    public function exhausted_stream_run_hands_the_waiting_message_on(): void
    {
        $this->scenario = 'provider_retry_exhausted_stream';
        $this->expectInterruptedRun = true;
        $conversation = $this->conversationFor('stream');
        $this->streamTurns([$this->turnChunks('', $this->stepCalls(1), self::USAGE)]);
        $this->outage->failRequests(range(2, 30));

        $this->send('stream', $conversation, 'Store a note, then tell me when done.')->assertStatus(201);
        // Step 1 runs, the next call is refused and the run waits.
        $this->workNextJob();
        $this->workNextJob();
        $this->assertSame('waiting_provider_retry', $this->runRowFor($conversation)->resume_state, 'precondition: the run is waiting');

        // The user writes again while the task waits: queued, no second run.
        $this->send('stream', $conversation, 'Also read it back.')->assertStatus(201);
        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count());

        $this->workUntilRunEnds($conversation);

        $run = $this->runRowFor($conversation);
        $this->assertSame('failed', $run->end_state);
        $this->assertSame(
            2,
            DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(),
            'the message that waited behind the failed task starts the next run',
        );
        $this->assertCount(1, $this->noteWrites);
    }

    // ---------------------------------------------------------------
    // AC4 — the wait is visible, with its next attempt
    // ---------------------------------------------------------------

    #[Test]
    public function waiting_shown_with_next_attempt(): void
    {
        $this->scenario = 'provider_retry_waiting_shown';
        $this->expectInterruptedRun = true;
        $conversation = $this->conversationFor('sync');
        $this->scriptOneStepThenAnswer();
        $this->outage->failRequests([2]);

        $this->send('sync', $conversation, 'Store a note, then tell me when done.')->assertStatus(202);

        $run = $this->runRowFor($conversation);
        $this->assertSame('waiting_provider_retry', $run->resume_state);
        $this->assertSame(
            now()->addSeconds(10)->format('Y-m-d H:i:s'),
            Carbon::parse((string) $run->next_attempt_at, 'UTC')->format('Y-m-d H:i:s'),
            'the run row carries when the next attempt is due',
        );
        // Phase 8: GET agent-runs/{id} summary `resume.state = waiting_provider_retry`
        // and `resume.next_attempt_at` (the summary block lands with the progress API).
    }

    // ---------------------------------------------------------------
    // A lost delayed job is found by the sweep
    // ---------------------------------------------------------------

    #[Test]
    public function delayed_job_lost_sweep_redrives(): void
    {
        $this->scenario = 'provider_retry_lost_delayed_job';
        $conversation = $this->conversationFor('sync');
        $this->scriptOneStepThenAnswer();
        $this->outage->failRequests([2]);

        $this->send('sync', $conversation, 'Store a note, then tell me when done.')->assertStatus(202);
        $run = $this->runRowFor($conversation);
        $oldDispatch = $run->dispatch_id;
        $oldJob = Queue::pushed(ResumeInterruptedRunJob::class)->first();
        $this->assertNotNull($oldJob, 'precondition: the delayed job was queued');

        // The queue is lost (a flushed queue, a restarted service) and the
        // retry is overdue by more than the detection window.
        Queue::fake([AgentStreamStepJob::class, ResumeInterruptedRunJob::class]);
        $this->workedJobs = [];
        Carbon::setTestNow(Carbon::parse((string) $run->next_attempt_at, 'UTC')->addSeconds(300 + 1));
        $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs'));

        $fresh = Queue::pushed(ResumeInterruptedRunJob::class);
        $this->assertCount(1, $fresh, 'the sweep re-drives the lost retry');
        $this->assertNotSame($oldDispatch, $fresh->first()->dispatchId, 'under a fresh dispatch id');
        $this->assertCount(1, $this->resumeEventsOf($run->id, 'interruption_detected'), 'a lost delayed job is a lost unit of work');
        $this->assertSame(1, (int) $this->runRowFor($conversation)->consecutive_interruptions);

        // The old job, if it ever runs, loses its claim and does nothing.
        $callsBefore = $this->outage->calls;
        $this->app->forgetScopedInstances();
        $oldJob->handle(app(\ClarionApp\LlmClient\Services\RunResumer::class));
        $this->assertSame($callsBefore, $this->outage->calls, 'the stale job made no model call');
        $this->assertSame('in_progress', $this->runRowFor($conversation)->end_state);

        // The fresh one carries the task to its end.
        $this->assertSame(ResumeInterruptedRunJob::class, $this->workNextJob());
        $this->assertSame('completed', $this->runRowFor($conversation)->end_state);
        $this->assertCount(1, $this->noteWrites, 'nothing was repeated');
        $this->assertContains('All done.', $this->telegram->textsFor($conversation->id));
    }

    // ---------------------------------------------------------------
    // Ending the conversation during the wait
    // ---------------------------------------------------------------

    #[Test]
    public function cancel_during_wait_makes_no_attempt(): void
    {
        $this->scenario = 'provider_retry_cancel_during_wait';
        $this->expectInterruptedRun = true;
        $conversation = $this->conversationFor('sync');
        $this->scriptOneStepThenAnswer();
        $this->outage->failRequests([2]);

        $this->send('sync', $conversation, 'Store a note, then tell me when done.')->assertStatus(202);
        $this->actingAs($this->fixture()->user, 'api')
            ->postJson('/api/clarion-app/llm-client/conversation/'.$conversation->id.'/end')
            ->assertStatus(200);

        $callsBefore = $this->outage->calls;
        $requestsBefore = $this->modelRequests('sync');
        $this->assertSame(ResumeInterruptedRunJob::class, $this->workNextJob());

        $run = $this->runRowFor($conversation);
        $this->assertSame($callsBefore, $this->outage->calls, 'the delayed job made no attempt');
        $this->assertSame($requestsBefore, $this->modelRequests('sync'));
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame(app(TaskResumeText::class)->cancelledReason(), $run->end_reason);
        $this->assertCount(1, $this->resumeEventsOf($run->id, 'resume_refused_cancelled'));
        $this->assertFalse((bool) Conversation::withTrashed()->whereKey($conversation->id)->value('is_processing'));
        $this->assertCount(1, $this->noteWrites);
    }

    // ---------------------------------------------------------------
    // SC-005 — a two-minute outage is ridden through
    // ---------------------------------------------------------------

    #[Test]
    public function two_minute_outage_ridden_through(): void
    {
        $this->scenario = 'provider_retry_two_minute_outage';
        $conversation = $this->conversationFor('sync');
        $this->scriptOneStepThenAnswer();
        $this->outage->failUntil(now()->addSeconds(120));

        $this->send('sync', $conversation, 'Store a note, then tell me when done.')->assertStatus(202);
        $this->workUntilRunEnds($conversation);

        $run = $this->runRowFor($conversation);
        $this->assertSame('completed', $run->end_state, 'the task completes without the user doing anything');
        $this->assertSame(3, $this->outage->failures, 'attempts at 0 s, 10 s and 40 s failed; the one at 130 s succeeded');
        $this->assertGreaterThanOrEqual(120, Carbon::parse('2026-10-12 09:00:00', 'UTC')->diffInSeconds(now()), 'the clock moved past the outage');
        $this->assertCount(1, $this->noteWrites);
        $this->assertContains('All done.', $this->telegram->textsFor($conversation->id));
    }
}
