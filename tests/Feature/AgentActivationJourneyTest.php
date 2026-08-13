<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md US1 (Phase 3, 092-agent-activation), contracts/agent-activation-api.md
 * §1/§2, quickstart.md steps 3, 4, 8, 11, 12, 13, 14, 15 — the end-to-end
 * HTTP acceptance scenarios for `POST /agents/{id}/activate` and
 * `POST /agents/{id}/deactivate`, mirroring AgentCloneJourneyTest.php's own
 * setUp()/tearDown()/agentsUrl()/agentUrl()/seedOperationCatalog()/
 * clearOperationCatalog() pattern.
 *
 * Written first, confirmed RED: no `POST agents/{id}/activate`/
 * `.../deactivate` route exists yet (Phase 3's own implementation,
 * T011-T014, comes after these tests).
 *
 * US2/US3/US4 scenarios (quickstart steps 1, 2, 5-7, 9, 10, 16) are Phase
 * 4/5/6's own scope and are added to this same file later, per the
 * Ordering grounding note in tasks.md — not present in this Phase 3 pass.
 */
class AgentActivationJourneyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        // Required by AgentLoopService::run()'s condensation check
        // (CondensationSummaryStore::inCooldown()) — this file's own US2
        // section is the first in this file to drive run() directly,
        // mirroring the identical hasTable()-guarded pattern already
        // established by ConversationBindingSurvivesQueueContinuationTest.php
        // and other sibling *JourneyTest.php files in this suite.
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

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('llm_memory_entries')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // URL helpers
    // ---------------------------------------------------------------

    private function agentsUrl(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function agentUrl(string $id): string
    {
        return $this->agentsUrl().'/'.$id;
    }

    private function versionsUrl(string $agentId): string
    {
        return $this->agentUrl($agentId).'/versions';
    }

    private function activateUrl(string $id): string
    {
        return "{$this->agentUrl($id)}/activate";
    }

    private function deactivateUrl(string $id): string
    {
        return "{$this->agentUrl($id)}/deactivate";
    }

    private function conversationUrl(): string
    {
        return '/api/clarion-app/llm-client/conversation';
    }

    private function conversationShowUrl(string $id): string
    {
        return "{$this->conversationUrl()}/{$id}";
    }

    // ---------------------------------------------------------------
    // Operation catalog seam — required before any *valid*
    // AgentDefinitionParser::parse() call (AgentServiceTest's own
    // established convention).
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

    private function createAgent(string $definition, ?User $as = null): string
    {
        return $this->actingAs($as ?? $this->user)
            ->postJson($this->agentsUrl(), ['definition' => $definition])
            ->assertStatus(201)
            ->json('id');
    }

    private function createServer(): Server
    {
        return Server::create([
            'name' => 'TestServer',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'test-token',
        ]);
    }

    /**
     * Mirrors InFlightWorkCompletesJourneyTest.php's own fakeProvider()
     * helper verbatim (same mocked LlmProvider/ProviderRegistry shape) —
     * needed here so US2's "a conversation already in progress finishes
     * normally" case (quickstart step 5) can drive a real
     * AgentLoopService::run() call without an outbound HTTP request.
     */
    private function fakeProvider(): void
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => 'A whole answer, start to finish.']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    // =================================================================
    // T010 — US1 (quickstart steps 3, 4, 8, 11, 12, 13, 14, 15)
    // =================================================================

    #[Test]
    public function deactivation_touches_nothing_about_the_agents_definition_or_version_history(): void
    {
        $agentId = $this->createAgent('name: original-name');
        $this->createAgent('name: sibling-agent'); // sibling, so the agent under test is never the caller's last active one (this test is about version history, not FR-013)
        $this->actingAs($this->user)->putJson($this->agentUrl($agentId), ['definition' => 'name: v2-name'])->assertStatus(200);
        $this->actingAs($this->user)->putJson($this->agentUrl($agentId), ['definition' => 'name: v3-name'])->assertStatus(200);

        $show = $this->actingAs($this->user)->getJson($this->agentUrl($agentId));
        $show->assertStatus(200);
        $currentVersionNumberBefore = $show->json('current_version_number');
        $definitionBefore = $show->json('definition');

        $versionsBefore = $this->actingAs($this->user)->getJson($this->versionsUrl($agentId));
        $versionsBefore->assertStatus(200);
        $versionsDataBefore = $versionsBefore->json('data');
        $this->assertCount(3, $versionsDataBefore, 'fixture sanity: three versions must exist before deactivation');

        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId))->assertStatus(200);

        $versionsAfter = $this->actingAs($this->user)->getJson($this->versionsUrl($agentId));
        $versionsAfter->assertStatus(200);
        $this->assertSame($versionsDataBefore, $versionsAfter->json('data'), 'deactivation must leave every version entry byte-identical');

        $showAfter = $this->actingAs($this->user)->getJson($this->agentUrl($agentId));
        $showAfter->assertStatus(200);
        $this->assertSame($currentVersionNumberBefore, $showAfter->json('current_version_number'), 'deactivation must never change current_version_number');
        $this->assertSame($definitionBefore, $showAfter->json('definition'), 'deactivation must never change the resolved definition');
    }

    #[Test]
    public function deactivation_touches_nothing_the_agent_has_already_produced(): void
    {
        $agentId = $this->createAgent('name: chatty-agent');
        $this->createAgent('name: sibling-agent'); // sibling, so the agent under test is never the caller's last active one (this test is about produced content, not FR-013)
        $server = $this->createServer();

        $conversation = $this->actingAs($this->user, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agentId,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ])->assertStatus(201);
        $conversationId = $conversation->json('id');

        Message::create([
            'conversation_id' => $conversationId,
            'content' => 'a produced message',
            'role' => 'user',
            'user' => 'Test User',
        ]);
        $messagesBefore = Message::where('conversation_id', $conversationId)->orderBy('created_at')->get()->toArray();

        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId))->assertStatus(200);

        $show = $this->actingAs($this->user, 'api')->getJson($this->conversationShowUrl($conversationId));
        $show->assertStatus(200);
        $this->assertSame($conversationId, $show->json('id'), 'the conversation must still be readable after its agent is deactivated');

        $messagesAfter = Message::where('conversation_id', $conversationId)->orderBy('created_at')->get()->toArray();
        $this->assertSame($messagesBefore, $messagesAfter, 'deactivation must never alter anything the agent has already produced');
    }

    #[Test]
    public function deactivating_an_already_deactivated_agent_is_a_clean_no_op_over_http(): void
    {
        $agentId = $this->createAgent('name: agent-a');
        $this->createAgent('name: agent-b'); // sibling, so agent-a is never the caller's last active one

        $first = $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId));
        $first->assertStatus(200);
        $this->assertFalse($first->json('is_active'));

        $second = $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId));
        $second->assertStatus(200);
        $this->assertFalse($second->json('is_active'));
    }

    #[Test]
    public function activating_an_already_active_agent_is_a_clean_no_op_over_http(): void
    {
        $agentId = $this->createAgent('name: agent-a');

        $first = $this->actingAs($this->user)->postJson($this->activateUrl($agentId));
        $first->assertStatus(200);
        $this->assertTrue($first->json('is_active'));

        $second = $this->actingAs($this->user)->postJson($this->activateUrl($agentId));
        $second->assertStatus(200);
        $this->assertTrue($second->json('is_active'));
    }

    #[Test]
    public function deactivating_the_callers_last_active_agent_is_refused_with_a_clear_warning_and_nothing_changes(): void
    {
        $agentId = $this->createAgent('name: only-agent');

        $response = $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId));

        $response->assertStatus(409);
        $this->assertSame('last_active_agent', $response->json('code'));

        $show = $this->actingAs($this->user)->getJson($this->agentUrl($agentId));
        $show->assertStatus(200);
        $this->assertTrue($show->json('is_active'), 'no state change: the agent must still be active after the refusal');
    }

    #[Test]
    public function passing_confirm_true_lets_the_person_deliberately_deactivate_their_last_active_agent(): void
    {
        $agentId = $this->createAgent('name: only-agent');
        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId))->assertStatus(409);

        $response = $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId), ['confirm' => true]);

        $response->assertStatus(200);
        $this->assertFalse($response->json('is_active'));
    }

    #[Test]
    public function the_last_active_agent_guard_is_scoped_per_user_never_installation_wide_over_http(): void
    {
        $userB = User::factory()->create();
        $agentAId = $this->createAgent('name: agent-a-only');
        $agentBId = $this->createAgent('name: agent-b-only', $userB);

        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentAId), ['confirm' => true])->assertStatus(200);

        $listForB = $this->actingAs($userB)->getJson($this->agentsUrl());
        $listForB->assertStatus(200);
        $agentBEntry = collect($listForB->json('data'))->firstWhere('id', $agentBId);
        $this->assertNotNull($agentBEntry, "user B's own agent must still be listed");

        $showB = $this->actingAs($userB)->getJson($this->agentUrl($agentBId));
        $showB->assertStatus(200);
        $this->assertTrue($showB->json('is_active'), "user A deactivating their own last active agent must never affect user B's agent");

        $response = $this->actingAs($userB)->postJson($this->deactivateUrl($agentBId));
        $response->assertStatus(409, "user B's own last-active-agent deactivation must still be refused with the identical guard, scoped to user B's own agents only");
        $this->assertSame('last_active_agent', $response->json('code'));
    }

    #[Test]
    public function the_last_active_agent_guard_never_counts_another_users_still_active_agent_as_this_users_own_over_http(): void
    {
        // Opposite direction of the_last_active_agent_guard_is_scoped_per_user_never_installation_wide_over_http()
        // above: that test only exercises userB's guard after userA's agent
        // has already been deactivated (via confirm), so an unscoped guard
        // (dropping the user_id filter) would count zero other active
        // agents installation-wide anyway and produce an identical 409 —
        // silently passing even with the scope removed. Here userB's agent
        // stays genuinely active throughout, forcing a scope-dropping
        // mutation to visibly diverge: it would wrongly treat userB's
        // still-active agent as "another active agent of userA's" and admit
        // the deactivation (200) instead of refusing it (409).
        $userB = User::factory()->create();
        $agentAId = $this->createAgent('name: agent-a-only');
        $this->createAgent('name: agent-b-still-active', $userB);

        $response = $this->actingAs($this->user)->postJson($this->deactivateUrl($agentAId));
        $response->assertStatus(409, "userA has no other active agent of their own, regardless of userB's still-active agent");
        $this->assertSame('last_active_agent', $response->json('code'));

        $showA = $this->actingAs($this->user)->getJson($this->agentUrl($agentAId));
        $showA->assertStatus(200);
        $this->assertTrue($showA->json('is_active'), 'no state change: agent-a-only must still be active after the refusal');
    }

    #[Test]
    public function ownership_isolation_holds_for_both_new_endpoints(): void
    {
        $userB = User::factory()->create();
        $agentId = $this->createAgent('name: user-a-agent');

        $activateResponse = $this->actingAs($userB)->postJson($this->activateUrl($agentId));
        $activateResponse->assertStatus(404);

        $deactivateResponse = $this->actingAs($userB)->postJson($this->deactivateUrl($agentId));
        $deactivateResponse->assertStatus(404);

        $this->assertTrue((bool) Agent::find($agentId)->is_active, "user B's attempts must never change user A's agent state");
    }

    #[Test]
    public function a_soft_deleted_agent_is_not_a_valid_target_for_either_endpoint(): void
    {
        $agentId = $this->createAgent('name: retired-agent');
        Agent::find($agentId)->delete();
        $this->assertNotNull(Agent::withTrashed()->find($agentId)->deleted_at, 'fixture sanity: the agent must actually be soft-deleted');

        $deactivateResponse = $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId));
        $deactivateResponse->assertStatus(404);

        $activateResponse = $this->actingAs($this->user)->postJson($this->activateUrl($agentId));
        $activateResponse->assertStatus(404);
    }

    // =================================================================
    // T017 — US2 (quickstart steps 1, 2, 5, 6, 7)
    // =================================================================

    #[Test]
    public function a_deactivated_agent_refuses_a_new_conversation_with_a_clear_explanation(): void
    {
        $agentId = $this->createAgent('name: refused-agent');
        $this->createAgent('name: sibling-agent'); // sibling, so the agent under test is never the caller's last active one
        $server = $this->createServer();

        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId))->assertStatus(200);

        $countBefore = Conversation::count();

        $response = $this->actingAs($this->user, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agentId,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(409);
        $this->assertSame('agent_deactivated', $response->json('code'));
        $this->assertStringContainsString('refused-agent', (string) $response->json('message'), 'the refusal must name the agent');

        $this->assertSame($countBefore, Conversation::count(), 'no Conversation row may be created when admission is refused');
    }

    #[Test]
    public function a_reactivated_agent_accepts_new_conversations_again_exactly_as_before(): void
    {
        $agentId = $this->createAgent('name: reactivated-agent');
        $this->createAgent('name: sibling-agent');
        $server = $this->createServer();

        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId))->assertStatus(200);
        $this->actingAs($this->user)->postJson($this->activateUrl($agentId))->assertStatus(200);

        $response = $this->actingAs($this->user, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agentId,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(201);
        $this->assertSame($agentId, $response->json('agent_id'));
        $this->assertNotNull($response->json('agent_version_id'));
        $this->assertNotNull(Conversation::find($response->json('id')), 'a Conversation row must exist once admission is restored');
    }

    #[Test]
    public function a_conversation_already_in_progress_when_its_agent_is_deactivated_finishes_normally_undisturbed(): void
    {
        $agentId = $this->createAgent('name: mid-conversation-agent');
        $this->createAgent('name: sibling-agent');
        $server = $this->createServer();

        $conversation = $this->actingAs($this->user, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agentId,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ])->assertStatus(201);
        $conversationModel = Conversation::find($conversation->json('id'));

        // The greeting message store() itself creates (L118-124) — the
        // baseline this test's own delta is measured against, so a
        // pre-existing assistant row is never mistaken for run()'s output.
        $assistantCountBefore = Message::where('conversation_id', $conversationModel->id)->where('role', 'assistant')->count();

        // The agent is deactivated mid-conversation.
        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId))->assertStatus(200);

        $this->fakeProvider();

        $result = app(AgentLoopService::class)->run($conversationModel, 'A question after my agent was deactivated.');

        $this->assertSame('completed', $result['status'], 'a conversation already in progress must finish normally, undisturbed by a mid-conversation deactivation');
        $this->assertSame(
            $assistantCountBefore + 1,
            Message::where('conversation_id', $conversationModel->id)->where('role', 'assistant')->count(),
            'an assistant Message must be written exactly as it would without the deactivation'
        );

        $conversationModel->refresh();
        $this->assertFalse((bool) $conversationModel->is_processing);
    }

    #[Test]
    public function work_already_queued_on_a_now_deactivated_agents_behalf_resolves_predictably(): void
    {
        $agentId = $this->createAgent('name: queued-work-agent');
        $this->createAgent('name: sibling-agent');
        $server = $this->createServer();

        $conversation = $this->actingAs($this->user, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agentId,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ])->assertStatus(201);
        $conversationModel = Conversation::find($conversation->json('id'));

        // Deactivate the agent before the queue-boundary continuation
        // (AgentLoopStreamHandler::finish()) ever runs, mirroring
        // InFlightWorkCompletesJourneyTest.php's own "cross the gate mid-flight"
        // shape for a different gate.
        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId))->assertStatus(200);

        $handler = new AgentLoopStreamHandler();
        $handler->reply = 'A reply produced after my agent was deactivated.';
        $handler->message = Message::create([
            'conversation_id' => $conversationModel->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $data = json_encode([
            'conversation_id' => $conversationModel->id,
            'iteration' => 1,
        ]);

        // Invoked directly against a fixture SSE payload, exactly as the
        // real http-queue job would call it once dequeued.
        $handler->finish($data, 1);

        $handler->message->refresh();
        $this->assertSame(
            'A reply produced after my agent was deactivated.',
            $handler->message->content,
            'the queued turn must reach an ordinary terminal state, not hang or silently drop'
        );

        $conversationModel->refresh();
        $this->assertFalse((bool) $conversationModel->is_processing);

        // Code-inspection assertion (research.md D3): the queue-boundary
        // continuation point must gain no new is_active/Agent check of its
        // own — the guarantee is structural absence, not a new check.
        $handlerSource = file_get_contents(
            (new \ReflectionClass(AgentLoopStreamHandler::class))->getFileName()
        );
        $this->assertStringNotContainsString(
            'is_active',
            $handlerSource,
            'AgentLoopStreamHandler must never gain its own is_active check (research.md D3) — the queue path re-checks nothing about admission'
        );

        $streamRequestSource = file_get_contents(
            (new \ReflectionClass(SendHttpStreamRequest::class))->getFileName()
        );
        $this->assertStringNotContainsString(
            'is_active',
            $streamRequestSource,
            'SendHttpStreamRequest is a generic, cross-package job and must never gain an llm-client-specific is_active check'
        );
    }

    #[Test]
    public function deactivation_takes_effect_immediately_with_no_window(): void
    {
        $agentId = $this->createAgent('name: immediate-effect-agent');
        $this->createAgent('name: sibling-agent');
        $server = $this->createServer();

        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId))->assertStatus(200);

        // Immediately, same test, no delay: the very first post-deactivation
        // attempt must already be refused.
        $response = $this->actingAs($this->user, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agentId,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(409);
        $this->assertSame('agent_deactivated', $response->json('code'));
    }

    // =================================================================
    // T021 — US3 (quickstart steps 9, 10)
    // =================================================================

    #[Test]
    public function a_listing_plainly_distinguishes_active_from_deactivated_agents(): void
    {
        $agentAId = $this->createAgent('name: agent-a');
        $agentBId = $this->createAgent('name: agent-b');
        $agentCId = $this->createAgent('name: agent-c');

        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentBId))->assertStatus(200);

        $listing = $this->actingAs($this->user)->getJson($this->agentsUrl());
        $listing->assertStatus(200);

        $entries = collect($listing->json('data'));
        $this->assertCount(3, $entries, 'fixture sanity: all three agents must be listed');

        $entryA = $entries->firstWhere('id', $agentAId);
        $entryB = $entries->firstWhere('id', $agentBId);
        $entryC = $entries->firstWhere('id', $agentCId);

        $this->assertArrayHasKey('is_active', $entryA, 'each listing entry must carry its own is_active field');
        $this->assertArrayHasKey('is_active', $entryB, 'each listing entry must carry its own is_active field');
        $this->assertArrayHasKey('is_active', $entryC, 'each listing entry must carry its own is_active field');

        $this->assertTrue($entryA['is_active'], 'agent-a was never deactivated');
        $this->assertFalse($entryB['is_active'], 'agent-b was deactivated');
        $this->assertTrue($entryC['is_active'], 'agent-c was never deactivated');
    }

    #[Test]
    public function the_listing_reflects_the_current_status_never_a_stale_one(): void
    {
        $agentId = $this->createAgent('name: flip-flop-agent');
        $this->createAgent('name: sibling-agent'); // sibling, so the agent under test is never the caller's last active one

        $before = $this->actingAs($this->user)->getJson($this->agentsUrl());
        $before->assertStatus(200);
        $entryBefore = collect($before->json('data'))->firstWhere('id', $agentId);
        $this->assertArrayHasKey('is_active', $entryBefore);
        $this->assertTrue($entryBefore['is_active'], 'fixture sanity: the agent starts active');

        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId))->assertStatus(200);

        $afterDeactivate = $this->actingAs($this->user)->getJson($this->agentsUrl());
        $afterDeactivate->assertStatus(200);
        $entryAfterDeactivate = collect($afterDeactivate->json('data'))->firstWhere('id', $agentId);
        $this->assertArrayHasKey('is_active', $entryAfterDeactivate);
        $this->assertFalse($entryAfterDeactivate['is_active'], 'a fresh listing must reflect the just-applied deactivation, never a stale cached true');
        $this->assertNotSame($entryBefore['is_active'], $entryAfterDeactivate['is_active'], 'the read must be live: the second listing must differ from the first');

        $this->actingAs($this->user)->postJson($this->activateUrl($agentId))->assertStatus(200);

        $afterReactivate = $this->actingAs($this->user)->getJson($this->agentsUrl());
        $afterReactivate->assertStatus(200);
        $entryAfterReactivate = collect($afterReactivate->json('data'))->firstWhere('id', $agentId);
        $this->assertArrayHasKey('is_active', $entryAfterReactivate);
        $this->assertTrue($entryAfterReactivate['is_active'], 'a fresh listing must reflect the just-applied reactivation, never a stale cached false');
    }

    // =================================================================
    // T025 — US4 (quickstart step 16, research.md D4 — structural only)
    // =================================================================

    #[Test]
    public function the_admission_gate_is_keyed_on_the_target_agents_identity_not_the_callers(): void
    {
        // No agent-to-agent delegation mechanism exists anywhere in this
        // codebase (research.md D4 — re-confirmed via a repo-wide grep for
        // delegat/handoff/sub-?agent immediately before writing this test;
        // roadmap item 4.2.4 remains unbuilt). This test therefore proves
        // the one structural property this feature *can* honestly
        // establish in delegation's absence: ConversationController::store()'s
        // admission check is keyed on the target agent's own identity/state
        // (Agent.is_active, resolved via the ownership-scoped findAgent()),
        // never on any notion of "who" or "what" is making the request — so
        // a future 4.2.4 that happened to route through this same,
        // unmodified POST /conversation path would inherit the refusal
        // automatically, with zero new code.
        $agentId = $this->createAgent('name: helper-agent');
        $this->createAgent('name: sibling-agent'); // sibling, so the agent under test is never the caller's last active one
        $userB = User::factory()->create();
        $server = $this->createServer();

        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId))->assertStatus(200);

        // The owning caller ("User A") is refused via the deactivated-agent
        // branch — a 409, never a 201, never an unhandled exception.
        $ownerAttempt = $this->actingAs($this->user, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agentId,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);
        $ownerAttempt->assertStatus(409);
        $this->assertSame('agent_deactivated', $ownerAttempt->json('code'));

        // A completely unrelated caller ("User B," standing in for what a
        // future delegating agent's request would look like) is refused via
        // the pre-existing ownership check instead — a 404, never a 201,
        // never an unhandled exception. The refusal path has no
        // special-cased "is this a delegating agent vs. a person" branch:
        // both callers are refused, each via whichever pre-existing check
        // their own relationship to the agent already triggers.
        $strangerAttempt = $this->actingAs($userB, 'api')->postJson($this->conversationUrl(), [
            'agent_id' => $agentId,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);
        $strangerAttempt->assertStatus(404);

        // No caller, regardless of identity, was ever admitted to the
        // deactivated agent.
        $this->assertSame(0, Conversation::where('agent_id', $agentId)->count(), 'no caller may ever be admitted to a deactivated agent, regardless of identity');

        // Code-inspection assertion: store()'s method body contains no
        // conditional branching on any caller-type/caller-role/delegation
        // concept — confirming the Phase 4 check is unconditionally
        // agent-identity-keyed, not caller-identity-keyed.
        $controllerSource = file_get_contents(
            (new \ReflectionClass(\ClarionApp\LlmClient\Controllers\ConversationController::class))->getFileName()
        );
        $reflectionMethod = (new \ReflectionClass(\ClarionApp\LlmClient\Controllers\ConversationController::class))->getMethod('store');
        $storeSource = implode('', array_slice(
            explode("\n", $controllerSource),
            $reflectionMethod->getStartLine() - 1,
            $reflectionMethod->getEndLine() - $reflectionMethod->getStartLine() + 1
        ));

        foreach (['delegat', 'handoff', 'hand-off', 'caller_type', 'callerType', 'sub_agent', 'subAgent'] as $forbiddenToken) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbiddenToken,
                $storeSource,
                "store() must contain no caller-type/delegation concept ({$forbiddenToken}) — the admission check must stay unconditionally agent-identity-keyed"
            );
        }
    }
}
