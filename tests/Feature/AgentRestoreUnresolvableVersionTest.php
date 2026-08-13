<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Server;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * research.md D7 / quickstart.md step 6 / mutation-checklist row 4, contracts
 * §7: restoring to a version whose content no longer resolves against
 * *current* installation state (its named model has since been deleted)
 * refuses cleanly with a 422 naming the exact resolution problem, and never
 * makes a broken version current — current_version_id stays pointed at the
 * agent's actual current version throughout.
 */
class AgentRestoreUnresolvableVersionTest extends TestCase
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
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();

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

    #[Test]
    public function restoring_to_a_version_whose_named_model_has_since_been_deleted_returns_422_and_leaves_current_version_unchanged(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Primary']);
        $model = LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'retiring-model', 'server_id' => $server->id]);

        $createResponse = $this->actingAs($this->user)->postJson($this->base(), [
            'definition' => "name: weather-agent\nmodel: retiring-model",
        ]);
        $createResponse->assertStatus(201);
        $agentId = $createResponse->json('id');
        $firstVersion = AgentVersion::where('agent_id', $agentId)->where('version_number', 1)->first();

        $updateResponse = $this->actingAs($this->user)->putJson($this->base()."/{$agentId}", [
            'definition' => 'name: weather-agent-v2',
        ]);
        $updateResponse->assertStatus(200);
        $secondVersionId = DB::table('agents')->where('id', $agentId)->value('current_version_id');
        $this->assertNotSame($firstVersion->id, $secondVersionId);

        // The first version's named model no longer exists on this
        // installation.
        $model->delete();

        $restoreResponse = $this->actingAs($this->user)
            ->postJson($this->base()."/{$agentId}/versions/{$firstVersion->id}/restore");

        $restoreResponse->assertStatus(422);
        $restoreResponse->assertJson([
            'kind' => 'UnknownModel',
        ]);
        $this->assertStringContainsString('retiring-model', $restoreResponse->json('message'), 'the 422 body must name the specific unresolvable model');
    }

    #[Test]
    public function a_rejected_restore_leaves_current_version_id_pointed_at_the_second_version(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Primary']);
        $model = LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'retiring-model-2', 'server_id' => $server->id]);

        $createResponse = $this->actingAs($this->user)->postJson($this->base(), [
            'definition' => "name: weather-agent\nmodel: retiring-model-2",
        ]);
        $agentId = $createResponse->json('id');
        $firstVersion = AgentVersion::where('agent_id', $agentId)->where('version_number', 1)->first();

        $this->actingAs($this->user)->putJson($this->base()."/{$agentId}", [
            'definition' => 'name: weather-agent-v2',
        ])->assertStatus(200);

        $secondVersionId = DB::table('agents')->where('id', $agentId)->value('current_version_id');

        $model->delete();

        $this->actingAs($this->user)
            ->postJson($this->base()."/{$agentId}/versions/{$firstVersion->id}/restore")
            ->assertStatus(422);

        $this->assertSame(
            $secondVersionId,
            DB::table('agents')->where('id', $agentId)->value('current_version_id'),
            'current_version_id must still be the second version — no broken version was ever made current'
        );
        $this->assertSame(2, DB::table('agent_versions')->where('agent_id', $agentId)->count(), 'a rejected restore must write no new version row');
    }
}
