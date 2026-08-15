<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\ManagedTaskUpdated;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ManagerService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 4 (US2), tasks.md T030.
 *
 * Unit tests for the not-yet-built `ManagerService::acceptPart()`
 * (data-model.md §5, contracts/manager-agent-meta-tools.md §3): refuses
 * `no_outstanding_result` when the part has nothing outstanding to judge,
 * refuses `cannot_accept_failed_result` as a structural backstop against a
 * model that miscalls accept_part on a delegation whose own result_status
 * already says it did not succeed (FR-013), and on success writes
 * state = 'accepted' plus accepted_delegation_id/accepted_summary from the
 * outstanding delegation's own result_status/result_summary. Also
 * re-exercises T016's "already finalized" assignPart() guard to prove the
 * two methods agree on what "already finalized" means (accept_part is
 * terminal).
 *
 * Written before ManagerService::acceptPart() exists -- every test below
 * is expected to FAIL red (method not found / BadMethodCallException)
 * until T032 adds it.
 */
class ManagerServiceAcceptPartTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers (ManagerServiceAssignPartTest.php precedent)
    // -----------------------------------------------------------------

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

    private function makeAgent(string $name)
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    /**
     * Builds a manager agent, a helper agent assigned to it, a
     * channel='managed-task' ManagedTask, and one ManagedTaskPart, without
     * ever driving a real assignPart()/nested delegate() call -- the
     * part's outstanding Delegation row is created directly, exactly as
     * ManagerServiceAssignPartTest.php's own state-guard fixtures already
     * do for testing admission in isolation from the nested delegate()
     * mechanism.
     *
     * @return array{ManagedTask, ManagedTaskPart, Delegation, \ClarionApp\LlmClient\Models\Agent}
     */
    private function makePartWithOutstandingDelegation(string $state, string $resultStatus, ?string $resultSummary = 'The work is done.'): array
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $helper = $this->makeAgent('helper-'.uniqid());
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task with one part.');
        [$part] = app(ManagerService::class)->planParts($task, ['The only part.']);

        $delegation = Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'Do the only part.',
            'depth' => 1,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'result_status' => $resultStatus,
            'result_summary' => $resultSummary,
            'managed_task_id' => $task->id,
            'part_id' => $part->id,
        ]);

        $part->state = $state;
        $part->current_delegation_id = $delegation->id;
        $part->assignment_count = 1;
        $part->save();

        return [$task, $part, $delegation, $helper];
    }

    // =================================================================
    // no_outstanding_result
    // =================================================================

    #[Test]
    public function refuses_when_the_part_has_not_yet_been_assigned(): void
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $helper = $this->makeAgent('helper-'.uniqid());
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task with one part.');
        [$part] = app(ManagerService::class)->planParts($task, ['The only part.']);

        app(ManagerService::class)->acceptPart($task, $part);

        $part->refresh();
        $this->assertSame('not_yet_assigned', $part->state, 'no_outstanding_result must refuse without changing the part\'s state');
    }

    #[Test]
    public function refuses_when_the_part_is_already_accepted(): void
    {
        [$task, $part] = $this->makePartWithOutstandingDelegation('accepted', 'success');

        app(ManagerService::class)->acceptPart($task, $part);

        $part->refresh();
        $this->assertSame('accepted', $part->state, 'refusing an already-accepted part must not alter it');
    }

    #[Test]
    public function refuses_when_the_part_is_already_reported_as_shortfall(): void
    {
        [$task, $part] = $this->makePartWithOutstandingDelegation('reported_as_shortfall', 'success');

        app(ManagerService::class)->acceptPart($task, $part);

        $part->refresh();
        $this->assertSame('reported_as_shortfall', $part->state);
        $this->assertNull($part->accepted_delegation_id, 'a reported_as_shortfall part must never gain an accepted_delegation_id');
    }

    // =================================================================
    // cannot_accept_failed_result (FR-013 structural backstop)
    // =================================================================

    #[Test]
    public function refuses_when_the_outstanding_delegations_result_status_is_failure(): void
    {
        [$task, $part, $delegation] = $this->makePartWithOutstandingDelegation('out_for_assignment', 'failure');

        app(ManagerService::class)->acceptPart($task, $part);

        $part->refresh();
        $this->assertSame('out_for_assignment', $part->state, 'a failed result must never be accepted -- the part stays outstanding');
        $this->assertNull($part->accepted_delegation_id);
        $this->assertNull($part->accepted_summary);
    }

    // =================================================================
    // Success path
    // =================================================================

    #[Test]
    public function accepts_a_successful_result_and_stamps_accepted_fields(): void
    {
        [$task, $part, $delegation] = $this->makePartWithOutstandingDelegation('out_for_assignment', 'success', 'Compiled the report.');

        app(ManagerService::class)->acceptPart($task, $part);

        $part->refresh();
        $this->assertSame('accepted', $part->state);
        $this->assertSame($delegation->id, $part->accepted_delegation_id);
        $this->assertSame('Compiled the report.', $part->accepted_summary);
    }

    #[Test]
    public function accepts_an_adequate_partial_result_and_stamps_accepted_fields(): void
    {
        [$task, $part, $delegation] = $this->makePartWithOutstandingDelegation('out_for_correction', 'partial', 'Mostly done, judged adequate.');

        app(ManagerService::class)->acceptPart($task, $part);

        $part->refresh();
        $this->assertSame('accepted', $part->state, 'a partial result the manager judges adequate must still be acceptable');
        $this->assertSame($delegation->id, $part->accepted_delegation_id);
        $this->assertSame('Mostly done, judged adequate.', $part->accepted_summary);
    }

    #[Test]
    public function every_write_updates_last_progress_at_and_fires_managed_task_updated(): void
    {
        [$task, $part] = $this->makePartWithOutstandingDelegation('out_for_assignment', 'success');
        $before = $task->last_progress_at;

        Event::fake([ManagedTaskUpdated::class]);

        app(ManagerService::class)->acceptPart($task, $part);

        $task->refresh();
        $this->assertTrue($task->last_progress_at->greaterThanOrEqualTo($before), 'acceptPart() must update (or at minimum never regress) ManagedTask.last_progress_at');

        Event::assertDispatched(ManagedTaskUpdated::class, fn (ManagedTaskUpdated $e) => $e->managedTaskId === $task->id);
    }

    // =================================================================
    // Terminal -- re-exercises assignPart()'s own "already finalized" guard
    // =================================================================

    #[Test]
    public function accept_part_is_terminal_a_subsequent_assign_part_call_is_refused(): void
    {
        [$task, $part, , $helper] = $this->makePartWithOutstandingDelegation('out_for_assignment', 'success');

        app(ManagerService::class)->acceptPart($task, $part);
        $part->refresh();
        $this->assertSame('accepted', $part->state, 'fixture sanity');

        $result = app(ManagerService::class)->assignPart($task, $part, $helper->id, 'One more, please.', null);

        $this->assertSame('part_already_finalized', $result['error'] ?? null, 'assignPart() and acceptPart() must agree on what "already finalized" means');
        $this->assertSame(1, Delegation::where('managed_task_id', $task->id)->count(), 'no new Delegation row must be created for a refused assignPart() call');
    }
}
