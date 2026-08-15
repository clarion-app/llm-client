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
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 104-multi-agent-consensus, Phase 3 (US1), tasks.md T016.
 *
 * Feature tests for `ConsensusController` (contracts/consensus-api.md
 * §1/§2): `POST /consensus-requests`, `GET /consensus-requests/{id}`.
 * DelegationService is a container-bound mock -- its own dispatch
 * machinery has its own dedicated test elsewhere. ConsensusReconciliationJudge
 * is `final` (mirrors RubricJudge, Grounding note item 3) and so cannot be
 * mocked directly -- every test controlling its outcome instead seeds a
 * real judge-role RoleAssignment plus a fake ProviderRegistry provider, the
 * same technique RubricJudgeTest already establishes.
 *
 * Written before ConsensusController/the routes exist -- every request
 * below hits Laravel's own route-not-found 404 until T023/T024 create the
 * controller and routes.
 */
class ConsensusControllerTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
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

    private function makeConversation(User $owner, ?Agent $agent, array $overrides = []): Conversation
    {
        return Conversation::create(array_merge([
            'user_id' => $owner->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ], $overrides));
    }

    /** A parent agent with N assigned helper agents, and a conversation bound to the parent. */
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

    private function mockDelegationBatchAgreeing(Conversation $conversation, array $helpers): void
    {
        $batchId = (string) Str::uuid();
        $results = [];
        foreach ($helpers as $index => $helper) {
            $delegation = $this->makeDelegationRow($conversation, $helper, $this->user->id, $batchId, "Answer {$index}.", now()->addSeconds($index));
            $results['call_'.$index] = $this->sixFieldResult($delegation->id, 'success', "Answer {$index}.");
        }

        $delegationService = Mockery::mock(DelegationService::class);
        $delegationService->shouldReceive('delegateBatch')->once()->andReturn($results);
        $this->app->instance(DelegationService::class, $delegationService);
    }

    /**
     * ConsensusReconciliationJudge is final and cannot be mocked directly
     * -- seeds a real judge-role RoleAssignment plus a fake ProviderRegistry
     * provider returning the given JSON content, exactly like
     * RubricJudgeTest's own established technique.
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
    // POST /consensus-requests
    // =================================================================

    #[Test]
    public function store_returns_200_with_the_full_completed_agreed_shape(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'agree');
        $this->mockDelegationBatchAgreeing($fixture['conversation'], $fixture['helpers']);
        $this->seedJudge([
            'classification' => 'agreed',
            'reconciled_answer' => 'Yes, all contributors agree this is safe.',
            'positions' => [],
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe to run this migration?',
                'conversation_id' => $fixture['conversation']->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'consensus_request_id',
            'conversation_id',
            'answer_message_id',
            'status',
            'agreement_classification',
            'reconciled_answer',
            'disagreement_detail',
            'independence_note',
            'dispatched_count',
            'successful_count',
            'quorum_required',
            'estimated_additional_cost',
            'actual_additional_cost',
            'approximation_notice',
        ]);
        $this->assertSame('completed', $response->json('status'));
        $this->assertSame('agreed', $response->json('agreement_classification'));
        $this->assertIsString($response->json('reconciled_answer'));
        $this->assertNull($response->json('disagreement_detail'));
        $this->assertNotEmpty($response->json('approximation_notice'));
    }

    #[Test]
    public function store_returns_404_for_an_unowned_conversation_id(): void
    {
        $othersAgent = $this->makeAgent($this->otherUser, 'others-agent');
        $othersConversation = $this->makeConversation($this->otherUser, $othersAgent);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe?',
                'conversation_id' => $othersConversation->id,
            ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function store_returns_404_for_a_nonexistent_conversation_id(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe?',
                'conversation_id' => (string) Str::uuid(),
            ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function store_returns_409_when_the_conversation_is_already_processing(): void
    {
        $agent = $this->makeAgent($this->user, 'processing-agent');
        $conversation = $this->makeConversation($this->user, $agent, ['is_processing' => true]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe?',
                'conversation_id' => $conversation->id,
            ]);

        $response->assertStatus(409);
    }

    #[Test]
    public function store_returns_422_for_an_empty_question(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'empty-q');

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => '   ',
                'conversation_id' => $fixture['conversation']->id,
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function store_returns_422_no_eligible_contributors_and_creates_no_row_when_below_the_minimum(): void
    {
        $agent = $this->makeAgent($this->user, 'no-helpers-agent');
        $conversation = $this->makeConversation($this->user, $agent);

        $countBefore = ConsensusRequest::count();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe?',
                'conversation_id' => $conversation->id,
            ]);

        $response->assertStatus(422);
        $this->assertSame('no_eligible_contributors', $response->json('error'));
        $this->assertSame($countBefore, ConsensusRequest::count());
    }

    #[Test]
    public function store_returns_500_with_a_failure_reason_when_reconciliation_fails(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'fail');
        $this->mockDelegationBatchAgreeing($fixture['conversation'], $fixture['helpers']);
        // No judge role assigned at all -- the real judge deterministically
        // returns unreconciled('No judge model is assigned.').

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe?',
                'conversation_id' => $fixture['conversation']->id,
            ]);

        $response->assertStatus(500);
        $this->assertSame('failed', $response->json('status'));
        $this->assertSame('No judge model is assigned.', $response->json('failure_reason'));
    }

    // =================================================================
    // GET /consensus-requests/{id}
    // =================================================================

    #[Test]
    public function show_returns_the_identical_stored_shape_for_a_completed_request(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'show');
        $this->mockDelegationBatchAgreeing($fixture['conversation'], $fixture['helpers']);
        $this->seedJudge([
            'classification' => 'agreed',
            'reconciled_answer' => 'Yes, safe.',
            'positions' => [],
        ]);

        $storeResponse = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe?',
                'conversation_id' => $fixture['conversation']->id,
            ]);
        $storeResponse->assertStatus(200);
        $id = $storeResponse->json('consensus_request_id');

        $showResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/consensus-requests/{$id}");

        $showResponse->assertStatus(200);
        $this->assertSame($storeResponse->json(), $showResponse->json());
    }

    #[Test]
    public function show_returns_404_for_a_request_not_owned_by_the_caller(): void
    {
        $fixture = $this->makeParentWithHelpers($this->otherUser, 3, 'not-owned');
        $this->mockDelegationBatchAgreeing($fixture['conversation'], $fixture['helpers']);
        $this->seedJudge([
            'classification' => 'agreed',
            'reconciled_answer' => 'Yes, safe.',
            'positions' => [],
        ]);

        $storeResponse = $this->actingAs($this->otherUser, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe?',
                'conversation_id' => $fixture['conversation']->id,
            ]);
        $storeResponse->assertStatus(200);
        $id = $storeResponse->json('consensus_request_id');

        $showResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/consensus-requests/{$id}");

        $showResponse->assertStatus(404);
    }

    #[Test]
    public function show_returns_404_for_an_unknown_request_id(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/clarion-app/llm-client/consensus-requests/'.(string) Str::uuid());

        $response->assertStatus(404);
    }
}
