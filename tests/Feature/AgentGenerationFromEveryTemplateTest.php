<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /agent-starting-points/{slug}, for each of the four registered
 * slugs, writes an AgentVersion::raw_definition byte-for-byte identical
 * to the corresponding src/Templates/{slug}.yaml file content -- never
 * re-serialized/round-tripped through a YAML parse/dump cycle. Mirrors
 * the same byte-for-byte assertion ResearchAgentProvisionerTest,
 * CodingAgentProvisionerTest, DataAgentProvisionerTest, and
 * SchedulerAgentProvisionerTest each already make for
 * ensureForUser()'s own template read, applied here to the second,
 * explicit creation path this feature adds.
 *
 * Each slug's operation catalog is seeded exactly as its own
 * *AgentProvisionerTest seeds it for ensureForUser() -- the `coding`
 * template names explicit operationIds in tools.allow, so it needs the
 * three coding-workspace operations; `research` and `data` each allow a
 * bare GET verb (research additionally needs the fetchPage.* glob to
 * resolve); `scheduler`'s tools.allow/safety.* lists are all empty, so
 * the catalog is still seeded with zero operations rather than left
 * unseeded, matching SchedulerAgentProvisionerTest's own precedent.
 */
class AgentGenerationFromEveryTemplateTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
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
    // Helpers
    // ---------------------------------------------------------------

    private function base(): string
    {
        return '/api/clarion-app/llm-client/agent-starting-points';
    }

    private function templatePath(string $slug): string
    {
        return __DIR__.'/../../src/Templates/'.$slug.'.yaml';
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

    private function seedCatalogFor(string $slug): void
    {
        $conversationsIndex = [
            'clarionApp.llmClient.conversations.index' => [
                'path' => '/api/conversations',
                'method' => 'get',
                'summary' => 'List conversations',
            ],
        ];

        if ($slug === 'coding') {
            $this->seedOperationCatalog([
                'clarionApp.llmClient.codingWorkspace.writeFile' => [
                    'path' => '/api/coding-project/{project}/file',
                    'method' => 'post',
                    'summary' => 'Write a file in a registered project',
                ],
                'clarionApp.llmClient.codingWorkspace.deleteFile' => [
                    'path' => '/api/coding-project/{project}/file',
                    'method' => 'delete',
                    'summary' => 'Delete a file in a registered project',
                ],
                'clarionApp.llmClient.codingWorkspace.runTests' => [
                    'path' => '/api/coding-project/{project}/run-tests',
                    'method' => 'post',
                    'summary' => "Run a registered project's own test command",
                ],
                ...$conversationsIndex,
            ]);

            return;
        }

        if ($slug === 'research') {
            $this->seedOperationCatalog([
                'clarionApp.llmClient.fetchPage.getTextFromUrl' => [
                    'path' => '/api/page/text',
                    'method' => 'post',
                    'summary' => 'Fetch the text of a page',
                ],
                ...$conversationsIndex,
            ]);

            return;
        }

        if ($slug === 'data') {
            $this->seedOperationCatalog($conversationsIndex);

            return;
        }

        // scheduler.yaml's tools.allow/safety.* lists are all empty --
        // still seed with zero operations, so catalog resolution never
        // falls through to the real doc generator.
        $this->seedOperationCatalog([]);
    }

    public static function slugProvider(): array
    {
        return [
            'research' => ['research'],
            'coding' => ['coding'],
            'data' => ['data'],
            'scheduler' => ['scheduler'],
        ];
    }

    #[Test]
    #[DataProvider('slugProvider')]
    public function creating_from_each_slug_returns_201_with_a_byte_identical_definition(string $slug): void
    {
        $this->seedCatalogFor($slug);

        $response = $this->actingAs($this->user)->postJson($this->base().'/'.$slug);

        $response->assertStatus(201);

        $agentId = $response->json('id');
        $version = AgentVersion::where('agent_id', $agentId)->first();

        $this->assertNotNull($version, 'the created agent must have a current version');
        $this->assertSame(
            file_get_contents($this->templatePath($slug)),
            $version->raw_definition,
            "the stored raw_definition for slug \"{$slug}\" must be byte-for-byte identical to its template file"
        );
    }

    #[Test]
    public function a_second_call_for_the_same_slug_produces_a_second_independent_agent(): void
    {
        $this->seedCatalogFor('research');

        $first = $this->actingAs($this->user)->postJson($this->base().'/research');
        $second = $this->actingAs($this->user)->postJson($this->base().'/research');

        $first->assertStatus(201);
        $second->assertStatus(201);

        $firstId = $first->json('id');
        $secondId = $second->json('id');

        $this->assertNotSame($firstId, $secondId, 'each POST must create a new, independent Agent row -- this path never checks for an existing agent of the same name');
        $this->assertSame(
            2,
            Agent::where('user_id', $this->user->id)->where('name', 'research')->count(),
            'two independent agent rows must exist for the same user and starting point'
        );
    }
}
