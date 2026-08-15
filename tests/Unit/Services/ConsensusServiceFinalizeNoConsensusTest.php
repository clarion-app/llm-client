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
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ConsensusService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 104-multi-agent-consensus, Phase 6 (US4), tasks.md T039.
 *
 * ConsensusService::finalize() (contracts/consensus-reconciliation-
 * contract.md §2, FR-007): when ConsensusReconciliationJudge::reconcile()
 * returns classification 'no_consensus', the reconciled_answer written to
 * the ConsensusRequest MUST always be the fixed no-consensus statement --
 * never the judge result's own reconciledAnswer value, even when the raw
 * judge response supplies a materially different string for that field. A
 * fixed string removes any risk of the judge's own composed text drifting
 * into something that reads like a hedged answer rather than a plain
 * refusal to pick one.
 *
 * Mirrors ConsensusServiceFinalizeTest's own established technique exactly
 * (Phase 3): ConsensusReconciliationJudge/RoleResolver are both final and
 * cannot be mocked directly, so a controlled reconciliation outcome is
 * produced via a real judge-role RoleAssignment plus a fake
 * ProviderRegistry provider returning fixed JSON.
 *
 * Written before any Phase 6 production change is confirmed necessary --
 * ConsensusService.php already contains an explicit no_consensus override
 * (added ahead of schedule, per this file's own convention of "verify
 * rather than assume" -- see Progress Log). Run first to confirm actual
 * pass/fail status, then non-vacuousness is proven by a temporary mutation
 * + revert rather than relying on a natural red state, exactly as Phase
 * 5's T035/T036 did for an analogous already-implemented property.
 */
class ConsensusServiceFinalizeNoConsensusTest extends TestCase
{
    private const NO_CONSENSUS_STATEMENT = 'No consensus was reached — the contributors\' answers could not be '
        .'reconciled into a shared, defensible position. See each contributor\'s individual answer.';

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

        $agent = app(AgentService::class)->create($this->user->id, "name: no-consensus-agent\ninstructions: I am no-consensus-agent.");

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
            'question' => 'Will this feature ship on time?',
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

    private function makeDelegation(string $batchId, string $summary, \DateTimeInterface $startedAt): Delegation
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
            'task' => 'Will this feature ship on time?',
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

    private function sixFieldResult(Delegation $delegation): array
    {
        return [
            'delegation_id' => $delegation->id,
            'helper' => 'helper',
            'status' => $delegation->result_status,
            'summary' => $delegation->result_summary,
            'output' => [],
            'undone' => '',
            'truncated' => false,
            'reason' => null,
        ];
    }

    // =================================================================
    // The fixed statement is ALWAYS written, never the judge's own
    // reconciled_answer value -- even when the raw judge response supplies
    // a materially different string for that field (mutation-checklist
    // row 4).
    // =================================================================

    #[Test]
    public function no_consensus_writes_the_fixed_statement_never_the_judges_own_reconciled_answer_text(): void
    {
        $this->assignJudgeRole();

        $judgesOwnDifferentText = 'Contributor 2 is right and the others are wrong.';

        $this->registerProviderChat(fn () => $this->chatResponse(json_encode([
            'classification' => 'no_consensus',
            // Deliberately a DIFFERENT string than the fixed statement --
            // proves the override actually overrides, rather than merely
            // observing that the fake judge already happens to return the
            // fixed text.
            'reconciled_answer' => $judgesOwnDifferentText,
            'positions' => [
                ['summary' => 'Definitely yes.', 'supporting' => []],
                ['summary' => 'Definitely no.', 'supporting' => []],
                ['summary' => 'Unanswerable as posed.', 'supporting' => []],
            ],
        ])));

        $batchId = (string) Str::uuid();
        $request = $this->makeRequest(3, 2, $batchId);

        $a = $this->makeDelegation($batchId, 'Definitely yes.', now());
        $b = $this->makeDelegation($batchId, 'Definitely no.', now()->addSecond());
        $c = $this->makeDelegation($batchId, 'Unanswerable as posed.', now()->addSeconds(2));

        $batchResults = [
            'call_0' => $this->sixFieldResult($a),
            'call_1' => $this->sixFieldResult($b),
            'call_2' => $this->sixFieldResult($c),
        ];

        $this->service()->finalize($request, $batchResults);

        $request->refresh();

        $this->assertSame(self::NO_CONSENSUS_STATEMENT, $request->reconciled_answer);
        $this->assertNotSame($judgesOwnDifferentText, $request->reconciled_answer);
    }

    // =================================================================
    // status is still 'completed' -- a no-consensus outcome is a
    // completed, honestly-reported request, distinct from
    // insufficient_quorum or failed.
    // =================================================================

    #[Test]
    public function no_consensus_leaves_status_completed(): void
    {
        $this->assignJudgeRole();
        $this->registerProviderChat(fn () => $this->chatResponse(json_encode([
            'classification' => 'no_consensus',
            'reconciled_answer' => 'irrelevant -- overridden regardless',
            'positions' => [
                ['summary' => 'Definitely yes.', 'supporting' => []],
                ['summary' => 'Definitely no.', 'supporting' => []],
            ],
        ])));

        $batchId = (string) Str::uuid();
        $request = $this->makeRequest(2, 2, $batchId);

        $a = $this->makeDelegation($batchId, 'Definitely yes.', now());
        $b = $this->makeDelegation($batchId, 'Definitely no.', now()->addSecond());

        $batchResults = [
            'call_0' => $this->sixFieldResult($a),
            'call_1' => $this->sixFieldResult($b),
        ];

        $this->service()->finalize($request, $batchResults);

        $request->refresh();
        $this->assertSame('completed', $request->status);
        $this->assertSame('no_consensus', $request->agreement_classification);
    }

    // =================================================================
    // disagreement_detail is populated with every contributor's position
    // =================================================================

    #[Test]
    public function no_consensus_populates_disagreement_detail_with_every_contributors_position(): void
    {
        $this->assignJudgeRole();

        $batchId = (string) Str::uuid();
        $request = $this->makeRequest(3, 2, $batchId);

        $a = $this->makeDelegation($batchId, 'Definitely yes.', now());
        $b = $this->makeDelegation($batchId, 'Definitely no.', now()->addSecond());
        $c = $this->makeDelegation($batchId, 'Unanswerable as posed.', now()->addSeconds(2));

        $this->registerProviderChat(fn () => $this->chatResponse(json_encode([
            'classification' => 'no_consensus',
            'reconciled_answer' => 'irrelevant -- overridden regardless',
            'positions' => [
                ['summary' => 'Definitely yes.', 'supporting' => [$a->id]],
                ['summary' => 'Definitely no.', 'supporting' => [$b->id]],
                ['summary' => 'Unanswerable as posed.', 'supporting' => [$c->id]],
            ],
        ])));

        $batchResults = [
            'call_0' => $this->sixFieldResult($a),
            'call_1' => $this->sixFieldResult($b),
            'call_2' => $this->sixFieldResult($c),
        ];

        $this->service()->finalize($request, $batchResults);

        $request->refresh();
        $this->assertIsArray($request->disagreement_detail);
        $this->assertCount(3, $request->disagreement_detail);

        $allSupportingIds = collect($request->disagreement_detail)
            ->flatMap(fn (array $position) => $position['supporting_contributor_delegation_ids'])
            ->all();
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $allSupportingIds);
    }
}
