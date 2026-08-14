<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentShareGrant;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

        DB::table('agent_runs')->delete();
        if (Schema::hasTable('episodic_memories')) {
            DB::table('episodic_memories')->delete();
        }
        DB::table('messages')->delete();
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

    private function shareUrl(string $agentId, string $recipientUserId): string
    {
        return $this->sharesUrl($agentId).'/'.$recipientUserId;
    }

    private function agentTurnUrl(): string
    {
        return '/api/clarion-app/llm-client/agent';
    }

    /**
     * Scripted-provider mechanism established by this package's own sibling
     * tests (CrossAgentIsolationJourneyTest.php, RefusalLeavesTranscript
     * UntouchedTest.php) — a mocked ProviderRegistry so a full agent turn
     * can be driven over HTTP without a real model server.
     */
    private function fakeProvider(): void
    {
        // A wildcard body, not a bare Http::fake(): a new, still-untitled
        // conversation's first turn also fires an out-of-band title-
        // generation request (HandleOpenAIGenerateConversationTitleResponse),
        // which reads $response->object()->choices directly rather than
        // going through the mocked ProviderRegistry below — a bare fake
        // (empty 200 body) makes that read fatal.
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'Generated Title']]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
            ], 200),
        ]);

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => 'Sure, happy to help.']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
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

    // ---------------------------------------------------------------
    // T044 — Phase 5/US3, AC1 (FR-008/FR-009) + AC3 (FR-008),
    // contracts/agent-sharing-api.md §3.
    //
    // Written first, confirmed RED: no `DELETE /agents/{id}/shares/
    // {recipientUserId}` route exists yet, so every revoke call below
    // 404s via Laravel's own route-not-found handling rather than
    // AgentShareController.
    // ---------------------------------------------------------------

    #[Test]
    public function ac1_revoking_access_returns_204_and_the_recipient_immediately_gets_the_uniform_not_found_shape(): void
    {
        $agent = $this->makeAgent($this->owner, "name: revoke-ac1-agent\ninstructions: x.");
        $this->actingAs($this->owner, 'api')->postJson($this->sharesUrl($agent->id), [
            'recipient_user_id' => $this->recipient->id,
            'permission' => 'use',
        ])->assertStatus(201);

        $revokeResponse = $this->actingAs($this->owner, 'api')->deleteJson($this->shareUrl($agent->id, $this->recipient->id));
        $revokeResponse->assertStatus(204);

        $server = $this->makeServer();
        $response = $this->actingAs($this->recipient, 'api')->postJson($this->conversationUrl(), [
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

    #[Test]
    public function ac3_a_revoked_recipient_no_longer_sees_the_agent_in_search(): void
    {
        $agent = $this->makeAgent($this->owner, "name: revoke-ac3-agent\ninstructions: x.");
        $this->actingAs($this->owner, 'api')->postJson($this->sharesUrl($agent->id), [
            'recipient_user_id' => $this->recipient->id,
            'permission' => 'use',
        ])->assertStatus(201);

        $before = $this->actingAs($this->recipient, 'api')->getJson($this->searchUrl());
        $before->assertStatus(200);
        $this->assertContains(
            $agent->id,
            collect($before->json('data'))->pluck('id')->all(),
            'fixture sanity: the agent must be visible before revocation',
        );

        $this->actingAs($this->owner, 'api')->deleteJson($this->shareUrl($agent->id, $this->recipient->id))->assertStatus(204);

        $after = $this->actingAs($this->recipient, 'api')->getJson($this->searchUrl());
        $after->assertStatus(200);
        $this->assertNotContains(
            $agent->id,
            collect($after->json('data'))->pluck('id')->all(),
            'the agent must no longer appear in the recipient\'s own search results after revocation',
        );
    }

    #[Test]
    public function revoke_on_a_pair_with_no_active_grant_is_also_204_idempotent_not_an_error(): void
    {
        $agent = $this->makeAgent($this->owner, "name: revoke-idempotent-agent\ninstructions: x.");

        $response = $this->actingAs($this->owner, 'api')->deleteJson($this->shareUrl($agent->id, $this->recipient->id));

        $response->assertStatus(204);
    }

    // ---------------------------------------------------------------
    // T045 — Phase 5/US3, AC2 (FR-010), contracts §5. The security-
    // critical mid-conversation revocation test.
    //
    // The grant is revoked directly at the model layer (soft-delete)
    // rather than through the not-yet-built DELETE endpoint (T044/T051):
    // going through that endpoint here would conflate two independently
    // missing pieces — the route itself and the AgentLoopService check
    // this test actually targets — into a single failure, making it
    // impossible to tell which one a red result is actually about. A
    // direct soft-delete is exactly what AgentShareService::revoke()
    // (T050) will do internally, so it is a faithful proxy for "access
    // was revoked," not a shortcut around the property under test.
    //
    // Written first, confirmed RED: nothing in AgentLoopService checks
    // agent-share status once a conversation is already underway, so the
    // second turn below currently completes normally instead of being
    // refused.
    // ---------------------------------------------------------------

    /**
     * A full agent turn exercises AgentLoopService::run()'s condensation
     * check, which queries `condensation_states` — a table this test
     * package's own migrations do not always define. Declared the same
     * way this package's own sibling full-turn tests already do
     * (RefusalLeavesTranscriptUntouchedTest, CrossAgentIsolationJourneyTest).
     */
    private function declareCondensationStatesSchema(): void
    {
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * A conversation ending (ConversationLifecycleService::end(), reached
     * here via AgentLoopService's own agent_access_revoked stop) dispatches
     * GenerateEpisodicMemoryJob synchronously under this test suite's
     * default sync queue connection, and that job's very first statement
     * queries `episodic_memories` unconditionally — a table this test
     * package's own migrations do not always define. Declared the same way
     * this package's own sibling full-turn tests already do
     * (AgentHandoffJourneyTest's own identical note: "needed by any call
     * into run()/resumeSync()/start()").
     */
    private function declareEpisodicMemoriesSchema(): void
    {
        if (!Schema::hasTable('episodic_memories')) {
            Schema::create('episodic_memories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('conversation_id');
                $table->text('summary');
                $table->json('topics');
                $table->boolean('protected')->default(false);
                $table->unsignedInteger('word_count');
                $table->unsignedInteger('summary_word_count');
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    #[Test]
    public function ac2_a_conversation_already_underway_ends_cleanly_when_access_is_revoked_mid_conversation(): void
    {
        $this->declareCondensationStatesSchema();
        $this->declareEpisodicMemoriesSchema();
        config(['llm-client.run_trace.enabled' => true]);
        $this->fakeProvider();

        $agent = $this->makeAgent($this->owner, "name: revoke-mid-conversation-agent\ninstructions: Assist the user.");
        $grant = $this->grant($agent, $this->owner, $this->recipient, 'use');

        $server = $this->makeServer();
        $convResponse = $this->actingAs($this->recipient, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);
        $convResponse->assertStatus(201);
        $conversationId = $convResponse->json('id');

        // One full turn, successfully, before any revocation.
        $firstTurn = $this->actingAs($this->recipient, 'api')->postJson($this->agentTurnUrl(), [
            'message' => 'Hello, can you help me plan my day?',
            'conversation_id' => $conversationId,
        ]);
        $firstTurn->assertStatus(200);
        $this->assertSame(
            'completed',
            $firstTurn->json('status'),
            'fixture sanity: the first turn must succeed normally, before revocation',
        );

        // A revokes B's access.
        $grant->delete();
        $this->assertNotNull($grant->fresh()->deleted_at, 'fixture sanity: the grant is genuinely revoked');

        $secondTurn = $this->actingAs($this->recipient, 'api')->postJson($this->agentTurnUrl(), [
            'message' => 'Are you still there?',
            'conversation_id' => $conversationId,
        ]);

        $this->assertLessThan(
            400,
            $secondTurn->getStatusCode(),
            'a revoked-access refusal must never surface as an HTTP 4xx/5xx — it is a clean, in-band stop (contracts §5)',
        );
        $this->assertSame('stopped', $secondTurn->json('status'));
        $this->assertSame('agent_access_revoked', $secondTurn->json('code'));
        $this->assertNotNull($secondTurn->json('message_id'), 'a new assistant Message explaining the withdrawal must be created');

        $refusalMessage = Message::find($secondTurn->json('message_id'));
        $this->assertNotNull($refusalMessage);
        $this->assertSame('assistant', $refusalMessage->role);
        $this->assertSame($conversationId, $refusalMessage->conversation_id);

        $conversation = Conversation::find($conversationId);
        $this->assertFalse((bool) $conversation->is_processing, 'is_processing must be false after the clean stop');
        $this->assertNotNull(
            $conversation->ended_at,
            'ConversationLifecycleService::end() must be called, marking the session ended',
        );

        $run = DB::table('agent_runs')
            ->where('conversation_id', $conversationId)
            ->where('end_state', 'stopped_early')
            ->orderByDesc('started_at')
            ->first();
        $this->assertNotNull($run, 'the refused turn must be recorded as a stopped_early run');
        $this->assertStringContainsString(
            'agent_access_revoked',
            (string) $run->end_reason,
            "the run's own end_reason must name the revocation, not a generic stop",
        );

        // A further message after this point must produce the identical
        // clean refusal again — never silently resuming as if access were
        // restored.
        $thirdTurn = $this->actingAs($this->recipient, 'api')->postJson($this->agentTurnUrl(), [
            'message' => 'One more try.',
            'conversation_id' => $conversationId,
        ]);
        $this->assertLessThan(400, $thirdTurn->getStatusCode());
        $this->assertSame('agent_access_revoked', $thirdTurn->json('code'));
    }

    // ---------------------------------------------------------------
    // T046 — Phase 5/US3, Edge Case (FR-012), quickstart.md scenario 10:
    // a recipient's own clone of a shared agent is unaffected by a later
    // revocation of the original.
    // ---------------------------------------------------------------

    #[Test]
    public function edge_case_a_recipients_own_clone_of_a_shared_agent_survives_revocation_of_the_original(): void
    {
        $original = $this->makeAgent($this->owner, "name: clone-source-agent\ninstructions: Assist.");
        $grant = $this->grant($original, $this->owner, $this->recipient, 'use_and_edit');

        $cloneResponse = $this->actingAs($this->recipient, 'api')->postJson(
            $this->agentUrl($original->id).'/clone',
            ['name' => 'recipients-own-clone'],
        );
        $cloneResponse->assertStatus(201);
        $cloneId = $cloneResponse->json('id');

        $grant->delete();
        $this->assertNotNull($grant->fresh()->deleted_at, "fixture sanity: the original's grant is genuinely revoked");

        $searchResponse = $this->actingAs($this->recipient, 'api')->getJson($this->searchUrl());
        $searchResponse->assertStatus(200);
        $entry = collect($searchResponse->json('data'))->firstWhere('id', $cloneId);
        $this->assertNotNull(
            $entry,
            "the recipient's own clone must still appear in their own search results after the original's revocation",
        );
        $this->assertFalse($entry['is_shared']);
        $this->assertSame('owner', $entry['permission']);

        $editResponse = $this->actingAs($this->recipient, 'api')->putJson($this->agentUrl($cloneId), [
            'definition' => "name: recipients-own-clone\ninstructions: Assist, edited.",
        ]);
        $editResponse->assertStatus(200);

        $server = $this->makeServer();
        $convResponse = $this->actingAs($this->recipient, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $cloneId,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);
        $convResponse->assertStatus(201);
    }

    // ---------------------------------------------------------------
    // T047 — Phase 5/US3, research.md D7: re-granting after a revocation
    // reuses the same lifetime row, at the HTTP level (T043's own unit-
    // level assertion's complement).
    // ---------------------------------------------------------------

    #[Test]
    public function research_d7_a_re_grant_after_revocation_reuses_the_same_row_at_the_http_level(): void
    {
        $agent = $this->makeAgent($this->owner, "name: regrant-agent\ninstructions: x.");

        $firstGrantResponse = $this->actingAs($this->owner, 'api')->postJson($this->sharesUrl($agent->id), [
            'recipient_user_id' => $this->recipient->id,
            'permission' => 'use',
        ]);
        $firstGrantResponse->assertStatus(201);
        $originalCreatedAt = $firstGrantResponse->json('created_at');

        $this->actingAs($this->owner, 'api')->deleteJson($this->shareUrl($agent->id, $this->recipient->id))->assertStatus(204);

        $regrantResponse = $this->actingAs($this->owner, 'api')->postJson($this->sharesUrl($agent->id), [
            'recipient_user_id' => $this->recipient->id,
            'permission' => 'use_and_edit',
        ]);
        $regrantResponse->assertStatus(200, 're-granting after a prior revocation is a 200, not a first-ever-grant 201');

        $rows = DB::table('agent_share_grants')
            ->where('agent_id', $agent->id)
            ->where('recipient_user_id', $this->recipient->id)
            ->get();

        $this->assertCount(
            1,
            $rows,
            'exactly one row must exist for the pair across the whole grant/revoke/grant sequence, including soft-deleted',
        );
        $this->assertNull($rows[0]->deleted_at, 'the row must be active (not soft-deleted) again after the re-grant');
        $this->assertSame(
            $originalCreatedAt,
            $regrantResponse->json('created_at'),
            'created_at must be unchanged from the original grant',
        );
    }

    // ---------------------------------------------------------------
    // T048 — Phase 5/US3, FR-013/FR-014: installation-boundary validation
    // and auth/ownership uniformity across all three sharing endpoints,
    // DELETE included.
    // ---------------------------------------------------------------

    #[Test]
    public function fr013_grant_422s_for_a_recipient_user_id_outside_this_installation(): void
    {
        $agent = $this->makeAgent($this->owner, "name: outside-installation-agent\ninstructions: x.");

        $response = $this->actingAs($this->owner, 'api')->postJson($this->sharesUrl($agent->id), [
            'recipient_user_id' => 'not-a-real-installation-user-'.Str::random(8),
            'permission' => 'use',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function fr014_none_of_the_three_sharing_endpoints_is_reachable_without_authentication(): void
    {
        $agent = $this->makeAgent($this->owner, "name: auth-boundary-agent\ninstructions: x.");
        $this->grant($agent, $this->owner, $this->recipient, 'use');

        $this->postJson($this->sharesUrl($agent->id), [
            'recipient_user_id' => $this->recipient->id,
            'permission' => 'use',
        ])->assertStatus(401);

        $this->getJson($this->sharesUrl($agent->id))->assertStatus(401);

        $this->deleteJson($this->shareUrl($agent->id, $this->recipient->id))->assertStatus(401);
    }

    #[Test]
    public function fr014_a_caller_who_neither_owns_nor_has_been_granted_the_agent_gets_no_data_from_any_sharing_endpoint(): void
    {
        $agent = $this->makeAgent($this->owner, "name: stranger-boundary-agent\ninstructions: x.");
        $this->grant($agent, $this->owner, $this->recipient, 'use');

        $shape = ['error' => 'Agent not found', 'code' => 'agent_not_found'];

        $this->actingAs($this->stranger, 'api')->postJson($this->sharesUrl($agent->id), [
            'recipient_user_id' => $this->recipient->id,
            'permission' => 'use',
        ])->assertStatus(404)->assertJson($shape);

        $this->actingAs($this->stranger, 'api')->getJson($this->sharesUrl($agent->id))
            ->assertStatus(404)->assertJson($shape);

        $this->actingAs($this->stranger, 'api')->deleteJson($this->shareUrl($agent->id, $this->recipient->id))
            ->assertStatus(404)->assertJson($shape);
    }
}
