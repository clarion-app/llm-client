<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\RunResumer;
use ClarionApp\LlmClient\Services\TaskResumeText;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\InterruptedRunJourneys;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\Support\InjectedFault;

/**
 * US6 AC1-AC2, FR-019 — a task that is killed at the same point on every
 * attempt is resumed twice and then stopped with a report; it is never
 * resumed again, the conversation is free, and a step that completes resets
 * the count (quickstart M8). Takeovers count toward the bound like sweeps.
 */
class ResumeBoundJourneyTest extends AssembledSystemTestCase
{
    use InterruptedRunJourneys;

    private RecordingChannelDelivery $tgDelivery;

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    private function startJourney(): Conversation
    {
        $this->setupLedgerJourney();
        $this->tgDelivery = new RecordingChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->tgDelivery);
        $conversation = $this->syncConversation();
        $conversation->update(['channel' => 'telegram']);

        return $conversation->fresh();
    }

    /** Resume through a queued job, the worker dying at the same point again. */
    private function resumeAndDie(ResumeInterruptedRunJob $job, string $point, int $occurrence): void
    {
        $this->killAt(
            fn () => app(RunResumer::class)->resume($job->runId, $job->dispatchId, ResumeCaller::Background),
            $point,
            $occurrence,
        );
    }

    private function runStep5Task(Conversation $conversation): void
    {
        // Killed at step 5's first write: steps 1-4 reach the point twice each.
        $this->scriptLedgerSteps(6);
        $this->killAt(
            fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write six entries.'),
            'before_tool_started',
            9,
        );
        $this->assertSame(4, $this->effectLedger->count('ledger.write'), 'precondition: four completed steps');
    }

    // ---------------------------------------------------------------
    // AC1 — the third interruption stops the task with a report
    // ---------------------------------------------------------------

    #[Test]
    public function three_interruptions_at_the_same_step_stop_the_task_with_a_report(): void
    {
        $this->scenario = 'us6_bound';
        $conversation = $this->startJourney();
        $this->runStep5Task($conversation);
        $runId = $this->latestRun($conversation)->id;

        // Two automatic resumes, each dying at the same step.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $jobs = $this->sweep($conversation);
            $this->assertCount(1, $jobs, "interruption {$attempt} is detected and resumed");
            $this->assertSame($attempt, (int) $this->latestRun($conversation)->consecutive_interruptions);
            $this->resumeAndDie($jobs[0], 'before_tool_started', 1);
            $this->assertSame('in_progress', $this->latestRun($conversation)->end_state, 'still in progress after resume '.$attempt);
        }

        // The third interruption is detected: the task is stopped, not resumed.
        $jobs = $this->sweep($conversation);
        $this->assertCount(1, $jobs, 'the sweep still hands the run to a job (which stops it)');
        $jobs[0]->handle(app(RunResumer::class));

        $run = $this->latestRun($conversation);
        $text = app(TaskResumeText::class);
        $this->assertSame('failed', $run->end_state, 'the task stops as failed');
        $this->assertSame($text->couldNotResumeReason('repeated_interruption'), $run->end_reason);
        $this->assertSame(3, (int) $run->consecutive_interruptions);
        $this->assertSame(4, $this->effectLedger->count('ledger.write'), 'step 5 is never executed');
        $this->assertSame(0, DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', 'in_progress')->count(), 'the open step is closed');

        $stopped = $this->eventsOf($runId, 'stopped_unresumable');
        $this->assertCount(1, $stopped, 'one stopped_unresumable event');
        $this->assertSame('repeated_interruption', $stopped[0]['payload']['reason_code'] ?? null);
        $this->assertSame(4, $stopped[0]['payload']['completed_steps'] ?? null);

        // The report names the step it stopped at and what was completed.
        $reports = $this->reportsOf($conversation);
        $this->assertCount(1, $reports, 'one report');
        $this->assertStringContainsStringIgnoringCase('step 5', (string) $reports[0]->content);
        $this->assertReportShape((string) $reports[0]->content, 4, 'the bound report');
        $this->assertContains((string) $reports[0]->content, array_map(fn (array $d) => $d['text'], $this->tgDelivery->deliveries), 'the report reaches the channel');

        // The conversation is free.
        $this->assertFalse((bool) Conversation::find($conversation->id)->is_processing);

        // Never resumed again.
        $this->assertSame([], $this->sweep($conversation), 'a later sweep pushes nothing');
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);

        // A new request is accepted.
        $this->resetScriptCursor();
        $this->script()->finalAnswer('A fresh answer.')->usage(...self::USAGE);
        $response = $this->send('sync', $conversation, 'Try something else.');
        $response->assertStatus(200);
        $this->assertStringContainsString('A fresh answer.', (string) $response->json('content'));
    }

    // ---------------------------------------------------------------
    // M8 — a completed step resets the count
    // ---------------------------------------------------------------

    #[Test]
    public function completed_step_resets_count(): void
    {
        $this->scenario = 'us6_bound_reset';
        $conversation = $this->startJourney();

        // Killed at step 3's first write (steps 1-2 reach the point twice each).
        $this->scriptLedgerSteps(4);
        $this->killAt(
            fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write four entries.'),
            'before_tool_started',
            5,
        );
        $runId = $this->latestRun($conversation)->id;

        // Interrupted a second time at the same step; a third detection would stop it.
        $jobs = $this->sweep($conversation);
        $this->resumeAndDie($jobs[0], 'before_tool_started', 1);
        $this->assertSame(1, (int) $this->latestRun($conversation)->consecutive_interruptions);

        // The third attempt (second resume) completes step 3 and dies at step 4's first write.
        $jobs = $this->sweep($conversation);
        $this->assertSame(2, (int) $this->latestRun($conversation)->consecutive_interruptions);
        $this->resumeAndDie($jobs[0], 'before_tool_started', 3);
        $this->assertSame(0, (int) $this->latestRun($conversation)->consecutive_interruptions, 'the completed step reset the count');

        // Two more interruptions: still resumed, never stopped.
        $jobs = $this->sweep($conversation);
        $this->assertSame(1, (int) $this->latestRun($conversation)->consecutive_interruptions);
        $this->resumeAndDie($jobs[0], 'before_tool_started', 1);
        $jobs = $this->sweep($conversation);
        $this->assertSame(2, (int) $this->latestRun($conversation)->consecutive_interruptions);
        $jobs[0]->handle(app(RunResumer::class));

        $run = $this->latestRun($conversation);
        $this->assertSame('completed', $run->end_state, 'the task is resumed to the end');
        $this->assertSame([], $this->eventsOf($runId, 'stopped_unresumable'));
        $this->assertSame(4, $this->effectLedger->count('ledger.write'), 'each step wrote once');
    }

    // ---------------------------------------------------------------
    // Takeovers count toward the bound
    // ---------------------------------------------------------------

    #[Test]
    public function takeovers_count_toward_the_bound(): void
    {
        $this->scenario = 'us6_bound_takeovers';
        $conversation = $this->startJourney();
        $this->runStep5Task($conversation);
        $runId = $this->latestRun($conversation)->id;

        // Interruption 1: a redelivered job claims the expired, still-held lease.
        $this->travelPastLease($conversation);
        $dispatch = (string) $this->latestRun($conversation)->dispatch_id;
        $this->killAt(
            fn () => app(RunResumer::class)->resume($runId, $dispatch, ResumeCaller::Background),
            'before_tool_started',
            1,
        );
        $this->assertSame(1, (int) $this->latestRun($conversation)->consecutive_interruptions, 'the takeover is counted once');

        // Interruption 2: the sweep.
        $jobs = $this->sweep($conversation);
        $this->assertSame(2, (int) $this->latestRun($conversation)->consecutive_interruptions);
        $this->resumeAndDie($jobs[0], 'before_tool_started', 1);
        $this->assertSame('in_progress', $this->latestRun($conversation)->end_state, 'two interruptions do not stop the task');

        // Interruption 3: another takeover reaches the bound.
        $this->travelPastLease($conversation);
        $dispatch = (string) $this->latestRun($conversation)->dispatch_id;
        app(RunResumer::class)->resume($runId, $dispatch, ResumeCaller::Background);

        $run = $this->latestRun($conversation);
        $this->assertSame('failed', $run->end_state, 'the third interruption stops the task');
        $this->assertSame(3, (int) $run->consecutive_interruptions, 'no interruption is counted twice');
        $stopped = $this->eventsOf($runId, 'stopped_unresumable');
        $this->assertCount(1, $stopped);
        $this->assertSame('repeated_interruption', $stopped[0]['payload']['reason_code'] ?? null);
        $this->assertSame(4, $this->effectLedger->count('ledger.write'));
    }

    private function resetScriptCursor(): void
    {
        $this->script->steps = [];
        $this->script->cursor = 0;
    }
}
