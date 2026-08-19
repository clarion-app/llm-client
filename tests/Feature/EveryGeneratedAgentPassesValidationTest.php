<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentDefinitionValidator;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * For each of the four registered starting points, the agent produced by
 * POST /agent-starting-points/{slug} passes AgentDefinitionValidator::check()
 * cleanly -- valid() true, no problems -- proving the "complete, valid,
 * immediately usable, no further mandatory input" guarantee the four
 * templates are meant to already satisfy on their own. Catalog seeding per
 * slug mirrors AgentGenerationFromEveryTemplateTest and each
 * *AgentProvisionerTest's own precedent.
 */
class EveryGeneratedAgentPassesValidationTest extends TestCase
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
                'clarionApp.llmClient.codingWorkspace.runCommand' => [
                    'path' => '/api/coding-project/{project}/run-command',
                    'method' => 'post',
                    'summary' => "Run a shell command in a registered project's sandboxed workspace",
                ],
                'clarionApp.llmClient.codingWorkspace.runCode' => [
                    'path' => '/api/coding-project/{project}/run-code',
                    'method' => 'post',
                    'summary' => "Run a code snippet in a registered project's sandboxed workspace",
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
        // still seed with zero operations rather than leave the catalog
        // unseeded.
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
    public function the_agent_generated_from_each_slug_passes_validation_cleanly(string $slug): void
    {
        $this->seedCatalogFor($slug);

        $response = $this->actingAs($this->user)->postJson($this->base().'/'.$slug);
        $response->assertStatus(201);

        $agentId = $response->json('id');
        $version = AgentVersion::where('agent_id', $agentId)->first();
        $this->assertNotNull($version, 'the created agent must have a current version');

        $result = $this->app->make(AgentDefinitionValidator::class)->check($version->raw_definition);

        $this->assertTrue($result->valid, "slug \"{$slug}\" must pass validation: ".json_encode(array_map(
            fn ($problem) => $problem->getMessage(),
            $result->problems,
        )));
        $this->assertSame([], $result->problems);
    }
}
