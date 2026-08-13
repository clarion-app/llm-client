<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
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
 * Edge Cases + FR-006/SC-006 (quickstart.md steps 6-7), 090-agent-version-binding
 * Phase 4/T024.
 *
 * "A conversation is resumed after a long gap" is simulated here by editing
 * the bound agent multiple times (versions 2, 3, 4) between binding and
 * resolution — version 1, the version the conversation was actually bound
 * to, is now doubly-superseded, yet must still be exactly what the
 * conversation runs on and exactly what remains readable (research.md D9 —
 * 087's own unbounded retention, no pruning/archiving of superseded
 * versions).
 *
 * Written first, confirmed RED: no ConversationAgentDefinitionResolver
 * class exists yet.
 */
class ConversationResumedOntoSupersededVersionTest extends TestCase
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

    /**
     * Binds a conversation to version 1, then edits the agent three more
     * times (versions 2, 3, 4), so version 1 is doubly (in fact, triply)
     * superseded by the time the assertions below run.
     *
     * @return array{conversation: Conversation, agent: Agent, version1Id: string}
     */
    private function bindThenSupersedeThreeTimes(): array
    {
        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: weather-agent\ninstructions: Version one instructions.",
        );
        $version1Id = $agent->current_version_id;

        $server = $this->makeServer();

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);
        $response->assertStatus(201);
        $conversationId = $response->json('id');

        app(AgentService::class)->update($agent, $this->user->id, "name: weather-agent\ninstructions: Version two instructions.");
        app(AgentService::class)->update($agent, $this->user->id, "name: weather-agent\ninstructions: Version three instructions.");
        app(AgentService::class)->update($agent, $this->user->id, "name: weather-agent\ninstructions: Version four instructions.");

        return [
            'conversation' => Conversation::find($conversationId),
            'agent' => $agent->fresh(),
            'version1Id' => $version1Id,
        ];
    }

    // ---------------------------------------------------------------
    // (a)/(1) A conversation resumed after a long gap still runs on the
    // version it was bound to.
    // ---------------------------------------------------------------

    #[Test]
    public function a_conversation_resumed_after_a_long_gap_still_runs_on_the_version_it_was_bound_to(): void
    {
        ['conversation' => $conversation, 'agent' => $agent, 'version1Id' => $version1Id] = $this->bindThenSupersedeThreeTimes();

        $this->assertSame(
            $version1Id,
            $conversation->agent_version_id,
            'conversation.agent_version_id must still name version 1, unchanged by any of the three subsequent edits',
        );
        $this->assertNotSame(
            $version1Id,
            $agent->current_version_id,
            'the agent itself must now be on version 4 — the two have diverged',
        );

        // Reload fresh from the database, matching production's own reload
        // discipline, before resolving.
        $reloaded = Conversation::find($conversation->id);

        $definition = app(ConversationAgentDefinitionResolver::class)->forConversation($reloaded);

        $this->assertNotNull($definition);
        $this->assertSame(
            'Version one instructions.',
            $definition->instructions,
            'must resolve version 1\'s definition, not the agent\'s now-current version 4',
        );
    }

    // ---------------------------------------------------------------
    // (b)/(2) A version any conversation still references remains fully
    // readable after the agent moves on (AC3, FR-006, SC-006).
    // ---------------------------------------------------------------

    #[Test]
    public function the_bound_version_remains_fully_readable_after_the_agent_has_moved_on(): void
    {
        ['conversation' => $conversation, 'agent' => $agent, 'version1Id' => $version1Id] = $this->bindThenSupersedeThreeTimes();

        $this->assertSame(4, AgentVersion::where('agent_id', $agent->id)->count(), 'the agent must now have accumulated four versions');

        $foundVersion = app(AgentQuery::class)->findVersion($this->user->id, $agent->id, $version1Id);

        $this->assertNotNull($foundVersion, '087\'s own unbounded retention (research.md D9) — version 1 must never be pruned or archived');
        $this->assertStringContainsString(
            'Version one instructions.',
            $foundVersion->raw_definition,
            'the exact raw_definition version 1 was created with must still be readable',
        );

        $definition = app(ConversationAgentDefinitionResolver::class)->forConversation(Conversation::find($conversation->id));

        $this->assertNotNull($definition);
        $this->assertSame('Version one instructions.', $definition->instructions);
    }
}
