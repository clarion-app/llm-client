<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\ConsensusRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ConsensusReconciliationJudge;
use ClarionApp\LlmClient\Services\ConsensusService;
use ClarionApp\LlmClient\Services\CostEstimator;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\Services\UsageEstimator;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 104-multi-agent-consensus, Phase 3 (US1), tasks.md T015.
 *
 * ConsensusService::finalize() (data-model.md §3, contracts/
 * consensus-reconciliation-contract.md §1). finalize() is exercised
 * directly against a directly-constructed ConsensusRequest fixture and a
 * directly-constructed six-field $batchResults array -- the exact shape
 * DelegationService::delegateBatch() returns -- so this file never needs
 * DelegationService/delegateBatch() itself, only the ConsensusRequest row
 * and the Delegation rows finalize() reads back to build the judge's
 * ordered input.
 *
 * ConsensusReconciliationJudge is `final` (mirrors RubricJudge, Grounding
 * note item 3) and so cannot be mocked directly -- every test controlling
 * its outcome instead seeds a real judge-role RoleAssignment plus a fake
 * ProviderRegistry provider, the exact technique RubricJudgeTest already
 * establishes for its own final RubricJudge collaborators. Only the
 * crash-exposure test needs a genuinely unexpected exception inside
 * finalize()'s own try block (something the never-throws
 * ConsensusReconciliationJudge cannot itself produce by design) -- that one
 * test uses a partial mock of ConsensusService (not final) overriding its
 * protected createJudgeConversation() seam.
 *
 * Written before ConsensusService::finalize() exists -- every assertion
 * below is expected to FAIL red until T020 creates it.
 */
class ConsensusServiceFinalizeTest extends TestCase
{
    private User $user;

    private Server $server;

    private Conversation $conversation;

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

        $agent = app(AgentService::class)->create($this->user->id, "name: finalize-agent\ninstructions: I am finalize-agent.");

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('consensus_requests')->delete();
        DB::table('agent_delegations')->delete();
        DB::table('messages')->delete();
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

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function assignJudgeRole(): void
    {
        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->server->id,
            'model' => 'judge-model',
        ]);
    }

    private function registerProviderChat(callable $callback): void
    {
        $provider = $this->createMock(LlmProvider::class);
        $provider->method('chat')->willReturnCallback($callback);
        app(ProviderRegistry::class)->register(ProviderType::OpenAI, fn () => $provider);
    }

    private function chatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
            'model' => 'judge-model',
        ];
    }

    private function service(): ConsensusService
    {
        return app(ConsensusService::class);
    }

    private function makeRequest(int $dispatchedCount, int $quorumRequired, string $batchId): ConsensusRequest
    {
        return ConsensusRequest::create([
            'conversation_id' => $this->conversation->id,
            'owner_user_id' => $this->user->id,
            'coordinator_agent_id' => $this->conversation->agent_id,
            'question' => 'Is it safe to run this migration?',
            'dispatched_count' => $dispatchedCount,
            'quorum_required' => $quorumRequired,
            'status' => 'in_progress',
            'batch_id' => $batchId,
            'started_at' => now(),
        ]);
    }

    private function makeHelperAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeDelegation(string $batchId, string $resultStatus, string $summary, \DateTimeInterface $startedAt): Delegation
    {
        $helper = $this->makeHelperAgent('helper-'.Str::random(8));
        $helperConversation = Conversation::create([
            'user_id' => $this->user->id,
            'character' => 'Clarion',
            'agent_id' => $helper->id,
        ]);

        return Delegation::create([
            'parent_conversation_id' => $this->conversation->id,
            'parent_agent_id' => $this->conversation->agent_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => $helperConversation->id,
            'owner_user_id' => $this->user->id,
            'task' => 'Is it safe to run this migration?',
            'depth' => 1,
            'status' => $resultStatus === 'failure' ? 'failed' : 'completed',
            'batch_id' => $batchId,
            'result_status' => $resultStatus,
            'result_summary' => $summary,
            'result_output' => $resultStatus === 'failure' ? null : json_encode([]),
            'result_undone' => $resultStatus === 'failure' ? 'Everything.' : '',
            'result_truncated' => false,
            'started_at' => $startedAt,
            'completed_at' => $startedAt,
        ]);
    }

    private function sixFieldResult(Delegation $delegation): array
    {
        return [
            'delegation_id' => $delegation->id,
            'helper' => 'helper',
            'status' => $delegation->result_status,
            'summary' => $delegation->result_summary,
            'output' => [],
            'undone' => $delegation->result_undone,
            'truncated' => false,
            'reason' => $delegation->result_status === 'failure' ? 'helper_reported' : null,
        ];
    }

    // =================================================================
    // successful_count counts only success/partial, never failure
    // (mutation-checklist row 2)
    // =================================================================

    #[Test]
    public function successful_count_excludes_failure_members(): void
    {
        $this->assignJudgeRole();
        $this->registerProviderChat(fn () => $this->chatResponse(json_encode([
            'classification' => 'agreed',
            'reconciled_answer' => 'Combined answer.',
            'positions' => [],
        ])));

        $batchId = (string) Str::uuid();
        $request = $this->makeRequest(3, 2, $batchId);

        $success = $this->makeDelegation($batchId, 'success', 'Answer A.', now());
        $partial = $this->makeDelegation($batchId, 'partial', 'Answer B (partial).', now()->addSecond());
        $failure = $this->makeDelegation($batchId, 'failure', 'irrelevant', now()->addSeconds(2));

        $batchResults = [
            'call_0' => $this->sixFieldResult($success),
            'call_1' => $this->sixFieldResult($partial),
            'call_2' => $this->sixFieldResult($failure),
        ];

        $this->service()->finalize($request, $batchResults);

        $request->refresh();
        $this->assertSame(2, $request->successful_count, 'success + partial = 2; failure must not count');
    }

    // =================================================================
    // Judge is called with successful contributors' result_summary texts,
    // in started_at order (research.md D8)
    // =================================================================

    #[Test]
    public function judge_is_called_with_only_successful_contributors_in_started_at_order(): void
    {
        $this->assignJudgeRole();

        $capturedUserMessage = null;
        $this->registerProviderChat(function (array $messages) use (&$capturedUserMessage) {
            $capturedUserMessage = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            return $this->chatResponse(json_encode([
                'classification' => 'agreed',
                'reconciled_answer' => 'Combined answer.',
                'positions' => [],
            ]));
        });

        $batchId = (string) Str::uuid();
        $request = $this->makeRequest(3, 2, $batchId);

        // Inserted out of started_at order deliberately.
        $second = $this->makeDelegation($batchId, 'success', 'Second answer text.', now()->addSeconds(5));
        $first = $this->makeDelegation($batchId, 'success', 'First answer text.', now());
        $failure = $this->makeDelegation($batchId, 'failure', 'Never included text.', now()->addSeconds(10));

        $batchResults = [
            'call_0' => $this->sixFieldResult($second),
            'call_1' => $this->sixFieldResult($first),
            'call_2' => $this->sixFieldResult($failure),
        ];

        $this->service()->finalize($request, $batchResults);

        $this->assertNotNull($capturedUserMessage);
        $this->assertStringNotContainsString('Never included text.', $capturedUserMessage, 'a failed contributor must never reach the judge');
        $this->assertLessThan(
            strpos($capturedUserMessage, 'Second answer text.'),
            strpos($capturedUserMessage, 'First answer text.'),
            'contributors must be given to the judge in started_at order, regardless of $batchResults array order',
        );
    }

    // =================================================================
    // Reconciled result -> completed
    // =================================================================

    #[Test]
    public function a_reconciled_result_writes_the_full_completed_shape_and_creates_the_answer_message(): void
    {
        $this->assignJudgeRole();

        $batchId = (string) Str::uuid();
        $request = $this->makeRequest(3, 2, $batchId);

        $a = $this->makeDelegation($batchId, 'success', 'Answer A.', now());
        $b = $this->makeDelegation($batchId, 'success', 'Answer B.', now()->addSecond());

        $this->registerProviderChat(fn () => $this->chatResponse(json_encode([
            'classification' => 'materially_disagreed',
            'reconciled_answer' => 'Contributors disagree.',
            'positions' => [
                ['summary' => 'Position 1.', 'supporting' => [$a->id]],
                ['summary' => 'Position 2.', 'supporting' => [$b->id]],
            ],
        ])));

        $batchResults = [
            'call_0' => $this->sixFieldResult($a),
            'call_1' => $this->sixFieldResult($b),
        ];

        $this->service()->finalize($request, $batchResults);

        $request->refresh();
        $this->assertSame('completed', $request->status);
        $this->assertSame('materially_disagreed', $request->agreement_classification);
        $this->assertSame('Contributors disagree.', $request->reconciled_answer);
        $this->assertIsArray($request->disagreement_detail);
        $this->assertCount(2, $request->disagreement_detail);
        $this->assertSame('Position 1.', $request->disagreement_detail[0]['position_summary']);
        $this->assertSame([$a->id], $request->disagreement_detail[0]['supporting_contributor_delegation_ids']);

        $this->assertNotNull($request->answer_message_id);
        $message = Message::find($request->answer_message_id);
        $this->assertNotNull($message);
        $this->assertSame('Contributors disagree.', $message->content);
        $this->assertSame($this->conversation->id, $message->conversation_id);

        $this->assertNotNull($request->actual_additional_cost, 'actual_additional_cost must always be written');
        $this->assertNotNull($request->completed_at, 'completed_at must always be written');
    }

    // =================================================================
    // Unreconciled result -> failed
    // =================================================================

    #[Test]
    public function an_unreconciled_result_writes_failed_with_a_failure_reason_and_no_answer_message(): void
    {
        // No judge role assigned at all -- the real ConsensusReconciliationJudge
        // deterministically returns unreconciled('No judge model is assigned.').

        $batchId = (string) Str::uuid();
        $request = $this->makeRequest(2, 2, $batchId);

        $a = $this->makeDelegation($batchId, 'success', 'Answer A.', now());
        $b = $this->makeDelegation($batchId, 'success', 'Answer B.', now()->addSecond());

        $batchResults = [
            'call_0' => $this->sixFieldResult($a),
            'call_1' => $this->sixFieldResult($b),
        ];

        $this->service()->finalize($request, $batchResults);

        $request->refresh();
        $this->assertSame('failed', $request->status);
        $this->assertSame('No judge model is assigned.', $request->failure_reason);
        $this->assertNull($request->answer_message_id);
        $this->assertNull($request->agreement_classification);
        $this->assertNull($request->reconciled_answer);

        $this->assertNotNull($request->actual_additional_cost, 'actual_additional_cost must still be written even on reconciliation failure');
        $this->assertNotNull($request->completed_at);
    }

    // =================================================================
    // Below quorum -> insufficient_quorum, no reconciliation attempted
    // =================================================================

    #[Test]
    public function below_quorum_writes_insufficient_quorum_without_ever_calling_the_judge(): void
    {
        $this->assignJudgeRole();

        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->never())->method('chat');
        app(ProviderRegistry::class)->register(ProviderType::OpenAI, fn () => $provider);

        $batchId = (string) Str::uuid();
        $request = $this->makeRequest(3, 2, $batchId);

        $a = $this->makeDelegation($batchId, 'success', 'Answer A.', now());
        $b = $this->makeDelegation($batchId, 'failure', 'irrelevant', now()->addSecond());
        $c = $this->makeDelegation($batchId, 'failure', 'irrelevant', now()->addSeconds(2));

        $batchResults = [
            'call_0' => $this->sixFieldResult($a),
            'call_1' => $this->sixFieldResult($b),
            'call_2' => $this->sixFieldResult($c),
        ];

        $this->service()->finalize($request, $batchResults);

        $request->refresh();
        $this->assertSame('insufficient_quorum', $request->status);
        $this->assertSame(1, $request->successful_count);
        $this->assertNull($request->reconciled_answer);
        $this->assertNull($request->agreement_classification);
        $this->assertNull($request->answer_message_id);

        $this->assertNotNull($request->actual_additional_cost, 'contributors still ran and still cost money even though quorum was not met');
        $this->assertNotNull($request->completed_at);
    }

    // =================================================================
    // Crash-exposure discipline (research.md D5): an unexpected exception
    // anywhere inside finalize() still leaves a terminal failed row.
    // =================================================================

    #[Test]
    public function an_unexpected_exception_inside_finalize_still_leaves_a_terminal_failed_row(): void
    {
        $this->assignJudgeRole();

        $batchId = (string) Str::uuid();
        $request = $this->makeRequest(2, 2, $batchId);

        $a = $this->makeDelegation($batchId, 'success', 'Answer A.', now());
        $b = $this->makeDelegation($batchId, 'success', 'Answer B.', now()->addSecond());

        $batchResults = [
            'call_0' => $this->sixFieldResult($a),
            'call_1' => $this->sixFieldResult($b),
        ];

        // ConsensusReconciliationJudge never throws by design, so the only
        // way to exercise this backstop is to force an unrelated, genuinely
        // unexpected failure elsewhere inside finalize()'s try block -- its
        // one protected seam for exactly this purpose.
        $service = Mockery::mock(ConsensusService::class, [
            app(DelegationService::class),
            app(ConsensusReconciliationJudge::class),
            app(RoleResolver::class),
            app(CostEstimator::class),
            app(UsageEstimator::class),
        ])->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('createJudgeConversation')->andThrow(new \RuntimeException('Unexpected failure.'));

        $service->finalize($request, $batchResults);

        $request->refresh();
        $this->assertSame('failed', $request->status);
        $this->assertNotNull($request->failure_reason);
        $this->assertNotNull($request->completed_at);
    }
}
