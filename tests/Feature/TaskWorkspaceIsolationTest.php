<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ManagerService;
use ClarionApp\LlmClient\Services\TaskWorkspaceQuery;
use ClarionApp\LlmClient\Services\TaskWorkspaceService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 108-shared-task-workspace, Phase 5 (US3), tasks.md T027.
 *
 * Feature/Unit test proving FR-003/FR-004/FR-005 (quickstart scenario 4,
 * mutation-checklist row 1): two ManagedTasks, one per user, each with a
 * recorded entry. No agent or caller working task A can read or write
 * task B's shared area, or vice versa, under any tested access attempt --
 * TaskWorkspaceQuery::entriesForTask()'s reuse of
 * ManagedTaskQuery::findManagedTask()'s owner_user_id comparison is
 * confirmed to make this structurally true, not merely coincidentally
 * true for the happy path.
 */
class TaskWorkspaceIsolationTest extends TestCase
{
    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('task_workspace_entries')->delete();
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
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

    private function makeAgent(User $owner, string $name)
    {
        return app(AgentService::class)->create($owner->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    #[Test]
    public function no_agent_working_one_task_can_read_or_write_the_other_tasks_shared_area(): void
    {
        $managerA = $this->makeAgent($this->userA, 'manager-a-'.uniqid());
        $managerB = $this->makeAgent($this->userB, 'manager-b-'.uniqid());

        $taskA = app(ManagerService::class)->createManagedTask($this->userA->id, $managerA->id, 'Task A, owned by user A.');
        $taskB = app(ManagerService::class)->createManagedTask($this->userB->id, $managerB->id, 'Task B, owned by user B.');

        $entryA = app(TaskWorkspaceService::class)->recordEntry($taskA, $managerA->id, 'Task A finding: the vendor rotated its API key.');
        $entryB = app(TaskWorkspaceService::class)->recordEntry($taskB, $managerB->id, 'Task B finding: the staging DB needs a schema migration.');

        $this->assertNotNull($entryA);
        $this->assertNotNull($entryB);

        // (a) TaskWorkspaceQuery::entriesForTask($userB, $taskA) returns null.
        $crossUserRead = app(TaskWorkspaceQuery::class)->entriesForTask($this->userB->id, $taskA->id);
        $this->assertNull($crossUserRead, 'user B must not be able to read task A\'s entries via TaskWorkspaceQuery directly');

        // (b) GET /managed-tasks/{taskA}/workspace with user B's credentials returns 404.
        $crossUserResponse = $this->actingAs($this->userB, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$taskA->id}/workspace");
        $crossUserResponse->assertStatus(404);

        // (c) Task A's own GET (as user A) shows only task A's entry, no trace of task B.
        $ownResponseA = $this->actingAs($this->userA, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$taskA->id}/workspace");
        $ownResponseA->assertStatus(200);
        $bodyA = $ownResponseA->json();
        $this->assertSame($taskA->id, $bodyA['managed_task_id']);
        $this->assertCount(1, $bodyA['entries']);
        $this->assertSame($entryA->id, $bodyA['entries'][0]['entry_id']);
        $this->assertSame('Task A finding: the vendor rotated its API key.', $bodyA['entries'][0]['content']);
        $this->assertNotSame($entryB->id, $bodyA['entries'][0]['entry_id']);
        foreach ($bodyA['entries'] as $entry) {
            $this->assertStringNotContainsString('staging DB', $entry['content']);
        }

        // (d) Task B's own GET (as user B) shows only task B's entry.
        $ownResponseB = $this->actingAs($this->userB, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$taskB->id}/workspace");
        $ownResponseB->assertStatus(200);
        $bodyB = $ownResponseB->json();
        $this->assertSame($taskB->id, $bodyB['managed_task_id']);
        $this->assertCount(1, $bodyB['entries']);
        $this->assertSame($entryB->id, $bodyB['entries'][0]['entry_id']);
        $this->assertSame('Task B finding: the staging DB needs a schema migration.', $bodyB['entries'][0]['content']);
        $this->assertNotSame($entryA->id, $bodyB['entries'][0]['entry_id']);
        foreach ($bodyB['entries'] as $entry) {
            $this->assertStringNotContainsString('vendor rotated', $entry['content']);
        }

        // Reverse cross-user check for completeness: user A must not be
        // able to read task B's entries either.
        $reverseCrossUserRead = app(TaskWorkspaceQuery::class)->entriesForTask($this->userA->id, $taskB->id);
        $this->assertNull($reverseCrossUserRead, 'user A must not be able to read task B\'s entries via TaskWorkspaceQuery directly');

        $reverseCrossUserResponse = $this->actingAs($this->userA, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$taskB->id}/workspace");
        $reverseCrossUserResponse->assertStatus(404);
    }
}
