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
 * 104-multi-agent-consensus, Phase 5 (US3), tasks.md T035.
 *
 * quickstart.md scenario 4: 3 contributors, two of whom converge on the
 * same substantive conclusion via visibly different wording ("This is safe
 * to proceed" / "There's no issue running this"), one of whom reaches a
 * genuinely different conclusion ("This carries real risk"). Proves two
 * things at once: wording differences among the majority must NOT falsely
 * trigger disagreement (they still end up in one position together), and
 * the minority's differing substance MUST trigger materially_disagreed
 * (not agreed). Also proves disagreement_detail reaches the HTTP response
 * in contracts/consensus-api.md §1's exact {position_summary,
 * supporting_contributor_delegation_ids} shape, end to end from the judge's
 * own positions output through ConsensusService::finalize()'s persisted
 * JSON through the controller's response body.
 *
 * Mirrors ConsensusReliableAnswerJourneyTest's own established technique
 * exactly (Phase 3): ConsensusReconciliationJudge/RoleResolver are both
 * final and cannot be mocked directly, so a controlled reconciliation
 * outcome is produced via a real judge-role RoleAssignment plus a fake
 * ProviderRegistry provider returning fixed JSON, and DelegationService is
 * a container-bound mock.
 *
 * Written before any Phase 5 fix (if one turns out to be needed) -- run
 * first to confirm its actual pass/fail status against the as-built Phase
 * 3/4 code before concluding whether T036 needs to change anything.
 */
class ConsensusMaterialDisagreementJourneyTest extends TestCase
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

    /**
     * Dispatches 3 contributors with the exact wording quickstart scenario
     * 4 describes: contributor 0/1 converge on the same substantive
     * conclusion via visibly different wording, contributor 2 reaches a
     * genuinely different conclusion. Returns the 3 Delegation rows (in
     * dispatch order) so the caller can build a judge response naming the
     * real delegation ids.
     *
     * @return Delegation[]
     */
    private function mockDelegationBatchMateriallyDisagreeing(Conversation $conversation, array $helpers): array
    {
        $batchId = (string) Str::uuid();
        $summaries = [
            'This is safe to proceed.',
            "There's no issue running this.",
            'This carries real risk.',
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
     * provider returning the given JSON content (ConsensusReliableAnswerJourneyTest's
     * own established technique).
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
    // Scenario 4 (US3, FR-006, SC-002): two contributors converge via
    // different wording, one genuinely disagrees -> materially_disagreed,
    // disagreement_detail names exactly 2 positions in the exact contract
    // shape, the majority position naming both agreeing contributors.
    // =================================================================

    #[Test]
    public function scenario_4_wording_differences_among_the_majority_do_not_false_positive_but_the_minoritys_differing_substance_does(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 's4');
        $delegations = $this->mockDelegationBatchMateriallyDisagreeing($fixture['conversation'], $fixture['helpers']);

        [$majorityA, $majorityB, $minority] = $delegations;

        $this->seedJudge([
            'classification' => 'materially_disagreed',
            'reconciled_answer' => 'Contributors disagree: two hold this is safe to proceed, one holds it carries real risk.',
            'positions' => [
                [
                    'summary' => 'Safe to proceed.',
                    'supporting' => [$majorityA->id, $majorityB->id],
                ],
                [
                    'summary' => 'Carries real risk.',
                    'supporting' => [$minority->id],
                ],
            ],
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe to run this migration?',
                'conversation_id' => $fixture['conversation']->id,
            ]);

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));

        // Wording differences among the majority did NOT falsely trigger
        // disagreement, and the minority's differing substance DID.
        $this->assertSame('materially_disagreed', $response->json('agreement_classification'));

        $disagreementDetail = $response->json('disagreement_detail');
        $this->assertIsArray($disagreementDetail);
        $this->assertCount(2, $disagreementDetail, 'exactly 2 positions: the converged majority and the differing minority');

        foreach ($disagreementDetail as $position) {
            $this->assertArrayHasKey('position_summary', $position);
            $this->assertArrayHasKey('supporting_contributor_delegation_ids', $position);
            $this->assertCount(2, $position, 'exactly the {position_summary, supporting_contributor_delegation_ids} shape, no extra keys');
        }

        $majorityPosition = collect($disagreementDetail)
            ->first(fn (array $position) => count($position['supporting_contributor_delegation_ids']) === 2);

        $this->assertNotNull($majorityPosition, 'the majority position (both agreeing contributors) must be present');
        $this->assertEqualsCanonicalizing(
            [$majorityA->id, $majorityB->id],
            $majorityPosition['supporting_contributor_delegation_ids'],
            'the majority position must list both agreeing contributors\' delegation ids',
        );

        $minorityPosition = collect($disagreementDetail)
            ->first(fn (array $position) => count($position['supporting_contributor_delegation_ids']) === 1);

        $this->assertNotNull($minorityPosition, 'the minority position must be present');
        $this->assertSame([$minority->id], $minorityPosition['supporting_contributor_delegation_ids']);
    }
}
