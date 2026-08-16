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

    // =================================================================
    // US2 (Phase 4, T022): attribution/immutability -- proven, not assumed
    // =================================================================

    #[Test]
    public function two_different_authors_entries_are_each_individually_and_permanently_attributed(): void
    {
        $manager = $this->makeAgent('Manager Agent');
        $helper = $this->makeAgent('Helper Agent');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task worked by a manager and a helper.');

        // The manager records first, then a helper -- order matters for
        // this test's "does writing #2 disturb #1" assertion, but the
        // Independent Test explicitly calls out "in either order", so the
        // manager-then-helper ordering here is representative, not special.
        $managerEntry = app(TaskWorkspaceService::class)->recordEntry($task, $manager->id, 'Manager: kicking off, no findings yet.');

        $firstFetch = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}/workspace");
        $firstFetch->assertStatus(200);
        $entryAfterFirstWrite = collect($firstFetch->json('entries'))->firstWhere('entry_id', $managerEntry->id);
        $this->assertNotNull($entryAfterFirstWrite);

        usleep(1000);
        $helperEntry = app(TaskWorkspaceService::class)->recordEntry($task, $helper->id, 'Helper: found the vendor requires an auth header now.');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}/workspace");
        $response->assertStatus(200);
        $body = $response->json();

        $this->assertCount(2, $body['entries']);

        $managerRow = collect($body['entries'])->firstWhere('entry_id', $managerEntry->id);
        $helperRow = collect($body['entries'])->firstWhere('entry_id', $helperEntry->id);

        $this->assertNotNull($managerRow);
        $this->assertNotNull($helperRow);

        // Each entry individually reports its own correct authorship.
        $this->assertSame($manager->id, $managerRow['author_agent_id']);
        $this->assertSame('Manager Agent', $managerRow['author_agent_name']);
        $this->assertSame($helper->id, $helperRow['author_agent_id']);
        $this->assertSame('Helper Agent', $helperRow['author_agent_name']);

        // No cross-contamination: the manager's row must not carry the
        // helper's identity or vice versa.
        $this->assertNotSame($managerRow['author_agent_id'], $helperRow['author_agent_id']);
        $this->assertNotSame($managerRow['author_agent_name'], $helperRow['author_agent_name']);

        // Re-fetching after the second write leaves every field of the
        // first entry's own row byte-for-byte unchanged.
        $this->assertSame($entryAfterFirstWrite, $managerRow);
    }
}
