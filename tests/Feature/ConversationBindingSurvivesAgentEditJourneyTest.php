<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md US1 Acceptance Scenario 2, FR-003, quickstart.md step 3 —
 * Phase 3/T015 (090-agent-version-binding).
 *
 * This file's own US1 portion only: editing the agent after a conversation
 * is bound to it must not change what the conversation recorded — a pure
 * write-path/persistence assertion. Phase 4/US2 extends this SAME file
 * with the behavioral "does the response actually differ" case
 * (quickstart step 4) — do not add that case here.
 *
 * Written first, confirmed RED: ConversationController::store() has no
 * agent_id handling yet, so the conversation created below never carries
 * an agent_version_id to begin with.
 */
class ConversationBindingSurvivesAgentEditJourneyTest extends TestCase
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

    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'TestServer',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'test-token',
        ]);
    }

    #[Test]
    public function editing_the_agent_afterward_does_not_change_what_the_conversation_recorded(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: weather-agent\ninstructions: Always respond in English.");
        $version1Id = $agent->current_version_id;

        $server = $this->makeServer();

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);
        $response->assertStatus(201);
        $conversationId = $response->json('id');

        // Edit the agent while the conversation is still open — this produces version 2.
        app(AgentService::class)->update($agent, $this->user->id, "name: weather-agent\ninstructions: changed");

        $conversation = Conversation::find($conversationId);
        $this->assertNotNull($conversation);
        $this->assertSame(
            $version1Id,
            $conversation->agent_version_id,
            'the conversation must still name version 1 — its own agent_version_id is immutable once written (FR-003)',
        );

        $agentFresh = Agent::find($agent->id);
        $this->assertNotSame(
            $version1Id,
            $agentFresh->current_version_id,
            'the agent itself must now point at a newer version — the two have diverged, as intended',
        );
    }
}
