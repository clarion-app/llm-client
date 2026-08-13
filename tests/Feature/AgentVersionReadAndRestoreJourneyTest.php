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
 * spec.md US2 Acceptance Scenarios 1-3 / quickstart.md step 4, through the
 * real HTTP endpoints (contracts §5/§6/§7): every version of an agent can be
 * listed in order without leaking raw_definition; any past version's exact
 * definition can be read regardless of later edits; restoring to an earlier
 * version makes its content current again as a brand-new version, leaving
 * every version in between untouched and individually readable.
 *
 * Also covers contracts §2 (`GET /agents`), otherwise untested by any other
 * file in this feature: the caller's own agents all appear in the list.
 */
class AgentVersionReadAndRestoreJourneyTest extends TestCase
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
     * (AgentDefinitionMinimalJourneyTest's own established convention).
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

    private function createAgent(string $name): string
    {
        return $this->actingAs($this->user)
            ->postJson($this->base(), ['definition' => "name: {$name}"])
            ->assertStatus(201)
            ->json('id');
    }

    #[Test]
    public function versions_can_be_listed_read_individually_and_restored_without_disturbing_versions_in_between(): void
    {
        $agentId = $this->createAgent('weather-agent-v1');

        $this->actingAs($this->user)
            ->putJson($this->agentUrl($agentId), ['definition' => 'name: weather-agent-v2'])
            ->assertStatus(200);

        $this->actingAs($this->user)
            ->putJson($this->agentUrl($agentId), ['definition' => 'name: weather-agent-v3'])
            ->assertStatus(200);

        $versionRows = AgentVersion::where('agent_id', $agentId)->orderBy('version_number')->get();
        $this->assertCount(3, $versionRows);
        $v1Id = $versionRows[0]->id;
        $v2Id = $versionRows[1]->id;
        $v3Id = $versionRows[2]->id;

        // ---- GET /agents/{id}/versions (contracts §5) ----
        $listResponse = $this->actingAs($this->user)->getJson($this->versionsUrl($agentId));
        $listResponse->assertStatus(200);

        $listedIds = collect($listResponse->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$v1Id, $v2Id, $v3Id], $listedIds, 'every version must be listed');

        $listedNumbers = collect($listResponse->json('data'))->pluck('version_number')->all();
        $this->assertSame([1, 2, 3], $listedNumbers, 'versions must be listed in ascending order');

        foreach ($listResponse->json('data') as $entry) {
            $this->assertArrayNotHasKey('raw_definition', $entry, 'the list must never include raw_definition');
        }

        // ---- GET /agents/{id}/versions/{v1Id} (contracts §6) ----
        $v1DetailResponse = $this->actingAs($this->user)->getJson($this->versionUrl($agentId, $v1Id));
        $v1DetailResponse->assertStatus(200);
        $this->assertSame('name: weather-agent-v1', $v1DetailResponse->json('raw_definition'), 'reading v1 must return exactly what was originally submitted, unaffected by the two later edits');
        $this->assertSame(1, $v1DetailResponse->json('version_number'));
        $resolvedFromV1 = $v1DetailResponse->json('resolved');
        $this->assertNotNull($resolvedFromV1, 'v1 must still resolve against current installation state');

        // ---- POST /agents/{id}/versions/{v1Id}/restore (contracts §7) ----
        $restoreResponse = $this->actingAs($this->user)->postJson($this->restoreUrl($agentId, $v1Id));
        $restoreResponse->assertStatus(200);

        $this->assertSame(4, DB::table('agent_versions')->where('agent_id', $agentId)->count(), 'restore must create a new version 4, not repoint at version 1');

        $v4 = AgentVersion::where('agent_id', $agentId)->where('version_number', 4)->first();
        $this->assertNotNull($v4);
        $this->assertSame('name: weather-agent-v1', $v4->raw_definition, 'the new version must match version 1 exactly');
        $this->assertSame($v1Id, $v4->restored_from_version_id);

        // ---- GET /agents/{id} now resolves identically to version 1 ----
        $showResponse = $this->actingAs($this->user)->getJson($this->agentUrl($agentId));
        $showResponse->assertStatus(200);
        $this->assertEquals($resolvedFromV1, $showResponse->json('definition'), "the agent's current resolved definition must now match what version 1's own resolution produces");

        // ---- versions 1-3 remain unchanged and individually readable ----
        foreach ([$v1Id => 'name: weather-agent-v1', $v2Id => 'name: weather-agent-v2', $v3Id => 'name: weather-agent-v3'] as $id => $expectedRaw) {
            $detail = $this->actingAs($this->user)->getJson($this->versionUrl($agentId, $id));
            $detail->assertStatus(200);
            $this->assertSame($expectedRaw, $detail->json('raw_definition'), "version {$id} must remain unchanged and readable after the restore");
        }
    }

    // ---------------------------------------------------------------
    // contracts §2 — GET /agents lists every one of the caller's own
    // agents, not just the most recently touched one
    // ---------------------------------------------------------------

    #[Test]
    public function get_agents_lists_every_agent_the_caller_owns(): void
    {
        $firstId = $this->createAgent('first-agent');
        $this->actingAs($this->user)
            ->putJson($this->agentUrl($firstId), ['definition' => 'name: first-agent-v2'])
            ->assertStatus(200);

        $secondId = $this->createAgent('second-agent');

        $listResponse = $this->actingAs($this->user)->getJson($this->base());
        $listResponse->assertStatus(200);

        $byId = collect($listResponse->json('data'))->keyBy('id');

        $this->assertTrue($byId->has($firstId), 'the first, earlier-touched agent must still appear');
        $this->assertSame(2, $byId->get($firstId)['current_version_number']);

        $this->assertTrue($byId->has($secondId), 'a second, unrelated agent by the same caller must also appear');
        $this->assertSame(1, $byId->get($secondId)['current_version_number']);
    }
}
