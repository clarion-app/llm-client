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
 * spec.md US1 Acceptance Scenario 1 / quickstart.md step 1, through the
 * real HTTP endpoint: an authenticated POST /agents with a valid
 * definition already has exactly one version the moment it is created
 * (FR-001/SC-001) — never a state with an agent but zero history
 * (spec Edge Cases).
 */
class AgentFirstVersionJourneyTest extends TestCase
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

    #[Test]
    public function posting_a_valid_definition_creates_an_agent_that_already_has_exactly_one_version(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->base(), [
            'definition' => "name: weather-agent",
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('current_version_number', 1);

        $agentId = $response->json('id');
        $this->assertNotEmpty($agentId, 'the 201 response must carry the newly created agent id');

        $this->assertSame(
            1,
            AgentVersion::where('agent_id', $agentId)->count(),
            'a newly stored agent must already have exactly one version, never zero',
        );
    }
}
