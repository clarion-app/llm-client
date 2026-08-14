<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentShareGrant;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    private function agentsUrl(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function agentUrl(string $id): string
    {
        return $this->agentsUrl().'/'.$id;
    }

    private function searchUrl(): string
    {
        return $this->agentsUrl().'/search';
    }

    private function sharesUrl(string $agentId): string
    {
        return $this->agentUrl($agentId).'/shares';
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

    // ---------------------------------------------------------------
    // T018 — Phase 3/US1, full HTTP grant/list journey
    // (contracts/agent-sharing-api.md §1/§2/§4, data-model.md §9).
    //
    // Written first, confirmed RED: no `POST /agents/{id}/shares` route
    // exists yet, so every grant call below 404s via Laravel's own
    // route-not-found handling rather than AgentShareController — and
    // agentSearchEntryResource() does not yet emit is_shared/shared_by/
    // permission, so even a hand-seeded grant would not show up correctly
    // in a search response today.
    // ---------------------------------------------------------------

    #[Test]
    public function ac1_owner_grants_use_access_and_the_recipient_sees_it_marked_shared_while_a_third_user_does_not(): void
    {
        $agentA = $this->makeAgent($this->owner, "name: shared-support-agent\ninstructions: Help customers.");
        $agentC = $this->makeAgent($this->stranger, "name: strangers-own-agent\ninstructions: Not shared with anyone.");

        $grantResponse = $this->actingAs($this->owner, 'api')->postJson($this->sharesUrl($agentA->id), [
            'recipient_user_id' => $this->recipient->id,
            'permission' => 'use',
        ]);
        $grantResponse->assertStatus(201);

        $bResponse = $this->actingAs($this->recipient, 'api')->getJson($this->searchUrl());
        $bResponse->assertStatus(200);
        $bEntry = collect($bResponse->json('data'))->firstWhere('id', $agentA->id);
        $this->assertNotNull($bEntry, "B must see A's shared agent in their own search results");
        $this->assertTrue($bEntry['is_shared']);
        $this->assertSame(['id' => $this->owner->id, 'name' => $this->owner->name], $bEntry['shared_by']);
        $this->assertSame('use', $bEntry['permission']);
        $this->assertFalse($bEntry['can_use']);

        $cResponse = $this->actingAs($this->stranger, 'api')->getJson($this->searchUrl());
        $cResponse->assertStatus(200);
        $cIds = collect($cResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($agentA->id, $cIds, "C must not see A's agent, which was shared only with B");
        $this->assertSame(1, $cResponse->json('total_unfiltered'), "C's total_unfiltered must reflect only C's own agent(s)");
        $this->assertContains($agentC->id, $cIds);
    }

    #[Test]
    public function ac2_a_use_and_edit_recipient_can_converse_with_and_edit_the_shared_agent_attributed_to_themself(): void
    {
        $agentA = $this->makeAgent($this->owner, "name: editable-shared-agent\ninstructions: Assist.");
        $this->actingAs($this->owner, 'api')->postJson($this->sharesUrl($agentA->id), [
            'recipient_user_id' => $this->recipient->id,
            'permission' => 'use_and_edit',
        ])->assertStatus(201);

        $server = $this->makeServer();
        $convResponse = $this->actingAs($this->recipient, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agentA->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);
        $convResponse->assertStatus(201);

        $updateResponse = $this->actingAs($this->recipient, 'api')->putJson($this->agentUrl($agentA->id), [
            'definition' => "name: editable-shared-agent\ninstructions: Assist warmly.",
        ]);
        $updateResponse->assertStatus(200);

        $latestVersion = AgentVersion::where('agent_id', $agentA->id)
            ->orderByDesc('version_number')
            ->first();
        $this->assertNotNull($latestVersion);
        $this->assertSame($this->recipient->id, $latestVersion->changed_by_user_id);
        $this->assertNotSame($this->owner->id, $latestVersion->changed_by_user_id);
    }

    #[Test]
    public function ac3_a_recipient_does_not_see_a_different_agent_from_the_same_owner_that_was_never_shared_with_them(): void
    {
        $sharedAgent = $this->makeAgent($this->owner, "name: shared-one\ninstructions: shared with B.");
        $otherAgent = $this->makeAgent($this->owner, "name: never-shared-two\ninstructions: not shared with B.");

        $this->actingAs($this->owner, 'api')->postJson($this->sharesUrl($sharedAgent->id), [
            'recipient_user_id' => $this->recipient->id,
            'permission' => 'use',
        ])->assertStatus(201);

        $response = $this->actingAs($this->recipient, 'api')->getJson($this->searchUrl());
        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($sharedAgent->id, $ids);
        $this->assertNotContains($otherAgent->id, $ids, 'an ungranted agent from the same owner must not appear');
    }

    // ---------------------------------------------------------------
    // T019 — Phase 3/US1, §1/§2 contract error cases.
    //
    // Written first, confirmed RED for the same reason as T018 above: the
    // grant/list routes do not exist yet.
    // ---------------------------------------------------------------

    #[Test]
    public function grant_404s_when_the_caller_does_not_own_the_target_agent(): void
    {
        $agent = $this->makeAgent($this->owner, "name: not-yours\ninstructions: x.");

        $response = $this->actingAs($this->stranger, 'api')->postJson($this->sharesUrl($agent->id), [
            'recipient_user_id' => $this->recipient->id,
            'permission' => 'use',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Agent not found', 'code' => 'agent_not_found']);
    }

    #[Test]
    public function grant_404s_when_the_target_agent_does_not_exist(): void
    {
        $response = $this->actingAs($this->owner, 'api')->postJson($this->sharesUrl((string) Str::uuid()), [
            'recipient_user_id' => $this->recipient->id,
            'permission' => 'use',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Agent not found', 'code' => 'agent_not_found']);
    }

    #[Test]
    public function grant_422s_for_a_missing_recipient_user_id(): void
    {
        $agent = $this->makeAgent($this->owner, "name: agent-missing-recipient\ninstructions: x.");

        $response = $this->actingAs($this->owner, 'api')->postJson($this->sharesUrl($agent->id), [
            'permission' => 'use',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function grant_422s_for_an_unknown_recipient_user_id(): void
    {
        $agent = $this->makeAgent($this->owner, "name: agent-unknown-recipient\ninstructions: x.");

        $response = $this->actingAs($this->owner, 'api')->postJson($this->sharesUrl($agent->id), [
            'recipient_user_id' => (string) Str::uuid(),
            'permission' => 'use',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function grant_422s_when_the_recipient_is_the_caller_themself(): void
    {
        $agent = $this->makeAgent($this->owner, "name: agent-self-share\ninstructions: x.");

        $response = $this->actingAs($this->owner, 'api')->postJson($this->sharesUrl($agent->id), [
            'recipient_user_id' => $this->owner->id,
            'permission' => 'use',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function grant_422s_for_an_invalid_permission_value(): void
    {
        $agent = $this->makeAgent($this->owner, "name: agent-bad-permission\ninstructions: x.");

        $response = $this->actingAs($this->owner, 'api')->postJson($this->sharesUrl($agent->id), [
            'recipient_user_id' => $this->recipient->id,
            'permission' => 'read_only',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function list_shares_404s_for_a_non_owner_caller_identically_to_grant(): void
    {
        $agent = $this->makeAgent($this->owner, "name: agent-list-non-owner\ninstructions: x.");
        $this->grant($agent, $this->owner, $this->recipient, 'use');

        $response = $this->actingAs($this->stranger, 'api')->getJson($this->sharesUrl($agent->id));

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Agent not found', 'code' => 'agent_not_found']);
    }

    #[Test]
    public function list_shares_returns_only_currently_active_grants_for_the_owner(): void
    {
        $agent = $this->makeAgent($this->owner, "name: agent-list-active-only\ninstructions: x.");
        $this->grant($agent, $this->owner, $this->recipient, 'use');

        $revokedRecipient = User::factory()->create();
        $revokedGrant = $this->grant($agent, $this->owner, $revokedRecipient, 'use_and_edit');
        $revokedGrant->delete();
        $this->assertNotNull($revokedGrant->fresh()->deleted_at, 'fixture sanity: the grant must actually be soft-deleted');

        $response = $this->actingAs($this->owner, 'api')->getJson($this->sharesUrl($agent->id));

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('recipient_user_id')->all();
        $this->assertContains($this->recipient->id, $ids);
        $this->assertNotContains($revokedRecipient->id, $ids, 'a revoked grant must not be listed');
    }
}
