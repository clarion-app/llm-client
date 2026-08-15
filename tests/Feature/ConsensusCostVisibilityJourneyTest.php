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
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\CostEstimator;
use ClarionApp\LlmClient\Services\DelegationService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 104-multi-agent-consensus, Phase 4 (US2), tasks.md T028.
 *
 * quickstart.md scenarios 7-8 (FR-012/FR-013, SC-003), through the real HTTP
 * endpoints:
 *
 *  - Scenario 7: POST /consensus-requests/cost-estimate previews the
 *    additional cost before a user commits -- roughly 2x one contributor's
 *    own cost for a 3-contributor coordinator, never 3x and never 0, with
 *    no ConsensusRequest row ever created.
 *  - Scenario 8: the completed POST /consensus-requests response's
 *    actual_additional_cost matches contracts §5's exact subtraction
 *    formula against seeded usage_records costs, excluding the
 *    reconciliation judge's own cost, and reads identically on a later,
 *    independent GET.
 *
 * Written before T029 (estimated_additional_cost's full formula) and T032
 * (the cost-estimate endpoint) are complete -- every assertion below is
 * expected to FAIL red first (the cost-estimate route doesn't exist yet,
 * and actual_additional_cost's formula was only partially wired per Phase
 * 3's own Progress Log note on T021).
 */
class ConsensusCostVisibilityJourneyTest extends TestCase
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

        DB::table('usage_records')->delete();
        DB::table('model_prices')->delete();
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

    // -----------------------------------------------------------------
    // Operation-catalog scaffolding (Scramble is not under test here)
    // -----------------------------------------------------------------

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

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

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

    private function seedInferenceRole(string $model): void
    {
        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->server->id,
            'model' => $model,
        ]);
    }

    private function seedJudgeRole(string $model): void
    {
        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->server->id,
            'model' => $model,
        ]);
    }

    private function seedPrice(string $model, array $overrides = []): ModelPrice
    {
        return ModelPrice::create(array_merge([
            'provider_type' => 'openai',
            'model' => $model,
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ], $overrides));
    }

    private function registerJudgeProvider(array $jsonResponse): void
    {
        $provider = $this->createMock(LlmProvider::class);
        $provider->method('chat')->willReturn([
            'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($jsonResponse)]]],
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 10, 'total_tokens' => 40],
            'model' => 'priced-judge-model',
        ]);
        app(ProviderRegistry::class)->register(ProviderType::OpenAI, fn () => $provider);
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

    private function seedContributorUsage(string $conversationId, string $totalCost): UsageRecord
    {
        return UsageRecord::create([
            'conversation_id' => $conversationId,
            'user_id' => $this->user->id,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'model' => 'contributor-model',
            'provider_type' => 'openai',
            'total_cost' => $totalCost,
            'cost_unpriced' => false,
            'created_at' => now(),
        ]);
    }

    // =================================================================
    // Scenario 7 (US2 AC1, FR-012, SC-003): cost-estimate preview
    // =================================================================

    #[Test]
    public function cost_estimate_endpoint_previews_roughly_twice_one_contributors_cost_without_creating_a_row(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'cost-preview');
        $this->seedInferenceRole('priced-inference-model');
        $this->seedPrice('priced-inference-model');

        $question = 'Is it safe to run this migration against the production replica during business hours?';

        $countBefore = ConsensusRequest::count();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests/cost-estimate', [
                'question' => $question,
                'conversation_id' => $fixture['conversation']->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['dispatched_count', 'estimated_additional_cost']);
        $this->assertSame(3, $response->json('dispatched_count'));

        // Independently compute what one contributor's own single-turn
        // estimate would be: CostEstimator::estimate() against the
        // (message-less) conversation, plus the priced cost of the pending
        // question's own input tokens (research.md D6 -- the question is
        // not yet persisted as a Message, so CostEstimator alone would
        // under-count by exactly that text).
        $costEstimate = app(CostEstimator::class)->estimate($fixture['conversation']->id, 'openai', 'priced-inference-model');
        $this->assertFalse($costEstimate->unpriced);

        $price = ModelPrice::currentFor('openai', 'priced-inference-model', now());
        $questionTokens = (int) ceil(strlen($question) / 1.3);
        $questionCost = bcdiv(bcmul((string) $questionTokens, (string) $price->fresh_input_rate, 20), '1000000', 20);

        $perContributor = bcadd($costEstimate->amount, $questionCost, 10);
        $expectedTwoX = bcadd($perContributor, $perContributor, 10);
        $expectedThreeX = bcadd($expectedTwoX, $perContributor, 10);

        $actual = $response->json('estimated_additional_cost');
        $this->assertNotNull($actual, 'a 3-contributor estimate must be a positive figure, never null');
        $this->assertGreaterThan(0.0, (float) $actual);
        $this->assertSame(0, bccomp($actual, $expectedTwoX, 8), "expected roughly 2x one contributor's own cost ({$expectedTwoX}), got {$actual}");
        $this->assertNotSame(0, bccomp($actual, $expectedThreeX, 8), 'must not be 3x one contributor\'s own cost');
        $this->assertNotSame(0, bccomp($actual, '0', 8), 'must not be zero');

        $this->assertSame($countBefore, ConsensusRequest::count(), 'the cost-estimate endpoint must never create a ConsensusRequest row');
    }

    // =================================================================
    // Scenario 8 (US2 AC2, FR-013, SC-003): actual_additional_cost matches
    // the exact subtraction formula, excludes the judge's own cost, and is
    // identical on a later independent GET.
    // =================================================================

    #[Test]
    public function actual_additional_cost_matches_the_exact_subtraction_formula_and_excludes_the_judge_cost(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'cost-actual');
        $helpers = $fixture['helpers'];
        $conversation = $fixture['conversation'];

        $this->seedJudgeRole('priced-judge-model');
        $this->seedPrice('priced-judge-model');
        $this->registerJudgeProvider([
            'classification' => 'agreed',
            'reconciled_answer' => 'Yes, all contributors agree this is safe.',
            'positions' => [],
        ]);

        $delegationService = Mockery::mock(DelegationService::class);
        $delegationService->shouldReceive('delegateBatch')
            ->once()
            ->andReturnUsing(function ($conv, array $calls) use ($conversation, $helpers) {
                $batchId = (string) Str::uuid();
                $results = [];
                foreach ($calls as $index => $call) {
                    $delegation = $this->makeDelegationRow(
                        $conversation,
                        $helpers[$index],
                        $this->user->id,
                        $batchId,
                        "Answer {$index}.",
                        now()->addSeconds($index),
                    );
                    // Each contributor's helper conversation genuinely
                    // incurred $0.02 -- three contributors, $0.06 total.
                    $this->seedContributorUsage($delegation->helper_conversation_id, '0.0200000000');
                    $results[$call['tool_call_id']] = $this->sixFieldResult($delegation->id, 'success', "Answer {$index}.");
                }

                return $results;
            });
        $this->app->instance(DelegationService::class, $delegationService);

        $storeResponse = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe to run this migration?',
                'conversation_id' => $conversation->id,
            ]);

        $storeResponse->assertStatus(200);
        $this->assertSame('completed', $storeResponse->json('status'));
        $this->assertSame(3, $storeResponse->json('successful_count'));

        // total_actual_contributor_cost = 0.06; successful_count = 3
        // actual_additional_cost = 0.06 - (0.06 / 3) = 0.04
        $expected = '0.0400000000';
        $actual = $storeResponse->json('actual_additional_cost');
        $this->assertNotNull($actual);
        $this->assertSame(0, bccomp($actual, $expected, 10), "expected {$expected}, got {$actual}");

        // The judge call actually incurred a real, priced cost of its own
        // (proving "excluded" below is a genuine assertion, not vacuously
        // true because no judge cost was ever recorded) -- yet the figure
        // above still matches the contributor-only sum exactly, never
        // folding the judge's own conversation into the subtraction basis.
        $judgeUsage = UsageRecord::where('model', 'priced-judge-model')->first();
        $this->assertNotNull($judgeUsage, 'the judge call must have actually incurred a priced cost for the exclusion assertion above to be meaningful');
        $this->assertGreaterThan(0.0, (float) $judgeUsage->total_cost);

        // GET /consensus-requests/{id} returns the identical figure later,
        // independently (mutation-checklist row 5/6).
        $id = $storeResponse->json('consensus_request_id');
        $showResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/consensus-requests/{$id}");

        $showResponse->assertStatus(200);
        $this->assertSame($actual, $showResponse->json('actual_additional_cost'));
    }
}
