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
 * spec.md US1 Acceptance Scenarios 2-3 / quickstart.md steps 2-3, through
 * the real HTTP endpoints: POST then PUT twice with different content each
 * time produces three distinct, ordered, individually attributed versions,
 * none overwriting a prior one (FR-002/FR-003/SC-002); an update naming an
 * unresolvable capability is refused before anything is written
 * (contracts §1/§4, 086 reuse).
 */
class AgentChangeHistoryJourneyTest extends TestCase
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

    // ---------------------------------------------------------------
    // Step 2 — each change produces its own distinct version
    // (AC2/AC3/SC-002)
    // ---------------------------------------------------------------

    #[Test]
    public function each_of_three_writes_produces_its_own_distinct_ordered_version_none_overwriting_a_prior_one(): void
    {
        $agentId = $this->createAgent('weather-agent-v1');

        $this->actingAs($this->user)
            ->putJson($this->agentUrl($agentId), ['definition' => "name: weather-agent-v2"])
            ->assertStatus(200);

        $this->actingAs($this->user)
            ->putJson($this->agentUrl($agentId), ['definition' => "name: weather-agent-v3"])
            ->assertStatus(200);

        $versions = AgentVersion::where('agent_id', $agentId)->orderBy('version_number')->get();

        $this->assertCount(3, $versions, 'three writes must produce exactly three version rows');
        $this->assertSame([1, 2, 3], $versions->pluck('version_number')->all());

        $ids = $versions->pluck('id')->all();
        $this->assertSame($ids, array_unique($ids), 'every version must have a distinct id');

        $rawDefinitions = $versions->pluck('raw_definition')->all();
        $this->assertSame($rawDefinitions, array_unique($rawDefinitions), 'every version must hold distinct content');
        $this->assertSame('name: weather-agent-v1', $rawDefinitions[0]);
        $this->assertSame('name: weather-agent-v2', $rawDefinitions[1]);
        $this->assertSame('name: weather-agent-v3', $rawDefinitions[2]);

        foreach ($versions as $version) {
            $this->assertNotNull($version->created_at);
            $this->assertSame($this->user->id, $version->changed_by_user_id);
        }

        // The first two rows, captured again after the third write, must
        // remain exactly what they were — never overwritten or absorbed
        // (FR-003, mutation-checklist row 1's own property applied to a
        // three-write chain).
        $v1After = AgentVersion::find($versions[0]->id);
        $v2After = AgentVersion::find($versions[1]->id);
        $this->assertSame('name: weather-agent-v1', $v1After->raw_definition);
        $this->assertSame('name: weather-agent-v2', $v2After->raw_definition);
    }

    // ---------------------------------------------------------------
    // Step 3 — invalid content refused before writing (086 reuse)
    // ---------------------------------------------------------------

    #[Test]
    public function updating_with_a_document_naming_an_unknown_capability_writes_no_version_and_leaves_current_version_unchanged(): void
    {
        $agentId = $this->createAgent('weather-agent');

        $versionCountBefore = AgentVersion::where('agent_id', $agentId)->count();
        $currentVersionIdBefore = DB::table('agents')->where('id', $agentId)->value('current_version_id');

        $response = $this->actingAs($this->user)->putJson($this->agentUrl($agentId), [
            'definition' => <<<YAML
name: weather-agent
capabilities: [web_browsing]
YAML,
        ]);

        $response->assertStatus(422);
        $this->assertSame('UnknownCapability', $response->json('kind'));

        $this->assertSame(
            $versionCountBefore,
            AgentVersion::where('agent_id', $agentId)->count(),
            'a rejected update must write no new version row',
        );
        $this->assertSame(
            $currentVersionIdBefore,
            DB::table('agents')->where('id', $agentId)->value('current_version_id'),
            'a rejected update must leave current_version_id unchanged',
        );
    }
}
