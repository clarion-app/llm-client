<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ManagerService;
use ClarionApp\LlmClient\Services\TaskWorkspaceService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 108-shared-task-workspace, Phase 3 (US1), tasks.md T015.
 *
 * Feature tests for `GET /managed-tasks/{id}/workspace`
 * (contracts/task-workspace-api.md §1, US1 AC3): `200 {"entries": []}` --
 * not 404, not an exception -- for an in_progress task with no entries;
 * the contract's exact response shape (`entry_id`/`content`/
 * `author_agent_id`/`author_agent_name`/`created_at`), oldest first, for
 * a task with entries.
 *
 * US2's attribution-immutability proof and US3's isolation/404 proof are
 * both deferred to their own Phases (4/5) per the Ordering rationale --
 * this file only needs the happy paths here.
 */
class TaskWorkspaceControllerTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
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

    private function makeAgent(string $name)
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    // =================================================================
    // US1 AC3: empty, not an error
    // =================================================================

    #[Test]
    public function returns_200_with_an_empty_entries_array_for_a_task_with_no_entries(): void
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A fresh task.');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}/workspace");

        $response->assertStatus(200);
        $response->assertJson([
            'managed_task_id' => $task->id,
            'entries' => [],
        ]);
    }

    // =================================================================
    // Contract shape, oldest first
    // =================================================================

    #[Test]
    public function returns_recorded_entries_in_the_contract_shape_oldest_first(): void
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $author = $this->makeAgent('Research Assistant');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task with entries.');

        $entryOne = app(TaskWorkspaceService::class)->recordEntry($task, $author->id, 'The pricing page is stale.');
        usleep(1000);
        $entryTwo = app(TaskWorkspaceService::class)->recordEntry($task, $author->id, 'Cross-check against the Q2 deck instead.');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}/workspace");

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertSame($task->id, $body['managed_task_id']);
        $this->assertCount(2, $body['entries']);

        $first = $body['entries'][0];
        $this->assertSame($entryOne->id, $first['entry_id']);
        $this->assertSame('The pricing page is stale.', $first['content']);
        $this->assertSame($author->id, $first['author_agent_id']);
        $this->assertSame('Research Assistant', $first['author_agent_name']);
        $this->assertArrayHasKey('created_at', $first);

        $second = $body['entries'][1];
        $this->assertSame($entryTwo->id, $second['entry_id']);
        $this->assertSame('Cross-check against the Q2 deck instead.', $second['content']);
    }
}
