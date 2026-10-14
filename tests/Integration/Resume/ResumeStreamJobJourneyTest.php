<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\TaskResumeText;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\ProviderRetryJourneys;

/**
 * Streamed tasks driven through the real stream job, the way a queue worker
 * runs them: each step's job claims the run, streams, runs the step's tools
 * and hands the run on to the next step's job.
 *
 * - With resume switched off a streamed task still runs to its end: the
 *   switch stops checkpoints being written, it does not stop the task.
 * - Ending the conversation while the next step waits in the queue closes
 *   the task as cancelled when that step's job comes up, rather than
 *   leaving it in progress for the abandonment sweep.
 */
class ResumeStreamJobJourneyTest extends AssembledSystemTestCase
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

    /** Two note-storing steps, then the answer, as stream turns. */
    private function streamTwoStepsThenAnswer(): void
    {
        $this->streamTurns([
            $this->turnChunks('', $this->stepCalls(1), self::USAGE),
            $this->turnChunks('', $this->stepCalls(2), self::USAGE),
            $this->turnChunks('All done.', [], self::USAGE),
        ]);
    }

    #[Test]
    public function a_streamed_task_with_resume_switched_off_runs_to_its_end(): void
    {
        $this->scenario = 'stream_job_resume_disabled';
        config(['llm-client.resume.enabled' => false]);
        $conversation = $this->conversationFor('stream');
        $this->streamTwoStepsThenAnswer();

        $this->send('stream', $conversation, 'Store two notes, then tell me when done.')->assertStatus(201);
        $worked = $this->workUntilRunEnds($conversation);

        $run = $this->runRowFor($conversation);
        $this->assertSame(3, $worked, 'one stream job per step');
        $this->assertSame('completed', $run->end_state, 'the task runs to its end');
        $this->assertNull($run->checkpoint_version, 'and nothing was checkpointed');
        $this->assertCount(2, $this->noteWrites, 'each step\'s note was written once');
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
        $replies = $this->finalReplies($conversation);
        $this->assertCount(1, $replies);
        $this->assertSame('All done.', $replies[0]->content);
    }

    #[Test]
    public function ending_the_conversation_while_the_next_step_is_queued_closes_the_task_as_cancelled(): void
    {
        $this->scenario = 'stream_job_cancelled_while_queued';
        $this->expectInterruptedRun = true;
        $conversation = $this->conversationFor('stream');
        $this->streamTwoStepsThenAnswer();

        $this->send('stream', $conversation, 'Store two notes, then tell me when done.')->assertStatus(201);
        $this->assertSame(AgentStreamStepJob::class, $this->workNextJob(), 'step 1 streams and hands the run on');
        $this->assertCount(1, $this->noteWrites);
        $this->assertCount(1, $this->pendingJobs(), 'precondition: step 2 waits in the queue');

        $this->actingAs($this->fixture()->user, 'api')
            ->postJson('/api/clarion-app/llm-client/conversation/'.$conversation->id.'/end')
            ->assertStatus(200);
        $callsBefore = $this->outage->calls;

        $this->assertSame(AgentStreamStepJob::class, $this->workNextJob(), 'step 2\'s job comes up');

        $run = $this->runRowFor($conversation);
        $this->assertSame($callsBefore, $this->outage->calls, 'no model call after the end');
        $this->assertSame('stopped_early', $run->end_state, 'the task is closed, not left in progress');
        $this->assertSame(app(TaskResumeText::class)->cancelledReason(), $run->end_reason);
        $this->assertSame(0, DB::table('agent_run_steps')->where('run_id', $run->id)->where('end_state', 'in_progress')->count(), 'no step is left open');
        $this->assertCount(1, $this->noteWrites, 'step 2 never ran');
        $this->assertFalse((bool) Conversation::whereKey($conversation->id)->value('is_processing'));
        $this->assertNull($this->workNextJob(), 'nothing more is queued');
    }
}
