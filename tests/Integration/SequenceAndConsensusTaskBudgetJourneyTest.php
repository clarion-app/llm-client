<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\ConsensusRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\SequenceService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RequestLane;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;

/**
 * A sequence run and a consensus request are each one task, with one budget.
 *
 * A sequence run has no agent of its own that could pause in front of the
 * user, so starting one opens its task budget (nobody to ask) and its
 * dedicated conversation points at it; every stage's helper claims against
 * it. When a stage's helper is stopped by the ceiling, the sequence stops —
 * never recorded as completed — with a stage failure that names the task's
 * budget, and no later stage is ever started; a stage that would start with
 * the ceiling already reached is not started at all. A sequence that
 * finishes within its budget ends it as completed.
 *
 * A consensus request opens a task of its own too, even on a conversation
 * whose own task (an earlier request, paused at its ceiling) is still
 * waiting: the contributors claim against the consensus task only — the
 * paused task and the conversation's pointer to it are left exactly as they
 * were. Contributors stopped by the ceiling are reported as budget-stopped,
 * their partial answers kept and marked as such for the reconciliation, and
 * the consensus task ends when the request is finalized.
 */
class SequenceAndConsensusTaskBudgetJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetHelperJourneys;

    private Agent $coordinator;

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
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Sequence runs
    // ---------------------------------------------------------------

    /** Defines a three-stage sequence (One, Two, Three) and starts a run of it through its endpoint. */
    private function runThreeStageSequence(): SequenceRun
    {
        $stages = [];
        foreach (['One', 'Two', 'Three'] as $name) {
            $helper = $this->agent('stage-'.strtolower($name));
            $this->assignHelper($this->coordinator, $helper);
            $stages[] = ['name' => $name, 'helper_agent_id' => $helper->id];
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

    private function assertTheSequenceRunsOnOneBudget(SequenceRun $run): TaskBudget
    {
        $task = $this->onlyTask();
        $this->assertSame(TaskBudget::SCOPE_SEQUENCE_RUN, $task->scope);
        $this->assertTrue((bool) $task->unattended, 'nobody is present to be asked');
        $this->assertSame($run->conversation_id, $task->root_conversation_id);
        $this->assertSame($task->id, Conversation::findOrFail($run->conversation_id)->active_task_budget_id, 'the sequence\'s conversation points at it');
        foreach (Delegation::all() as $delegation) {
            $this->assertSame($task->id, Conversation::findOrFail($delegation->helper_conversation_id)->active_task_budget_id, 'every stage\'s helper works on the sequence\'s budget');
        }
        $this->assertSame($this->agentRequests(), (int) $task->steps_used, 'every stage\'s request claimed against the one budget');

        return $task;
    }

    private function assertStoppedBeforeStageThree(SequenceRun $run, TaskBudget $task): void
    {
        $run->refresh();
        $stopped = (new TaskBudgetText())->sequenceStopped();
        $this->assertSame('failed', $run->status, 'stopped, never recorded as completed');
        $this->assertSame("Stage 'Two' failed: {$stopped}", $run->failure_reason);
        $this->assertSame('completed', $this->stageResult($run, 'One')->status);
        $this->assertSame('failed', $this->stageResult($run, 'Two')->status);
        $this->assertSame($stopped, $this->stageResult($run, 'Two')->failure_reason, 'the stage failure names the task\'s budget');
        $this->assertSame('pending', $this->stageResult($run, 'Three')->status, 'the next stage never started');
        $this->assertNull($this->stageResult($run, 'Three')->delegation_id);
        $this->assertSame('ended', $task->fresh()->state->value, 'the stopped sequence ends its budget');
        $this->assertNotNull($task->fresh()->ended_at);
    }

    #[Test]
    public function a_stage_helper_stopped_by_the_ceiling_stops_the_sequence_naming_the_task_budget(): void
    {
        $this->scenario = 'task_budget_sequence_stopped_mid_stage';
        config(['llm-client.agent_loop.max_iterations' => 3]);
        // Stage One: a step, then its answer. Stage Two: a step — the last one.
        $this->scriptNoteSteps(['stage one']);
        $this->scriptHelperAnswer('Stage one is done.');
        $this->scriptNoteSteps(['stage two']);

        $run = $this->runThreeStageSequence();

        $task = $this->assertTheSequenceRunsOnOneBudget($run);
        $this->assertSame(3, $this->agentRequests());
        $this->assertSame(3, (int) $task->steps_used);
        $twoDelegation = Delegation::findOrFail($this->stageResult($run, 'Two')->delegation_id);
        $this->assertSame('task_budget', $twoDelegation->result_reason, 'stage Two\'s helper was stopped by the task\'s budget');
        $this->assertSame(2, Delegation::count(), 'no helper was started for stage Three');
        $this->assertStoppedBeforeStageThree($run, $task);
    }

    #[Test]
    public function a_stage_that_would_start_at_the_ceiling_is_never_started(): void
    {
        $this->scenario = 'task_budget_sequence_stopped_between_stages';
        config(['llm-client.agent_loop.max_iterations' => 2]);
        // Stage One uses exactly the whole budget.
        $this->scriptNoteSteps(['stage one']);
        $this->scriptHelperAnswer('Stage one is done.');

        $run = $this->runThreeStageSequence();

        $task = $this->assertTheSequenceRunsOnOneBudget($run);
        $this->assertSame(2, (int) $task->steps_used);
        $this->assertSame(1, Delegation::count(), 'stage Two was never delegated: no helper conversation, no request');
        $this->assertNull($this->stageResult($run, 'Two')->delegation_id);
        $this->assertStoppedBeforeStageThree($run, $task);
    }

    #[Test]
    public function resuming_a_sequence_stopped_by_its_budget_runs_the_rest_on_a_fresh_budget(): void
    {
        $this->scenario = 'task_budget_sequence_resumed';
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $this->scriptNoteSteps(['stage one']);
        $this->scriptHelperAnswer('Stage one is done.');
        $run = $this->runThreeStageSequence();
        $stoppedTask = $this->assertTheSequenceRunsOnOneBudget($run);
        $this->assertSame('failed', $run->fresh()->status, 'precondition: stopped at the ceiling');

        // The user resumes it: stages Two and Three run, on a budget of their own.
        $this->scriptHelperAnswer('Stage two is done.');
        $this->scriptHelperAnswer('Stage three is done.');
        $this->actingAs($this->fixture()->user, 'api')
            ->postJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}/resume")
            ->assertStatus(202);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(2, TaskBudget::count(), 'the resumed run has a budget of its own');
        $this->assertSame('ended', $stoppedTask->fresh()->state->value, 'the stopped run\'s budget stays as it ended');
        $this->assertSame(2, (int) $stoppedTask->fresh()->steps_used);
        $resumed = TaskBudget::where('id', '!=', $stoppedTask->id)->firstOrFail();
        $this->assertSame(TaskBudget::SCOPE_SEQUENCE_RUN, $resumed->scope);
        $this->assertSame($resumed->id, Conversation::findOrFail($run->conversation_id)->active_task_budget_id);
        $this->assertSame(2, (int) $resumed->steps_used, 'stages Two and Three claimed against the fresh budget');
        $this->assertSame('completed', $resumed->state->value);
        $this->assertSame(1, Delegation::whereIn('helper_conversation_id', Conversation::where('active_task_budget_id', $stoppedTask->id)->pluck('id'))->count(), 'only stage One ran on the first budget');
    }

    #[Test]
    public function a_sequence_that_finishes_within_its_budget_ends_it_as_completed(): void
    {
        $this->scenario = 'task_budget_sequence_completes';
        $this->scriptHelperAnswer('Stage one is done.');
        $this->scriptHelperAnswer('Stage two is done.');
        $this->scriptHelperAnswer('Stage three is done.');

        $run = $this->runThreeStageSequence();

        $task = $this->assertTheSequenceRunsOnOneBudget($run);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(3, (int) $task->steps_used);
        $this->assertSame('completed', $task->fresh()->state->value);
        $this->assertNotNull($task->fresh()->ended_at);
    }

    // ---------------------------------------------------------------
    // Consensus requests
    // ---------------------------------------------------------------

    #[Test]
    public function a_consensus_request_runs_on_its_own_budget_and_leaves_the_conversations_paused_task_alone(): void
    {
        $this->scenario = 'task_budget_consensus';
        $contributors = [];
        foreach (['first', 'second', 'third'] as $name) {
            $contributors[$name] = $this->agent("contributor-{$name}");
            $this->assignHelper($this->coordinator, $contributors[$name]);
        }
        RoleAssignment::create([
            'role' => 'judge', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->fixture()->server->id, 'model' => 'gpt-4',
        ]);
        $conversation = $this->rootConversation('sync', $this->coordinator);

        // An earlier request on this conversation is paused at its one-step ceiling.
        config(['llm-client.agent_loop.max_iterations' => 1]);
        $this->scriptNoteSteps(['earlier request']);
        $this->send('sync', $conversation, 'Store a note.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $paused = TaskBudget::firstOrFail();
        $this->assertSame('paused', $paused->state->value, 'precondition: the conversation\'s own task is paused');

        // The consensus task gets two steps: the first contributor answers,
        // the second takes a step and is stopped, the third is stopped before
        // its first step.
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $this->scriptHelperAnswer('The answer is 42.');
        $this->script()->steps[] = [
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Two of three sources agree on 42.', 'tool_calls' => [[
                    'id' => 'call_second_note', 'type' => 'function',
                    'function' => ['name' => 'execute_operation', 'arguments' => json_encode(['operationId' => 'storeNote', 'parameters' => ['body' => ['text' => 'second']]])],
                ], [
                    'id' => 'call_second_apps', 'type' => 'function',
                    'function' => ['name' => 'list_applications', 'arguments' => '{}'],
                ]]],
                'finish_reason' => 'tool_calls',
            ]],
        ];
        $this->script()->finalAnswer(json_encode(['classification' => 'agreed', 'reconciled_answer' => 'The answer is 42.', 'positions' => []]));

        $response = $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/consensus-requests', [
            'conversation_id' => $conversation->id,
            'question' => 'What is the answer?',
        ]);
        $response->assertStatus(200);

        // Two tasks: the paused one, untouched, and the consensus request's own.
        $this->assertSame(2, TaskBudget::count());
        $paused = TaskBudget::findOrFail($paused->id);
        $this->assertSame('paused', $paused->state->value);
        $this->assertSame(1, (int) $paused->steps_used, 'the paused task\'s consumption is unchanged');
        $this->assertSame($paused->id, Conversation::findOrFail($conversation->id)->active_task_budget_id, 'the conversation still points at its paused task');

        $consensusTask = TaskBudget::where('id', '!=', $paused->id)->firstOrFail();
        $this->assertSame(TaskBudget::SCOPE_CONSENSUS, $consensusTask->scope);
        $this->assertTrue((bool) $consensusTask->unattended);
        $this->assertSame(2, (int) $consensusTask->steps_used, 'every contributor claimed against the consensus task — the reconciliation is not an agent step');

        $request = ConsensusRequest::findOrFail($response->json('consensus_request_id'));
        $members = Delegation::where('batch_id', $request->batch_id)->get()->keyBy('helper_agent_id');
        $this->assertCount(3, $members);
        foreach ($members as $member) {
            $this->assertSame($consensusTask->id, Conversation::findOrFail($member->helper_conversation_id)->active_task_budget_id, 'every contributor works on the consensus task');
        }

        $listed = collect($this->actingAs($this->fixture()->user, 'api')
            ->getJson("/api/clarion-app/llm-client/consensus-requests/{$request->id}/contributors")
            ->assertStatus(200)
            ->json('contributors'))->keyBy('helper_agent_id');
        $this->assertNull($listed[$contributors['first']->id]['result_reason']);
        $this->assertSame('task_budget', $listed[$contributors['second']->id]['result_reason'], 'reported as stopped by the task\'s budget');
        $this->assertSame('Two of three sources agree on 42.', $listed[$contributors['second']->id]['answer'], 'its partial answer is kept');
        $this->assertSame('task_budget', $listed[$contributors['third']->id]['result_reason']);

        $stopped = (new TaskBudgetText())->consensusStopped();
        $judge = collect($this->transport()->capturedPayloadsForLane(RequestLane::AgentTurn))
            ->first(fn ($payload) => str_starts_with((string) ($payload->messages[0]['content'] ?? ''), 'You are reconciling several independent contributors'));
        $this->assertNotNull($judge, 'precondition: the answers were reconciled');
        $judged = (string) ($judge->messages[1]['content'] ?? '');
        $this->assertStringContainsString("Contributor [{$members[$contributors['second']->id]->id}]:\nTwo of three sources agree on 42. {$stopped}", $judged, 'a budget-stopped answer is marked as such');
        $this->assertStringContainsString("Contributor [{$members[$contributors['third']->id]->id}]:\n{$stopped}", $judged);
        $this->assertStringContainsString("Contributor [{$members[$contributors['first']->id]->id}]:\nThe answer is 42.", $judged);
        $this->assertStringNotContainsString("The answer is 42. {$stopped}", $judged, 'a contributor that finished is not marked');

        $this->assertSame('completed', $request->status);
        $consensusTask->refresh();
        $this->assertSame('completed', $consensusTask->state->value, 'finalizing the request ends its task');
        $this->assertNotNull($consensusTask->ended_at);
    }

    public static function contributorCounts(): array
    {
        return [
            'single contributor' => [1],
            'batch of three' => [3],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('contributorCounts')]
    public function a_consensus_request_whose_task_cannot_be_opened_is_refused_and_never_runs_on_the_conversations_task(int $count): void
    {
        $this->scenario = 'task_budget_consensus_unavailable';
        foreach (array_slice(['first', 'second', 'third'], 0, $count) as $name) {
            $this->assignHelper($this->coordinator, $this->agent("contributor-{$name}"));
        }
        $conversation = $this->rootConversation('sync', $this->coordinator);

        // An earlier request on this conversation is paused at its one-step ceiling.
        config(['llm-client.agent_loop.max_iterations' => 1]);
        $this->scriptNoteSteps(['earlier request']);
        $this->send('sync', $conversation, 'Store a note.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $paused = TaskBudget::firstOrFail();
        $this->assertSame('paused', $paused->state->value, 'precondition: the conversation\'s own task is paused');
        $requestsBefore = $this->agentRequests();
        $consensusBefore = ConsensusRequest::count();

        // The task store refuses every new task from here on (logged as a degradation).
        $this->ledger->expect("Task budget could not be opened for conversation {$conversation->id}");
        \Illuminate\Support\Facades\DB::statement("CREATE TRIGGER refuse_new_task_budgets BEFORE INSERT ON task_budgets BEGIN SELECT RAISE(ABORT, 'task store unavailable'); END");

        try {
            $response = $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'conversation_id' => $conversation->id,
                'question' => 'What is the answer?',
            ]);
        } finally {
            \Illuminate\Support\Facades\DB::statement('DROP TRIGGER IF EXISTS refuse_new_task_budgets');
        }

        $response->assertStatus(503);
        $response->assertExactJson(['error' => 'task_budget_unavailable', 'message' => (new TaskBudgetText())->unavailable()]);

        $this->assertSame($consensusBefore, ConsensusRequest::count(), 'no consensus request is recorded');
        $this->assertSame(0, Delegation::count(), 'no contributor is asked');
        $this->assertSame($requestsBefore, $this->agentRequests(), 'no model request is made');
        $this->assertSame(1, TaskBudget::count());
        $paused = TaskBudget::findOrFail($paused->id);
        $this->assertSame('paused', $paused->state->value, 'the conversation\'s paused task is untouched');
        $this->assertSame(1, (int) $paused->steps_used);
        $this->assertSame($paused->id, Conversation::findOrFail($conversation->id)->active_task_budget_id);
    }
}
