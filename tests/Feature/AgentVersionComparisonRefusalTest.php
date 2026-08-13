<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md Edge Cases / FR-010, quickstart.md steps 12-13, contracts §4 —
 * Phase 5/T033 (090-agent-version-binding). Covers the refusal paths of
 * `GET agents/versions/compare` distinctly from one another: same-version,
 * different-agents, not-found (three sub-cases), and an unresolvable
 * version (research.md D8).
 *
 * Written first, confirmed FAILS — no such route is registered yet, so
 * every request here 404s at the router itself (a generic Laravel
 * "not found" response, not this controller's own {error, code} shape),
 * and the 422 cases cannot be reached at all.
 */
class AgentVersionComparisonRefusalTest extends TestCase
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

        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function base(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function seedOperationCatalog(array $operations = []): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
            ];
        }
        $doc = ['paths' => $paths];

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

    // ---------------------------------------------------------------
    // Comparing a version against itself is refused, not silently
    // "no differences" — distinct from a genuine identical: true result
    // for two DIFFERENT version ids with identical content.
    // ---------------------------------------------------------------

    #[Test]
    public function comparing_a_version_against_itself_returns_422_naming_same_version(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: solo-agent");
        $v1Id = $agent->current_version_id;

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->base()."/versions/compare?left={$v1Id}&right={$v1Id}");

        $response->assertStatus(422);
        $response->assertExactJson([
            'error' => 'same_version',
            'message' => 'Cannot compare a version against itself.',
            'kind' => 'SameVersion',
        ]);
    }

    // ---------------------------------------------------------------
    // Comparing versions from different agents is refused, distinctly
    // from "not found."
    // ---------------------------------------------------------------

    #[Test]
    public function comparing_versions_from_different_agents_returns_422_naming_different_agents(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b");

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->base()."/versions/compare?left={$agentA->current_version_id}&right={$agentB->current_version_id}");

        $response->assertStatus(422);
        $response->assertExactJson([
            'error' => 'different_agents',
            'message' => 'Cannot compare versions belonging to different agents.',
            'kind' => 'DifferentAgents',
        ]);
        $this->assertNotSame(404, $response->getStatusCode(), 'must be a distinguishable 422, never a generic 404');
    }

    // ---------------------------------------------------------------
    // Either id absent, nonexistent, or belonging to another user's agent
    // is refused with a uniform 404 — three sub-cases.
    // ---------------------------------------------------------------

    #[Test]
    public function a_random_uuid_for_left_returns_a_uniform_404(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: right-side-agent");

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->base()."/versions/compare?left=11111111-1111-1111-1111-111111111111&right={$agent->current_version_id}");

        $response->assertStatus(404);
        $response->assertExactJson([
            'error' => 'Agent version not found',
            'code' => 'agent_version_not_found',
        ]);
    }

    #[Test]
    public function a_version_belonging_to_another_users_agent_for_right_returns_a_uniform_404(): void
    {
        $myAgent = app(AgentService::class)->create($this->user->id, "name: left-side-agent");

        $otherUser = User::factory()->create();
        $otherAgent = app(AgentService::class)->create($otherUser->id, "name: not-yours-agent");

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->base()."/versions/compare?left={$myAgent->current_version_id}&right={$otherAgent->current_version_id}");

        $response->assertStatus(404);
        $response->assertExactJson([
            'error' => 'Agent version not found',
            'code' => 'agent_version_not_found',
        ]);
    }

    #[Test]
    public function two_well_formed_but_nonexistent_ids_return_a_uniform_404(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->base().'/versions/compare?left=11111111-1111-1111-1111-111111111111&right=22222222-2222-2222-2222-222222222222');

        $response->assertStatus(404);
        $response->assertExactJson([
            'error' => 'Agent version not found',
            'code' => 'agent_version_not_found',
        ]);
    }

    // ---------------------------------------------------------------
    // Either version's raw_definition fails to parse against current
    // installation state (research.md D8) — identical shape to
    // StoredAgentController::definitionErrorResponse().
    // ---------------------------------------------------------------

    #[Test]
    public function a_version_that_no_longer_resolves_returns_422_naming_the_specific_resolution_problem(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Primary']);
        $model = LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'retiring-model', 'server_id' => $server->id]);

        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: refusal-agent\nmodel: retiring-model",
        );
        $v1Id = $agent->current_version_id;

        $agent = app(AgentService::class)->update(
            $agent,
            $this->user->id,
            "name: refusal-agent\ninstructions: unrelated change.",
        );
        $v2Id = $agent->current_version_id;

        // The first version's named model no longer exists on this
        // installation.
        $model->delete();

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->base()."/versions/compare?left={$v1Id}&right={$v2Id}");

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'unknown_model');
        $response->assertJsonPath('kind', 'UnknownModel');
        $this->assertStringContainsString('retiring-model', $response->json('message'), 'the 422 body must name the specific unresolvable model');
    }
}
