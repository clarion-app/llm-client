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
 * 103-manager-agent, Phase 7 (US5), tasks.md T054.
 *
 * Unit tests for the not-yet-built `ManagerService::reportShortfall()`
 * (data-model.md §5, contracts/manager-agent-meta-tools.md §4): shares
 * the "already finalized" guard already established by
 * `admitAssignmentRound()` (refused if the part's own state is already
 * `accepted`/`reported_as_shortfall` -- a part is closed once, by either
 * `accept_part` or `report_shortfall`, never both); on success, writes
 * `state = 'reported_as_shortfall'`, `shortfall_reason = $reason`, and is
 * itself terminal -- a later `assignPart()` call on the same part is
 * refused exactly as it already is for an accepted part
 * (`ManagerServiceAcceptPartTest::accept_part_is_terminal...`'s own
 * precedent).
 *
 * Written before ManagerService::reportShortfall() exists -- every test
 * below is expected to FAIL red (method not found / BadMethodCallException)
 * until T056 adds it.
 */
class ManagerServiceReportShortfallTest extends TestCase
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
    // Fixture helpers (ManagerServiceAcceptPartTest.php precedent)
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
     * ManagerServiceAcceptPartTest.php's own state-guard fixtures already
     * do for testing a state transition in isolation from the nested
     * delegate() mechanism.
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
    // "Already finalized" guard -- shares admitAssignmentRound()'s own
    // condition (accepted/reported_as_shortfall refused).
    // =================================================================

    #[Test]
    public function refuses_when_the_part_is_already_accepted(): void
    {
        [$task, $part] = $this->makePartWithOutstandingDelegation('accepted', 'success');

        app(ManagerService::class)->reportShortfall($task, $part, 'Could not be completed.');

        $part->refresh();
        $this->assertSame('accepted', $part->state, 'refusing an already-accepted part must not alter it');
        $this->assertNull($part->shortfall_reason, 'an already-accepted part must never gain a shortfall_reason');
    }

    #[Test]
    public function refuses_when_the_part_is_already_reported_as_shortfall(): void
    {
        [$task, $part] = $this->makePartWithOutstandingDelegation('reported_as_shortfall', 'failure');
        $part->shortfall_reason = 'First reason.';
        $part->save();

        app(ManagerService::class)->reportShortfall($task, $part, 'A second, different reason.');

        $part->refresh();
        $this->assertSame('reported_as_shortfall', $part->state);
        $this->assertSame('First reason.', $part->shortfall_reason, 'a part can only be closed once -- a second report_shortfall call must never overwrite the original reason');
    }

    #[Test]
    public function the_refusal_matches_admit_assignment_rounds_own_already_finalized_condition(): void
    {
        [$task, $part, , $helper] = $this->makePartWithOutstandingDelegation('accepted', 'success');

        $reportRefusal = app(ManagerService::class)->reportShortfallRefusal($part);
        $assignResult = app(ManagerService::class)->assignPart($task, $part, $helper->id, 'One more, please.', null);

        $this->assertSame('part_already_finalized', $reportRefusal['error'] ?? null);
        $this->assertSame('part_already_finalized', $assignResult['error'] ?? null, 'reportShortfall() and assignPart() must agree on what "already finalized" means');
    }

    // =================================================================
    // Success path -- reachable from either genuine "assignment
    // outstanding" state.
    // =================================================================

    #[Test]
    public function reports_a_shortfall_for_a_part_out_for_assignment(): void
    {
        [$task, $part] = $this->makePartWithOutstandingDelegation('out_for_assignment', 'failure');

        app(ManagerService::class)->reportShortfall($task, $part, 'Neither helper could complete this part.');

        $part->refresh();
        $this->assertSame('reported_as_shortfall', $part->state);
        $this->assertSame('Neither helper could complete this part.', $part->shortfall_reason);
    }

    #[Test]
    public function reports_a_shortfall_for_a_part_out_for_correction(): void
    {
        [$task, $part] = $this->makePartWithOutstandingDelegation('out_for_correction', 'partial');

        app(ManagerService::class)->reportShortfall($task, $part, 'Corrections did not resolve the gap and no further helper is available.');

        $part->refresh();
        $this->assertSame('reported_as_shortfall', $part->state);
        $this->assertSame('Corrections did not resolve the gap and no further helper is available.', $part->shortfall_reason);
    }

    #[Test]
    public function every_write_updates_last_progress_at_and_fires_managed_task_updated(): void
    {
        [$task, $part] = $this->makePartWithOutstandingDelegation('out_for_assignment', 'failure');
        $before = $task->last_progress_at;

        Event::fake([ManagedTaskUpdated::class]);

        app(ManagerService::class)->reportShortfall($task, $part, 'Could not be completed.');

        $task->refresh();
        $this->assertTrue($task->last_progress_at->greaterThanOrEqualTo($before), 'reportShortfall() must update (or at minimum never regress) ManagedTask.last_progress_at');

        Event::assertDispatched(ManagedTaskUpdated::class, fn (ManagedTaskUpdated $e) => $e->managedTaskId === $task->id);
    }

    // =================================================================
    // Terminal -- re-exercises assignPart()'s own "already finalized"
    // guard, exactly as ManagerServiceAcceptPartTest's own precedent does
    // for accept_part.
    // =================================================================

    #[Test]
    public function report_shortfall_is_terminal_a_subsequent_assign_part_call_is_refused(): void
    {
        [$task, $part, , $helper] = $this->makePartWithOutstandingDelegation('out_for_assignment', 'failure');

        app(ManagerService::class)->reportShortfall($task, $part, 'Could not be completed.');
        $part->refresh();
        $this->assertSame('reported_as_shortfall', $part->state, 'fixture sanity');

        $result = app(ManagerService::class)->assignPart($task, $part, $helper->id, 'One more, please.', null);

        $this->assertSame('part_already_finalized', $result['error'] ?? null, 'assignPart() and reportShortfall() must agree on what "already finalized" means');
        $this->assertSame(1, Delegation::where('managed_task_id', $task->id)->count(), 'no new Delegation row must be created for a refused assignPart() call');
    }

    #[Test]
    public function a_subsequent_accept_part_call_is_also_refused(): void
    {
        [$task, $part] = $this->makePartWithOutstandingDelegation('out_for_correction', 'partial');

        app(ManagerService::class)->reportShortfall($task, $part, 'Could not be completed.');
        $part->refresh();
        $this->assertSame('reported_as_shortfall', $part->state, 'fixture sanity');

        app(ManagerService::class)->acceptPart($task, $part);

        $part->refresh();
        $this->assertSame('reported_as_shortfall', $part->state, 'a part closed via report_shortfall must never be flipped to accepted by a subsequent accept_part call');
        $this->assertNull($part->accepted_delegation_id);
    }
}
