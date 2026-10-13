<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Jobs\RunDelegationBatchMemberJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;

/**
 * Helpers spend from the budget of the task they work for.
 *
 * A task's agent delegates to helpers — two at once, one of which delegates
 * further — and every step any of them takes, and every cost any of them
 * incurs, is counted once against the one task: its steps used equal the
 * model requests made across every conversation in the tree, and its spend
 * equals the sum of every usage record in the tree. No helper opens a task
 * of its own, and a helper's conversation points at the task from the
 * moment it is created.
 *
 * When the ceiling is reached while two helpers each have a step in
 * progress, both of those steps finish, neither helper starts another, the
 * steps used land exactly on the ceiling, and each helper hands back its
 * partial work marked as stopped by the task's budget. The agent that
 * delegated then makes no further model request: it pauses at its own next
 * step boundary, and the report it asks the user with lists each helper's
 * outcome.
 *
 * The agent here works through the synchronous path (a messaging channel's
 * POST agent); helpers always run synchronously, whichever path their
 * delegator is on.
 */
class TaskBudgetHelperAccountingJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetHelperJourneys;

    private const USAGE = [10, 10];

    private Agent $root;

    private Agent $a;

    private Agent $b;

    private Agent $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHelperJourney();
        $this->scenario = 'task_budget_helper_accounting';
        $this->entryPath = 'sync';

        $this->fixtureWithModel();
        $this->root = $this->agent('root-agent');
        $this->a = $this->agent('helper-a');
        $this->b = $this->agent('helper-b');
        $this->c = $this->agent('helper-c');
        $this->assignHelper($this->root, $this->a);
        $this->assignHelper($this->root, $this->b);
        $this->assignHelper($this->a, $this->c);
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** @return list<string> */
    private static function notes(string $prefix, int $from, int $to): array
    {
        return array_map(fn (int $n) => "{$prefix} {$n}", range($from, $to));
    }

    #[Test]
    public function every_step_and_every_cost_in_a_tree_of_helpers_is_counted_once_against_the_one_task(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 30]);
        $this->priceModel();
        $conversation = $this->rootConversation('sync', $this->root);

        // The agent's own ten steps, then a batch of two helpers.
        $this->scriptNoteSteps(self::notes('root', 1, 10), self::USAGE);
        $this->script()->toolRequests([
            $this->delegateCall('call_to_a', $this->a, 'Research the topic.'),
            $this->delegateCall('call_to_b', $this->b, 'Draft the outline.'),
        ])->usage(...self::USAGE);
        // Helper A delegates further, to C; C works, answers; A answers.
        $this->script()->toolRequests([$this->delegateCall('call_to_c', $this->c, 'Collect the sources.')])->usage(...self::USAGE);
        $this->scriptNoteSteps(['c 1'], self::USAGE);
        $this->scriptHelperAnswer('Sources collected.', self::USAGE);
        $this->scriptHelperAnswer('Research done.', self::USAGE);
        // Helper B works, answers.
        $this->scriptNoteSteps(['b 1'], self::USAGE);
        $this->scriptHelperAnswer('Outline drafted.', self::USAGE);
        // The agent finishes.
        $this->script()->finalAnswer('Everything is done.')->usage(...self::USAGE);

        $this->send('sync', $conversation, 'Research and outline the topic.')->assertStatus(200)->assertJson(['status' => 'completed']);

        $task = $this->onlyTask();
        $helperA = $this->helperConversationOf($this->a);
        $helperB = $this->helperConversationOf($this->b);
        $helperC = $this->helperConversationOf($this->c);

        // 12 requests by the agent, 2 by A, 2 by C, 2 by B.
        $this->assertSame(18, $this->agentRequests(), 'precondition: the scripted tree made 18 model requests');
        $this->assertSame(18, (int) $task->steps_used, 'every request in the tree claimed exactly one step of the one task');
        $this->assertSame('completed', $task->state->value, 'the task completed with its root run');

        foreach ([$conversation, $helperA, $helperB, $helperC] as $member) {
            $this->assertSame($task->id, Conversation::findOrFail($member->id)->active_task_budget_id, 'every conversation in the tree points at the one task');
        }

        $counts = [];
        foreach (['root' => $conversation, 'a' => $helperA, 'b' => $helperB, 'c' => $helperC] as $label => $member) {
            $rows = $this->usageOf($member->id);
            $counts[$label] = count($rows);
            foreach ($rows as $row) {
                $this->assertSame($task->id, $row->task_budget_id, "every usage record of {$label} is the task's");
            }
        }
        $this->assertSame(['root' => 12, 'a' => 2, 'b' => 2, 'c' => 2], $counts, 'one usage record per request, none counted twice');

        $total = (string) DB::table('usage_records')->sum('total_cost');
        $this->assertSame(0, bccomp('0.72', $total, 10), 'precondition: 18 priced requests of 0.04');
        $this->assertSame(0, bccomp('0.72', (string) DB::table('usage_records')->where('task_budget_id', $task->id)->sum('total_cost'), 10), 'the task\'s spend is every cost in the tree');
        $this->assertCount(12, $this->noteWrites, 'root 10, C 1, B 1 — nothing repeated');
    }

    #[Test]
    public function after_a_nested_helper_returns_its_delegators_next_request_is_still_counted_against_the_task(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 30]);
        $this->priceModel();
        $conversation = $this->rootConversation('sync', $this->root);

        $this->script()->toolRequests([$this->delegateCall('call_to_a', $this->a, 'Research the topic.')])->usage(...self::USAGE);
        $this->script()->toolRequests([$this->delegateCall('call_to_c', $this->c, 'Collect the sources.')])->usage(...self::USAGE);
        $this->scriptHelperAnswer('Sources collected.', self::USAGE);
        // A's next step, after C has returned.
        $this->scriptNoteSteps(['a after c'], self::USAGE);
        $this->scriptHelperAnswer('Research done.', self::USAGE);
        // The agent's next step, after A has returned.
        $this->scriptNoteSteps(['root after a'], self::USAGE);
        $this->script()->finalAnswer('Everything is done.')->usage(...self::USAGE);

        $this->send('sync', $conversation, 'Research the topic.')->assertStatus(200)->assertJson(['status' => 'completed']);

        $task = $this->onlyTask();
        $helperA = $this->helperConversationOf($this->a);

        $aRows = $this->usageOf($helperA->id);
        $this->assertCount(3, $aRows);
        $this->assertSame($task->id, $aRows[1]->task_budget_id, 'A\'s request after C returned is the task\'s');
        $this->assertSame($task->id, $aRows[2]->task_budget_id);

        $rootRows = $this->usageOf($conversation->id);
        $this->assertCount(3, $rootRows);
        $this->assertSame($task->id, $rootRows[1]->task_budget_id, 'the agent\'s request after A returned is the task\'s');
        $this->assertSame($task->id, $rootRows[2]->task_budget_id);
        $this->assertSame(0, DB::table('usage_records')->whereNull('task_budget_id')->count(), 'no request in the tree went unattributed');
        $this->assertSame(7, (int) $task->steps_used);
        $this->assertSame(0, bccomp('0.28', (string) DB::table('usage_records')->where('task_budget_id', $task->id)->sum('total_cost'), 10));
    }

    #[Test]
    public function at_the_ceiling_two_helpers_finish_the_steps_they_are_in_and_neither_starts_another(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 30]);
        $conversation = $this->rootConversation('sync', $this->root);

        // The agent uses 10 steps: nine of its own, the tenth delegates to A and B at once.
        $this->scriptNoteSteps(self::notes('root', 1, 9));
        $this->script()->toolRequests([
            $this->delegateCall('call_to_a', $this->a, 'Research the topic.'),
            $this->delegateCall('call_to_b', $this->b, 'Draft the outline.'),
        ]);
        // A takes steps 11–29. While its 19th step is in progress, B (on
        // another worker) takes step 30 — the last one.
        $this->scriptNoteSteps(self::notes('a', 1, 18));
        $this->script()->toolRequests($this->noteStep('a 19'));
        $this->script()->toolRequests($this->noteStep('b 1'));

        $bPointerWhenItStarted = 'not observed';
        $this->noteHooks['a 19'] = function () use (&$bPointerWhenItStarted) {
            $member = $this->delegationTo($this->b);
            $this->assertSame('queued', $member->status, 'precondition: B has not started yet');
            $bPointerWhenItStarted = Conversation::findOrFail($member->helper_conversation_id)->active_task_budget_id;
            RunDelegationBatchMemberJob::dispatch($member->id);
        };

        $this->send('sync', $conversation, 'Research and outline the topic.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $task = $this->onlyTask();
        $this->assertSame([], $this->noteHooks, 'precondition: B ran while A\'s 19th step was in progress');
        $this->assertSame($task->id, $bPointerWhenItStarted, 'a helper\'s conversation points at the task from the moment it is created');

        $this->assertSame(30, $this->agentRequests(), 'the agent 10, A 19, B 1 — and no request after the ceiling, anywhere');
        $this->assertSame(30, (int) $task->steps_used, 'exactly the ceiling: the two steps in progress finished, nothing more');
        $this->assertCount(29, $this->noteWrites, 'every step in progress completed its tools: the agent 9, A 19, B 1');
        $this->assertSame(['a 19', 'b 1'], [$this->noteWrites[27]['text'] ?? null, $this->noteWrites[28]['text'] ?? null], 'B took and completed step 30 while A\'s step 29 was in progress');

        $text = new TaskBudgetText();
        foreach (['call_to_a' => $this->a, 'call_to_b' => $this->b] as $toolCallId => $helper) {
            $delegation = $this->delegationTo($helper);
            $this->assertSame('exhausted', $delegation->status);
            $this->assertSame('partial', $delegation->result_status);
            $this->assertSame('task_budget', $delegation->result_reason, "{$helper->name} was stopped by the task's budget");

            $handedBack = $this->toolResultFor($conversation, $toolCallId);
            $this->assertNotNull($handedBack, "the agent received {$helper->name}'s result");
            $this->assertSame('task_budget', $handedBack['stopped_by'] ?? null);
            $this->assertSame('partial', $handedBack['status'] ?? null);
            $this->assertSame($text->helperStopped(), $handedBack['undone'] ?? null);
        }

        $row = $this->taskRow($task->id);
        $this->assertSame('paused', $row->state, 'the agent paused at its next boundary');
        $this->assertSame('steps', $row->pause_reason);
        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt, 'the user is asked');
        $this->assertStringContainsString("helper-a: stopped by this task's budget", (string) $prompt->preamble, 'the report lists each helper\'s outcome');
        $this->assertStringContainsString("helper-b: stopped by this task's budget", (string) $prompt->preamble);
        $this->assertSame(0, Conversation::where('is_processing', true)->count(), 'nothing is left processing');
        $this->assertSame(0, Delegation::whereIn('status', ['queued', 'in_progress'])->count());
    }
}
