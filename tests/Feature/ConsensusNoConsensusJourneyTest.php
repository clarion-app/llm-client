<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Agent;
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
 * 104-multi-agent-consensus, Phase 6 (US4), tasks.md T041.
 *
 * quickstart.md scenario 3 (US4, FR-007/FR-008, SC-004): 3 contributors
 * returning mutually exclusive, non-overlapping answers to the same
 * yes/no-shaped question (one "definitely yes," one "definitely no," one
 * "the question is unanswerable as posed"). Asserts status: 'completed',
 * agreement_classification: 'no_consensus'; reconciled_answer is the fixed
 * no-consensus statement, NEVER any one contributor's own answer text
 * verbatim; disagreement_detail names all 3 differing positions;
 * separately, GET /consensus-requests/{id}/contributors returns all 3
 * individual answers in full, each attributable to its own
 * delegation_id/helper_agent_id.
 *
 * Mirrors ConsensusMaterialDisagreementJourneyTest's own established
 * technique exactly (Phase 5): DelegationService is a container-bound
 * mock; ConsensusReconciliationJudge/RoleResolver are both final and
 * cannot be mocked directly, so a controlled reconciliation outcome is
 * produced via a real judge-role RoleAssignment plus a fake
 * ProviderRegistry provider returning fixed JSON.
 *
 * Written before ConsensusController::contributors()/the contributors
 * route exist -- the GET .../contributors half of this test is expected to
 * FAIL red (route-not-found) until T043/T044 create them.
 */
class ConsensusNoConsensusJourneyTest extends TestCase
{
    private const NO_CONSENSUS_STATEMENT = 'No consensus was reached — the contributors\' answers could not be '
        .'reconciled into a shared, defensible position. See each contributor\'s individual answer.';

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
            'task' => 'Should we ship this release today?',
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

    /**
     * Dispatches 3 contributors with mutually exclusive, non-overlapping
     * answers (quickstart scenario 3's own wording). Returns the 3
     * Delegation rows (in dispatch order) so the caller can build a judge
     * response naming the real delegation ids.
     *
     * @return Delegation[]
     */
    private function mockDelegationBatchFullyDisagreeing(Conversation $conversation, array $helpers): array
    {
        $batchId = (string) Str::uuid();
        $summaries = [
            'Definitely yes, ship it today.',
            'Definitely no, do not ship today.',
            'The question is unanswerable as posed -- it depends on unresolved factors.',
        ];

        $delegations = [];
        $results = [];
        foreach ($helpers as $index => $helper) {
            $delegation = $this->makeDelegationRow(
                $conversation,
                $helper,
                $this->user->id,
                $batchId,
                $summaries[$index],
                now()->addSeconds($index),
            );
            $delegations[] = $delegation;
            $results['call_'.$index] = $this->sixFieldResult($delegation->id, 'success', $summaries[$index]);
        }

        $delegationService = Mockery::mock(DelegationService::class);
        $delegationService->shouldReceive('delegateBatch')->once()->andReturn($results);
        $this->app->instance(DelegationService::class, $delegationService);

        return $delegations;
    }

    /**
     * ConsensusReconciliationJudge is final and cannot be mocked directly
     * -- seeds a real judge-role RoleAssignment plus a fake ProviderRegistry
     * provider returning the given JSON content.
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
    // Scenario 3 (US4, FR-007/FR-008, SC-004): mutually exclusive answers
    // -> no_consensus; reconciled_answer is the fixed statement, never any
    // one contributor's own text; disagreement_detail names all 3
    // positions; GET .../contributors returns all 3 individual answers.
    // =================================================================

    #[Test]
    public function scenario_3_mutually_exclusive_answers_produce_an_honest_no_consensus_result_with_all_individual_answers_inspectable(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'nc');
        $delegations = $this->mockDelegationBatchFullyDisagreeing($fixture['conversation'], $fixture['helpers']);

        [$yes, $no, $unanswerable] = $delegations;

        $this->seedJudge([
            'classification' => 'no_consensus',
            // Deliberately a judge-composed string distinct from the fixed
            // statement AND from any one contributor's own answer -- proves
            // the response is neither the judge's own free-text nor a
            // contributor's text leaking through.
            'reconciled_answer' => 'The contributors gave three incompatible answers.',
            'positions' => [
                ['summary' => 'Ship it today.', 'supporting' => [$yes->id]],
                ['summary' => 'Do not ship today.', 'supporting' => [$no->id]],
                ['summary' => 'Unanswerable as posed.', 'supporting' => [$unanswerable->id]],
            ],
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Should we ship this release today?',
                'conversation_id' => $fixture['conversation']->id,
            ]);

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));
        $this->assertSame('no_consensus', $response->json('agreement_classification'));

        $reconciledAnswer = $response->json('reconciled_answer');
        $this->assertSame(self::NO_CONSENSUS_STATEMENT, $reconciledAnswer);
        $this->assertNotSame('The contributors gave three incompatible answers.', $reconciledAnswer);
        $this->assertStringNotContainsString('Definitely yes', $reconciledAnswer);
        $this->assertStringNotContainsString('Definitely no', $reconciledAnswer);

        $disagreementDetail = $response->json('disagreement_detail');
        $this->assertIsArray($disagreementDetail);
        $this->assertCount(3, $disagreementDetail, 'all 3 differing positions must be named');

        $allSupportingIds = collect($disagreementDetail)
            ->flatMap(fn (array $position) => $position['supporting_contributor_delegation_ids'])
            ->all();
        $this->assertEqualsCanonicalizing([$yes->id, $no->id, $unanswerable->id], $allSupportingIds);

        $requestId = $response->json('consensus_request_id');

        // Separately: GET .../contributors returns all 3 individual answers
        // in full, each attributable to its own delegation_id/helper_agent_id.
        $contributorsResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/consensus-requests/{$requestId}/contributors");

        $contributorsResponse->assertStatus(200);
        $contributors = $contributorsResponse->json('contributors');
        $this->assertIsArray($contributors);
        $this->assertCount(3, $contributors);

        $byDelegationId = collect($contributors)->keyBy('delegation_id');
        $this->assertSame('Definitely yes, ship it today.', $byDelegationId[$yes->id]['answer']);
        $this->assertSame($fixture['helpers'][0]->id, $byDelegationId[$yes->id]['helper_agent_id']);
        $this->assertSame('Definitely no, do not ship today.', $byDelegationId[$no->id]['answer']);
        $this->assertSame($fixture['helpers'][1]->id, $byDelegationId[$no->id]['helper_agent_id']);
        $this->assertSame('The question is unanswerable as posed -- it depends on unresolved factors.', $byDelegationId[$unanswerable->id]['answer']);
        $this->assertSame($fixture['helpers'][2]->id, $byDelegationId[$unanswerable->id]['helper_agent_id']);

        foreach ($contributors as $contributor) {
            $this->assertSame('success', $contributor['result_status']);
        }
    }

    // =================================================================
    // GET .../contributors 404s for a request not owned by the caller
    // (same uniform shape as GET /consensus-requests/{id}, contracts §3).
    // =================================================================

    #[Test]
    public function contributors_endpoint_returns_404_for_a_request_not_owned_by_the_caller(): void
    {
        $otherUser = User::factory()->create();
        $fixture = $this->makeParentWithHelpers($otherUser, 3, 'other');
        $this->mockDelegationBatchFullyDisagreeing($fixture['conversation'], $fixture['helpers']);
        $this->seedJudge([
            'classification' => 'no_consensus',
            'reconciled_answer' => 'irrelevant',
            'positions' => [
                ['summary' => 'A.', 'supporting' => []],
                ['summary' => 'B.', 'supporting' => []],
            ],
        ]);

        $storeResponse = $this->actingAs($otherUser, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Should we ship this release today?',
                'conversation_id' => $fixture['conversation']->id,
            ]);
        $storeResponse->assertStatus(200);
        $id = $storeResponse->json('consensus_request_id');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/consensus-requests/{$id}/contributors");

        $response->assertStatus(404);
    }
}
