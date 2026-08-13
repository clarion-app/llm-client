<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentQuery;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md US1 Acceptance Scenario 1/3, FR-001/FR-002, SC-001, quickstart.md
 * steps 1-2 — Phase 3/T014 (090-agent-version-binding).
 *
 * POST /conversation with an agent_id must resolve the agent's current
 * version at that exact moment and record both agent_id/agent_version_id
 * on the created Conversation row. An agent_id that does not resolve (does
 * not exist, or belongs to another user) must refuse the whole request
 * with a 404 and write no Conversation row at all.
 *
 * Written first, confirmed RED: ConversationController::store() has no
 * agent_id handling yet, so it is silently dropped by validation and the
 * response never carries either new column.
 */
class ConversationRecordsBoundAgentVersionJourneyTest extends TestCase
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

    /**
     * Seeds both of ApiManager's live-catalog seams — required before any
     * *valid* AgentDefinitionParser::parse() call (AgentFirstVersionJourneyTest's
     * own established convention).
     */
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
    public function posting_a_conversation_with_an_agent_id_records_that_agents_current_version(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: weather-agent");
        $version1Id = $agent->current_version_id;
        $this->assertNotNull($version1Id, 'AgentService::create() must always leave current_version_id set (research.md D3)');

        $server = $this->makeServer();

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('agent_id', $agent->id);
        $response->assertJsonPath('agent_version_id', $version1Id);

        $conversationId = $response->json('id');
        $conversation = Conversation::find($conversationId);
        $this->assertNotNull($conversation);
        $this->assertSame($agent->id, $conversation->agent_id);
        $this->assertSame($version1Id, $conversation->agent_version_id);

        // AC3 — the recorded version answers "which behavior produced this
        // response" precisely, via the existing, unmodified AgentQuery::findVersion().
        $resolvedVersion = app(AgentQuery::class)->findVersion($this->user->id, $agent->id, $conversation->agent_version_id);
        $this->assertNotNull($resolvedVersion);
        $this->assertSame("name: weather-agent", $resolvedVersion->raw_definition);
    }

    /**
     * Mutation-checklist row 1 (research.md D2's capture-point discipline):
     * store() must bind agent_version_id from the single AgentQuery::findAgent()
     * read, never a second, later re-read (e.g. $agent->fresh()->current_version_id).
     * A test on step 1's own scenario alone can't distinguish the two, since
     * nothing edits the agent between findAgent() and Conversation::create()
     * in the ordinary happy path — so AgentQuery itself is intercepted here to
     * simulate a write landing in that exact gap: the mock resolves the real
     * agent (capturing its then-current version 1) and, as a side effect of
     * that same call returning, edits the agent to version 2 before store()
     * ever reaches Conversation::create(). Correct code still binds version 1
     * (the value already captured in the returned Agent object); reading via
     * a later fresh() re-query would incorrectly bind version 2.
     */
    #[Test]
    public function the_bound_version_is_captured_at_the_single_find_agent_read_not_re_read_later(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: weather-agent");
        $version1Id = $agent->current_version_id;

        $server = $this->makeServer();

        $agentQueryMock = Mockery::mock(AgentQuery::class);
        $agentQueryMock->shouldReceive('findAgent')
            ->once()
            ->andReturnUsing(function (string $callerUserId, string $agentId) use ($agent) {
                $found = \ClarionApp\LlmClient\Models\Agent::where('id', $agentId)
                    ->where('user_id', $callerUserId)
                    ->first();

                // Simulate a concurrent edit landing between the read and its
                // use — the returned $found object's own current_version_id
                // was already captured before this update runs.
                app(AgentService::class)->update($agent, $callerUserId, "name: weather-agent\ninstructions: changed mid-request");

                return $found;
            });
        $this->app->instance(AgentQuery::class, $agentQueryMock);

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('agent_version_id', $version1Id);

        $conversationId = $response->json('id');
        $conversation = Conversation::find($conversationId);
        $this->assertSame(
            $version1Id,
            $conversation->agent_version_id,
            'agent_version_id must be the value captured at the single findAgent() read, not re-read afterward',
        );
    }

    #[Test]
    public function an_agent_id_that_does_not_exist_refuses_the_whole_request(): void
    {
        $server = $this->makeServer();

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => '11111111-1111-1111-1111-111111111111',
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(404);
        $response->assertExactJson([
            'error' => 'Agent not found',
            'code' => 'agent_not_found',
        ]);
        $this->assertSame(0, Conversation::count(), 'no Conversation row must be written, even partially, on a 404 refusal');
    }

    #[Test]
    public function an_agent_id_belonging_to_another_user_refuses_the_whole_request(): void
    {
        $otherUser = User::factory()->create();
        $agent = app(AgentService::class)->create($otherUser->id, "name: someone-elses-agent");

        $server = $this->makeServer();

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(404);
        $response->assertExactJson([
            'error' => 'Agent not found',
            'code' => 'agent_not_found',
        ]);
        $this->assertSame(0, Conversation::count(), 'no Conversation row must be written, even partially, on a 404 refusal');
    }
}
