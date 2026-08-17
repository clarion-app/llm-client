<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Services\AgentDefinitionValidator;
use ClarionApp\LlmClient\Services\AgentStartingPointCatalog;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /agent-starting-points -- browsing the catalog. Confirms every
 * registered slug is listed, in registration order, each carrying a
 * non-empty and distinct description, and that viewing the list alone
 * never writes an Agent row. Also covers the degraded, zero-registered
 * case (an installation with every starting point disabled via config),
 * which must still respond 200 with an empty data array rather than an
 * error -- the same shape AgentStartingPointController::index() already
 * returns when AgentStartingPointCatalog::list() is empty.
 */
class AgentStartingPointListingTest extends TestCase
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

    /**
     * Covers every default template's tools.allow requirements at once
     * (research's fetchPage.* glob, coding's three named codingWorkspace
     * operationIds, data's and research's bare GET verb) so list()'s
     * per-entry validator check reflects each template's real,
     * satisfied state rather than an unseeded doc generator's output.
     */
    private function seedFullOperationCatalog(): void
    {
        $this->seedOperationCatalog([
            'clarionApp.llmClient.conversations.index' => [
                'path' => '/api/conversations',
                'method' => 'get',
                'summary' => 'List conversations',
            ],
            'clarionApp.llmClient.fetchPage.getTextFromUrl' => [
                'path' => '/api/page/text',
                'method' => 'post',
                'summary' => 'Fetch the text of a page',
            ],
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
        ]);
    }

    #[Test]
    public function lists_all_four_registered_slugs_in_order_each_with_a_non_empty_distinct_description(): void
    {
        $this->seedFullOperationCatalog();

        $response = $this->actingAs($this->user)->getJson($this->base());

        $response->assertStatus(200);

        $slugs = array_column($response->json('data'), 'slug');
        $this->assertSame(['research', 'coding', 'data', 'scheduler'], $slugs, 'slugs must appear in registration order');

        $descriptions = array_column($response->json('data'), 'description');
        foreach ($descriptions as $slug => $description) {
            $this->assertNotEmpty($description, "slug at index {$slug} must carry a non-empty description");
        }
        $this->assertCount(
            count($descriptions),
            array_unique($descriptions),
            'every starting point must carry a distinct description'
        );
    }

    #[Test]
    public function viewing_the_list_writes_no_agent_row(): void
    {
        $this->seedFullOperationCatalog();

        $this->assertSame(0, Agent::count());

        $this->actingAs($this->user)->getJson($this->base())->assertStatus(200);

        $this->assertSame(0, Agent::count(), 'GET /agent-starting-points must never create an Agent row on its own');
    }

    #[Test]
    public function returns_200_with_empty_data_when_no_starting_points_are_registered(): void
    {
        config(['llm-client.agent_definitions.starting_points.enabled' => []]);
        $this->app->instance(
            AgentStartingPointCatalog::class,
            new AgentStartingPointCatalog($this->app->make(AgentDefinitionValidator::class)),
        );

        $response = $this->actingAs($this->user)->getJson($this->base());

        $response->assertStatus(200);
        $response->assertExactJson(['data' => []]);
    }
}
