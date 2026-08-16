<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\TaskWorkspaceEntry;
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
 * 108-shared-task-workspace, Phase 8 (US6), tasks.md T041.
 *
 * Dedicated proof of T019's (Phase 3) existing
 * `AgentLoopService::buildSharedTaskWorkspaceSection()` context-budget
 * truncation (FR-012/US6 AC3, quickstart scenario 8): with
 * `max_entries = 50` and a small `context_budget_bytes` relative to 50
 * full-length entries, the rendered section is truncated via
 * `ContentSanitizer::truncate()` and ends with a trailing truncation
 * notice naming `GET /managed-tasks/{id}/workspace` as the authoritative
 * full record. Per tasks.md's own wording, this is expected to pass
 * without further production change -- buildSharedTaskWorkspaceSection()
 * already implements this behavior in Phase 3 (T019); the point of this
 * test is proof, not new behavior.
 */
class AgentLoopServiceSharedTaskWorkspaceSectionTest extends TestCase
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
    public function the_rendered_section_is_truncated_to_the_context_budget_with_a_trailing_notice(): void
    {
        // max_entries = 50 -- large enough that no eviction happens while
        // this test writes exactly 50 entries; the truncation under test
        // is the SECTION's own context_budget_bytes cap, not trimToCap()'s
        // count cap.
        config(['llm-client.task_workspace.max_entries' => 50]);
        // A small budget relative to 50 full-length entries below (each
        // well over 50 bytes) -- the rendered section will vastly exceed
        // this.
        config(['llm-client.task_workspace.context_budget_bytes' => 500]);

        $manager = $this->makeAgent('manager-'.uniqid());
        $author = $this->makeAgent('Research Assistant');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task with many findings.');
        $service = app(TaskWorkspaceService::class);

        for ($i = 1; $i <= 50; $i++) {
            $entry = $service->recordEntry($task, $author->id, str_repeat("Finding {$i}. ", 5));
            $this->assertNotNull($entry, "entry {$i} must be recorded");
        }

        $this->assertSame(
            50,
            TaskWorkspaceEntry::where('managed_task_id', $task->id)->count(),
            'fixture sanity -- all 50 entries survive (max_entries is also 50, so no eviction has happened)'
        );

        $section = $this->callBuildSharedTaskWorkspaceSection($task->id);

        $this->assertNotNull($section);

        // The section's own untruncated form (50 entries, each with the
        // "- [{timestamp}, {author}]: " prefix plus the 50-byte finding
        // text) is comfortably over 3000 bytes -- the rendered, truncated
        // section returned above must be far smaller.
        $this->assertLessThan(1000, strlen($section), 'the rendered section must genuinely have been truncated down to fit the small context budget');

        // Proof of truncation via ContentSanitizer::truncate(): the
        // trailing notice (appended by buildSharedTaskWorkspaceSection()
        // ONLY when ContentSanitizer::isTruncated() was true on the
        // truncate() result) is present and IS the final content of the
        // section -- not merely present somewhere inside it.
        $notice = 'GET /managed-tasks/{id}/workspace for the authoritative full record.)';
        $this->assertStringContainsString($notice, $section, 'the trailing truncation notice must name the authoritative full-record endpoint');
        $this->assertStringEndsWith($notice, $section, 'the truncation notice must be trailing, not merely present somewhere in the section');
    }
}
