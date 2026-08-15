<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\ManagedTaskUpdated;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\Models\Server;
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
 * 103-manager-agent, Phase 3 (US1), tasks.md T015.
 *
 * Unit tests for the not-yet-built `ManagerService::createManagedTask()`/
 * `planParts()` (data-model.md §5, research.md D1/D5/D7/D8, contracts/
 * manager-agent-api.md §1, contracts/manager-agent-meta-tools.md §1).
 *
 * Written before ManagerService exists -- every test below is expected to
 * FAIL red (class not found) until T021 creates it.
 */
class ManagerServiceTest extends TestCase
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

        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_role_assignments')->delete();
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

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    // =================================================================
    // createManagedTask()
    // =================================================================

    #[Test]
    public function creates_a_dedicated_managed_task_conversation_and_a_managed_task_row(): void
    {
        $manager = $this->makeAgent('manager-create');

        Event::fake([ManagedTaskUpdated::class]);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'Produce a competitive analysis.');

        $this->assertInstanceOf(ManagedTask::class, $task);
        $this->assertSame('in_progress', $task->status);
        $this->assertSame($this->user->id, $task->owner_user_id);
        $this->assertSame($manager->id, $task->manager_agent_id);
        $this->assertSame('Produce a competitive analysis.', $task->original_request);
        $this->assertSame(0, $task->rounds_used);
        $this->assertNotNull($task->last_progress_at);
        $this->assertNotNull($task->started_at);
        $this->assertNull($task->completed_at);

        $conversation = Conversation::find($task->conversation_id);
        $this->assertNotNull($conversation, 'createManagedTask() must create a dedicated Conversation row');
        $this->assertSame('managed-task', $conversation->channel);
        $this->assertSame($manager->id, $conversation->agent_id);
        $this->assertSame($manager->current_version_id, $conversation->agent_version_id);
        $this->assertSame($this->user->id, $conversation->user_id);

        Event::assertDispatched(ManagedTaskUpdated::class, fn (ManagedTaskUpdated $e) => $e->managedTaskId === $task->id);
    }

    #[Test]
    public function snapshots_round_ceiling_and_max_seconds_from_config_at_creation_time(): void
    {
        $manager = $this->makeAgent('manager-snapshot');

        config(['llm-client.manager.max_rounds' => 7, 'llm-client.manager.max_seconds' => 900]);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A bounded task.');

        $this->assertSame(7, $task->round_ceiling);
        $this->assertSame(900, $task->max_seconds);

        // A later config change must never retroactively change an
        // already-created task's own snapshotted values (research.md D5).
        config(['llm-client.manager.max_rounds' => 99, 'llm-client.manager.max_seconds' => 99999]);

        $task->refresh();
        $this->assertSame(7, $task->round_ceiling, 'round_ceiling must stay snapshotted, never re-read from config after creation');
        $this->assertSame(900, $task->max_seconds, 'max_seconds must stay snapshotted, never re-read from config after creation');
    }

    // =================================================================
    // planParts()
    // =================================================================

    #[Test]
    public function plan_parts_creates_parts_at_sequential_sequence_starting_at_one(): void
    {
        $manager = $this->makeAgent('manager-plan');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task with three parts.');

        Event::fake([ManagedTaskUpdated::class]);

        $parts = app(ManagerService::class)->planParts($task, ['First part.', 'Second part.', 'Third part.']);

        $this->assertCount(3, $parts);
        foreach ($parts as $index => $part) {
            $this->assertInstanceOf(ManagedTaskPart::class, $part);
            $this->assertSame($task->id, $part->managed_task_id);
            $this->assertSame('not_yet_assigned', $part->state);
            $this->assertSame($index + 1, $part->sequence);
            $this->assertSame(0, $part->assignment_count);
        }

        $this->assertSame(3, ManagedTaskPart::where('managed_task_id', $task->id)->count());

        Event::assertDispatched(ManagedTaskUpdated::class, fn (ManagedTaskUpdated $e) => $e->managedTaskId === $task->id);

        $task->refresh();
        $this->assertNotNull($task->last_progress_at);
    }

    #[Test]
    public function plan_parts_is_additive_across_repeated_calls_never_deleting_or_renumbering_existing_parts(): void
    {
        $manager = $this->makeAgent('manager-plan-additive');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task discovered to need more parts.');

        $firstBatch = app(ManagerService::class)->planParts($task, ['Alpha.', 'Beta.']);
        $firstBatchIds = array_map(fn (ManagedTaskPart $p) => $p->id, $firstBatch);

        $secondBatch = app(ManagerService::class)->planParts($task, ['Gamma.']);

        $this->assertSame(3, ManagedTaskPart::where('managed_task_id', $task->id)->count(), 'planParts() must never delete existing parts');

        // Existing parts untouched -- same id, same sequence.
        foreach ($firstBatch as $index => $part) {
            $stillThere = ManagedTaskPart::find($part->id);
            $this->assertNotNull($stillThere, 'an existing part must never be deleted by a later planParts() call');
            $this->assertSame($index + 1, $stillThere->sequence, 'an existing part must never be renumbered by a later planParts() call');
        }

        $this->assertSame(3, $secondBatch[0]->sequence, 'a newly added part must continue the sequence, not restart it');
        $this->assertNotContains($secondBatch[0]->id, $firstBatchIds);
    }

    #[Test]
    public function last_progress_at_advances_on_every_plan_parts_write(): void
    {
        $manager = $this->makeAgent('manager-progress');
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task.');

        $originalProgress = $task->last_progress_at;

        usleep(1000);

        app(ManagerService::class)->planParts($task, ['Only part.']);

        $task->refresh();
        $this->assertTrue($task->last_progress_at->greaterThanOrEqualTo($originalProgress), 'last_progress_at must advance (or at minimum never regress) on a planParts() write');
    }
}
