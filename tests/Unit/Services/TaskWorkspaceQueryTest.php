<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ManagerService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\TaskWorkspaceQuery;
use ClarionApp\LlmClient\Services\TaskWorkspaceService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 108-shared-task-workspace, Phase 3 (US1), tasks.md T014.
 *
 * Unit tests for `TaskWorkspaceQuery` (data-model.md §3, research.md D5):
 * `resolveManagedTaskIdForConversation()`'s two-step resolution (manager's
 * own conversation via ManagedTask.conversation_id; a helper's own
 * conversation via agent_delegations.helper_conversation_id/
 * managed_task_id; null for an unrelated conversation) and
 * `entriesForTask()`'s oldest-first read with author_agent_name resolved
 * per entry, plus its own absent/not-owned null collapse (broadened
 * further in Phase 5/US3 -- this file only needs the single-user happy
 * path plus the one not-found/not-owned case here).
 */
class TaskWorkspaceQueryTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
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
        DB::table('agent_delegations')->delete();
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

    private function makeAgent(User $owner, string $name)
    {
        return app(AgentService::class)->create($owner->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeManagedTask(User $owner): ManagedTask
    {
        $manager = $this->makeAgent($owner, 'manager-'.uniqid());

        return app(ManagerService::class)->createManagedTask($owner->id, $manager->id, 'A task with a shared workspace.');
    }

    // =================================================================
    // resolveManagedTaskIdForConversation()
    // =================================================================

    #[Test]
    public function resolves_the_managers_own_conversation(): void
    {
        $task = $this->makeManagedTask($this->user);

        $resolved = app(TaskWorkspaceQuery::class)->resolveManagedTaskIdForConversation($task->conversation_id);

        $this->assertSame($task->id, $resolved);
    }

    #[Test]
    public function resolves_a_helpers_own_conversation_via_the_delegation_tree(): void
    {
        $task = $this->makeManagedTask($this->user);
        $helperConversationId = (string) Str::uuid();

        Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => $helperConversationId,
            'owner_user_id' => $this->user->id,
            'task' => 'Do a part.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'managed_task_id' => $task->id,
        ]);

        $resolved = app(TaskWorkspaceQuery::class)->resolveManagedTaskIdForConversation($helperConversationId);

        $this->assertSame($task->id, $resolved);
    }

    #[Test]
    public function returns_null_for_an_unrelated_ordinary_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'model' => 'test-model',
            'channel' => 'web',
        ]);

        $resolved = app(TaskWorkspaceQuery::class)->resolveManagedTaskIdForConversation($conversation->id);

        $this->assertNull($resolved);
    }

    // =================================================================
    // entriesForTask()
    // =================================================================

    #[Test]
    public function returns_entries_ordered_oldest_first_with_author_agent_name_resolved(): void
    {
        $task = $this->makeManagedTask($this->user);
        $agentOne = $this->makeAgent($this->user, 'Research Assistant');
        $agentTwo = $this->makeAgent($this->user, 'Copy Editor');

        $entryOne = app(TaskWorkspaceService::class)->recordEntry($task, $agentOne->id, 'First finding.');
        usleep(1000);
        $entryTwo = app(TaskWorkspaceService::class)->recordEntry($task, $agentTwo->id, 'Second finding.');

        $entries = app(TaskWorkspaceQuery::class)->entriesForTask($this->user->id, $task->id);

        $this->assertNotNull($entries);
        $this->assertCount(2, $entries);

        $this->assertSame($entryOne->id, $entries[0]['entry_id']);
        $this->assertSame('First finding.', $entries[0]['content']);
        $this->assertSame($agentOne->id, $entries[0]['author_agent_id']);
        $this->assertSame('Research Assistant', $entries[0]['author_agent_name']);

        $this->assertSame($entryTwo->id, $entries[1]['entry_id']);
        $this->assertSame('Second finding.', $entries[1]['content']);
        $this->assertSame($agentTwo->id, $entries[1]['author_agent_id']);
        $this->assertSame('Copy Editor', $entries[1]['author_agent_name']);
    }

    #[Test]
    public function returns_empty_array_for_an_in_progress_task_with_no_entries(): void
    {
        $task = $this->makeManagedTask($this->user);

        $entries = app(TaskWorkspaceQuery::class)->entriesForTask($this->user->id, $task->id);

        $this->assertSame([], $entries);
    }

    #[Test]
    public function returns_null_for_a_task_absent_or_owned_by_another_user(): void
    {
        $task = $this->makeManagedTask($this->otherUser);
        app(TaskWorkspaceService::class)->recordEntry($task, (string) Str::uuid(), 'Not visible to the wrong user.');

        $entries = app(TaskWorkspaceQuery::class)->entriesForTask($this->user->id, $task->id);
        $this->assertNull($entries, 'a task owned by another user must resolve to null');

        $unknown = app(TaskWorkspaceQuery::class)->entriesForTask($this->user->id, (string) Str::uuid());
        $this->assertNull($unknown, 'an unknown task id must resolve to null');
    }
}
