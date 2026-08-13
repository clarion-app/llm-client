<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentQuery;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ConversationAgentDefinitionResolver;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md Edge Cases / FR-011, quickstart.md step 14 — Phase 5/T034
 * (090-agent-version-binding). A composition/regression proof, not new red
 * state per se: exercises only already-shipped US1 binding
 * (ConversationController::store()'s agent_id handling, Phase 3),
 * ConversationAgentDefinitionResolver (Phase 4), and 087's own unmodified
 * AgentService::restore()/StoredAgentController::restore() — nothing from
 * this phase's own AgentVersionComparer/AgentVersionComparisonController.
 *
 * Expected to already be GREEN: rolling an agent back to an earlier
 * version (087's restore(), which always appends a new version rather than
 * deleting or rewriting anything) must never erase the versions it rolls
 * past, and must never disturb a conversation's own, already-immutable
 * binding to one of them (FR-003 is completely independent of what the
 * agent's own current_version_id happens to point at afterward).
 */
class AgentRollbackDoesNotDisturbBoundConversationTest extends TestCase
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

    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'TestServer',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'test-token',
        ]);
    }

    #[Test]
    public function rolling_back_does_not_erase_versions_it_rolls_past_and_leaves_a_bound_conversation_undisturbed(): void
    {
        // Version 1.
        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: rollback-agent\ninstructions: v1 instructions.",
        );
        $v1Id = $agent->current_version_id;

        // Version 2.
        $agent = app(AgentService::class)->update(
            $agent,
            $this->user->id,
            "name: rollback-agent\ninstructions: v2 instructions.",
        );
        $v2Id = $agent->current_version_id;

        // Bind a conversation to version 2, partway through — a second
        // POST /conversation with the same agent_id, taken after the
        // version-2 edit and before the version-3 edit.
        $server = $this->makeServer();
        $conversationResponse = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);
        $conversationResponse->assertStatus(201);
        $conversationId = $conversationResponse->json('id');
        $this->assertSame($v2Id, $conversationResponse->json('agent_version_id'), 'the conversation must bind to version 2, the agent\'s current version at the moment it was created');

        // Version 3.
        $agent = app(AgentService::class)->update(
            $agent,
            $this->user->id,
            "name: rollback-agent\ninstructions: v3 instructions.",
        );
        $v3Id = $agent->current_version_id;

        // Roll the agent back to version 1 — 087's restore(), unmodified.
        // This produces a new version 4, content-identical to version 1.
        $restoreResponse = $this->actingAs($this->user, 'api')
            ->postJson($this->base()."/{$agent->id}/versions/{$v1Id}/restore");
        $restoreResponse->assertStatus(200);

        // The intermediate versions between the bound one (v2) and the
        // rollback point (v1) are not erased — 087's own append-only
        // guarantee, still intact.
        $versions = app(AgentQuery::class)->versionsForAgent($this->user->id, $agent->id);
        $versionNumbers = collect($versions->items())->pluck('version_number')->all();
        $this->assertSame([1, 2, 3, 4], $versionNumbers, 'the rollback must not erase any version — all four must exist, in original order');

        $v1 = AgentVersion::find($v1Id);
        $v2 = AgentVersion::find($v2Id);
        $v3 = AgentVersion::find($v3Id);
        $this->assertNotNull($v1);
        $this->assertNotNull($v2);
        $this->assertNotNull($v3);
        $this->assertStringContainsString('v1 instructions.', $v1->raw_definition);
        $this->assertStringContainsString('v2 instructions.', $v2->raw_definition);
        $this->assertStringContainsString('v3 instructions.', $v3->raw_definition);

        // The agent's own current pointer has moved to the new version 4 —
        // a completely independent fact from what any given conversation
        // is bound to.
        $agentFresh = DB::table('agents')->where('id', $agent->id)->first();
        $this->assertNotSame($v1Id, $agentFresh->current_version_id);
        $this->assertNotSame($v2Id, $agentFresh->current_version_id);
        $this->assertNotSame($v3Id, $agentFresh->current_version_id);

        // The conversation bound to version 2 — read fresh from the
        // database — still names its original bound version, untouched by
        // the agent's rollback.
        $conversation = Conversation::find($conversationId);
        $this->assertNotNull($conversation);
        $this->assertSame($v2Id, $conversation->agent_version_id, 'the conversation\'s own binding must be completely undisturbed by the agent\'s rollback');

        // ...and resolves version 2 correctly via ConversationAgentDefinitionResolver.
        $resolved = app(ConversationAgentDefinitionResolver::class)->forConversation($conversation);
        $this->assertNotNull($resolved);
        $this->assertSame('v2 instructions.', $resolved->instructions);
    }
}
