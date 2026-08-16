<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\TaskWorkspaceEntry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ManagerService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\TaskWorkspaceQuery;
use ClarionApp\LlmClient\Services\TaskWorkspaceService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 108-shared-task-workspace, Phase 8 (US6), tasks.md T042.
 *
 * FR-014/FR-015/FR-016 -- a task's shared workspace is discarded,
 * completely and permanently, the moment the task reaches any terminal
 * status, on all three of this codebase's termination paths (research.md
 * D2/D7, data-model.md §4): (a) normal completion via
 * `ManagerService::finalize()`; (b) forced failure via
 * `ManagerService::finalizeWithShortfall()` triggered by the round
 * ceiling (mirrors 103 quickstart scenario 4, `RunManagedTaskStepJob`'s
 * own pre-step ceiling check); (c) abandonment via
 * `finalizeWithShortfall()` reached through the
 * `llm-client:resolve-stalled-managed-tasks` sweep's own wall-clock-past
 * force-finalize branch (mirrors 103 quickstart scenario 8). All three
 * converge on the SAME post-condition, proving the single
 * `discardForTask()` call site inside `finalizeWithShortfall()` covers
 * both of its distinct callers identically.
 *
 * Post-condition asserted identically for all three sub-cases:
 * `task_workspace_entries` is empty for the task; a further
 * `TaskWorkspaceService::recordEntry()` call returns null (not merely a
 * silent no-op -- refused, precisely); `TaskWorkspaceQuery::
 * entriesForTask()` returns null, not `[]` (a concluded task is a
 * DIFFERENT outcome from an in_progress task with zero entries); and
 * `GET /managed-tasks/{id}/workspace` returns 404, not
 * `200 {"entries": []}`.
 */
class ManagedTaskWorkspaceCleanupTest extends TestCase
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

    /**
     * The shared post-condition every sub-case below must reach,
     * verified through all four surfaces FR-014/FR-015/FR-016 promise:
     * the table itself, the write path, the read path, and the HTTP
     * endpoint -- $task must already be refreshed to its terminal status
     * before this is called.
     */
    private function assertWorkspaceDiscardedAndInaccessible(ManagedTask $task): void
    {
        $this->assertNotSame('in_progress', $task->status, 'fixture sanity -- the task must be terminal before checking the post-condition');

        $this->assertSame(
            0,
            TaskWorkspaceEntry::where('managed_task_id', $task->id)->count(),
            'task_workspace_entries must be empty immediately after the task concludes'
        );

        $writeAttempt = app(TaskWorkspaceService::class)->recordEntry(
            $task,
            (string) Str::uuid(),
            'A write attempted after this task has already concluded.'
        );
        $this->assertNull($writeAttempt, 'recordEntry() must refuse a write against a concluded task, returning null');
        $this->assertSame(
            0,
            TaskWorkspaceEntry::where('managed_task_id', $task->id)->count(),
            'a refused write must never leave a row behind'
        );

        $readAttempt = app(TaskWorkspaceQuery::class)->entriesForTask($task->owner_user_id, $task->id);
        $this->assertNull($readAttempt, 'entriesForTask() must return null -- not [] -- for a concluded task');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}/workspace");
        $response->assertStatus(404);
    }

    // =================================================================
    // (a) Completion via ManagerService::finalize()
    // =================================================================

    #[Test]
    public function completion_via_finalize_discards_the_workspace_and_refuses_further_reads_and_writes(): void
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $author = $this->makeAgent('Research Assistant');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task that will complete normally.');

        $entry = app(TaskWorkspaceService::class)->recordEntry($task, $author->id, 'A finding recorded before this task completes.');
        $this->assertNotNull($entry, 'fixture sanity -- the entry must be recorded before finalize()');
        $this->assertSame(1, TaskWorkspaceEntry::where('managed_task_id', $task->id)->count());

        $this->assertNull(app(ManagerService::class)->finalizeRefusal($task, null), 'fixture sanity -- no parts exist yet, finalize must be admitted');
        app(ManagerService::class)->finalize($task, 'All done.', null);

        $task->refresh();
        $this->assertSame('completed', $task->status, 'fixture sanity -- a task with no parts and no shortfall finalizes as plainly completed');

        $this->assertWorkspaceDiscardedAndInaccessible($task);
    }

    // =================================================================
    // (b) Forced failure via finalizeWithShortfall(), triggered by the
    // round ceiling (mirrors 103 quickstart scenario 4).
    // =================================================================

    #[Test]
    public function forced_failure_via_the_round_ceiling_discards_the_workspace_and_refuses_further_reads_and_writes(): void
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $author = $this->makeAgent('Research Assistant');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task that will hit its own round ceiling.');

        $entry = app(TaskWorkspaceService::class)->recordEntry($task, $author->id, 'A finding recorded before the ceiling forces a shortfall.');
        $this->assertNotNull($entry, 'fixture sanity -- the entry must be recorded before the ceiling is reached');
        $this->assertSame(1, TaskWorkspaceEntry::where('managed_task_id', $task->id)->count());

        // Drive rounds_used to the ceiling directly -- RunManagedTaskStepJob::
        // handle()'s pre-step check only reads these two columns, so there is
        // no need to reproduce the full assign_part/delegate round-by-round
        // journey ManagedTaskRoundCeilingJourneyTest.php already covers.
        $task->rounds_used = $task->round_ceiling;
        $task->save();

        $agentLoopService = Mockery::mock(AgentLoopService::class);
        $agentLoopService->shouldReceive('run')->never();

        Queue::fake();
        (new RunManagedTaskStepJob($task->id))->handle($agentLoopService);

        $task->refresh();
        $this->assertSame('completed_with_shortfalls', $task->status, 'fixture sanity -- the pre-step ceiling check must force-finalize with shortfall');

        $this->assertWorkspaceDiscardedAndInaccessible($task);
    }

    // =================================================================
    // (c) Abandonment via the resolve-stalled-managed-tasks sweep,
    // landing on finalizeWithShortfall() (mirrors 103 quickstart
    // scenario 8) -- proving the SAME discardForTask() call site inside
    // finalizeWithShortfall() covers both of its two distinct callers
    // identically.
    // =================================================================

    #[Test]
    public function abandonment_via_the_stale_sweep_discards_the_workspace_and_refuses_further_reads_and_writes(): void
    {
        config(['llm-client.manager.stale_after_minutes' => 10]);

        // A stale, in_progress task, directly constructed (no real
        // Conversation needed -- neither the sweep command nor
        // discardForTask()/entriesForTask()'s ownership check touches
        // conversations), owned by $this->user so the HTTP 404 check
        // below can authenticate as its owner.
        $task = ManagedTask::create([
            'conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $this->user->id,
            'manager_agent_id' => (string) Str::uuid(),
            'original_request' => 'A task abandoned by its own worker.',
            'status' => 'in_progress',
            'round_ceiling' => 30,
            'rounds_used' => 5,
            'max_seconds' => 1800,
            'last_progress_at' => now()->subMinutes(15), // stale: older than the 10-minute threshold
            'started_at' => now()->subMinutes(40), // 40 elapsed minutes HAS exceeded the 1800s (30-minute) max_seconds bound
        ]);

        $entry = TaskWorkspaceEntry::create([
            'managed_task_id' => $task->id,
            'owner_user_id' => $task->owner_user_id,
            'author_agent_id' => (string) Str::uuid(),
            'content' => 'A finding recorded before the sweep force-finalizes this abandoned task.',
        ]);
        $this->assertNotNull($entry, 'fixture sanity -- the entry must exist before the sweep runs');
        $this->assertSame(1, TaskWorkspaceEntry::where('managed_task_id', $task->id)->count());

        Queue::fake();
        $exitCode = Artisan::call('llm-client:resolve-stalled-managed-tasks');
        $this->assertSame(0, $exitCode);
        Queue::assertNotPushed(RunManagedTaskStepJob::class, null, 'fixture sanity -- a task past its own wall-clock bound must be force-finalized, never resumed');

        $task->refresh();
        $this->assertSame('completed_with_shortfalls', $task->status, 'fixture sanity -- the sweep must force-finalize this stale, past-max_seconds task');

        $this->assertWorkspaceDiscardedAndInaccessible($task);
    }
}
