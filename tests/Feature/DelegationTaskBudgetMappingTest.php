<?php

namespace Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\CapabilityOfferingService;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a delegating agent is handed back when the helper it delegated to is
 * stopped by the task's budget.
 *
 * A helper that meets the ceiling at a step boundary ends its own run with
 * the code `task_budget_reached`. The delegation is then a bounded
 * non-completion, like a helper that ran out of its own iterations or time:
 * exhausted, a partial result, the helper's last words kept as the summary —
 * but it says *why* it stopped (the task's budget, not the helper's own
 * bound), so the delegating agent and anyone reading the record can tell the
 * two apart. The run record marks the delegation unfinished, never failed.
 *
 * The helper's run itself is scripted here (its return value is the agent
 * loop's own budget-stopped shape); everything from the delegation row to
 * the run-trace action is the real code. The two existing bounded
 * non-completions keep their exact wording.
 */
class DelegationTaskBudgetMappingTest extends TestCase
{
    private User $user;

    private Server $server;

    private ?string $runId = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['llm-client.run_trace.enabled' => true]);

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();
        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');
    }

    protected function tearDown(): void
    {
        Context::forget('run_id');
        $this->clearOperationCatalog();
        Mockery::close();

        foreach (['capability_offerings', 'agent_delegations', 'messages', 'agent_run_actions', 'agent_run_steps', 'agent_runs', 'task_budget_events', 'task_budgets', 'usage_records', 'agent_helper_assignments', 'llm_role_assignments', 'conversations', 'agent_versions', 'agents', 'llm_servers', 'users'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    /**
     * A delegating conversation with a run and an open step, so the
     * delegation is recorded as an action of that step.
     *
     * @return array{0: Agent, 1: Agent, 2: Conversation}
     */
    private function delegatingConversation(string $suffix): array
    {
        $parent = $this->makeAgent("parent-{$suffix}");
        $helper = $this->makeAgent("helper-{$suffix}");
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helper->id);

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Already titled',
            'agent_id' => $parent->id,
            'agent_version_id' => $parent->current_version_id,
        ]);

        $recorder = app(RunTraceRecorder::class);
        $this->runId = $recorder->openRun(RunKind::Interactive, (string) $this->user->id, $conversation->id, streamed: false);
        $recorder->openStep($this->runId);

        return [$parent, $helper, $conversation];
    }

    /**
     * The helper's run, as the agent loop ends it: the helper writes
     * $partial as its last assistant text (when given) and the run returns
     * $result.
     */
    private function helperRunReturns(array $result, ?string $partial): void
    {
        $loop = Mockery::mock(AgentLoopService::class);
        $loop->shouldReceive('run')->once()->andReturnUsing(function (Conversation $helperConversation) use ($result, $partial) {
            if ($partial !== null) {
                Message::create([
                    'conversation_id' => $helperConversation->id,
                    'role' => 'assistant',
                    'content' => $partial,
                ]);
            }

            return $result;
        });
        $this->app->instance(AgentLoopService::class, $loop);
    }

    private static function budgetStopped(): array
    {
        return ['status' => 'error', 'code' => 'task_budget_reached', 'content' => 'Task budget ceiling reached', 'message_id' => null];
    }

    private function actionOf(Delegation $delegation): object
    {
        $action = DB::table('agent_run_actions')->where('id', $delegation->parent_action_id)->first();
        $this->assertNotNull($action, 'the delegation is recorded as an action of the delegating step');

        return $action;
    }

    // ---------------------------------------------------------------
    // A helper stopped by the task's budget
    // ---------------------------------------------------------------

    #[Test]
    public function a_helper_stopped_by_the_task_budget_hands_back_a_partial_result_marked_as_stopped_by_it(): void
    {
        [, $helper, $conversation] = $this->delegatingConversation('budget');
        $this->helperRunReturns(self::budgetStopped(), 'Half of the report is drafted: sections one and two.');

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Draft the report.', null);

        $text = new TaskBudgetText();
        $this->assertSame(
            ['delegation_id', 'helper', 'status', 'summary', 'output', 'undone', 'truncated', 'reason', 'stopped_by'],
            array_keys($result),
            'the delegate_to_helper result keeps its eight keys and adds stopped_by',
        );
        $this->assertSame('partial', $result['status']);
        $this->assertSame('Half of the report is drafted: sections one and two.', $result['summary']);
        $this->assertNull($result['output']);
        $this->assertSame($text->helperStopped(), $result['undone']);
        $this->assertFalse($result['truncated']);
        $this->assertSame('task_budget', $result['reason']);
        $this->assertSame('task_budget', $result['stopped_by']);

        $delegation = Delegation::findOrFail($result['delegation_id']);
        $this->assertSame('exhausted', $delegation->status);
        $this->assertSame('partial', $delegation->result_status);
        $this->assertSame('task_budget', $delegation->result_reason);
        $this->assertSame('Half of the report is drafted: sections one and two.', $delegation->result_summary);
        $this->assertSame($text->helperStopped(), $delegation->result_undone);
        $this->assertNull($delegation->result_output, 'the partial work is the summary; there is no validated output');
        $this->assertFalse((bool) $delegation->result_truncated);
        $this->assertNotNull($delegation->completed_at);

        $action = $this->actionOf($delegation);
        $this->assertSame('unfinished', $action->outcome, 'stopped by the budget is unfinished work, not a failure');
        $content = json_decode((string) $action->content, true);
        $this->assertSame('partial', $content['status'] ?? null);
        $this->assertSame('task_budget', $content['reason'] ?? null);
        $this->assertSame('task_budget', $content['stopped_by'] ?? null);
        $this->assertSame($text->helperStopped(), $content['undone'] ?? null);
    }

    #[Test]
    public function the_partial_summary_is_the_helpers_last_text_cut_to_five_hundred_characters(): void
    {
        [, $helper, $conversation] = $this->delegatingConversation('long');
        $long = str_repeat('word ', 200);
        $this->helperRunReturns(self::budgetStopped(), $long);

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Draft the report.', null);

        $this->assertSame(Str::limit($long, 500), $result['summary']);
        $this->assertSame(Str::limit($long, 500), Delegation::findOrFail($result['delegation_id'])->result_summary);
    }

    #[Test]
    public function a_helper_stopped_before_it_wrote_anything_hands_back_an_empty_partial(): void
    {
        [, $helper, $conversation] = $this->delegatingConversation('empty');
        $this->helperRunReturns(self::budgetStopped(), null);

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Draft the report.', null);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('', $result['summary']);
        $this->assertSame('task_budget', $result['stopped_by']);
        $this->assertSame('exhausted', Delegation::findOrFail($result['delegation_id'])->status);
    }

    // ---------------------------------------------------------------
    // The helper's own bounds keep their exact outcome
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function ownBounds(): array
    {
        return [
            'its own iteration limit' => ['max_iterations', 'Reached its iteration_limit before finishing.'],
            'its own time limit' => ['time_ceiling_reached', 'Reached its time_limit before finishing.'],
        ];
    }

    #[Test]
    #[DataProvider('ownBounds')]
    public function a_helper_stopped_by_its_own_bound_is_reported_exactly_as_before(string $code, string $undone): void
    {
        [, $helper, $conversation] = $this->delegatingConversation($code);
        $this->helperRunReturns(['status' => 'error', 'code' => $code, 'content' => '', 'message_id' => null], 'Some of it is done.');

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Draft the report.', null);

        $this->assertSame(
            ['delegation_id', 'helper', 'status', 'summary', 'output', 'undone', 'truncated', 'reason'],
            array_keys($result),
            'no stopped_by on a helper\'s own bound',
        );
        $this->assertSame('partial', $result['status']);
        $this->assertSame('Some of it is done.', $result['summary']);
        $this->assertSame($undone, $result['undone']);
        $this->assertSame('bound_exceeded', $result['reason']);

        $delegation = Delegation::findOrFail($result['delegation_id']);
        $this->assertSame('exhausted', $delegation->status);
        $this->assertSame('bound_exceeded', $delegation->result_reason);
        $this->assertSame($undone, $delegation->result_undone);
        $this->assertSame('unfinished', $this->actionOf($delegation)->outcome);
        $this->assertSame(
            ['status' => 'partial', 'summary' => 'Some of it is done.', 'output' => null, 'undone' => $undone, 'truncated' => false, 'reason' => 'bound_exceeded'],
            json_decode((string) $this->actionOf($delegation)->content, true),
        );
    }

    // ---------------------------------------------------------------
    // A capability-agent call stopped by the task's budget
    // ---------------------------------------------------------------

    private function capabilityCall(string $suffix): array
    {
        $caller = $this->makeAgent("caller-{$suffix}");
        $offered = $this->makeAgent("offered-{$suffix}");
        $offering = app(CapabilityOfferingService::class)->offer(
            $this->user->id,
            $offered->id,
            $caller->id,
            'draft_report',
            'Drafts a report.',
            'What to report on.',
        );
        $this->assertInstanceOf(CapabilityOffering::class, $offering);

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Already titled',
            'agent_id' => $caller->id,
            'agent_version_id' => $caller->current_version_id,
        ]);
        $recorder = app(RunTraceRecorder::class);
        $this->runId = $recorder->openRun(RunKind::Interactive, (string) $this->user->id, $conversation->id, streamed: false);
        $recorder->openStep($this->runId);

        return [$conversation, $offering];
    }

    #[Test]
    public function a_capability_call_stopped_by_the_task_budget_reaches_the_caller_as_an_error_naming_it_with_the_partial_work(): void
    {
        [$conversation, $offering] = $this->capabilityCall('budget');
        $this->helperRunReturns(self::budgetStopped(), 'Sections one and two are drafted.');

        $result = app(DelegationService::class)->invokeAsCapability($conversation, $offering, 'Q3 numbers');

        $this->assertSame(
            ['error' => "Stopped by this task's budget before finishing. Partial: Sections one and two are drafted."],
            $result,
            'a budget-stopped capability call is a bounded non-completion, never its raw (empty) output',
        );

        $delegation = Delegation::where('parent_conversation_id', $conversation->id)->firstOrFail();
        $this->assertSame('capability_offering', $delegation->origin);
        $this->assertSame('exhausted', $delegation->status);
        $this->assertSame('task_budget', $delegation->result_reason);
    }

    #[Test]
    public function a_capability_call_stopped_before_it_wrote_anything_is_an_error_with_no_partial(): void
    {
        [$conversation, $offering] = $this->capabilityCall('empty');
        $this->helperRunReturns(self::budgetStopped(), null);

        $result = app(DelegationService::class)->invokeAsCapability($conversation, $offering, 'Q3 numbers');

        $this->assertSame(['error' => "Stopped by this task's budget before finishing."], $result);
    }
}
