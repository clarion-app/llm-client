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
 * FR-013/SC-003, quickstart.md step 7 / mutation-checklist row 5, contracts
 * §6: reading a past version's exact definition never depends on whether it
 * would still resolve against *today's* installation state (research.md
 * D7) — this is the deliberate asymmetry with AgentRestoreUnresolvableVersionTest,
 * which covers the same fixture but exercises the *restore* action instead.
 * A version whose named model has since been deleted is still readable —
 * 200, never a failure — with raw_definition present and exact, resolved
 * null, and resolution_error naming the UnknownModel kind.
 */
class AgentOldVersionRemainsReadableAfterSupersessionTest extends TestCase
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
    public function reading_a_version_whose_named_model_has_since_been_deleted_still_returns_200_with_the_exact_content(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Primary']);
        $model = LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'retiring-model-3', 'server_id' => $server->id]);

        $rawDefinition = "name: weather-agent\nmodel: retiring-model-3";

        $createResponse = $this->actingAs($this->user)->postJson($this->base(), [
            'definition' => $rawDefinition,
        ]);
        $createResponse->assertStatus(201);
        $agentId = $createResponse->json('id');
        $firstVersion = AgentVersion::where('agent_id', $agentId)->where('version_number', 1)->first();

        $this->actingAs($this->user)->putJson($this->base()."/{$agentId}", [
            'definition' => 'name: weather-agent-v2',
        ])->assertStatus(200);

        // The first version's named model no longer exists on this
        // installation — today's state has moved on, but reading history
        // must not care.
        $model->delete();

        $detailResponse = $this->actingAs($this->user)
            ->getJson($this->base()."/{$agentId}/versions/{$firstVersion->id}");

        $detailResponse->assertStatus(200, 'reading an old version must never fail because of today\'s installation state (SC-003)');
        $this->assertSame($rawDefinition, $detailResponse->json('raw_definition'), 'raw_definition must be present and exact');
        $this->assertNull($detailResponse->json('resolved'), 'resolved must be null when the content no longer resolves today');
        $this->assertSame('UnknownModel', $detailResponse->json('resolution_error.kind'));
        $this->assertSame('retiring-model-3', $detailResponse->json('resolution_error.value'));
    }
}
