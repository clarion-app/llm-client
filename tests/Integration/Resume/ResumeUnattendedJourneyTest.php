<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
use ClarionApp\LlmClient\Jobs\RunSchedulerTriggerJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\SchedulerTrigger;
use ClarionApp\LlmClient\Models\SchedulerTriggerFiring;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ManagerService;
use ClarionApp\LlmClient\Services\RunResumer;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\HelperResumeJourneys;
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
    use HelperResumeJourneys;
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

    // ---------------------------------------------------------------
    // FR-018 / US5 AC3 — unattended work resumes, and what its caller
    // would have done on completion is still done, once
    // ---------------------------------------------------------------

    /** The first queued round of $managed, worked as its queue worker would. */
    private function workFirstRound(): void
    {
        $round = Queue::pushed(RunManagedTaskStepJob::class)->first();
        $this->assertNotNull($round, 'a round is queued');
        $this->app->forgetScopedInstances();
        $this->app->call([$round, 'handle']);
    }

    /** A managed task whose first round (one note step, then an answer) was killed after its first call was recorded. */
    private function managedRoundKilledMidRound(): ManagedTask
    {
        $this->bindInjector();
        $managed = $this->startManagedTask();
        $this->scriptSteps(1, 1);
        $this->script()->finalAnswer('Round one is done.');
        $this->killTreeAt(fn () => $this->workFirstRound(), 'after_tool_recorded', 1);

        $conversation = Conversation::findOrFail($managed->fresh()->conversation_id);
        $this->assertSame('in_progress', $this->runRowFor($conversation)->end_state, 'precondition: the round was interrupted');
        $this->assertSame(['note 1'], $this->storedNotes(), 'precondition: the first call\'s effect fired once');
        $this->assertCount(1, Queue::pushed(RunManagedTaskStepJob::class), 'precondition: no next round yet');

        return $managed;
    }

    /** The next round queued for $managed since the sweep reset the fake: exactly one. */
    private function assertOneNextRound(ManagedTask $managed): void
    {
        $next = Queue::pushed(RunManagedTaskStepJob::class);
        $this->assertCount(1, $next, 'the task\'s next round is queued exactly once');
        $this->assertSame($managed->id, $next->first()->managedTaskId);
    }

    #[Test]
    public function scheduler_firing_recorded_once_for_a_resumed_run(): void
    {
        $this->scenario = 'resume_unattended_scheduler_firing';
        $this->expectInterruptedRun = true;
        $this->bindInjector();
        $user = $this->fixture()->user;
        $yaml = "name: note-keeper\ninstructions: Keep notes.\ntools:\n  allow:\n    - storeNote\n"
            ."safety:\n  confirmation_required: []\n  unattended_authorized:\n    - storeNote\n";
        $agent = Agent::create(['user_id' => $user->id, 'name' => 'note-keeper']);
        $version = AgentVersion::create([
            'agent_id' => $agent->id, 'version_number' => 1, 'raw_definition' => $yaml, 'content_hash' => hash('sha256', $yaml),
            'source' => AgentChangeSource::Created->value, 'changed_by_user_id' => $user->id,
        ]);
        $agent->update(['current_version_id' => $version->id]);
        $trigger = SchedulerTrigger::create([
            'user_id' => $user->id, 'agent_id' => $agent->id, 'name' => 'Nightly notes',
            'kind' => SchedulerTrigger::KIND_SCHEDULE, 'schedule_expression' => '0 3 * * *',
            'defined_work' => 'Store a note.', 'retry_limit' => 0, 'is_active' => true,
        ]);
        $fireKey = 'fire-'.Str::uuid();
        SchedulerTriggerFiring::create(['trigger_id' => $trigger->id, 'fire_key' => $fireKey]);
        $this->scriptSteps(1, 1);
        $this->script()->finalAnswer('Stored.');

        // The worker dies after the first call's result was recorded: the
        // job's own bookkeeping (the firing's run id) never ran.
        $this->killTreeAt(fn () => $this->app->call([new RunSchedulerTriggerJob($trigger->id, $fireKey), 'handle']), 'after_tool_recorded', 1);

        $conversation = Conversation::where('scheduler_trigger_id', $trigger->id)->firstOrFail();
        $run = $this->runRowFor($conversation);
        $this->assertSame('in_progress', $run->end_state, 'precondition: the run was interrupted');
        $firing = fn () => SchedulerTriggerFiring::where('trigger_id', $trigger->id)->where('fire_key', $fireKey)->firstOrFail();
        $this->assertNull($firing()->run_id, 'precondition: the firing does not name the run yet');

        $updates = 0;
        DB::listen(function ($query) use (&$updates) {
            if (str_starts_with($query->sql, 'update "scheduler_trigger_firings"')) {
                $updates++;
            }
        });
        $jobs = $this->sweepFaking([RunSchedulerTriggerJob::class]);
        $this->assertCount(1, $jobs);
        $this->app->forgetScopedInstances();
        $jobs[0]->handle(app(RunResumer::class));

        $this->assertSame('completed', $this->runRowFor($conversation)->end_state, 'the run resumed in its own run');
        $this->assertSame($run->id, $this->runRowFor($conversation)->id);
        $this->assertSame(['note 1'], $this->storedNotes(), 'the recorded effect was not repeated');
        $this->assertSame($run->id, $firing()->run_id, 'the firing names the resumed run');
        $this->assertSame(1, $updates, 'the hook ran exactly once');
        $this->assertSame([], $this->sweepFaking([RunSchedulerTriggerJob::class]), 'nothing more is resumed');
        $this->assertSame(1, $updates);
    }

    #[Test]
    public function managed_round_killed_mid_round_resumes_and_continues_the_task(): void
    {
        $this->scenario = 'resume_unattended_managed_round';
        $this->expectInterruptedRun = true;
        $managed = $this->managedRoundKilledMidRound();
        $conversation = Conversation::findOrFail($managed->fresh()->conversation_id);
        $runId = $this->runRowFor($conversation)->id;
        $before = $managed->fresh()->last_progress_at;

        $jobs = $this->sweepFaking([RunManagedTaskStepJob::class]);
        $this->assertCount(1, $jobs);
        $this->app->forgetScopedInstances();
        $jobs[0]->handle(app(RunResumer::class));

        // The round finished in its own run, its note written once...
        $this->assertSame($runId, $this->runRowFor($conversation)->id);
        $this->assertSame('completed', $this->runRowFor($conversation)->end_state);
        $this->assertSame(['note 1'], $this->storedNotes());
        // ...and the rest of the round's job, which died with the worker, was done: the task moved on.
        $this->assertOneNextRound($managed);
        $this->assertNotEquals($before, $managed->fresh()->last_progress_at, 'the task\'s progress was recorded');
        $this->assertSame('in_progress', $managed->fresh()->status);

        // And nothing is done twice.
        $this->assertSame([], $this->sweepFaking([RunManagedTaskStepJob::class]));
        $this->assertCount(0, Queue::pushed(RunManagedTaskStepJob::class), 'no further round');
    }

    #[Test]
    public function stopped_unresumable_round_still_runs_its_hook_once(): void
    {
        $this->scenario = 'resume_unattended_managed_round_unresumable';
        $this->expectInterruptedRun = true;
        $managed = $this->managedRoundKilledMidRound();
        $conversation = Conversation::findOrFail($managed->fresh()->conversation_id);
        $runId = $this->runRowFor($conversation)->id;
        // Its saved progress can no longer be read.
        DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', 'in_progress')->update(['checkpoint' => '{not json']);

        $jobs = $this->sweepFaking([RunManagedTaskStepJob::class]);
        $this->assertCount(1, $jobs);
        $this->app->forgetScopedInstances();
        $jobs[0]->handle(app(RunResumer::class));

        $this->assertSame('failed', $this->runRowFor($conversation)->end_state, 'the round is stopped with a report');
        $this->assertCount(1, $this->eventsOf($runId, 'stopped_unresumable'));
        $this->assertOneNextRound($managed);
        $this->assertSame([], $this->sweepFaking([RunManagedTaskStepJob::class]));
        $this->assertCount(0, Queue::pushed(RunManagedTaskStepJob::class), 'the hook did not run again');
    }

    #[Test]
    public function round_whose_provider_retry_is_exhausted_still_runs_its_hook_once(): void
    {
        $this->scenario = 'resume_unattended_managed_round_exhausted';
        $this->expectInterruptedRun = true;
        $managed = $this->startManagedTask();
        $this->script()->finalAnswer('Never served.');
        $this->outage->failRequests(range(1, 40));
        $conversation = Conversation::findOrFail($managed->fresh()->conversation_id);

        $this->workFirstRound();
        $this->assertSame('waiting_provider_retry', $this->runRowFor($conversation)->resume_state, 'precondition: the round waits for its delayed retry');
        $this->assertCount(1, Queue::pushed(RunManagedTaskStepJob::class), 'precondition: no second round while it waits');

        // The delayed job retries until the allowance is spent.
        $this->workUntilRunEnds($conversation);

        $run = $this->runRowFor($conversation);
        $this->assertSame('failed', $run->end_state, 'the retry allowance was spent');
        $this->assertCount(1, $this->resumeEventsOf($run->id, 'provider_retry_exhausted'));
        $next = Queue::pushed(RunManagedTaskStepJob::class);
        $this->assertCount(2, $next, 'the round\'s hook queued the task\'s next round, once');
        $this->assertSame($managed->id, $next->last()->managedTaskId);
        $this->assertNull($this->workNextJob(), 'no further attempt is queued');
        $this->assertCount(2, Queue::pushed(RunManagedTaskStepJob::class));
    }

    #[Test]
    public function eval_case_run_is_not_resumed_by_this_feature(): void
    {
        $this->scenario = 'resume_unattended_eval_case';
        $this->expectInterruptedRun = true;
        $this->bindInjector();
        $conversation = $this->fixture()->conversation;
        $conversation->update(['channel' => 'telegram']);
        $conversation = $conversation->fresh();
        $this->scriptSteps(1, 1);
        $this->script()->finalAnswer('Stored.');

        // The run an eval case makes names no resume driver of the feature.
        $this->killTreeAt(
            fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Store a note.', ['_resume_driver' => 'none']),
            'after_tool_recorded',
            1,
        );
        $run = $this->runRowFor($conversation);
        $this->assertSame('in_progress', $run->end_state, 'precondition: the run was interrupted');
        $this->assertSame('none', $run->resume_driver);

        $this->assertSame([], $this->sweepFaking([]), 'the sweep hands it to no job');
        $after = $this->runRowFor($conversation);
        $this->assertSame('in_progress', $after->end_state, 'it is left to the eval and abandonment sweeps');
        $this->assertSame([], $this->eventsOf($run->id), 'nothing about it is recorded as an interruption or a resume');
        $this->assertSame(['note 1'], $this->storedNotes());
    }

    #[Test]
    public function unknown_hook_is_logged_and_ignored(): void
    {
        $this->scenario = 'resume_unattended_unknown_hook';
        $this->expectInterruptedRun = true;
        $this->bindInjector();
        $conversation = $this->fixture()->conversation;
        $conversation->update(['channel' => 'telegram']);
        $conversation = $conversation->fresh();
        $this->scriptSteps(1, 1);
        $this->script()->finalAnswer('Stored.');

        $this->killTreeAt(
            fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Store a note.', ['on_complete' => ['hook' => 'no_such_hook', 'subject' => 'x']]),
            'after_tool_recorded',
            1,
        );
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $jobs = $this->sweepFaking([]);
        $this->assertCount(1, $jobs);
        $this->app->forgetScopedInstances();
        $jobs[0]->handle(app(RunResumer::class));

        $this->assertSame('completed', $this->runRowFor($conversation)->end_state, 'the run still finished: an unknown hook is not an error');
        $this->assertNotEmpty(array_filter($logged, fn (string $line) => str_contains($line, 'no_such_hook')), 'the unknown hook name is logged');
        $this->assertSame(['note 1'], $this->storedNotes());
    }
}
