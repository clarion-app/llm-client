<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Exceptions\ConsensusNoEligibleContributorsException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\ConsensusRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ConsensusReconciliationJudge;
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
 * 104-multi-agent-consensus, Phase 3 (US1), tasks.md T014.
 *
 * ConsensusService::dispatch() (data-model.md §3, contracts/
 * consensus-reconciliation-contract.md §1). DelegationService is a
 * container-bound Mockery mock throughout (a UNIT test of dispatch()'s own
 * orchestration, not an exercise of DelegationService's own internals,
 * which has its own dedicated test elsewhere).
 * ConsensusReconciliationJudge and RoleResolver are both `final` classes
 * (mirroring RubricJudge's own precedent, Grounding note item 3) and so
 * cannot be mocked directly when type-hinted -- every batch-dispatch test
 * below therefore seeds a REAL judge-role RoleAssignment plus a fake
 * ProviderRegistry provider (the exact technique RubricJudgeTest already
 * establishes) so ConsensusReconciliationJudge runs for real and
 * deterministically returns `agreed`. Where a test needs SELECTED
 * CONTRIBUTORS to resolve to deliberately different models (the
 * independence-note tests), it instead builds a partial mock of
 * ConsensusService itself (not final) overriding the one protected seam
 * `resolveContributorModel()` exists for exactly this purpose.
 *
 * **The branch order is the load-bearing assertion in this file**
 * (contracts/consensus-reconciliation-contract.md §1): "exactly one
 * eligible contributor" MUST be checked BEFORE any comparison against
 * min_contributor_count, otherwise the default min_contributor_count of 2
 * would swallow the single-contributor case into the 422 refusal, breaking
 * FR-016.
 *
 * Written before ConsensusService exists -- every assertion below is
 * expected to FAIL red until T020/T021 create it.
 */
class ConsensusServiceTest extends TestCase
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

        // A real judge-role assignment + fake provider, deterministically
        // returning `agreed` -- ConsensusReconciliationJudge is final and
        // cannot be mocked, so every batch-dispatch test below (which
        // always runs finalize() as part of dispatch(), contracts §1)
        // needs this to complete without error, exactly as RubricJudgeTest
        // seeds real fixtures rather than mocking RubricJudge itself.
        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->server->id,
            'model' => 'judge-model',
        ]);

        $provider = $this->createMock(LlmProvider::class);
        $provider->method('chat')->willReturn([
            'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode([
                'classification' => 'agreed',
                'reconciled_answer' => 'Combined answer.',
                'positions' => [],
            ])]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
            'model' => 'judge-model',
        ]);
        app(ProviderRegistry::class)->register(ProviderType::OpenAI, fn () => $provider);
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
            'title' => 'Consensus fixture conversation',
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

    private function mockDelegationService(): \Mockery\MockInterface
    {
        return Mockery::mock(DelegationService::class);
    }

    /**
     * A partial mock of ConsensusService itself (not final, unlike its
     * final RoleResolver/ConsensusReconciliationJudge collaborators) --
     * every collaborator except DelegationService is real. When
     * $resolutions is given, the one protected seam
     * resolveContributorModel() exists for is overridden to return them in
     * sequence, letting a test construct contributors that resolve to
     * deliberately different models without needing to mock the final
     * RoleResolver directly.
     *
     * @param  RoleResolution[]|null  $resolutions
     */
    private function partialService(\Mockery\MockInterface $delegationService, ?array $resolutions = null): ConsensusService
    {
        $service = Mockery::mock(ConsensusService::class, [
            $delegationService,
            app(ConsensusReconciliationJudge::class),
            app(RoleResolver::class),
            app(CostEstimator::class),
            app(UsageEstimator::class),
        ])->makePartial();

        if ($resolutions !== null) {
            $service->shouldAllowMockingProtectedMethods();
            $service->shouldReceive('resolveContributorModel')->andReturn(...$resolutions);
        }

        return $service;
    }

    private function resolutionFor(string $model): RoleResolution
    {
        return RoleResolution::resolved(ModelRole::Inference, 'installation', $this->server, $model);
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

    /** Directly-written Delegation fixture row, standing in for a real delegateBatch() write. */
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
            'task' => 'Is it safe?',
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

    // =================================================================
    // Zero eligible contributors
    // =================================================================

    #[Test]
    public function zero_eligible_contributors_throws_and_creates_no_row(): void
    {
        $agent = $this->makeAgent($this->user, 'lonely-agent');
        $conversation = $this->makeConversation($this->user, $agent);

        $countBefore = ConsensusRequest::count();

        try {
            $this->partialService($this->mockDelegationService())->dispatch($conversation, 'Is it safe?');
            $this->fail('Expected ConsensusNoEligibleContributorsException.');
        } catch (ConsensusNoEligibleContributorsException $e) {
            $this->assertSame(0, $e->eligibleCount);
        }

        $this->assertSame($countBefore, ConsensusRequest::count(), 'no ConsensusRequest row may be created for the zero-eligible refusal');
    }

    // =================================================================
    // Exactly one eligible contributor -- the critical branch-order case
    // =================================================================

    #[Test]
    public function exactly_one_eligible_contributor_takes_priority_over_the_min_contributor_count_refusal(): void
    {
        // Default min_contributor_count is 2. If dispatch() checked
        // "count < min_contributor_count" BEFORE "count == 1", this
        // single-contributor case (1 < 2) would be wrongly refused with a
        // 422 instead of falling back -- this is precisely the bug the
        // Grounding notes call out.
        $this->assertSame(2, (int) config('llm-client.consensus.min_contributor_count', 2), 'this test assumes the default min_contributor_count');

        $fixture = $this->makeParentWithHelpers($this->user, 1, 'solo');
        [$helper] = $fixture['helpers'];
        $conversation = $fixture['conversation'];

        $delegationService = $this->mockDelegationService();
        $delegationService->shouldReceive('delegate')
            ->once()
            ->with($conversation, $helper->id, 'Is it safe?', null)
            ->andReturn($this->sixFieldResult((string) Str::uuid(), 'success', 'It is safe.'));
        $delegationService->shouldNotReceive('delegateBatch');

        $countBefore = ConsensusRequest::count();

        $request = $this->partialService($delegationService)->dispatch($conversation, 'Is it safe?');

        $this->assertSame($countBefore + 1, ConsensusRequest::count());
        $this->assertSame('single_contributor_fallback', $request->status);
        $this->assertSame(1, $request->dispatched_count);
        $this->assertNull($request->agreement_classification, 'FR-016: never claim a reconciled result for a single contributor');
        $this->assertNull($request->quorum_required, 'no quorum is ever computed for the fallback branch');
        $this->assertNull($request->batch_id, 'delegateBatch() is never called for the fallback branch');
        $this->assertSame('It is safe.', $request->reconciled_answer);

        $this->assertNotNull($request->answer_message_id, 'the conversation transcript must still read as one coherent answer');
        $message = Message::find($request->answer_message_id);
        $this->assertNotNull($message);
        $this->assertSame('It is safe.', $message->content);
        $this->assertSame($conversation->id, $message->conversation_id);
    }

    // =================================================================
    // Two or more eligible, but fewer than a RAISED min_contributor_count
    // =================================================================

    #[Test]
    public function two_eligible_but_below_a_raised_min_contributor_count_throws_and_creates_no_row(): void
    {
        config(['llm-client.consensus.min_contributor_count' => 3]);

        $fixture = $this->makeParentWithHelpers($this->user, 2, 'raised-min');
        $conversation = $fixture['conversation'];

        $delegationService = $this->mockDelegationService();
        $delegationService->shouldNotReceive('delegate');
        $delegationService->shouldNotReceive('delegateBatch');

        $countBefore = ConsensusRequest::count();

        try {
            $this->partialService($delegationService)->dispatch($conversation, 'Is it safe?');
            $this->fail('Expected ConsensusNoEligibleContributorsException.');
        } catch (ConsensusNoEligibleContributorsException $e) {
            $this->assertSame(2, $e->eligibleCount);
            $this->assertSame(3, $e->minRequired);
        }

        $this->assertSame($countBefore, ConsensusRequest::count());
    }

    // =================================================================
    // Full batch dispatch (count >= max(2, min_contributor_count))
    // =================================================================

    #[Test]
    public function batch_branch_creates_a_row_then_dispatches_delegate_batch_with_the_question_verbatim(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'batch');
        [$helperA, $helperB, $helperC] = $fixture['helpers'];
        $conversation = $fixture['conversation'];

        $batchId = (string) Str::uuid();
        $delegationA = $this->makeDelegationRow($conversation, $helperA, $this->user->id, $batchId, 'Answer A.', now());
        $delegationB = $this->makeDelegationRow($conversation, $helperB, $this->user->id, $batchId, 'Answer B.', now()->addSecond());
        $delegationC = $this->makeDelegationRow($conversation, $helperC, $this->user->id, $batchId, 'Answer C.', now()->addSeconds(2));

        $delegationService = $this->mockDelegationService();
        $delegationService->shouldNotReceive('delegate');
        $delegationService->shouldReceive('delegateBatch')
            ->once()
            ->withArgs(function ($conv, array $calls) use ($conversation) {
                return $conv->id === $conversation->id
                    && count($calls) === 3
                    && collect($calls)->every(fn (array $c) => $c['task'] === 'Is it safe?');
            })
            ->andReturn([
                'call_0' => $this->sixFieldResult($delegationA->id, 'success', 'Answer A.'),
                'call_1' => $this->sixFieldResult($delegationB->id, 'success', 'Answer B.'),
                'call_2' => $this->sixFieldResult($delegationC->id, 'success', 'Answer C.'),
            ]);

        $resolutions = [$this->resolutionFor('model-x'), $this->resolutionFor('model-x'), $this->resolutionFor('model-x')];

        $request = $this->partialService($delegationService, $resolutions)->dispatch($conversation, 'Is it safe?');

        $this->assertSame(3, $request->dispatched_count);
        $this->assertSame(2, $request->quorum_required, 'max(2, ceil(3*0.5)) = 2');
        $this->assertNotNull($request->batch_id);
        $this->assertNotNull($request->estimated_additional_cost);
        $this->assertSame('completed', $request->status);
    }

    #[Test]
    public function quorum_required_floor_applies_at_the_two_contributor_boundary(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 2, 'floor');
        [$helperA, $helperB] = $fixture['helpers'];
        $conversation = $fixture['conversation'];

        $batchId = (string) Str::uuid();
        $delegationA = $this->makeDelegationRow($conversation, $helperA, $this->user->id, $batchId, 'Answer A.', now());
        $delegationB = $this->makeDelegationRow($conversation, $helperB, $this->user->id, $batchId, 'Answer B.', now()->addSecond());

        $delegationService = $this->mockDelegationService();
        $delegationService->shouldReceive('delegateBatch')->once()->andReturn([
            'call_0' => $this->sixFieldResult($delegationA->id, 'success', 'Answer A.'),
            'call_1' => $this->sixFieldResult($delegationB->id, 'success', 'Answer B.'),
        ]);

        $resolutions = [$this->resolutionFor('model-x'), $this->resolutionFor('model-x')];

        $request = $this->partialService($delegationService, $resolutions)->dispatch($conversation, 'Is it safe?');

        // ceil(2 * 0.5) = 1, but the max(2, ...) floor must still apply --
        // reconciliation cannot meaningfully classify agreement from one
        // opinion (research.md D4).
        $this->assertSame(2, $request->quorum_required);
    }

    #[Test]
    public function selects_at_most_default_contributor_count_even_when_more_helpers_are_eligible(): void
    {
        config(['llm-client.consensus.default_contributor_count' => 3]);

        $fixture = $this->makeParentWithHelpers($this->user, 5, 'many');
        $conversation = $fixture['conversation'];

        $delegationService = $this->mockDelegationService();
        $delegationService->shouldReceive('delegateBatch')
            ->once()
            ->andReturnUsing(function (Conversation $conv, array $calls) {
                $this->assertCount(3, $calls, 'only default_contributor_count contributors may be selected, even though 5 are eligible');

                $batchId = (string) Str::uuid();
                $results = [];
                $offset = 0;
                foreach ($calls as $call) {
                    $helperConversation = Conversation::create([
                        'user_id' => $this->user->id,
                        'character' => 'Clarion',
                    ]);
                    $delegation = Delegation::create([
                        'parent_conversation_id' => $conv->id,
                        'parent_agent_id' => $conv->agent_id,
                        'helper_agent_id' => $call['helper_agent_id'],
                        'helper_conversation_id' => $helperConversation->id,
                        'owner_user_id' => $this->user->id,
                        'task' => $call['task'],
                        'depth' => 1,
                        'status' => 'completed',
                        'batch_id' => $batchId,
                        'result_status' => 'success',
                        'result_summary' => 'An answer.',
                        'result_output' => json_encode([]),
                        'result_undone' => '',
                        'result_truncated' => false,
                        'started_at' => now()->addMicroseconds($offset),
                        'completed_at' => now()->addMicroseconds($offset),
                    ]);
                    $results[$call['tool_call_id']] = $this->sixFieldResult($delegation->id, 'success', 'An answer.');
                    $offset++;
                }

                return $results;
            });

        $resolutions = [$this->resolutionFor('model-x'), $this->resolutionFor('model-x'), $this->resolutionFor('model-x')];

        $request = $this->partialService($delegationService, $resolutions)->dispatch($conversation, 'Is it safe?');

        $this->assertSame(3, $request->dispatched_count);
    }

    // =================================================================
    // Independence note (FR-015, mutation-checklist row 9): compares
    // resolved (provider_type, model), never helper_agent_id -- proven by
    // holding helper_agent_id always-distinct across both cases below and
    // varying only the resolved model.
    // =================================================================

    #[Test]
    public function independence_note_is_set_when_every_selected_contributor_resolves_to_the_identical_model(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'same-model');
        [$helperA, $helperB, $helperC] = $fixture['helpers'];
        $conversation = $fixture['conversation'];

        $batchId = (string) Str::uuid();
        $delegationA = $this->makeDelegationRow($conversation, $helperA, $this->user->id, $batchId, 'Answer A.', now());
        $delegationB = $this->makeDelegationRow($conversation, $helperB, $this->user->id, $batchId, 'Answer B.', now()->addSecond());
        $delegationC = $this->makeDelegationRow($conversation, $helperC, $this->user->id, $batchId, 'Answer C.', now()->addSeconds(2));

        $delegationService = $this->mockDelegationService();
        $delegationService->shouldReceive('delegateBatch')->once()->andReturn([
            'call_0' => $this->sixFieldResult($delegationA->id, 'success', 'Answer A.'),
            'call_1' => $this->sixFieldResult($delegationB->id, 'success', 'Answer B.'),
            'call_2' => $this->sixFieldResult($delegationC->id, 'success', 'Answer C.'),
        ]);

        // Every resolution shares the identical (provider_type, model) pair
        // even though the three helper agents (distinct ids) differ.
        $resolutions = [$this->resolutionFor('shared-model'), $this->resolutionFor('shared-model'), $this->resolutionFor('shared-model')];

        $request = $this->partialService($delegationService, $resolutions)->dispatch($conversation, 'Is it safe?');

        $this->assertNotNull($request->independence_note, 'contributors sharing one resolved model must be disclosed as non-independent');
    }

    #[Test]
    public function independence_note_is_null_when_selected_contributors_resolve_to_different_models(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'diff-model');
        [$helperA, $helperB, $helperC] = $fixture['helpers'];
        $conversation = $fixture['conversation'];

        $batchId = (string) Str::uuid();
        $delegationA = $this->makeDelegationRow($conversation, $helperA, $this->user->id, $batchId, 'Answer A.', now());
        $delegationB = $this->makeDelegationRow($conversation, $helperB, $this->user->id, $batchId, 'Answer B.', now()->addSecond());
        $delegationC = $this->makeDelegationRow($conversation, $helperC, $this->user->id, $batchId, 'Answer C.', now()->addSeconds(2));

        $delegationService = $this->mockDelegationService();
        $delegationService->shouldReceive('delegateBatch')->once()->andReturn([
            'call_0' => $this->sixFieldResult($delegationA->id, 'success', 'Answer A.'),
            'call_1' => $this->sixFieldResult($delegationB->id, 'success', 'Answer B.'),
            'call_2' => $this->sixFieldResult($delegationC->id, 'success', 'Answer C.'),
        ]);

        $resolutions = [$this->resolutionFor('model-a'), $this->resolutionFor('model-b'), $this->resolutionFor('model-a')];

        $request = $this->partialService($delegationService, $resolutions)->dispatch($conversation, 'Is it safe?');

        $this->assertNull($request->independence_note, 'contributors resolving to different models must not be flagged as non-independent');
    }
}
