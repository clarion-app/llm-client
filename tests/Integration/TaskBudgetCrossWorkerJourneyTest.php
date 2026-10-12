<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;

/**
 * A worker never decides on a figure another worker has since changed.
 *
 * A helper running as a queued batch member works in its own job, with its
 * own service graph (a fresh container scope, as a queue worker starts every
 * job with). While its step is in progress, another worker — with a service
 * graph of its own, acting for the task's agent — consumes the rest of the
 * task: the remaining steps in one case, spend past the ceiling in the
 * other. The helper's next step boundary is refused on exactly that
 * consumption, which it can only know about by reading the shared task
 * afresh: nothing it read at its previous boundary is reused. The other
 * batch member and the agent itself then make no further request either.
 */
class TaskBudgetCrossWorkerJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetHelperJourneys;

    private Agent $root;

    private Agent $worker;

    private Agent $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHelperJourney();
        $this->entryPath = 'sync';

        $this->fixtureWithModel();
        $this->root = $this->agent('root-agent');
        $this->worker = $this->agent('helper-b');
        $this->other = $this->agent('helper-q');
        $this->assignHelper($this->root, $this->worker);
        $this->assignHelper($this->root, $this->other);
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** The agent's first step delegates to B and Q at once; B's first step stores note 'b 1'. */
    private function scriptBatchWithOneWorkerStep(): Conversation
    {
        $conversation = $this->rootConversation('sync', $this->root);
        $this->script()->toolRequests([
            $this->delegateCall('call_to_b', $this->worker, 'Research the topic.'),
            $this->delegateCall('call_to_q', $this->other, 'Draft the outline.'),
        ]);
        $this->script()->toolRequests($this->noteStep('b 1'));

        return $conversation;
    }

    /** Another worker's own service graph — nothing shared with the running job's scope. */
    private function anotherWorkersGate(): TaskBudgetGate
    {
        return $this->app->build(TaskBudgetGate::class);
    }

    private function assertTheRestOfTheTreeStopped(Conversation $conversation, string $reason): void
    {
        $this->assertSame(2, $this->agentRequests(), 'the agent\'s delegating step and B\'s one step — no request after the other worker\'s consumption');

        foreach ([$this->worker, $this->other] as $helper) {
            $delegation = $this->delegationTo($helper);
            $this->assertSame('exhausted', $delegation->status);
            $this->assertSame('task_budget', $delegation->result_reason, "{$helper->name} was stopped by the task's budget");
        }
        $this->assertSame('task_budget', $this->toolResultFor($conversation, 'call_to_b')['stopped_by'] ?? null);
        $this->assertSame(1, count($this->usageOf($this->helperConversationOf($this->worker)->id)), 'B made exactly one request');
        $this->assertSame([], $this->usageOf($this->helperConversationOf($this->other)->id), 'Q never made a request');

        $row = $this->taskRow($this->onlyTask()->id);
        $this->assertSame('paused', $row->state, 'the agent paused at its next boundary');
        $this->assertSame($reason, $row->pause_reason);
    }

    #[Test]
    public function steps_taken_by_another_worker_refuse_the_helpers_next_step(): void
    {
        $this->scenario = 'task_budget_cross_worker_steps';
        config(['llm-client.agent_loop.max_iterations' => 6]);
        $conversation = $this->scriptBatchWithOneWorkerStep();

        $claimedElsewhere = 0;
        $this->noteHooks['b 1'] = function () use ($conversation, &$claimedElsewhere) {
            // Worker A, for the task's agent, takes every remaining step.
            $root = Conversation::findOrFail($conversation->id);
            $gate = $this->anotherWorkersGate();
            while ($gate->beforeStep($root, null)->isAllow()) {
                $claimedElsewhere++;
            }
        };

        $this->send('sync', $conversation, 'Research and outline the topic.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $this->assertSame(4, $claimedElsewhere, 'precondition: the other worker took steps 3–6 while B\'s step 2 was in progress');
        $this->assertSame(6, (int) $this->onlyTask()->steps_used, 'never past the ceiling');
        $this->assertTheRestOfTheTreeStopped($conversation, 'steps');
    }

    #[Test]
    public function spend_recorded_only_by_another_worker_refuses_the_helpers_next_step(): void
    {
        $this->scenario = 'task_budget_cross_worker_spend';
        config(['llm-client.agent_loop.max_iterations' => 30, 'llm-client.task_budget.default_max_spend' => '0.10']);
        $conversation = $this->scriptBatchWithOneWorkerStep();

        $this->noteHooks['b 1'] = function () use ($conversation) {
            // Worker A, for the task's agent, takes a step and records what it cost.
            $root = Conversation::findOrFail($conversation->id);
            $this->assertTrue($this->anotherWorkersGate()->beforeStep($root, null)->isAllow());
            DB::table('usage_records')->insert([
                'id' => (string) Str::uuid(),
                'conversation_id' => $root->id,
                'user_id' => (string) $root->user_id,
                'attempt_group_id' => (string) Str::uuid(),
                'task_budget_id' => $root->active_task_budget_id,
                'total_tokens' => 20,
                'total_cost' => '0.1200000000',
                'created_at' => now(),
            ]);
        };

        $this->send('sync', $conversation, 'Research and outline the topic.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $task = $this->onlyTask();
        $this->assertSame(3, (int) $task->steps_used, 'the agent\'s step, B\'s step and the other worker\'s step');
        $this->assertSame(
            0,
            bccomp('0.12', (string) DB::table('usage_records')->where('task_budget_id', $task->id)->sum('total_cost'), 10),
            'precondition: the only priced spend is the other worker\'s',
        );
        $this->assertTheRestOfTheTreeStopped($conversation, 'spend');
    }
}
