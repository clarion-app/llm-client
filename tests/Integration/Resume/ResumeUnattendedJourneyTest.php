<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Services\ManagerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\ProviderRetryJourneys;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;
use Tests\Support\ProviderOutage;

/**
 * Unattended work (managed tasks, sequence runs, scheduler triggers) resumes
 * too. A managed task is driven round by round: each round is one queued
 * job that runs the manager's loop and then queues the next round. While a
 * round's model call is waiting on a provider retry, that round has not
 * finished — its work is carried by the delayed retry job — so no further
 * round may be queued behind it, or the next round would race the first for
 * the same manager conversation.
 */
class ResumeUnattendedJourneyTest extends AssembledSystemTestCase
{
    use ProviderRetryJourneys;
    use TaskBudgetHelperJourneys;

    private Agent $manager;

    private Agent $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHelperJourney();
        $this->armOutage();
        $this->entryPath = 'sync';

        $this->fixtureWithModel();
        $this->manager = $this->agent('manager-agent');
        $this->helper = $this->agent('part-helper');
        $this->assignHelper($this->manager, $this->helper);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** Starts a managed task through its endpoint and plans its two parts. */
    private function startManagedTask(): ManagedTask
    {
        $response = $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/managed-tasks', [
            'agent_id' => $this->manager->id,
            'request' => 'Write the two-part report.',
        ])->assertStatus(202);

        $task = ManagedTask::findOrFail($response->json('managed_task_id'));
        app(ManagerService::class)->planParts($task, ['Part one.', 'Part two.']);

        return $task;
    }

    // ---------------------------------------------------------------
    // M28 — no second round while the first waits for its provider
    // ---------------------------------------------------------------

    #[Test]
    public function no_second_round_during_retry_wait(): void
    {
        $this->scenario = 'resume_unattended_no_second_round';
        $this->expectInterruptedRun = true;
        $managed = $this->startManagedTask();
        $this->assertCount(1, Queue::pushed(RunManagedTaskStepJob::class), 'precondition: the first round is queued');
        $this->script()->finalAnswer('Part one is done.');
        $this->outage->failRequests([1]);

        // Work the first round, as its queue worker would.
        $round = Queue::pushed(RunManagedTaskStepJob::class)->first();
        $this->app->forgetScopedInstances();
        $this->app->call([$round, 'handle']);

        // The round's model call hit a transient failure: the manager's run
        // waits for its delayed retry, which is the only thing carrying it.
        $run = DB::table('agent_runs')->where('conversation_id', $managed->fresh()->conversation_id)->first();
        $this->assertNotNull($run, 'the manager\'s run exists');
        $this->assertSame('in_progress', $run->end_state, 'the run is waiting, not failed');
        $this->assertSame('waiting_provider_retry', $run->resume_state);
        $this->assertCount(1, Queue::pushed(ResumeInterruptedRunJob::class), 'a delayed job carries the retry');
        $this->assertSame(1, $this->outage->failures, 'the provider refused the round\'s call');

        // M28: no second round is queued behind the waiting one.
        $this->assertCount(
            1,
            Queue::pushed(RunManagedTaskStepJob::class),
            'a round whose run is waiting on a provider retry does not queue the next round',
        );
        $this->assertSame('in_progress', $managed->fresh()->status, 'the managed task is still in progress, not failed');
    }
}
