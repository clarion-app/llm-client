<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 105-stage-pipeline, Phase 3 (US1), tasks.md T020.
 *
 * Feature tests for `SequenceController`'s definition endpoints
 * (contracts/stage-pipeline-api.md §1/§2): `POST /sequence-definitions`,
 * `GET /sequence-definitions`, `GET /sequence-definitions/{id}`.
 *
 * Written before store()/index()/show() are implemented -- every request
 * below hits the T015 501 stub, so every status assertion is expected to
 * FAIL red until T025 lands.
 */
class SequenceDefinitionEndpointsTest extends TestCase
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

        DB::table('stage_results')->delete();
        DB::table('sequence_runs')->delete();
        DB::table('stages')->delete();
        DB::table('stage_sequence_definitions')->delete();
        DB::table('agent_helper_assignments')->delete();
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
    // POST /sequence-definitions
    // =================================================================

    #[Test]
    public function store_returns_201_with_the_contract_shape_for_a_valid_request(): void
    {
        $coordinator = $this->makeAgent($this->user, 'coordinator-store');
        $helper = $this->makeAgent($this->user, 'helper-store');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/sequence-definitions', [
                'name' => 'Draft, check, revise, finish',
                'description' => 'Standard pipeline',
                'coordinator_agent_id' => $coordinator->id,
                'stages' => [
                    ['name' => 'Draft', 'helper_agent_id' => $helper->id],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['sequence_definition_id', 'name', 'stages' => [['stage_id', 'position', 'name']]]);
        $this->assertSame('Draft, check, revise, finish', $response->json('name'));
        $this->assertSame(1, $response->json('stages.0.position'));
        $this->assertSame('Draft', $response->json('stages.0.name'));
    }

    #[Test]
    public function store_returns_422_empty_name_for_a_missing_name(): void
    {
        $coordinator = $this->makeAgent($this->user, 'coordinator-missing-name');
        $helper = $this->makeAgent($this->user, 'helper-missing-name');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/sequence-definitions', [
                'coordinator_agent_id' => $coordinator->id,
                'stages' => [
                    ['name' => 'Stage', 'helper_agent_id' => $helper->id],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertSame('empty_name', $response->json('error'));
    }

    #[Test]
    public function store_returns_422_empty_stages_for_an_empty_stages_array(): void
    {
        $coordinator = $this->makeAgent($this->user, 'coordinator-empty-stages');

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/sequence-definitions', [
                'name' => 'Empty',
                'coordinator_agent_id' => $coordinator->id,
                'stages' => [],
            ]);

        $response->assertStatus(422);
        $this->assertSame('empty_stages', $response->json('error'));
    }

    #[Test]
    public function store_returns_422_unknown_coordinator_agent_for_a_not_owned_coordinator(): void
    {
        $foreignCoordinator = $this->makeAgent($this->otherUser, 'foreign-coordinator');

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/sequence-definitions', [
                'name' => 'Bad coordinator',
                'coordinator_agent_id' => $foreignCoordinator->id,
                'stages' => [
                    ['name' => 'Stage', 'helper_agent_id' => $foreignCoordinator->id],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertSame('unknown_coordinator_agent', $response->json('error'));
    }

    #[Test]
    public function store_returns_422_unknown_helper_agent_naming_the_stage_position(): void
    {
        $coordinator = $this->makeAgent($this->user, 'coordinator-bad-helper');
        $notAssigned = $this->makeAgent($this->user, 'not-assigned-helper');

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/sequence-definitions', [
                'name' => 'Bad helper',
                'coordinator_agent_id' => $coordinator->id,
                'stages' => [
                    ['name' => 'Stage', 'helper_agent_id' => $notAssigned->id],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertSame('unknown_helper_agent', $response->json('error'));
        $this->assertSame(1, $response->json('stage_position'));
    }

    // =================================================================
    // GET /sequence-definitions
    // =================================================================

    #[Test]
    public function index_is_owner_scoped(): void
    {
        $coordinator = $this->makeAgent($this->user, 'coordinator-index');
        $helper = $this->makeAgent($this->user, 'helper-index');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $mine = app(\ClarionApp\LlmClient\Services\SequenceService::class)->defineSequence(
            $this->user->id, 'Mine', null, $coordinator->id, [['name' => 'Stage', 'helper_agent_id' => $helper->id]],
        );

        $otherCoordinator = $this->makeAgent($this->otherUser, 'coordinator-other');
        $otherHelper = $this->makeAgent($this->otherUser, 'helper-other');
        app(AgentHelperService::class)->assign($this->otherUser->id, $otherCoordinator->id, $otherHelper->id);
        app(\ClarionApp\LlmClient\Services\SequenceService::class)->defineSequence(
            $this->otherUser->id, 'Not mine', null, $otherCoordinator->id, [['name' => 'Stage', 'helper_agent_id' => $otherHelper->id]],
        );

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/clarion-app/llm-client/sequence-definitions');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('sequence_definition_id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertCount(1, $ids, 'index() must be owner-scoped, never listing another user\'s definitions');
    }

    // =================================================================
    // GET /sequence-definitions/{id}
    // =================================================================

    #[Test]
    public function show_returns_the_ordered_stages(): void
    {
        $coordinator = $this->makeAgent($this->user, 'coordinator-show');
        $helperOne = $this->makeAgent($this->user, 'helper-show-one');
        $helperTwo = $this->makeAgent($this->user, 'helper-show-two');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helperOne->id);
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helperTwo->id);

        $definition = app(\ClarionApp\LlmClient\Services\SequenceService::class)->defineSequence(
            $this->user->id, 'Show me', null, $coordinator->id, [
                ['name' => 'First', 'helper_agent_id' => $helperOne->id],
                ['name' => 'Second', 'helper_agent_id' => $helperTwo->id],
            ],
        );

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/sequence-definitions/{$definition->id}");

        $response->assertStatus(200);
        $this->assertSame($definition->id, $response->json('sequence_definition_id'));
        $this->assertCount(2, $response->json('stages'));
        $this->assertSame(1, $response->json('stages.0.position'));
        $this->assertSame('First', $response->json('stages.0.name'));
        $this->assertSame(2, $response->json('stages.1.position'));
        $this->assertSame('Second', $response->json('stages.1.name'));
    }

    #[Test]
    public function show_returns_a_uniform_404_when_absent_or_not_owned(): void
    {
        $coordinator = $this->makeAgent($this->otherUser, 'coordinator-foreign-show');
        $helper = $this->makeAgent($this->otherUser, 'helper-foreign-show');
        app(AgentHelperService::class)->assign($this->otherUser->id, $coordinator->id, $helper->id);

        $foreignDefinition = app(\ClarionApp\LlmClient\Services\SequenceService::class)->defineSequence(
            $this->otherUser->id, 'Not mine', null, $coordinator->id, [['name' => 'Stage', 'helper_agent_id' => $helper->id]],
        );

        $notOwnedResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/sequence-definitions/{$foreignDefinition->id}");
        $notOwnedResponse->assertStatus(404);

        $absentResponse = $this->actingAs($this->user, 'api')
            ->getJson('/api/clarion-app/llm-client/sequence-definitions/does-not-exist');
        $absentResponse->assertStatus(404);

        $this->assertSame(
            $notOwnedResponse->json('error'),
            $absentResponse->json('error'),
            'a not-owned definition and a genuinely absent one must return the SAME uniform 404 shape',
        );
    }
}
