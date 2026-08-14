<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\AgentShareGrant;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 096-agent-sharing, Phase 4 (User Story 2) — tasks.md T037-T040.
 *
 * This phase is proving, not building: research.md D2 found the
 * overwhelming majority of "a shared agent runs as the person using it,
 * never its owner" already holds today, by construction, because every
 * mechanism involved (RoleResolver, AutoMemoryRetriever, the conversation
 * ownership scope, AgentSummaryQuery) is keyed off the *conversation's own*
 * user_id, not the agent's owner. The one load-bearing production change —
 * ConversationController::store()'s findAgent() -> findAccessibleAgent()
 * swap, letting a shared agent be reached at all — already shipped in
 * Phase 2 (Foundational). Every test below is expected to PASS immediately
 * against that already-complete code; an unexpected failure here means D2's
 * finding was wrong somewhere and needs real investigation, not a new
 * production-code task slipped into this "zero new code" phase.
 */
class AgentSharingExecutionIdentityTest extends TestCase
{
    private User $owner;
    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->recipient = User::factory()->create();
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_runs')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('tool_reliability_summaries')->delete();
        DB::table('declarative_memories')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_share_grants')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Operation catalog seam — required before any *valid*
    // AgentDefinitionParser::parse() call. Duplicated from
    // AgentShareGrantJourneyTest's own setUp() per this package's
    // established small-helper-duplication precedent (each Feature test
    // file owns its own copy rather than sharing a trait).
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

    private function messageUrl(string $conversationId): string
    {
        return $this->conversationUrl().'/'.$conversationId.'/message';
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

    private function makeServer(string $name = 'TestServer', ?string $token = null): Server
    {
        return Server::create([
            'name' => $name,
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => $token ?? 'test-token-'.Str::random(8),
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

    // =================================================================
    // T037 — AC1 (FR-005, quickstart scenario 3): execution binds to the
    // RECIPIENT's own role-assignment resolution, never the owner's, when
    // the recipient omits server_id/model entirely.
    // =================================================================

    #[Test]
    public function ac1_a_shared_conversation_resolves_the_recipients_own_inference_role_never_the_owners(): void
    {
        $agent = $this->makeAgent($this->owner, "name: identity-agent\ninstructions: Be helpful.");
        $this->grant($agent, $this->owner, $this->recipient, 'use');

        $ownerServer = $this->makeServer('Owner Server');
        $recipientServer = $this->makeServer('Recipient Server');

        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->owner->id, $ownerServer->id, 'owner-model');
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->recipient->id, $recipientServer->id, 'recipient-model');

        // Deliberately omit server_id/model entirely — not even sent as null.
        $response = $this->actingAs($this->recipient, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agent->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame(
            $recipientServer->id,
            $response->json('server_id'),
            "the created conversation must resolve to the recipient's own server"
        );
        $this->assertSame('recipient-model', $response->json('model'));
        $this->assertNotSame(
            $ownerServer->id,
            $response->json('server_id'),
            "the owner's server must never be used for a conversation the recipient started"
        );
        $this->assertNotSame('owner-model', $response->json('model'));

        // Cross-check directly against what RoleResolver itself would
        // independently compute for the recipient — the exact guarantee
        // FR-005 names, not merely "some value other than the owner's".
        $expected = app(RoleResolver::class)->resolve(ModelRole::Inference, $this->recipient->id);
        $this->assertTrue($expected->hasEffectiveModel());
        $this->assertSame($expected->server->id, $response->json('server_id'));
        $this->assertSame($expected->model, $response->json('model'));
    }

    // =================================================================
    // T038 — AC2 (FR-006, quickstart scenario 4): the memory section
    // assembled for a shared-agent turn draws on the RECIPIENT's own
    // declarative memory, never the owner's.
    //
    // Exercises AgentLoopService::buildAutoMemorySection() directly via
    // reflection — tests/Unit/AgentLoopServiceTest.php already establishes
    // reflecting into this class's private methods as this package's own
    // precedent for testing a targeted private method in isolation. The
    // property under test here is exactly which $userId this method derives
    // ($conversation->user_id, never the agent's owner) — not HTTP/streaming
    // wiring, which research.md D2's table already attributes to
    // machinery this feature doesn't touch. A DeclarativeMemory 'rule' entry
    // is used because retrieveDeclarative() includes rules unconditionally
    // (no embedding/relevance-scoring dependency), keeping this test
    // deterministic without needing a scripted LLM/embedding transport.
    //
    // Scoped to declarative memory (this method's own retrieval also
    // covers episodic via the identical $userId parameter to
    // EpisodicMemorySearchService::hybridSearch()) — long-term memory is
    // deliberately NOT asserted here; see T040 below.
    // =================================================================

    #[Test]
    public function ac2_a_shared_agent_conversation_assembles_the_recipients_own_declarative_memory_never_the_owners(): void
    {
        $agent = $this->makeAgent($this->owner, "name: memory-agent\ninstructions: Assist the user.");
        $this->grant($agent, $this->owner, $this->recipient, 'use');

        // A rule is injected unconditionally, regardless of embedding
        // availability — the property under test is *whose* rules are
        // read, not the relevance-scoring machinery.
        DeclarativeMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->owner->id,
            'type' => 'rule',
            'content' => 'Always respond formally.',
            'source' => 'user_stated',
        ]);
        DeclarativeMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->recipient->id,
            'type' => 'rule',
            'content' => 'Always respond casually.',
            'source' => 'user_stated',
        ]);

        $server = $this->makeServer();
        $conversation = Conversation::create([
            'user_id' => $this->recipient->id,
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
            'character' => 'Clarion',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'How should you respond to me?',
        ]);

        $service = app(AgentLoopService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildAutoMemorySection');
        $method->setAccessible(true);

        /** @var string|null $section */
        $section = $method->invoke($service, $conversation->fresh());

        $this->assertNotNull(
            $section,
            'the recipient has an active binding rule; the assembled section must not be empty'
        );
        $this->assertStringContainsString(
            'Always respond casually.',
            $section,
            "the recipient's own rule must be present in the assembled memory section"
        );
        $this->assertStringNotContainsString(
            'Always respond formally.',
            $section,
            "the owner's rule must never leak into the recipient's turn on a shared agent"
        );
    }

    // =================================================================
    // T039 — AC3 (FR-007/SC-005, quickstart scenario 5): nothing belonging
    // to the owner — conversations, usage figures, credentials — is ever
    // reachable by the recipient through any shared-agent-related screen or
    // response.
    // =================================================================

    #[Test]
    public function ac3a_conversation_index_never_includes_any_of_the_owners_own_conversation_rows(): void
    {
        $agent = $this->makeAgent($this->owner, "name: conv-index-agent\ninstructions: x.");
        $this->grant($agent, $this->owner, $this->recipient, 'use');

        $ownerServer = $this->makeServer('Owner Server');

        // The owner's own conversations: one on the shared agent itself,
        // one entirely unrelated. Neither may ever appear in the
        // recipient's own conversation index, regardless of the grant.
        $ownersOwnConversationOnSharedAgent = Conversation::create([
            'user_id' => $this->owner->id,
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
            'server_id' => $ownerServer->id,
            'model' => 'owner-model',
            'title' => "Owner's own conversation on the shared agent",
        ]);
        $ownersUnrelatedConversation = Conversation::create([
            'user_id' => $this->owner->id,
            'server_id' => $ownerServer->id,
            'model' => 'owner-model',
            'title' => "Owner's unrelated conversation",
        ]);

        $recipientServer = $this->makeServer('Recipient Server');
        $recipientsOwnConversation = Conversation::create([
            'user_id' => $this->recipient->id,
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
            'server_id' => $recipientServer->id,
            'model' => 'recipient-model',
            'title' => "Recipient's own conversation on the shared agent",
        ]);

        $response = $this->actingAs($this->recipient, 'api')->getJson($this->conversationUrl());
        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertNotContains(
            $ownersOwnConversationOnSharedAgent->id,
            $ids,
            "the owner's own conversation on the shared agent must never appear in the recipient's index"
        );
        $this->assertNotContains($ownersUnrelatedConversation->id, $ids);
        $this->assertContains($recipientsOwnConversation->id, $ids, 'fixture sanity: the recipient does see their own conversation');
    }

    #[Test]
    public function ac3b_search_usage_figures_reflect_only_the_viewing_recipients_own_activity_on_the_shared_agent(): void
    {
        $this->seedOperationCatalog();

        $agent = $this->makeAgent($this->owner, "name: usage-scoping-agent\ninstructions: x.");
        $this->grant($agent, $this->owner, $this->recipient, 'use');

        // Owner's own activity on the shared agent: distinct, recognizable
        // figures (mirrors 095-agent-summary-cards'
        // AgentSummaryCardScopingJourneyTest fixture shape exactly).
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => 'agent',
            'entity_id' => $agent->id,
            'user_id' => $this->owner->id,
            'period_date' => Carbon::now()->toDateString(),
            'request_count' => 11,
            'priced_cost_total' => '1.1100000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
        DB::table('tool_reliability_summaries')->insert([
            'id' => (string) Str::uuid(),
            'tool_name' => 'search_documents',
            'agent_id' => $agent->id,
            'user_id' => $this->owner->id,
            'period_date' => Carbon::now()->toDateString(),
            'invocation_count' => 11,
            'success_count' => 10,
            'failure_count' => 1,
            'failure_timeout_count' => 1,
            'failure_connection_failure_count' => 0,
            'failure_authentication_failure_count' => 0,
            'failure_invalid_input_count' => 0,
            'failure_server_error_count' => 0,
            'failure_other_count' => 0,
            'failure_uncategorized_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
        AgentRun::create([
            'kind' => RunKind::Interactive,
            'user_id' => $this->owner->id,
            'agent_id' => $agent->id,
            'started_at' => Carbon::now(),
        ]);

        // Recipient's own activity on the SAME shared agent: different,
        // recognizable figures.
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => 'agent',
            'entity_id' => $agent->id,
            'user_id' => $this->recipient->id,
            'period_date' => Carbon::now()->toDateString(),
            'request_count' => 77,
            'priced_cost_total' => '7.7700000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
        DB::table('tool_reliability_summaries')->insert([
            'id' => (string) Str::uuid(),
            'tool_name' => 'search_documents',
            'agent_id' => $agent->id,
            'user_id' => $this->recipient->id,
            'period_date' => Carbon::now()->toDateString(),
            'invocation_count' => 77,
            'success_count' => 70,
            'failure_count' => 7,
            'failure_timeout_count' => 0,
            'failure_connection_failure_count' => 0,
            'failure_authentication_failure_count' => 0,
            'failure_invalid_input_count' => 0,
            'failure_server_error_count' => 7,
            'failure_other_count' => 0,
            'failure_uncategorized_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
        for ($i = 0; $i < 7; $i++) {
            AgentRun::create([
                'kind' => RunKind::Interactive,
                'user_id' => $this->recipient->id,
                'agent_id' => $agent->id,
                'started_at' => Carbon::now(),
            ]);
        }

        $response = $this->actingAs($this->recipient, 'api')->getJson($this->searchUrl());
        $response->assertStatus(200);

        $entry = collect($response->json('data'))->firstWhere('id', $agent->id);
        $this->assertNotNull($entry, "the recipient must see the shared agent in their own search results");

        $usage = $entry['usage'];
        $this->assertTrue($usage['has_run']);
        $this->assertSame(7, $usage['run_count'], "the recipient's own seven runs only, never the owner's one");
        $this->assertSame(77, $usage['reliability']['invocation_count'], "the recipient's own reliability figures only");
        $this->assertSame(70, $usage['reliability']['success_count']);
        $this->assertSame(7, $usage['reliability']['failure_count']);
        $this->assertSame(77, $usage['cost']['request_count'], "the recipient's own cost contribution only");
        $this->assertEqualsWithDelta(7.77, (float) $usage['cost']['priced_cost_total'], 0.0000001);

        // Explicitly disprove the regression this test exists to catch: the
        // owner's own recognizable figures must be absent, not merely
        // "some different number".
        $this->assertNotSame(11, $usage['run_count']);
        $this->assertNotSame(11, $usage['reliability']['invocation_count']);
        $this->assertNotEqualsWithDelta(1.11, (float) $usage['cost']['priced_cost_total'], 0.0000001);
    }

    #[Test]
    public function ac3c_no_response_reachable_by_the_recipient_ever_contains_the_owners_credential_secret(): void
    {
        $agent = $this->makeAgent($this->owner, "name: secret-check-agent\ninstructions: x.");
        $this->grant($agent, $this->owner, $this->recipient, 'use');

        $ownerSecret = 'OWNER-ONLY-SECRET-TOKEN-'.Str::random(16);
        $ownerServer = $this->makeServer('Owner Secret Server', $ownerSecret);
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->owner->id, $ownerServer->id, 'owner-secret-model');

        $recipientServer = $this->makeServer('Recipient Server');
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->recipient->id, $recipientServer->id, 'recipient-model');

        // Every response shape reachable by the recipient through the
        // shared agent: the agent resource, the search entry, conversation
        // creation, conversation listing, and the message list of a
        // conversation the recipient itself started on the shared agent.
        $agentShowResponse = $this->actingAs($this->recipient, 'api')->getJson($this->agentUrl($agent->id));
        $agentShowResponse->assertStatus(200);

        $searchResponse = $this->actingAs($this->recipient, 'api')->getJson($this->searchUrl());
        $searchResponse->assertStatus(200);

        $conversationCreateResponse = $this->actingAs($this->recipient, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agent->id,
        ]);
        $conversationCreateResponse->assertStatus(201);
        $conversationId = $conversationCreateResponse->json('id');

        $conversationIndexResponse = $this->actingAs($this->recipient, 'api')->getJson($this->conversationUrl());
        $conversationIndexResponse->assertStatus(200);

        $messageIndexResponse = $this->actingAs($this->recipient, 'api')->getJson($this->messageUrl($conversationId));
        $messageIndexResponse->assertStatus(200);

        $bodies = [
            'agent show' => $agentShowResponse->getContent(),
            'agent search' => $searchResponse->getContent(),
            'conversation create' => $conversationCreateResponse->getContent(),
            'conversation index' => $conversationIndexResponse->getContent(),
            'message index' => $messageIndexResponse->getContent(),
        ];

        foreach ($bodies as $label => $body) {
            $this->assertStringNotContainsString(
                $ownerSecret,
                $body,
                "the owner's credential must never appear in the {$label} response reachable by the recipient"
            );
        }

        // Fixture sanity: the recipient's own conversation genuinely
        // resolved to the recipient's own (non-secret) server, never the
        // owner's secret-bearing one.
        $this->assertSame($recipientServer->id, $conversationCreateResponse->json('server_id'));
        $this->assertNotSame($ownerServer->id, $conversationCreateResponse->json('server_id'));
    }

    // =================================================================
    // T040 — research.md D3's disclosed residual risk, recorded explicitly
    // rather than silently omitted.
    //
    // This system's long-term memory is pooled installation-wide: every
    // conversation created through ConversationController::store() gets
    // the identical hardcoded character = "Clarion" (L79 at the time
    // research.md/tasks.md were written), and
    // AgentLoopService::buildAutoMemorySection() derives long-term memory's
    // own scoping key from that literal character value, not from
    // conversation.user_id — so long-term memory retrieval already pools
    // across every user on the installation today, for every conversation,
    // shared-agent or not. Sharing an agent does not create, worsen, or
    // differentially expose this gap: the leak surface was already
    // installation-wide before this feature existed.
    //
    // This test suite deliberately does NOT assert long-term-memory
    // isolation between the owner and the recipient. Doing so would either
    // (a) fail honestly, correctly reporting a real, pre-existing gap this
    // feature does not fix, or (b) be quietly vacuous if scoped narrowly
    // enough to avoid tripping it. Neither is written here. Fixing this is
    // out of scope for 096-agent-sharing (research.md D3's own
    // recommendation: pass $userId as MemoryService::search()'s existing
    // eighth parameter from AutoMemoryRetriever::retrieveLongTerm(), and
    // separately reconsider the hardcoded "Clarion" character value — both
    // independent, general correctness fixes unrelated to sharing itself).
    //
    // What this test DOES assert, honestly and non-vacuously: the pooling
    // mechanism itself is real and unchanged by this feature — a shared
    // agent's conversation and a directly-owned agent's conversation both
    // resolve to the identical literal character value, confirming there is
    // exactly one shared long-term-memory pool key regardless of who owns
    // the agent being conversed with.
    // =================================================================

    #[Test]
    public function t040_long_term_memory_pooling_is_a_disclosed_pre_existing_gap_this_feature_does_not_fix(): void
    {
        $agent = $this->makeAgent($this->owner, "name: pooled-memory-agent\ninstructions: x.");
        $this->grant($agent, $this->owner, $this->recipient, 'use');

        $ownServer = $this->makeServer('Owner Own Server');
        $ownersOwnAgent = $this->makeAgent($this->owner, "name: owners-own-agent\ninstructions: x.");

        // The owner's own conversation on an agent they own outright.
        $ownersConversation = $this->actingAs($this->owner, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $ownersOwnAgent->id,
            'server_id' => $ownServer->id,
            'model' => 'owner-model',
        ]);
        $ownersConversation->assertStatus(201);

        // The recipient's own conversation on the OWNER's shared agent.
        $recipientServer = $this->makeServer('Recipient Server');
        $recipientsConversation = $this->actingAs($this->recipient, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agent->id,
            'server_id' => $recipientServer->id,
            'model' => 'recipient-model',
        ]);
        $recipientsConversation->assertStatus(201);

        // Both conversations pool under the identical literal long-term-
        // memory key, regardless of who owns the agent or who is
        // conversing — the documented, pre-existing gap, not something
        // this feature introduced or silently fixed.
        $this->assertSame('Clarion', $ownersConversation->json('character'));
        $this->assertSame('Clarion', $recipientsConversation->json('character'));
        $this->assertSame(
            $ownersConversation->json('character'),
            $recipientsConversation->json('character'),
            'the long-term-memory pooling key is identical across every conversation on the installation today'
        );
    }
}
