<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ManagerService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 5 (US3), tasks.md T038.
 *
 * Feature tests for the not-yet-built `GET /managed-tasks/{id}/parts`
 * (contracts/manager-agent-api.md §3): returns the array shape (`part_id`,
 * `sequence`, `description`, `state`, `assigned_helper_agent_id`/`_name`,
 * `assignment_count`, `accepted_summary`, `shortfall_reason`), reflecting
 * the part's `current_delegation_id`/`accepted_delegation_id` for
 * attribution -- a reassigned part shows the NEW helper, not the one that
 * failed. `404`s for a task not owned by the caller. Quickstart scenario
 * 13 (US3 AC2, mid-task): available without the task ever reaching a
 * terminal state.
 *
 * Written before `ManagedTaskController::parts()`/the route exist -- every
 * request below hits Laravel's own route-not-found 404, so every
 * assertion is expected to FAIL red until T043 adds it.
 */
class ManagedTaskControllerPartsTest extends TestCase
{
    private User $user;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

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

    private function makeAgent(User $owner, string $name): Agent
    {
        return app(AgentService::class)->create($owner->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    // =================================================================
    // Shape + ownership
    // =================================================================

    #[Test]
    public function returns_404_for_a_task_not_owned_by_the_caller(): void
    {
        $manager = $this->makeAgent($this->otherUser, 'manager-other');
        $task = app(ManagerService::class)->createManagedTask($this->otherUser->id, $manager->id, 'Someone else\'s task.');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}/parts");

        $response->assertStatus(404);
    }

    #[Test]
    public function returns_404_for_an_unknown_task_id(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/clarion-app/llm-client/managed-tasks/'.(string) Str::uuid().'/parts');

        $response->assertStatus(404);
    }

    // =================================================================
    // Quickstart scenario 13: mid-task, no part terminal, all visible
    // =================================================================

    #[Test]
    public function every_part_is_visible_with_its_own_current_state_mid_task(): void
    {
        $manager = $this->makeAgent($this->user, 'manager-parts');
        $helper = $this->makeAgent($this->user, 'helper-parts');
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A three-part task.');
        [$acceptedPart, $correctionPart, $freshPart] = app(ManagerService::class)->planParts($task, [
            'The accepted part.',
            'The part under correction.',
            'The not-yet-assigned part.',
        ]);

        $acceptedDelegation = Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'Do the accepted part.',
            'depth' => 1,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'result_status' => 'success',
            'result_summary' => 'Fully completed.',
            'managed_task_id' => $task->id,
            'part_id' => $acceptedPart->id,
        ]);
        $acceptedPart->state = 'accepted';
        $acceptedPart->current_delegation_id = $acceptedDelegation->id;
        $acceptedPart->accepted_delegation_id = $acceptedDelegation->id;
        $acceptedPart->accepted_summary = 'Fully completed.';
        $acceptedPart->assignment_count = 1;
        $acceptedPart->save();

        $correctionDelegation = Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'Correct the part.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'managed_task_id' => $task->id,
            'part_id' => $correctionPart->id,
        ]);
        $correctionPart->state = 'out_for_correction';
        $correctionPart->current_delegation_id = $correctionDelegation->id;
        $correctionPart->assignment_count = 2;
        $correctionPart->save();

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}/parts");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertCount(3, $body);

        $byId = collect($body)->keyBy('part_id');

        $this->assertSame('accepted', $byId[$acceptedPart->id]['state']);
        $this->assertSame($helper->id, $byId[$acceptedPart->id]['assigned_helper_agent_id']);
        $this->assertSame('helper-parts', $byId[$acceptedPart->id]['assigned_helper_agent_name']);
        $this->assertSame('Fully completed.', $byId[$acceptedPart->id]['accepted_summary']);
        $this->assertNull($byId[$acceptedPart->id]['shortfall_reason']);

        $this->assertSame('out_for_correction', $byId[$correctionPart->id]['state']);
        $this->assertSame(2, $byId[$correctionPart->id]['assignment_count']);
        $this->assertNull($byId[$correctionPart->id]['accepted_summary']);

        $this->assertSame('not_yet_assigned', $byId[$freshPart->id]['state']);
        $this->assertNull($byId[$freshPart->id]['assigned_helper_agent_id']);
        $this->assertSame(0, $byId[$freshPart->id]['assignment_count']);

        foreach ($body as $row) {
            $this->assertArrayHasKey('part_id', $row);
            $this->assertArrayHasKey('sequence', $row);
            $this->assertArrayHasKey('description', $row);
            $this->assertArrayHasKey('state', $row);
            $this->assertArrayHasKey('assigned_helper_agent_id', $row);
            $this->assertArrayHasKey('assigned_helper_agent_name', $row);
            $this->assertArrayHasKey('assignment_count', $row);
            $this->assertArrayHasKey('accepted_summary', $row);
            $this->assertArrayHasKey('shortfall_reason', $row);
        }
    }

    // =================================================================
    // A reassigned part shows the NEW helper, not the one that failed.
    // =================================================================

    #[Test]
    public function a_reassigned_part_shows_the_new_helper_not_the_one_that_failed(): void
    {
        $manager = $this->makeAgent($this->user, 'manager-reassign');
        $failedHelper = $this->makeAgent($this->user, 'helper-failed');
        $newHelper = $this->makeAgent($this->user, 'helper-new');
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $failedHelper->id);
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $newHelper->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A one-part task.');
        [$part] = app(ManagerService::class)->planParts($task, ['The only part.']);

        $failedDelegation = Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $failedHelper->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'First attempt.',
            'depth' => 1,
            'status' => 'completed',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(4),
            'result_status' => 'failure',
            'result_summary' => 'Could not complete it.',
            'managed_task_id' => $task->id,
            'part_id' => $part->id,
        ]);

        $newDelegation = Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $newHelper->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'Reassigned attempt.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'managed_task_id' => $task->id,
            'part_id' => $part->id,
        ]);

        $part->state = 'out_for_assignment';
        $part->current_delegation_id = $newDelegation->id;
        $part->assignment_count = 2;
        $part->save();

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}/parts");

        $response->assertStatus(200);
        $row = collect($response->json())->firstWhere('part_id', $part->id);

        $this->assertSame($newHelper->id, $row['assigned_helper_agent_id'], 'the reassigned part must attribute to the NEW helper');
        $this->assertSame('helper-new', $row['assigned_helper_agent_name']);
        $this->assertNotSame($failedHelper->id, $row['assigned_helper_agent_id']);
    }
}
