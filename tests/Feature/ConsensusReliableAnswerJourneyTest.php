<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\ConsensusRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ConsensusService;
use ClarionApp\LlmClient\Services\CostEstimator;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\Services\UsageEstimator;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RoleResolution;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 104-multi-agent-consensus, Phase 3 (US1), tasks.md T017.
 *
 * The full acceptance journey for the MVP: quickstart.md scenarios 1, 2,
 * 10, 11, and 13 (US1 AC1-AC4, FR-001/FR-002/FR-004/FR-005/FR-014/FR-015/
 * FR-016, SC-001/SC-002/SC-006).
 *
 * DelegationService is a container-bound mock throughout. ConsensusReconciliationJudge
 * is final (Grounding note item 3) and so cannot be mocked -- every
 * scenario needing a controlled reconciliation outcome instead seeds a
 * real judge-role RoleAssignment plus a fake ProviderRegistry provider,
 * matching ConsensusControllerTest's/ConsensusServiceTest's own
 * established technique. Scenario 13 (varying what each contributor
 * resolves to) additionally resolves ConsensusService out of the container
 * as a partial mock overriding its protected resolveContributorModel()
 * seam, since RoleResolver is likewise final.
 *
 * Written before ConsensusController/ConsensusService/the routes exist --
 * every assertion below is expected to FAIL red until Phase 3's
 * implementation tasks land.
 */
class ConsensusReliableAnswerJourneyTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('consensus_requests')->delete();
        DB::table('agent_delegations')->delete();
        DB::table('messages')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];

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

    private function makeAgent(User $owner, string $name): Agent
    {
        return app(AgentService::class)->create($owner->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeConversation(User $owner, ?Agent $agent): Conversation
    {
        return Conversation::create([
            'user_id' => $owner->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    private function makeParentWithHelpers(User $owner, int $n, string $label): array
    {
        $parent = $this->makeAgent($owner, "parent-{$label}");
        $helpers = [];
        for ($i = 0; $i < $n; $i++) {
            $helper = $this->makeAgent($owner, "helper-{$label}-{$i}");
            app(AgentHelperService::class)->assign($owner->id, $parent->id, $helper->id);
            $helpers[] = $helper;
        }

        return ['parent' => $parent, 'helpers' => $helpers, 'conversation' => $this->makeConversation($owner, $parent)];
    }

    private function sixFieldResult(string $delegationId, string $status, string $summary): array
    {
        return [
            'delegation_id' => $delegationId,
            'helper' => 'helper',
            'status' => $status,
            'summary' => $summary,
            'output' => [],
            'undone' => '',
            'truncated' => false,
            'reason' => $status === 'success' ? null : 'helper_reported',
        ];
    }

    private function makeDelegationRow(Conversation $parentConversation, Agent $helper, string $ownerUserId, string $batchId, string $summary, \DateTimeInterface $startedAt): Delegation
    {
        $helperConversation = Conversation::create([
            'user_id' => $ownerUserId,
            'character' => 'Clarion',
            'agent_id' => $helper->id,
        ]);

        return Delegation::create([
            'parent_conversation_id' => $parentConversation->id,
            'parent_agent_id' => $parentConversation->agent_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => $helperConversation->id,
            'owner_user_id' => $ownerUserId,
            'task' => 'Is it safe to run this migration?',
            'depth' => 1,
            'status' => 'completed',
            'batch_id' => $batchId,
            'result_status' => 'success',
            'result_summary' => $summary,
            'result_output' => json_encode([]),
            'result_undone' => '',
            'result_truncated' => false,
            'started_at' => $startedAt,
            'completed_at' => $startedAt,
        ]);
    }

    private function mockDelegationBatchAgreeing(Conversation $conversation, array $helpers): \Mockery\MockInterface
    {
        $batchId = (string) Str::uuid();
        $results = [];
        foreach ($helpers as $index => $helper) {
            $delegation = $this->makeDelegationRow($conversation, $helper, $this->user->id, $batchId, "Answer {$index}.", now()->addSeconds($index));
            $results['call_'.$index] = $this->sixFieldResult($delegation->id, 'success', "Answer {$index}.");
        }

        $delegationService = Mockery::mock(DelegationService::class);
        $delegationService->shouldReceive('delegateBatch')->once()->andReturn($results);

        return $delegationService;
    }

    /**
     * ConsensusReconciliationJudge is final and cannot be mocked directly
     * -- seeds a real judge-role RoleAssignment plus a fake ProviderRegistry
     * provider returning the given JSON content (RubricJudgeTest's own
     * established technique).
     */
    private function seedJudge(array $jsonResponse): void
    {
        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->server->id,
            'model' => 'judge-model',
        ]);

        $provider = $this->createMock(LlmProvider::class);
        $provider->method('chat')->willReturn([
            'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($jsonResponse)]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
            'model' => 'judge-model',
        ]);
        app(ProviderRegistry::class)->register(ProviderType::OpenAI, fn () => $provider);
    }

    // =================================================================
    // Scenarios 1/2 (US1 AC1-AC4, FR-001/FR-002/FR-004/FR-005,
    // SC-001/SC-002): a single reconciled answer, not a per-contributor
    // list -- and a later plain POST /agent call on the SAME conversation
    // carries zero consensus fields (FR-014/SC-006, structural proof the
    // two endpoints are genuinely independent code paths).
    // =================================================================

    #[Test]
    public function scenarios_1_and_2_a_single_reconciled_answer_and_a_later_plain_agent_call_carries_no_consensus_fields(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 's1');
        $delegationService = $this->mockDelegationBatchAgreeing($fixture['conversation'], $fixture['helpers']);
        $this->app->instance(DelegationService::class, $delegationService);
        $this->seedJudge([
            'classification' => 'agreed',
            'reconciled_answer' => 'Yes, all three contributors agree this is safe.',
            'positions' => [],
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe to run this migration?',
                'conversation_id' => $fixture['conversation']->id,
            ]);

        $response->assertStatus(200);
        $this->assertIsString($response->json('reconciled_answer'), 'a single reconciled answer, not an array of per-contributor answers');
        $this->assertSame('completed', $response->json('status'));
        $this->assertSame('agreed', $response->json('agreement_classification'));
        $this->assertNull($response->json('disagreement_detail'));
        $this->assertNotEmpty($response->json('approximation_notice'), 'the approximation disclosure must always be present regardless of classification');

        // Now an ordinary /agent call against the SAME conversation.
        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')->once()->andReturn([
            'status' => 'completed',
            'content' => 'A normal, unrelated reply.',
            'message_id' => (string) Str::uuid(),
        ]);
        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        $agentResponse = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/agent', [
                'message' => 'A follow-up, unrelated message.',
                'conversation_id' => $fixture['conversation']->id,
            ]);

        $agentResponse->assertStatus(200);

        $consensusOnlyKeys = [
            'consensus_request_id', 'agreement_classification', 'reconciled_answer',
            'disagreement_detail', 'independence_note', 'dispatched_count',
            'successful_count', 'quorum_required', 'estimated_additional_cost',
            'actual_additional_cost', 'approximation_notice',
        ];
        foreach ($consensusOnlyKeys as $key) {
            $this->assertArrayNotHasKey($key, $agentResponse->json(), "POST /agent must never carry the consensus-only field '{$key}'");
        }
    }

    // =================================================================
    // Scenario 10 (FR-016): exactly one active helper -- a plain
    // single-contributor answer, delegateBatch() never invoked, no
    // batch_id ever generated.
    // =================================================================

    #[Test]
    public function scenario_10_exactly_one_active_helper_produces_a_single_contributor_fallback(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 1, 's10');
        $conversation = $fixture['conversation'];

        $delegationService = Mockery::mock(DelegationService::class);
        $delegationService->shouldReceive('delegate')
            ->once()
            ->andReturn($this->sixFieldResult((string) Str::uuid(), 'success', 'It is safe.'));
        $delegationService->shouldNotReceive('delegateBatch');
        $this->app->instance(DelegationService::class, $delegationService);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe to run this migration?',
                'conversation_id' => $conversation->id,
            ]);

        $response->assertStatus(200);
        $this->assertSame('single_contributor_fallback', $response->json('status'));
        $this->assertNull($response->json('agreement_classification'));
        $this->assertSame(1, $response->json('dispatched_count'));

        $row = ConsensusRequest::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->batch_id, 'no batch_id may ever be generated for the single-contributor fallback');
    }

    // =================================================================
    // Scenario 11 (contracts §1's 422): zero active helpers.
    // =================================================================

    #[Test]
    public function scenario_11_zero_active_helpers_returns_422_and_creates_no_row(): void
    {
        $agent = $this->makeAgent($this->user, 's11-agent');
        $conversation = $this->makeConversation($this->user, $agent);

        $countBefore = ConsensusRequest::count();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe to run this migration?',
                'conversation_id' => $conversation->id,
            ]);

        $response->assertStatus(422);
        $this->assertSame('no_eligible_contributors', $response->json('error'));
        $this->assertSame($countBefore, ConsensusRequest::count());
    }

    // =================================================================
    // Scenario 13 (FR-015): all contributors resolve to the identical
    // (provider_type, model) pair -- independence_note is disclosed even
    // though the outcome is 'agreed'.
    // =================================================================

    #[Test]
    public function scenario_13_a_shared_underlying_model_discloses_independence_note_even_when_agreed(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 's13');
        $delegationService = $this->mockDelegationBatchAgreeing($fixture['conversation'], $fixture['helpers']);
        $this->seedJudge([
            'classification' => 'agreed',
            'reconciled_answer' => 'Yes, safe.',
            'positions' => [],
        ]);

        // RoleResolver is final and cannot be mocked directly -- resolve a
        // partial mock of ConsensusService itself (not final) overriding
        // its protected resolveContributorModel() seam, so every selected
        // contributor resolves to the identical shared model, and bind it
        // into the container so the controller picks it up.
        $sharedResolution = RoleResolution::resolved(ModelRole::Inference, 'installation', $this->server, 'shared-model');
        $service = Mockery::mock(ConsensusService::class, [
            $delegationService,
            app(\ClarionApp\LlmClient\Services\ConsensusReconciliationJudge::class),
            app(RoleResolver::class),
            app(CostEstimator::class),
            app(UsageEstimator::class),
        ])->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('resolveContributorModel')->andReturn($sharedResolution);
        $this->app->instance(ConsensusService::class, $service);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe to run this migration?',
                'conversation_id' => $fixture['conversation']->id,
            ]);

        $response->assertStatus(200);
        $this->assertSame('agreed', $response->json('agreement_classification'));
        $this->assertNotNull($response->json('independence_note'), 'agreement among non-independent contributors must still be disclosed as such');
    }
}
