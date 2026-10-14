<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Jobs\RunSequenceStageJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Services\SequenceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\HelperResumeJourneys;

/**
 * A sequence stage whose helper was interrupted resumes instead of starting
 * over. Today the stage job resets a stage it finds `running` to `pending`
 * and runs it again from the top, which repeats whatever its helper already
 * did; a stage whose delegation has a resumable helper run is resumed in
 * that run, with what the helper completed left alone. A `running` stage
 * with no delegation behind it has nothing to resume and still restarts.
 */
class RunSequenceStageResumeTest extends AssembledSystemTestCase
{
    use HelperResumeJourneys;

    private Agent $coordinator;

    /** @var array<string, string> stage name => the task text its helper is given */
    private array $stageTasks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHelperJourney();
        $this->entryPath = 'sync';
        $this->fixtureWithModel();
        $this->coordinator = $this->agent('coordinator');
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** Defines a two-stage sequence (One, Two), each stage's helper served by its task, and starts a run of it. */
    private function startSequence(): SequenceRun
    {
        $stages = [];
        foreach (['One', 'Two'] as $name) {
            $helper = $this->agent('stage-'.strtolower($name));
            $this->assignHelper($this->coordinator, $helper);
            $stages[] = ['name' => $name, 'helper_agent_id' => $helper->id];
            $task = "Execute stage \"{$name}\" of an automated sequence pipeline, using the input provided below.";
            $this->stageTasks[$name] = $task;
            $this->serveHelperTask($task, "{$name} note", "{$name} done.");
        }
        $definition = app(SequenceService::class)->defineSequence($this->fixture()->user->id, 'Report pipeline', null, $this->coordinator->id, $stages);

        $response = $this->actingAs($this->fixture()->user, 'api')
            ->postJson("/api/clarion-app/llm-client/sequence-definitions/{$definition->id}/runs", ['starting_input' => ['topic' => 'Q3']])
            ->assertStatus(202);

        return SequenceRun::findOrFail($response->json('sequence_run_id'));
    }

    private function stageResult(SequenceRun $run, string $name): StageResult
    {
        $stage = Stage::where('sequence_definition_id', $run->sequence_definition_id)->where('name', $name)->firstOrFail();

        return StageResult::where('sequence_run_id', $run->id)->where('stage_id', $stage->id)->firstOrFail();
    }

    private function helperDelegation(string $stageName): ?Delegation
    {
        return Delegation::where('task', $this->stageTasks[$stageName])->first();
    }

    // ---------------------------------------------------------------
    // M20 — a running stage with a resumable helper run is resumed
    // ---------------------------------------------------------------

    #[Test]
    public function a_running_stage_with_a_resumable_helper_run_resumes_instead_of_restarting(): void
    {
        $this->scenario = 'resume_sequence_stage_resumes';
        $this->expectInterruptedRun = true;
        $this->bindInjector();
        // The first stage job runs inline; the follow-on stage jobs are only observed.
        Queue::fake([ResumeInterruptedRunJob::class]);

        // Stage One's helper dies right after its first tool result was
        // recorded; the stage job dies with it.
        $run = null;
        $this->killTreeAt(function () use (&$run) {
            $run = $this->startSequence();
        }, 'after_tool_recorded', 1);
        $run ??= SequenceRun::firstOrFail();

        $this->assertSame('running', $this->stageResult($run, 'One')->status, 'precondition: the stage was running');
        $this->assertSame('pending', $this->stageResult($run, 'Two')->status);
        $delegation = $this->helperDelegation('One');
        $this->assertNotNull($delegation, 'precondition: the stage had a delegation');
        $this->assertSame('in_progress', $delegation->status);
        $helperRuns = $this->runsOfConversation($delegation->helper_conversation_id);
        $this->assertCount(1, $helperRuns);
        $this->assertSame('in_progress', $helperRuns[0]->end_state);
        $this->assertSame(['One note'], $this->storedNotes(), 'precondition: the helper\'s effect fired once');

        // The sweep hands the stage's run to its stage job, once.
        $jobs = $this->sweepFaking([RunSequenceStageJob::class]);
        $this->assertSame([], $jobs, 'a stage helper is not resumed by a resume job of its own');
        $stageJobs = Queue::pushed(RunSequenceStageJob::class);
        $this->assertCount(1, $stageJobs, 'one stage job is dispatched');
        $stageJob = $stageJobs->first();
        $this->assertSame($run->id, $stageJob->sequenceRunId);
        $this->assertSame('interrupted', DB::table('agent_runs')->where('id', $helperRuns[0]->id)->value('resume_state'), 'the helper run was claimed by the sweep');

        // A minute later, with that job still waiting, the sweep finds nothing to do.
        \Illuminate\Support\Carbon::setTestNow(now()->addMinute());
        Queue::fake([ResumeInterruptedRunJob::class, RunSequenceStageJob::class]);
        $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs'));
        Queue::assertNotPushed(RunSequenceStageJob::class);
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);

        // The stage job resumes the stage in its helper's own run.
        $this->app->forgetScopedInstances();
        $this->app->call([$stageJob, 'handle']);

        $one = $this->stageResult($run, 'One');
        $this->assertSame('completed', $one->status, 'the stage completed');
        $this->assertSame($delegation->id, $one->delegation_id, 'by the delegation it already had');
        $this->assertSame(1, Delegation::where('task', $this->stageTasks['One'])->count(), 'no second delegation was made for it');
        $this->assertCount(1, $this->runsOfConversation($delegation->helper_conversation_id), 'the helper kept its run');
        $this->assertSame('completed', $this->runsOfConversation($delegation->helper_conversation_id)[0]->end_state);
        $this->assertSame(['One note'], $this->storedNotes(), 'what the helper had completed was not repeated');
        $this->assertSame('[]', (string) $one->output, 'the stage\'s output is its helper\'s (an empty object, as an uninterrupted stage stores it)');

        // The pipeline carries on: stage Two is dispatched and runs.
        $next = Queue::pushed(RunSequenceStageJob::class);
        $this->assertCount(1, $next, 'the next stage is dispatched');
        $this->app->forgetScopedInstances();
        $this->app->call([$next->first(), 'handle']);
        $this->assertSame('completed', $this->stageResult($run, 'Two')->status);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(['One note', 'Two note'], $this->storedNotes(), 'each stage\'s effect fired once');
    }

    // ---------------------------------------------------------------
    // A running stage with nothing to resume restarts, as it always did
    // ---------------------------------------------------------------

    #[Test]
    public function a_running_stage_with_no_delegation_is_reset_and_run_again(): void
    {
        $this->scenario = 'resume_sequence_stage_no_delegation';
        Queue::fake([RunSequenceStageJob::class]);
        $run = $this->startSequence();
        $first = Queue::pushed(RunSequenceStageJob::class)->first();
        $this->assertNotNull($first, 'precondition: the first stage job is queued');

        // The previous worker marked stage One running and died before it
        // made a delegation.
        $one = $this->stageResult($run, 'One');
        $one->status = 'running';
        $one->started_at = now()->subMinutes(30);
        $one->save();
        $this->assertSame(0, Delegation::count(), 'precondition: no delegation exists');

        $this->app->forgetScopedInstances();
        $this->app->call([$first, 'handle']);

        $one->refresh();
        $this->assertSame('completed', $one->status, 'the stage ran again from the top');
        $this->assertNotNull($one->delegation_id);
        $this->assertSame(1, Delegation::where('task', $this->stageTasks['One'])->count(), 'with a delegation of its own');
        $this->assertSame(['One note'], $this->storedNotes());
        $this->assertCount(2, Queue::pushed(RunSequenceStageJob::class), 'and the pipeline moved on to stage Two');
        $this->assertSame('pending', $this->stageResult($run, 'Two')->status);
    }
}
