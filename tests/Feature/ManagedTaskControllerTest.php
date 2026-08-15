<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ManagerService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 3 (US1), tasks.md T018.
 *
 * Feature tests for the not-yet-built `ManagedTaskController` (contracts/
 * manager-agent-api.md §1/§2): `POST /managed-tasks`, `GET
 * /managed-tasks/{id}`.
 *
 * Written before ManagedTaskController/the routes exist -- every request
 * below hits Laravel's own route-not-found 404 (a DIFFERENT shape from
 * this controller's own uniform not-found body), so every assertion is
 * expected to FAIL red until T026 creates the controller and routes.
 */
class ManagedTaskControllerTest extends TestCase
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
    // POST /managed-tasks
    // =================================================================

    #[Test]
    public function store_returns_202_and_dispatches_exactly_one_step_job_for_a_valid_request(): void
    {
        $manager = $this->makeAgent($this->user, 'manager-store');
        $helper = $this->makeAgent($this->user, 'helper-store');
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

        Queue::fake();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/managed-tasks', [
                'agent_id' => $manager->id,
                'request' => 'Produce a competitive analysis.',
            ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['managed_task_id', 'conversation_id', 'status']);
        $this->assertSame('in_progress', $response->json('status'));

        Queue::assertPushed(RunManagedTaskStepJob::class, 1);
        Queue::assertPushed(RunManagedTaskStepJob::class, function (RunManagedTaskStepJob $job) use ($response) {
            return $job->managedTaskId === $response->json('managed_task_id');
        });
    }

    #[Test]
    public function store_returns_404_when_agent_id_is_not_owned_by_the_caller(): void
    {
        $othersAgent = $this->makeAgent($this->otherUser, 'others-agent');

        Queue::fake();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/managed-tasks', [
                'agent_id' => $othersAgent->id,
                'request' => 'Do something.',
            ]);

        $response->assertStatus(404);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function store_returns_404_when_agent_id_does_not_exist(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/managed-tasks', [
                'agent_id' => (string) Str::uuid(),
                'request' => 'Do something.',
            ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function store_returns_422_no_assigned_helpers_when_the_agent_has_zero_active_helpers(): void
    {
        $manager = $this->makeAgent($this->user, 'manager-no-helpers');

        Queue::fake();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/managed-tasks', [
                'agent_id' => $manager->id,
                'request' => 'Do something.',
            ]);

        $response->assertStatus(422);
        $this->assertSame('no_assigned_helpers', $response->json('error'));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function store_returns_422_empty_request_for_an_empty_request_string(): void
    {
        $manager = $this->makeAgent($this->user, 'manager-empty-request');
        $helper = $this->makeAgent($this->user, 'helper-empty-request');
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

        Queue::fake();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/managed-tasks', [
                'agent_id' => $manager->id,
                'request' => '   ',
            ]);

        $response->assertStatus(422);
        $this->assertSame('empty_request', $response->json('error'));
        Queue::assertNothingPushed();
    }

    // =================================================================
    // GET /managed-tasks/{id}
    // =================================================================

    #[Test]
    public function show_returns_the_task_status_shape(): void
    {
        $manager = $this->makeAgent($this->user, 'manager-show');

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task to show.');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'managed_task_id' => $task->id,
            'status' => 'in_progress',
            'rounds_used' => 0,
            'round_ceiling' => $task->round_ceiling,
            'final_response' => null,
            'shortfall_note' => null,
            'conflict_note' => null,
        ]);
        $response->assertJsonStructure(['started_at', 'completed_at']);
    }

    #[Test]
    public function show_returns_404_for_a_task_not_owned_by_the_caller(): void
    {
        $manager = $this->makeAgent($this->otherUser, 'manager-other');
        $task = app(ManagerService::class)->createManagedTask($this->otherUser->id, $manager->id, 'Someone else\'s task.');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}");

        $response->assertStatus(404);
    }

    #[Test]
    public function show_returns_404_for_an_unknown_task_id(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/clarion-app/llm-client/managed-tasks/'.(string) Str::uuid());

        $response->assertStatus(404);
    }
}
