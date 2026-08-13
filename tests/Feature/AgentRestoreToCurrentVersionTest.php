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
 * spec.md Edge Cases ("restoring to the version it is already currently
 * on") / quickstart.md step 5 / mutation-checklist row 10, and contracts §7:
 * restoring to the agent's own already-current version is never
 * special-cased — it still creates a genuinely new version, never a no-op,
 * never a 304, never skipping the write.
 */
class AgentRestoreToCurrentVersionTest extends TestCase
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
    public function restoring_to_the_agents_own_current_version_still_creates_a_genuinely_new_version(): void
    {
        $createResponse = $this->actingAs($this->user)
            ->postJson($this->base(), ['definition' => 'name: weather-agent']);
        $createResponse->assertStatus(201);
        $agentId = $createResponse->json('id');

        $v1 = AgentVersion::where('agent_id', $agentId)->where('version_number', 1)->first();
        $this->assertNotNull($v1);
        $this->assertSame(1, DB::table('agent_versions')->where('agent_id', $agentId)->count());

        $restoreResponse = $this->actingAs($this->user)
            ->postJson($this->base()."/{$agentId}/versions/{$v1->id}/restore");

        $restoreResponse->assertStatus(200);

        $this->assertSame(
            2,
            DB::table('agent_versions')->where('agent_id', $agentId)->count(),
            'restoring to the current version must still insert a new row, never a no-op'
        );

        $v2 = AgentVersion::where('agent_id', $agentId)->where('version_number', 2)->first();
        $this->assertNotNull($v2, 'a new version 2 must exist');
        $this->assertSame($v1->raw_definition, $v2->raw_definition, 'content must be identical to version 1');
        $this->assertSame($v1->id, $v2->restored_from_version_id);
        $this->assertNotSame($v1->id, $v2->id);

        $agentRow = DB::table('agents')->where('id', $agentId)->first();
        $this->assertSame($v2->id, $agentRow->current_version_id, 'current_version_id must now point at the new version 2, not version 1');
    }
}
