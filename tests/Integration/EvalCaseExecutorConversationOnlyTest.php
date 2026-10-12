<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * An eval case's graded response is what the agent produced — never a
 * message written only for the user. An eval conversation has no user, so
 * a case that meets its task budget halts and the conversation ends with
 * the halt report; that report must not be graded as the agent's answer.
 */
class EvalCaseExecutorConversationOnlyTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    #[Test]
    public function a_halt_report_is_not_graded_as_the_agents_response(): void
    {
        $this->scenario = 'eval_case_halt_report_not_graded';
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $fixture = $this->fixture()->build();
        config(['llm-client.cost.operator_user_ids' => [$fixture->user->id]]);
        RoleAssignment::create([
            'role' => 'inference', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $fixture->server->id, 'model' => 'gpt-4',
        ]);
        $api = '/api/clarion-app/llm-client/agent-eval-suites';
        $suite = $this->actingAs($fixture->user, 'api')->postJson($api, ['name' => 'Budget suite', 'agent_identifier' => 'note-keeper'])->assertStatus(200)->json();
        $this->actingAs($fixture->user, 'api')->postJson("{$api}/{$suite['id']}/cases", [
            'given' => 'Store notes.',
            'expected_behavior' => 'Stores notes.',
            'expectations' => [['kind' => 'text_match', 'expected_text' => "task's budget"]],
        ])->assertStatus(200);
        Bus::fake([RunEvalCaseJob::class]);
        $this->actingAs($fixture->user, 'api')->postJson("{$api}/{$suite['id']}/runs")->assertStatus(201);
        $this->scriptSteps(1, 2);

        foreach (Bus::dispatched(RunEvalCaseJob::class) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }

        $conversation = Conversation::where('title', 'like', 'eval-run-case:%')->firstOrFail();
        $this->assertSame('halted', $this->taskRowOf($conversation)->state, 'precondition: the case halted at its task budget');
        $report = Message::where('conversation_id', $conversation->id)->get()
            ->first(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_REPORT);
        $this->assertNotNull($report, 'precondition: the halt report is the conversation\'s last assistant message');

        $agentMessages = Message::where('conversation_id', $conversation->id)->where('role', 'assistant')->orderBy('created_at')->get()
            ->reject(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_REPORT);

        $result = DB::table('eval_case_results')->first();
        $this->assertNotNull($result);
        $this->assertSame($agentMessages->last()?->content, $result->produced_response, 'the graded response is the agent\'s own last message');
        $this->assertStringNotContainsString("task's budget", (string) $result->produced_response);
        $this->assertSame('fail', $result->outcome, 'the report\'s wording cannot satisfy an expectation');

        $attempted = json_decode((string) $result->attempted_actions, true);
        $this->assertCount(2, array_filter($attempted, fn ($a) => str_contains(json_encode($a), 'storeNote')), 'both steps\' actions are still graded');
    }
}
