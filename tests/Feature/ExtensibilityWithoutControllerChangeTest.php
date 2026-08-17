<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentStartingPointCatalog;
use ClarionApp\LlmClient\ValueObjects\AgentStartingPoint;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Adding a fifth starting point costs exactly one registration against
 * the already-container-bound catalog singleton -- zero edit to
 * AgentStartingPointController, the routes, or the service provider.
 * Registers a test-only starting point pointed at a fixture file under
 * the OS temp directory (never src/Templates/) and proves it is fully
 * live through the real HTTP surface.
 *
 * Written before AgentStartingPointController and the two new routes
 * exist -- expected to fail until they are added. That is the intended
 * RED state, not a mistake.
 */
class ExtensibilityWithoutControllerChangeTest extends TestCase
{
    private User $user;
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->fixturePath = sys_get_temp_dir().'/agent-starting-point-extensibility-test-'.uniqid().'.yaml';
        file_put_contents($this->fixturePath, "name: extensibility-test-agent\n");

        $catalog = $this->app->make(AgentStartingPointCatalog::class);
        $catalog->register(new AgentStartingPoint(
            'extensibility-test',
            'A test-only starting point proving a fifth starting point needs no controller change.',
            $this->fixturePath,
        ));
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath);
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

    #[Test]
    public function a_fifth_test_registered_starting_point_appears_in_the_listing(): void
    {
        $this->seedOperationCatalog();

        $response = $this->actingAs($this->user)->getJson($this->base());

        $response->assertStatus(200);
        $slugs = array_column($response->json('data'), 'slug');
        $this->assertContains('extensibility-test', $slugs);
    }

    #[Test]
    public function a_fifth_test_registered_starting_point_is_creatable(): void
    {
        $this->seedOperationCatalog();

        $response = $this->actingAs($this->user)->postJson($this->base().'/extensibility-test');

        $response->assertStatus(201);
        $response->assertJsonPath('starting_point_slug', 'extensibility-test');

        $agentId = $response->json('id');
        $this->assertSame(
            file_get_contents($this->fixturePath),
            AgentVersion::where('agent_id', $agentId)->first()->raw_definition,
        );
    }
}
