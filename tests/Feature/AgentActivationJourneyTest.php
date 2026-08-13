<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
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
}
