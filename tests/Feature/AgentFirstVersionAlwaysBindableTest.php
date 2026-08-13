<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * research.md D3, Edge Cases, FR-012, quickstart.md step 15 — Phase 3/T017
 * (090-agent-version-binding).
 *
 * An agent that has never been edited still gives every conversation
 * started against it an identifiable version — the structural guarantee
 * research.md D3 establishes at the 087 storage layer (AgentService::create()
 * always leaves current_version_id set before returning), now proven
 * end-to-end THROUGH this feature's own binding mechanism, not just at the
 * storage layer alone: no null-version-id edge case, no special handling
 * needed.
 *
 * Written first, confirmed RED: no binding exists yet to observe —
 * ConversationController::store() drops agent_id entirely.
 */
class AgentFirstVersionAlwaysBindableTest extends TestCase
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

        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
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
    public function a_never_edited_agent_still_binds_a_conversation_to_its_first_version(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: weather-agent");
        $this->assertSame(1, $agent->currentVersion->version_number, 'sanity check: a freshly created agent is on version 1');

        $server = Server::create([
            'name' => 'TestServer',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'test-token',
        ]);

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(201);
        $agentVersionId = $response->json('agent_version_id');
        $this->assertNotNull($agentVersionId, 'a never-edited agent must still bind to an identifiable version, never null');
        $this->assertSame($agent->current_version_id, $agentVersionId);

        $conversation = Conversation::find($response->json('id'));
        $this->assertNotNull($conversation->agentVersion);
        $this->assertSame(1, $conversation->agentVersion->version_number);
    }
}
