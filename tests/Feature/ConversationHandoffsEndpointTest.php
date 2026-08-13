<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 093-agent-handoff, Phase 7 (US6, T038).
 *
 * Mirrors AgentActivationJourneyTest.php's own actingAs()/getJson()/
 * ownership-scoping conventions (contracts §3, data-model.md §1,
 * research.md D10, quickstart.md steps 15/18).
 *
 * Written before `GET conversation/{id}/handoffs` exists — every test in
 * this file is expected to FAIL with a route-not-found error (no
 * `ConversationController::handoffs()` action, no matching route) until
 * T040/T041 add the controller action and route.
 */
class ConversationHandoffsEndpointTest extends TestCase
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

        DB::table('conversation_handoffs')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Operation catalog seam — required before any *valid*
    // AgentDefinitionParser::parse() call (AgentServiceTest's own
    // established convention, mirrored from AgentHandoffJourneyTest).
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
    // URL / fixture helpers
    // ---------------------------------------------------------------

    private function handoffsUrl(string $conversationId): string
    {
        return "/api/clarion-app/llm-client/conversation/{$conversationId}/handoffs";
    }

    /**
     * The direct, non-HTTP dispatch precedent this package already uses
     * for execute_operation/propose_declarative_memory, and that
     * AgentHandoffJourneyTest.php already uses for handoff_to_agent
     * (research.md D1/D8, contracts §1) — this feature ships no HTTP
     * endpoint to trigger a handoff itself, only this new read endpoint.
     *
     * @return array<string, mixed>
     */
    private function handoff(Conversation $conversation, string $targetAgentId): array
    {
        $result = app(AgentLoopService::class)->executeMetaTool(
            'handoff_to_agent',
            ['agent_id' => $targetAgentId],
            $conversation,
        );

        return json_decode($result, true);
    }

    private function makeConversation(?Agent $agent, string $userId): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $userId,
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    // =================================================================
    // T038 (US6, contracts §3, quickstart steps 15, 18)
    // =================================================================

    #[Test]
    public function the_endpoint_reflects_the_chain_accurately_in_position_order(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");
        $agentC = app(AgentService::class)->create($this->user->id, "name: agent-c\ninstructions: I am agent C.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $first = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($first['success'] ?? false, 'fixture sanity: the first handoff (A -> B) must succeed');
        $conversation = $conversation->fresh();

        $second = $this->handoff($conversation, $agentC->id);
        $this->assertTrue($second['success'] ?? false, 'fixture sanity: the second handoff (B -> C) must succeed');

        $response = $this->actingAs($this->user, 'api')->getJson($this->handoffsUrl($conversation->id));
        $response->assertStatus(200);

        $rows = $response->json();
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows, 'the endpoint must return one row per handoff — two handoffs, two rows');

        $this->assertSame(1, $rows[0]['position']);
        $this->assertSame($agentA->id, $rows[0]['from_agent_id']);
        $this->assertSame($agentA->name, $rows[0]['from_agent_name']);
        $this->assertSame($agentB->id, $rows[0]['to_agent_id']);
        $this->assertSame($agentB->name, $rows[0]['to_agent_name']);
        $this->assertSame($agentB->current_version_id, $rows[0]['to_agent_version_id']);
        $this->assertArrayHasKey('created_at', $rows[0]);
        $this->assertArrayHasKey('disclosed_at', $rows[0]);

        $this->assertSame(2, $rows[1]['position']);
        $this->assertSame($agentB->id, $rows[1]['from_agent_id']);
        $this->assertSame($agentB->name, $rows[1]['from_agent_name']);
        $this->assertSame($agentC->id, $rows[1]['to_agent_id']);
        $this->assertSame($agentC->name, $rows[1]['to_agent_name']);
        $this->assertSame($agentC->current_version_id, $rows[1]['to_agent_version_id']);
        $this->assertArrayHasKey('created_at', $rows[1]);
        $this->assertArrayHasKey('disclosed_at', $rows[1]);
    }

    #[Test]
    public function from_and_to_agent_names_still_resolve_even_when_the_named_agent_has_since_been_soft_deleted(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $result = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($result['success'] ?? false, 'fixture sanity: the handoff (A -> B) must succeed');

        Agent::find($agentB->id)->delete();
        $this->assertNotNull(
            Agent::withTrashed()->find($agentB->id)->deleted_at,
            'fixture sanity: agent B must actually be soft-deleted',
        );

        $response = $this->actingAs($this->user, 'api')->getJson($this->handoffsUrl($conversation->id));
        $response->assertStatus(200);

        $rows = $response->json();
        $this->assertCount(1, $rows);
        $this->assertSame(
            $agentB->name,
            $rows[0]['to_agent_name'],
            'to_agent_name must still resolve via Agent::withTrashed() even for a since soft-deleted agent (FR-005)',
        );
    }

    #[Test]
    public function a_request_for_a_conversation_belonging_to_a_different_user_is_refused(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $conversation = $this->makeConversation($agentA, $this->user->id);

        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser, 'api')->getJson($this->handoffsUrl($conversation->id));
        $response->assertStatus(403, 'a conversation owned by a different user must be refused, mirroring show()\'s own established ownership-scoping convention');
    }

    #[Test]
    public function a_nonexistent_conversation_returns_404(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson($this->handoffsUrl((string) Str::uuid()));
        $response->assertStatus(404);
    }

    #[Test]
    public function a_conversation_with_no_handoffs_at_all_returns_an_empty_array_not_an_error(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $conversation = $this->makeConversation($agentA, $this->user->id);

        $response = $this->actingAs($this->user, 'api')->getJson($this->handoffsUrl($conversation->id));
        $response->assertStatus(200);
        $this->assertSame([], $response->json());
    }
}
