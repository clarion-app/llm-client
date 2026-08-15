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
 * 103-manager-agent, Phase 5 (US3), tasks.md T036.
 *
 * Unit tests for the not-yet-built `ManagerService::finalize()` (data-model.md
 * §5, contracts/manager-agent-meta-tools.md §5): refuses `parts_outstanding`
 * when any part is still not_yet_assigned/out_for_assignment/out_for_correction
 * and the round ceiling has NOT been reached (mutation-checklist row 11);
 * that same refusal is BYPASSED once `ManagedTask.rounds_used >=
 * round_ceiling` (contracts §5's own "ceiling has not been reached"
 * qualifier); refuses `shortfall_note_required` when any part is
 * reported_as_shortfall and no shortfall_note is given; on success calls
 * `ResultAggregationService::combineForManagedTask()` and sets
 * `conflict_note` when it reports a conflict, sets `status` to `completed`
 * (zero shortfall parts) or `completed_with_shortfalls` (one or more
 * shortfall parts), writes `final_response`/`shortfall_note`, sets
 * `completed_at`, and fires `ManagedTaskUpdated`.
 *
 * Written before `ManagerService::finalize()` exists -- every test below is
 * expected to FAIL red (method not found / BadMethodCallException) until
 * T040 adds it.
 */
class ManagerServiceFinalizeTest extends TestCase
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

    private function makeManagedTask(int $roundCeiling = 30): ManagedTask
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $helper = $this->makeAgent('helper-'.uniqid());
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A multi-part task.');
        $task->round_ceiling = $roundCeiling;
        $task->save();

        return $task;
    }

    private function addPart(ManagedTask $task, string $state, array $overrides = []): ManagedTaskPart
    {
        [$part] = app(ManagerService::class)->planParts($task, [$overrides['description'] ?? 'A part.']);

        $part->state = $state;

        if ($state === 'accepted') {
            $delegation = $this->makeAcceptedDelegation($task, $overrides);
            $part->accepted_delegation_id = $delegation->id;
            $part->accepted_summary = $overrides['accepted_summary'] ?? $delegation->result_summary;
            $part->current_delegation_id = $delegation->id;
        } elseif ($state === 'reported_as_shortfall') {
            $part->shortfall_reason = $overrides['shortfall_reason'] ?? 'Could not be completed.';
        } elseif (in_array($state, ['out_for_assignment', 'out_for_correction'], true)) {
            $part->current_delegation_id = (string) \Illuminate\Support\Str::uuid();
        }

        $part->assignment_count = $state === 'not_yet_assigned' ? 0 : 1;
        $part->save();

        return $part;
    }

    private function makeAcceptedDelegation(ManagedTask $task, array $overrides = []): Delegation
    {
        $helper = $this->makeAgent('accepted-helper-'.uniqid());

        return Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'Do the part.',
            'depth' => 1,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'result_status' => 'success',
            'result_summary' => $overrides['result_summary'] ?? 'Part completed.',
            'result_output' => isset($overrides['result_output']) ? json_encode($overrides['result_output']) : null,
            'managed_task_id' => $task->id,
        ]);
    }

    // =================================================================
    // parts_outstanding
    // =================================================================

    #[Test]
    public function refuses_when_a_part_is_not_yet_assigned_and_the_ceiling_has_not_been_reached(): void
    {
        $task = $this->makeManagedTask();
        $this->addPart($task, 'not_yet_assigned');

        app(ManagerService::class)->finalize($task, 'Here is the answer.', null);

        $task->refresh();
        $this->assertSame('in_progress', $task->status, 'parts_outstanding must refuse without changing status');
        $this->assertNull($task->final_response);
        $this->assertNull($task->completed_at);
    }

    #[Test]
    public function refuses_when_a_part_is_out_for_assignment_and_the_ceiling_has_not_been_reached(): void
    {
        $task = $this->makeManagedTask();
        $this->addPart($task, 'out_for_assignment');

        app(ManagerService::class)->finalize($task, 'Here is the answer.', null);

        $task->refresh();
        $this->assertSame('in_progress', $task->status);
    }

    #[Test]
    public function refuses_when_a_part_is_out_for_correction_and_the_ceiling_has_not_been_reached(): void
    {
        $task = $this->makeManagedTask();
        $this->addPart($task, 'out_for_correction');

        app(ManagerService::class)->finalize($task, 'Here is the answer.', null);

        $task->refresh();
        $this->assertSame('in_progress', $task->status);
    }

    #[Test]
    public function the_parts_outstanding_refusal_is_bypassed_once_the_round_ceiling_has_been_reached(): void
    {
        $task = $this->makeManagedTask(roundCeiling: 3);
        $task->rounds_used = 3;
        $task->save();
        $this->addPart($task, 'out_for_correction');

        app(ManagerService::class)->finalize($task, 'The best available answer.', null);

        $task->refresh();
        $this->assertSame('completed', $task->status, 'once the ceiling is reached, finalize_task must be admitted despite an outstanding part');
        $this->assertSame('The best available answer.', $task->final_response);
    }

    // =================================================================
    // shortfall_note_required
    // =================================================================

    #[Test]
    public function refuses_when_a_part_is_reported_as_shortfall_and_no_shortfall_note_is_given(): void
    {
        $task = $this->makeManagedTask();
        $this->addPart($task, 'reported_as_shortfall');

        app(ManagerService::class)->finalize($task, 'Here is the answer.', null);

        $task->refresh();
        $this->assertSame('in_progress', $task->status, 'shortfall_note_required must refuse without changing status');
        $this->assertNull($task->final_response);
    }

    #[Test]
    public function succeeds_when_a_part_is_reported_as_shortfall_and_a_shortfall_note_is_given(): void
    {
        $task = $this->makeManagedTask();
        $this->addPart($task, 'reported_as_shortfall');

        app(ManagerService::class)->finalize($task, 'Here is the partial answer.', 'One part could not be completed.');

        $task->refresh();
        $this->assertSame('completed_with_shortfalls', $task->status);
        $this->assertSame('One part could not be completed.', $task->shortfall_note);
    }

    // =================================================================
    // Success path
    // =================================================================

    #[Test]
    public function sets_status_completed_with_zero_shortfall_parts_and_writes_final_response(): void
    {
        $task = $this->makeManagedTask();
        $this->addPart($task, 'accepted');
        $this->addPart($task, 'accepted');

        app(ManagerService::class)->finalize($task, 'The single coherent answer.', null);

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame('The single coherent answer.', $task->final_response);
        $this->assertNull($task->shortfall_note);
        $this->assertNotNull($task->completed_at);
    }

    #[Test]
    public function sets_status_completed_with_shortfalls_when_one_or_more_parts_are_reported_as_shortfall(): void
    {
        $task = $this->makeManagedTask();
        $this->addPart($task, 'accepted');
        $this->addPart($task, 'reported_as_shortfall');

        app(ManagerService::class)->finalize($task, 'The best available answer.', 'One part could not be completed.');

        $task->refresh();
        $this->assertSame('completed_with_shortfalls', $task->status);
    }

    #[Test]
    public function calls_combine_for_managed_task_and_sets_conflict_note_when_a_conflict_is_reported(): void
    {
        $task = $this->makeManagedTask();
        $this->addPart($task, 'accepted', ['result_output' => ['total' => '100.00']]);
        $this->addPart($task, 'accepted', ['result_output' => ['total' => '200.00']]);

        app(ManagerService::class)->finalize($task, 'The single coherent answer.', null);

        $task->refresh();
        $this->assertNotNull($task->conflict_note, 'a genuine conflict between accepted parts must populate conflict_note (FR-016)');
    }

    #[Test]
    public function conflict_note_stays_null_when_accepted_parts_do_not_conflict(): void
    {
        $task = $this->makeManagedTask();
        $this->addPart($task, 'accepted', ['result_output' => ['a' => 1]]);
        $this->addPart($task, 'accepted', ['result_output' => ['b' => 2]]);

        app(ManagerService::class)->finalize($task, 'The single coherent answer.', null);

        $task->refresh();
        $this->assertNull($task->conflict_note);
    }

    #[Test]
    public function every_write_updates_last_progress_at_and_fires_managed_task_updated(): void
    {
        $task = $this->makeManagedTask();
        $this->addPart($task, 'accepted');
        $before = $task->last_progress_at;

        Event::fake([ManagedTaskUpdated::class]);

        app(ManagerService::class)->finalize($task, 'Done.', null);

        $task->refresh();
        $this->assertTrue($task->last_progress_at->greaterThanOrEqualTo($before));

        Event::assertDispatched(ManagedTaskUpdated::class, fn (ManagedTaskUpdated $e) => $e->managedTaskId === $task->id);
    }
}
