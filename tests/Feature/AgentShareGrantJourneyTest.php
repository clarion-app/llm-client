<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentShareGrant;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 096-agent-sharing, Phase 2 (Foundational) — the base access-relaxation
 * scenarios this same file's later phases (US1/US3) will extend
 * (tasks.md T009/T018/T019/T044-T048, plan.md's own "one journey file
 * spans US1+US3" file list). This Phase 2 slice covers only the
 * ConversationController::store() access-relaxation swap (T010):
 * an active grant lets the recipient start a conversation with a shared
 * (not owned) agent; a never-shared agent still 404s identically to
 * today, for a third, ungranted user.
 *
 * A grant is seeded directly via the AgentShareGrant model in every test
 * here — AgentShareService does not exist yet in this phase (US1).
 *
 * Written first, confirmed RED: ConversationController::store() still
 * calls the owner-only findAgent(), so B's POST /conversation 404s
 * identically to a stranger's.
 */
class AgentShareGrantJourneyTest extends TestCase
{
    private User $owner;
    private User $recipient;
    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->recipient = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('conversations')->delete();
        DB::table('agent_share_grants')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Operation catalog seam — required before any *valid*
    // AgentDefinitionParser::parse() call (AgentSearchListingJourneyTest's
    // own established convention).
    // ---------------------------------------------------------------

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

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    private function conversationUrl(): string
    {
        return '/api/clarion-app/llm-client/conversation';
    }

    private function makeAgent(User $owner, string $definition): Agent
    {
        return app(AgentService::class)->create($owner->id, $definition);
    }

    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'TestServer',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'test-token',
        ]);
    }

    private function grant(Agent $agent, User $owner, User $recipient, string $permission = 'use'): AgentShareGrant
    {
        return AgentShareGrant::create([
            'agent_id' => $agent->id,
            'owner_user_id' => $owner->id,
            'recipient_user_id' => $recipient->id,
            'permission' => $permission,
        ]);
    }

    // ---------------------------------------------------------------
    // T009 — Phase 2/Foundational
    // ---------------------------------------------------------------

    #[Test]
    public function a_recipient_with_an_active_grant_can_start_a_conversation_with_the_shared_agent(): void
    {
        $agent = $this->makeAgent($this->owner, "name: shared-weather-agent\ninstructions: Always respond in English.");
        $this->grant($agent, $this->owner, $this->recipient, 'use');

        $server = $this->makeServer();

        $response = $this->actingAs($this->recipient, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(201);
        $this->assertSame($agent->id, $response->json('agent_id'));
    }

    #[Test]
    public function a_user_never_granted_access_still_gets_the_uniform_agent_not_found_shape(): void
    {
        $agent = $this->makeAgent($this->owner, "name: never-shared-agent\ninstructions: Always respond in English.");
        $this->grant($agent, $this->owner, $this->recipient, 'use');

        $server = $this->makeServer();

        $response = $this->actingAs($this->stranger, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(404);
        $response->assertJson([
            'error' => 'Agent not found',
            'code' => 'agent_not_found',
        ]);
    }
}
