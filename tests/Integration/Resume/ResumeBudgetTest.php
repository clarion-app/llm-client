<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Contracts\FaultInjector;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\ProviderRetryJourneys;
use Tests\Support\InjectedFault;
use Tests\Support\SnapshotFaultInjector;
use Tests\Support\TravelsPastLease;

/**
 * How a resumed or retried task is counted against its budget (FR-026,
 * research D16).
 *
 * Every model call of a task claims a step first. A call that was claimed
 * and then lost (the worker died, the provider answered nothing) belongs to
 * a step that already has its claim: repeating it claims nothing new, so a
 * task that used its last allowed step is not refused its own repeat. The
 * spend ceiling is still checked, since spend may have grown since; a lost
 * call's input is counted as an estimate, because the provider reports
 * nothing for a call that never completed; and nothing is refunded, because
 * the claim was made.
 */
class ResumeBudgetTest extends AssembledSystemTestCase
{
    use ProviderRetryJourneys;
    use TravelsPastLease;

    private const USAGE = [10, 10];

    private SnapshotFaultInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRetryJourney();
        config(['llm-client.agent_loop.max_iterations' => 10]);

        $this->injector = new SnapshotFaultInjector();
        $this->app->instance(FaultInjector::class, $this->injector);
        // The process dies while a model call is in flight: the provider
        // never answers it.
        $injector = $this->injector;
        $this->transport()->handlerStack()->push(function (callable $next) use ($injector) {
            return function ($request, $options) use ($next, $injector) {
                $body = json_decode((string) $request->getBody(), true);
                $request->getBody()->rewind();
                if (str_contains($request->getUri()->getPath(), '/chat') && is_array($body) && array_key_exists('tools', $body)) {
                    $injector->reach('during_model_call');
                }

                return $next($request, $options);
            };
        }, 'kill-during-model-call');
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** Run the task and kill the process during its $nth model call. */
    private function killDuringModelCall(Conversation $conversation, int $nth): void
    {
        $this->expectInterruptedRun = true;
        $this->injector->arm('during_model_call', $nth, ['agent_runs', 'agent_run_steps', 'agent_run_actions']);
        $thrown = null;
        try {
            $this->app->make(AgentLoopService::class)->run($conversation, 'Store the notes.');
        } catch (InjectedFault $e) {
            $thrown = $e;
            $this->injector->restore();
        }
        $this->assertNotNull($thrown, "the process died during model call {$nth}");
    }

    /** The sweep finds the dead run and its job resumes it. */
    private function sweepAndResume(Conversation $conversation): void
    {
        $this->travelPastLease($conversation);
        $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs'), 'the sweep ran');
        $this->assertNotNull($this->workNextJob(), 'the sweep pushed a resume job, which was worked');
    }

    private function stepsUsed(Conversation $conversation): int
    {
        return (int) $this->taskRowOf($conversation)->steps_used;
    }

    // ---------------------------------------------------------------
    // M11 — the last allowed step is not refused its own repeat
    // ---------------------------------------------------------------

    #[Test]
    public function last_allowed_step_not_refused_on_repeat(): void
    {
        $this->scenario = 'resume_budget_last_step_repeat';
        config(['llm-client.agent_loop.max_iterations' => 3]);
        $conversation = $this->conversationFor('sync');
        $this->scriptSteps(1, 2, self::USAGE);
        $this->script()->finalAnswer('All done.')->usage(...self::USAGE);

        // The task's third and last allowed step has its claim, and its
        // model call is lost.
        $this->killDuringModelCall($conversation, 3);
        $task = $this->taskRowOf($conversation);
        $this->assertSame(3, (int) $task->max_steps, 'precondition: three steps are allowed');
        $this->assertSame(3, (int) $task->steps_used, 'precondition: all three are used, the third by the lost call');
        $this->assertSame('running', $task->state);

        $this->sweepAndResume($conversation);

        $task = $this->taskRowOf($conversation);
        $this->assertContains($task->state, ['running', 'completed'], 'the task is not stopped at its ceiling');
        $this->assertNull($this->promptOf($conversation), 'no ceiling prompt: the repeat is not a new step');
        $this->assertSame(3, (int) $task->steps_used, 'the repeat claimed nothing');
        $run = $this->runRowFor($conversation);
        $this->assertSame('completed', $run->end_state, 'the step completes');
        $this->assertContains('All done.', $this->telegram->textsFor($conversation->id), 'the answer is delivered');
        $this->assertCount(2, $this->noteWrites, 'the two earlier steps were not repeated');
    }

    // ---------------------------------------------------------------
    // The spend ceiling still applies to a repeat
    // ---------------------------------------------------------------

    #[Test]
    public function spend_ceiling_applies_to_repeat(): void
    {
        $this->scenario = 'resume_budget_spend_ceiling_repeat';
        $this->priceTheModel();
        $conversation = $this->conversationFor('sync');
        $this->scriptSteps(1, 1, self::USAGE);
        $this->script()->finalAnswer('All done.')->usage(...self::USAGE);

        $this->killDuringModelCall($conversation, 2);
        // Step 1 cost 0.04. While the task was down its ceiling was lowered.
        DB::table('task_budgets')->where('id', $this->taskRowOf($conversation)->id)->update(['max_spend' => '0.0300000000']);
        $requestsBefore = $this->modelRequests('sync');

        $this->sweepAndResume($conversation);

        $this->assertSame($requestsBefore, $this->modelRequests('sync'), 'the repeat made no model call: the spend ceiling stopped it');
        $task = $this->taskRowOf($conversation);
        $this->assertSame('paused', $task->state, 'the root task pauses at its ceiling, as at any boundary');
        $this->assertNotNull($this->promptOf($conversation), 'and asks what to do');
        $this->assertSame('in_progress', $this->runRowFor($conversation)->end_state, 'the run is paused, not failed');
        $this->assertCount(1, $this->noteWrites);
    }

    // ---------------------------------------------------------------
    // A lost call's input is counted
    // ---------------------------------------------------------------

    #[Test]
    public function lost_call_estimated_usage_recorded(): void
    {
        $this->scenario = 'resume_budget_lost_call_estimate';
        $conversation = $this->conversationFor('sync');
        $this->scriptSteps(1, 1, self::USAGE);
        $this->script()->finalAnswer('All done.')->usage(...self::USAGE);

        $this->killDuringModelCall($conversation, 2);
        $this->assertSame(
            0,
            DB::table('usage_records')->where('conversation_id', $conversation->id)->where('input_estimated', true)->count(),
            'precondition: a call that never answered recorded nothing',
        );

        $this->sweepAndResume($conversation);

        $run = $this->runRowFor($conversation);
        $task = $this->taskRowOf($conversation);
        $estimated = DB::table('usage_records')->where('conversation_id', $conversation->id)->where('input_estimated', true)->get();
        $this->assertCount(1, $estimated, 'the lost call is counted once, as an estimate');
        $this->assertTrue((bool) $estimated[0]->output_estimated, 'its output is an estimate too');
        $this->assertSame($run->id, $estimated[0]->run_id, 'stamped with the run');
        $this->assertSame($task->id, $estimated[0]->task_budget_id, 'and charged to the task');
        $this->assertGreaterThan(0, (int) $estimated[0]->input_tokens, 'for the input the call carried');
        $this->assertSame(
            2,
            DB::table('usage_records')->where('conversation_id', $conversation->id)->where('input_estimated', false)->count(),
            'the two calls the provider answered report their own usage',
        );
    }

    // ---------------------------------------------------------------
    // Nothing is refunded
    // ---------------------------------------------------------------

    #[Test]
    public function no_refund_on_interruption(): void
    {
        $this->scenario = 'resume_budget_no_refund';
        $conversation = $this->conversationFor('sync');
        $this->scriptSteps(1, 1, self::USAGE);
        $this->script()->finalAnswer('All done.')->usage(...self::USAGE);

        $this->killDuringModelCall($conversation, 2);
        $this->assertSame(2, $this->stepsUsed($conversation), 'precondition: both claims were made, the second by the lost call');

        $this->sweepAndResume($conversation);

        $this->assertSame('completed', $this->runRowFor($conversation)->end_state);
        $this->assertSame(
            2,
            $this->stepsUsed($conversation),
            'two steps ran, two claims stand: the lost call\'s claim was neither refunded nor claimed again',
        );
    }

    // ---------------------------------------------------------------
    // A death between the claim and the step's opening
    // ---------------------------------------------------------------

    #[Test]
    public function kill_between_claim_and_openStep_overcounts_at_most_once(): void
    {
        $this->scenario = 'resume_budget_kill_between_claim_and_open_step';
        $conversation = $this->conversationFor('sync');
        $this->scriptSteps(1, 1, self::USAGE);
        $this->script()->finalAnswer('All done.')->usage(...self::USAGE);

        // Killed after step 1's message was written. The next step's claim
        // is then made and the worker dies before the step is even opened:
        // no fault point lies between those two statements, so the claim is
        // recorded the way the claiming statement leaves it.
        $this->expectInterruptedRun = true;
        $this->injector->arm('after_step_message', 1, ['agent_runs', 'agent_run_steps', 'agent_run_actions']);
        $thrown = null;
        try {
            $this->app->make(AgentLoopService::class)->run($conversation, 'Store the notes.');
        } catch (InjectedFault $e) {
            $thrown = $e;
            $this->injector->restore();
        }
        $this->assertNotNull($thrown, 'the process died after step 1');
        $this->assertSame(1, $this->stepsUsed($conversation), 'precondition: one claim, for step 1');
        DB::table('task_budgets')->where('id', $this->taskRowOf($conversation)->id)->increment('steps_used');
        $this->assertSame(2, $this->stepsUsed($conversation), 'the lost claim is on the record');

        $this->sweepAndResume($conversation);

        $this->assertSame('completed', $this->runRowFor($conversation)->end_state);
        $this->assertSame(
            3,
            $this->stepsUsed($conversation),
            'two steps ran and one claim was lost: the task is over-counted by that one claim and no more',
        );
        $this->assertCount(1, $this->noteWrites, 'step 1 was not repeated');
    }

    // ---------------------------------------------------------------
    // A provider retry is not a new step
    // ---------------------------------------------------------------

    #[Test]
    public function provider_retry_does_not_reclaim(): void
    {
        $this->scenario = 'resume_budget_provider_retry_no_reclaim';
        $conversation = $this->conversationFor('sync');
        $this->script()->finalAnswer('All done.')->usage(...self::USAGE);
        $this->outage->failRequests([1, 2]);

        $this->send('sync', $conversation, 'Say when done.')->assertStatus(202);
        $this->workUntilRunEnds($conversation);

        $this->assertSame('completed', $this->runRowFor($conversation)->end_state, 'the call succeeded on its third attempt');
        $this->assertSame(2, $this->outage->failures);
        $this->assertSame(1, $this->stepsUsed($conversation), 'one step, one claim: neither retry claimed another');
    }
}
