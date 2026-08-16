<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ManagerService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\TaskWorkspaceService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 108-shared-task-workspace, Phase 7 (US5), tasks.md T036.
 *
 * FR-010: two plainly contradictory entries about the same point on the
 * same task both stay visible forever -- no update, no merge, no
 * read-time reordering or promotion, and no resolved/superseded/hidden
 * marker of any kind. quickstart.md scenario 6, mutation-checklist row 10.
 *
 * Exercises both read surfaces `entriesForTask()` feeds:
 *  - `GET /managed-tasks/{id}/workspace` (human/API-facing), read twice to
 *    prove exact positional stability across consecutive reads;
 *  - `AgentLoopService::buildSharedTaskWorkspaceSection()` (agent-facing
 *    prompt section), called directly via reflection since it is private
 *    -- mirrors TaskWorkspaceServiceTest::record_entry_is_the_only_public_
 *    method_that_touches_an_individual_rows_fields()'s own established
 *    reflection-based-direct-call precedent for this feature, and is
 *    cheaper than driving the full agent-loop journey scaffolding
 *    TaskWorkspaceShareAcrossHelpersJourneyTest.php uses, which this test
 *    does not need (it is not proving the widened-gate wiring, only the
 *    ordering guarantee of the section itself).
 */
class TaskWorkspaceContradictionTest extends TestCase
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

        DB::table('task_workspace_entries')->delete();
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
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

    private function callBuildSharedTaskWorkspaceSection(?string $managedTaskId): ?string
    {
        $service = app(AgentLoopService::class);

        $method = new \ReflectionMethod(AgentLoopService::class, 'buildSharedTaskWorkspaceSection');
        $method->setAccessible(true);

        return $method->invoke($service, $managedTaskId);
    }

    #[Test]
    public function two_contradictory_entries_both_stay_visible_in_stable_chronological_order(): void
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $agentA = $this->makeAgent('Agent A');
        $agentB = $this->makeAgent('Agent B');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'Investigate the API auth requirement.');

        $entryA = app(TaskWorkspaceService::class)->recordEntry($task, $agentA->id, 'The API requires auth.');
        usleep(1000);
        $entryB = app(TaskWorkspaceService::class)->recordEntry($task, $agentB->id, 'The API is unauthenticated.');

        $this->assertNotNull($entryA, 'fixture sanity -- the first contradictory entry must be recorded');
        $this->assertNotNull($entryB, 'fixture sanity -- the second, contradicting entry must be recorded');

        // ---------------------------------------------------------------
        // GET /managed-tasks/{id}/workspace: both entries present, each
        // in its own chronological position, neither marked
        // resolved/superseded/hidden.
        // ---------------------------------------------------------------
        $firstRead = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}/workspace");
        $firstRead->assertStatus(200);
        $firstEntries = $firstRead->json('entries');

        $this->assertCount(2, $firstEntries);
        $this->assertSame($entryA->id, $firstEntries[0]['entry_id']);
        $this->assertSame('The API requires auth.', $firstEntries[0]['content']);
        $this->assertSame($entryB->id, $firstEntries[1]['entry_id']);
        $this->assertSame('The API is unauthenticated.', $firstEntries[1]['content']);

        foreach ($firstEntries as $entry) {
            $this->assertArrayNotHasKey('resolved', $entry, 'no entry may carry a resolved marker -- FR-010');
            $this->assertArrayNotHasKey('superseded', $entry, 'no entry may carry a superseded marker -- FR-010');
            $this->assertArrayNotHasKey('superseded_by', $entry, 'no entry may point at a replacement -- FR-010');
            $this->assertArrayNotHasKey('hidden', $entry, 'no entry may be hideable -- FR-010');
            $this->assertArrayNotHasKey('status', $entry, 'an entry has no status field at all -- it is a plain immutable record');
        }

        // ---------------------------------------------------------------
        // Reading a second time must not reorder, alter, or hide either
        // entry -- exact positional stability across consecutive reads.
        // ---------------------------------------------------------------
        $secondRead = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}/workspace");
        $secondRead->assertStatus(200);
        $secondEntries = $secondRead->json('entries');

        $this->assertSame(
            $firstEntries,
            $secondEntries,
            'reading the workspace a second time must produce byte-for-byte the same entries in the same order -- no read-time reordering or promotion'
        );

        // ---------------------------------------------------------------
        // The rendered "Shared Task Notes" prompt section shows both
        // entries in the same stable, oldest-first order.
        // ---------------------------------------------------------------
        $section = $this->callBuildSharedTaskWorkspaceSection($task->id);
        $this->assertNotNull($section);
        $this->assertStringContainsString('## Shared Task Notes', $section);
        $this->assertStringContainsString('The API requires auth.', $section);
        $this->assertStringContainsString('The API is unauthenticated.', $section);

        $posA = strpos($section, 'The API requires auth.');
        $posB = strpos($section, 'The API is unauthenticated.');
        $this->assertLessThan($posB, $posA, 'the earlier contradictory entry must render before the later one -- neither is promoted or reordered');

        // Rendering the section a second time is equally stable.
        $sectionAgain = $this->callBuildSharedTaskWorkspaceSection($task->id);
        $this->assertSame(
            $section,
            $sectionAgain,
            'rendering the prompt section a second time must not reorder either contradictory entry'
        );
    }
}
