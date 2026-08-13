<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\AgentVersion;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Per-user isolation across the whole `agents` HTTP surface (Constitution
 * §IV, research.md D5, quickstart.md step 8 / mutation-checklist row 3) —
 * not named by any single spec.md FR, but binding on every endpoint built
 * across Phases 3-5 alike, exactly as it is for RunController (070)'s own
 * findRun() precedent this feature's AgentQuery::findAgent() mirrors.
 *
 * A caller must never be able to distinguish "not yours" (a real agent id,
 * owned by someone else) from "doesn't exist" (an id naming no row at
 * all) — every foreign-owned lookup below is asserted to produce a 404
 * whose body is byte-identical to a request against a genuinely
 * nonexistent id, never a 403 (which would leak existence across users).
 */
class AgentPerUserIsolationJourneyTest extends TestCase
{
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
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

    private function base(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function agentUrl(string $id): string
    {
        return $this->base().'/'.$id;
    }

    private function versionsUrl(string $agentId): string
    {
        return $this->agentUrl($agentId).'/versions';
    }

    private function versionUrl(string $agentId, string $versionId): string
    {
        return $this->versionsUrl($agentId).'/'.$versionId;
    }

    private function restoreUrl(string $agentId, string $versionId): string
    {
        return $this->versionUrl($agentId, $versionId).'/restore';
    }

    /**
     * Seeds both of ApiManager's live-catalog seams — required before any
     * *valid* AgentDefinitionParser::parse() call, since parse()
     * unconditionally resolves the operation catalog once per call
     * (AgentServiceTest's own established convention).
     */
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
    // Every single-agent action is a uniform 404 for the wrong user,
    // byte-identical to a request against a nonexistent id.
    // ---------------------------------------------------------------

    #[Test]
    public function every_single_agent_action_is_a_uniform_404_for_a_foreign_owned_agent_matching_a_nonexistent_id(): void
    {
        // User A creates an agent with 2+ versions.
        $agentId = $this->actingAs($this->userA)
            ->postJson($this->base(), ['definition' => 'name: user-a-agent-v1'])
            ->assertStatus(201)
            ->json('id');

        $this->actingAs($this->userA)
            ->putJson($this->agentUrl($agentId), ['definition' => 'name: user-a-agent-v2'])
            ->assertStatus(200);

        $versionId = AgentVersion::where('agent_id', $agentId)->orderBy('version_number')->first()->id;

        $versionCountBefore = AgentVersion::where('agent_id', $agentId)->count();
        $currentVersionIdBefore = DB::table('agents')->where('id', $agentId)->value('current_version_id');

        $nonexistentAgentId = (string) \Illuminate\Support\Str::uuid();
        $nonexistentVersionId = (string) \Illuminate\Support\Str::uuid();

        $cases = [
            'GET /agents/{id}' => fn ($id, $vId) => $this->getJson($this->agentUrl($id)),
            'PUT /agents/{id}' => fn ($id, $vId) => $this->putJson($this->agentUrl($id), ['definition' => 'name: hijacked']),
            'GET /agents/{id}/versions' => fn ($id, $vId) => $this->getJson($this->versionsUrl($id)),
            'GET /agents/{id}/versions/{versionId}' => fn ($id, $vId) => $this->getJson($this->versionUrl($id, $vId)),
            'POST /agents/{id}/versions/{versionId}/restore' => fn ($id, $vId) => $this->postJson($this->restoreUrl($id, $vId)),
        ];

        foreach ($cases as $label => $makeRequest) {
            // User B against User A's real agent (and, where applicable,
            // User A's real version id).
            $this->actingAs($this->userB);
            $foreignResponse = ($makeRequest->bindTo($this))($agentId, $versionId);

            $foreignResponse->assertStatus(404);

            // The identical shape against a genuinely nonexistent agent id
            // (and a genuinely nonexistent version id, for the two
            // version-scoped actions) — still as User B, so only the id's
            // existence varies between the two requests.
            $nonexistentResponse = ($makeRequest->bindTo($this))($nonexistentAgentId, $nonexistentVersionId);
            $nonexistentResponse->assertStatus(404);

            $this->assertSame(
                $nonexistentResponse->json(),
                $foreignResponse->json(),
                "{$label}: a foreign-owned agent's 404 body must be identical to a nonexistent agent's 404 body — a caller must not be able to distinguish \"not yours\" from \"doesn't exist\"",
            );
        }

        // User A's data is unchanged after every one of User B's attempts.
        $this->assertSame(
            $versionCountBefore,
            AgentVersion::where('agent_id', $agentId)->count(),
            "User B's attempts must not have created or removed any version",
        );
        $this->assertSame(
            $currentVersionIdBefore,
            DB::table('agents')->where('id', $agentId)->value('current_version_id'),
            "User B's attempts must not have changed which version is current",
        );

        $stillReadable = $this->actingAs($this->userA)->getJson($this->agentUrl($agentId));
        $stillReadable->assertStatus(200);
        $this->assertSame('user-a-agent-v2', $stillReadable->json('name'), "User A's agent must be unaffected by User B's attempts");
    }

    // ---------------------------------------------------------------
    // GET /agents' own isolation — a separate query path from
    // findAgent() (AgentQuery::listForUser()), not implied by the
    // single-agent checks above.
    // ---------------------------------------------------------------

    #[Test]
    public function get_agents_list_is_isolated_per_user(): void
    {
        $agentAId = $this->actingAs($this->userA)
            ->postJson($this->base(), ['definition' => 'name: user-a-only-agent'])
            ->assertStatus(201)
            ->json('id');

        // User B attempts a few actions against it first, to prove they
        // have no side effect on either user's own listing.
        $this->actingAs($this->userB)->getJson($this->agentUrl($agentAId))->assertStatus(404);
        $this->actingAs($this->userB)->putJson($this->agentUrl($agentAId), ['definition' => 'name: hijacked'])->assertStatus(404);

        $userBList = $this->actingAs($this->userB)->getJson($this->base());
        $userBList->assertStatus(200);
        $userBIds = collect($userBList->json('data'))->pluck('id')->all();
        $this->assertNotContains($agentAId, $userBIds, "User B's own list must never include User A's agent");

        $userAList = $this->actingAs($this->userA)->getJson($this->base());
        $userAList->assertStatus(200);
        $userAIds = collect($userAList->json('data'))->pluck('id')->all();
        $this->assertContains($agentAId, $userAIds, "User A's own list must still show their agent, unaffected by User B's attempts");
    }
}
