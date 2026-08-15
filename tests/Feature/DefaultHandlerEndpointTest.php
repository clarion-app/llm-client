<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 102-router-pattern, Phase 6 (US4, T043).
 *
 * contracts/routing-mechanism.md §4, data-model.md §1 — the two new
 * default-handler-designation routes, mirroring `POST /agents/{id}/activate`
 * / `POST /agents/{id}/deactivate` exactly: same controller
 * (StoredAgentController), same auth group, same ownership resolution
 * (AgentQuery::findAgent(), owned-only, 404 for "not found or not yours").
 * AgentActivationJourneyTest.php's own URL-builder-helper/ownership-scoping
 * style (setUp()/tearDown()/seedOperationCatalog()/createAgent()/agentUrl())
 * is this file's direct structural precedent, read in full before writing
 * these cases.
 *
 * Written before `POST /agents/{id}/default-handler` /
 * `DELETE /agents/{id}/default-handler` exist as routes at all — every test
 * in this file is expected to FAIL with a 404 "Not Found" (an unrouted
 * verb+path, not the ownership-scoped 404 StoredAgentController itself
 * would return) until Phase 6's own implementation tasks (T046-T048) add
 * the AgentService methods, controller actions, and routes.
 */
class DefaultHandlerEndpointTest extends TestCase
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
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // URL helpers (AgentActivationJourneyTest's own precedent)
    // ---------------------------------------------------------------

    private function agentsUrl(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function agentUrl(string $id): string
    {
        return $this->agentsUrl().'/'.$id;
    }

    private function defaultHandlerUrl(string $id): string
    {
        return "{$this->agentUrl($id)}/default-handler";
    }

    // ---------------------------------------------------------------
    // Operation catalog seam (AgentActivationJourneyTest's own precedent)
    // ---------------------------------------------------------------

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
    // Fixture helper
    // ---------------------------------------------------------------

    private function createAgent(string $definition, ?User $as = null): string
    {
        return $this->actingAs($as ?? $this->user)
            ->postJson($this->agentsUrl(), ['definition' => $definition])
            ->assertStatus(201)
            ->json('id');
    }

    // =================================================================
    // POST /agents/{id}/default-handler — the caller's own agent
    // =================================================================

    #[Test]
    public function posting_default_handler_on_the_callers_own_agent_returns_200_with_the_flag_set(): void
    {
        $agentId = $this->createAgent('name: agent-a');

        $response = $this->actingAs($this->user)->postJson($this->defaultHandlerUrl($agentId));

        $response->assertStatus(200);
        $this->assertTrue($response->json('is_default_handler'), 'the response body must report is_default_handler: true');
        $this->assertArrayHasKey(
            'is_active',
            $response->json(),
            'the response must carry the existing is_active field alongside the new is_default_handler field, not replace it',
        );
        $this->assertTrue((bool) Agent::find($agentId)->is_default_handler, 'the flag must actually be persisted');
    }

    // =================================================================
    // DELETE /agents/{id}/default-handler — the caller's own agent
    // =================================================================

    #[Test]
    public function deleting_default_handler_on_the_callers_own_agent_returns_200_with_the_flag_cleared(): void
    {
        $agentId = $this->createAgent('name: agent-a');
        $this->actingAs($this->user)->postJson($this->defaultHandlerUrl($agentId))->assertStatus(200);
        $this->assertTrue((bool) Agent::find($agentId)->is_default_handler, 'fixture sanity: the agent must actually be the default handler first');

        $response = $this->actingAs($this->user)->deleteJson($this->defaultHandlerUrl($agentId));

        $response->assertStatus(200);
        $this->assertFalse($response->json('is_default_handler'), 'the response body must report is_default_handler: false');
        $this->assertFalse((bool) Agent::find($agentId)->is_default_handler, 'the cleared flag must actually be persisted');
    }

    // =================================================================
    // Ownership scoping — another user's agent id, either verb
    // =================================================================

    #[Test]
    public function posting_default_handler_against_another_users_agent_returns_404(): void
    {
        $userB = User::factory()->create();
        $agentId = $this->createAgent('name: user-a-agent');

        $response = $this->actingAs($userB)->postJson($this->defaultHandlerUrl($agentId));

        $response->assertStatus(404);
        $this->assertFalse(
            (bool) Agent::find($agentId)->is_default_handler,
            "user B's attempt must never change user A's agent state",
        );
    }

    #[Test]
    public function deleting_default_handler_against_another_users_agent_returns_404(): void
    {
        $userB = User::factory()->create();
        $agentId = $this->createAgent('name: user-a-agent');
        $this->actingAs($this->user)->postJson($this->defaultHandlerUrl($agentId))->assertStatus(200);
        $this->assertTrue((bool) Agent::find($agentId)->is_default_handler, 'fixture sanity: the agent must actually be the default handler first');

        $response = $this->actingAs($userB)->deleteJson($this->defaultHandlerUrl($agentId));

        $response->assertStatus(404);
        $this->assertTrue(
            (bool) Agent::find($agentId)->is_default_handler,
            "user B's attempt must never change user A's agent state",
        );
    }

    // =================================================================
    // Nonexistent id, either verb
    // =================================================================

    #[Test]
    public function posting_default_handler_against_a_nonexistent_id_returns_404(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->defaultHandlerUrl((string) \Illuminate\Support\Str::uuid()));

        $response->assertStatus(404);
    }

    #[Test]
    public function deleting_default_handler_against_a_nonexistent_id_returns_404(): void
    {
        $response = $this->actingAs($this->user)->deleteJson($this->defaultHandlerUrl((string) \Illuminate\Support\Str::uuid()));

        $response->assertStatus(404);
    }
}
