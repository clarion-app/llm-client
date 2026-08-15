<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\ConsensusReconciliationJudge;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 104-multi-agent-consensus, Phase 3 (US1), tasks.md T012.
 *
 * ConsensusReconciliationJudge::reconcile() mirrors RubricJudge::judge()'s
 * shape and never-throws discipline exactly (Grounding note item 3,
 * data-model.md §5, contracts/consensus-reconciliation-contract.md §2/§3).
 * Every failure mode converges on ConsensusReconciliationResult::unreconciled()
 * with a human-readable reason -- never a thrown exception.
 *
 * Written before ConsensusReconciliationJudge exists -- every assertion
 * below is expected to FAIL red until T019 creates it.
 */
class ConsensusReconciliationJudgeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('consensus_requests')->delete();
        DB::table('conversations')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('llm_servers')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('usage_records')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    private function judge(): ConsensusReconciliationJudge
    {
        return app(ConsensusReconciliationJudge::class);
    }

    private function makeServer(string $name = 'Judge Server'): Server
    {
        return Server::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'server_url' => 'https://api.example.com',
            'provider_type' => ProviderType::OpenAI,
        ]);
    }

    private function assignJudgeRole(Server $server, string $model = 'gpt-4o-mini'): RoleAssignment
    {
        return RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => $model,
        ]);
    }

    private function judgeConversation(): Conversation
    {
        return Conversation::create([
            'user_id' => null,
            'title' => 'consensus-reconciliation:test-'.Str::uuid(),
            'character' => 'Clarion',
        ]);
    }

    private function registerProvider(callable $chatCallback): void
    {
        $provider = $this->createMock(LlmProvider::class);
        $provider->method('chat')->willReturnCallback($chatCallback);

        app(ProviderRegistry::class)->register(ProviderType::OpenAI, fn () => $provider);
    }

    private function registerThrowingProvider(\Throwable $exception): void
    {
        $provider = $this->createMock(LlmProvider::class);
        $provider->method('chat')->willThrowException($exception);

        app(ProviderRegistry::class)->register(ProviderType::OpenAI, fn () => $provider);
    }

    /** @return array<string, mixed> the chat()-shaped return value */
    private function chatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 20, 'total_tokens' => 60],
            'model' => 'gpt-4o-mini',
        ];
    }

    private function reachStoppingCeiling(): void
    {
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::Installation,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            [
                'amount' => '0.01',
                'period_type' => 'month',
                'enforcement_mode' => 'stop',
            ],
        );

        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => \ClarionApp\LlmClient\Models\CostSummary::ENTITY_USER,
            'entity_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'period_date' => now()->toDateString(),
            'request_count' => 1,
            'priced_cost_total' => '1.0000000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => now(),
        ]);
    }

    /** @return array<int, array{delegation_id: string, helper_agent_id: string, answer: string}> */
    private function threeContributors(): array
    {
        return [
            ['delegation_id' => 'dlg_aaa', 'helper_agent_id' => 'agt_aaa', 'answer' => 'Yes, this is safe.'],
            ['delegation_id' => 'dlg_bbb', 'helper_agent_id' => 'agt_bbb', 'answer' => 'Safe, given a backup.'],
            ['delegation_id' => 'dlg_ccc', 'helper_agent_id' => 'agt_ccc', 'answer' => 'This carries real risk.'],
        ];
    }

    private function reconcile(?array $contributorAnswers = null): \ClarionApp\LlmClient\ValueObjects\ConsensusReconciliationResult
    {
        return $this->judge()->reconcile(
            'Is it safe to run this?',
            $contributorAnswers ?? $this->threeContributors(),
            $this->judgeConversation(),
        );
    }

    // ---------------------------------------------------------------
    // Role unassigned ⇒ unreconciled, never a thrown exception
    // ---------------------------------------------------------------

    #[Test]
    public function unreconciled_when_the_judge_role_is_unassigned(): void
    {
        $result = $this->reconcile();

        $this->assertFalse($result->isReconciled());
        $this->assertSame('No judge model is assigned.', $result->reason);
        $this->assertNull($result->classification);
        $this->assertNull($result->reconciledAnswer);
        $this->assertNull($result->positions);
    }

    #[Test]
    public function unreconciled_when_the_judge_role_is_broken(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $server->delete();

        $result = $this->reconcile();

        $this->assertFalse($result->isReconciled());
        $this->assertNotEmpty($result->reason);
    }

    // ---------------------------------------------------------------
    // BudgetGate refusal ⇒ unreconciled
    // ---------------------------------------------------------------

    #[Test]
    public function unreconciled_when_the_spending_ceiling_refuses_the_call(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode([
            'classification' => 'agreed',
            'reconciled_answer' => 'Yes, safe.',
            'positions' => [],
        ])));

        $this->reachStoppingCeiling();

        $result = $this->reconcile();

        $this->assertFalse($result->isReconciled());
        $this->assertSame('spending ceiling reached', $result->reason);
    }

    // ---------------------------------------------------------------
    // Provider throws ⇒ unreconciled
    // ---------------------------------------------------------------

    #[Test]
    public function unreconciled_when_the_provider_call_throws(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerThrowingProvider(new \RuntimeException('Connection timed out'));

        $result = $this->reconcile();

        $this->assertFalse($result->isReconciled());
        $this->assertStringContainsString('provider request failed', $result->reason);
    }

    // ---------------------------------------------------------------
    // Malformed responses (contracts §3, all five rules) ⇒ unreconciled
    // ---------------------------------------------------------------

    #[Test]
    public function unreconciled_when_classification_is_missing(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode([
            'reconciled_answer' => 'Yes, safe.',
            'positions' => [],
        ])));

        $result = $this->reconcile();

        $this->assertFalse($result->isReconciled());
        $this->assertSame('malformed judge response', $result->reason);
    }

    #[Test]
    public function unreconciled_when_classification_is_not_one_of_the_three_allowed_values(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode([
            'classification' => 'somewhat_agreed',
            'reconciled_answer' => 'Yes, safe.',
            'positions' => [],
        ])));

        $result = $this->reconcile();

        $this->assertFalse($result->isReconciled());
        $this->assertSame('malformed judge response', $result->reason);
    }

    #[Test]
    public function unreconciled_when_agreed_but_positions_is_non_empty(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode([
            'classification' => 'agreed',
            'reconciled_answer' => 'Yes, safe.',
            'positions' => [
                ['summary' => 'Safe.', 'supporting' => ['dlg_aaa']],
            ],
        ])));

        $result = $this->reconcile();

        $this->assertFalse($result->isReconciled());
        $this->assertSame('malformed judge response', $result->reason);
    }

    #[Test]
    public function unreconciled_when_a_disagreement_classification_has_fewer_than_two_positions(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode([
            'classification' => 'materially_disagreed',
            'reconciled_answer' => 'Contributors disagree.',
            'positions' => [
                ['summary' => 'Safe.', 'supporting' => ['dlg_aaa', 'dlg_bbb']],
            ],
        ])));

        $result = $this->reconcile();

        $this->assertFalse($result->isReconciled());
        $this->assertSame('malformed judge response', $result->reason);
    }

    #[Test]
    public function unreconciled_when_a_position_names_a_delegation_id_not_in_the_input(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode([
            'classification' => 'materially_disagreed',
            'reconciled_answer' => 'Contributors disagree.',
            'positions' => [
                ['summary' => 'Safe.', 'supporting' => ['dlg_aaa', 'dlg_bbb']],
                ['summary' => 'Unsafe.', 'supporting' => ['dlg_not_a_real_contributor']],
            ],
        ])));

        $result = $this->reconcile();

        $this->assertFalse($result->isReconciled());
        $this->assertSame('malformed judge response', $result->reason);
    }

    #[Test]
    public function unreconciled_when_the_response_has_no_parseable_json_at_all(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse('I refuse to answer in JSON, sorry.'));

        $result = $this->reconcile();

        $this->assertFalse($result->isReconciled());
        $this->assertSame('malformed judge response', $result->reason);
    }

    // ---------------------------------------------------------------
    // Happy path: all three classifications
    // ---------------------------------------------------------------

    #[Test]
    public function reconciled_agreed_with_empty_positions(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server, 'gpt-4o-mini');
        $this->registerProvider(fn () => $this->chatResponse(json_encode([
            'classification' => 'agreed',
            'reconciled_answer' => 'Yes, all contributors agree this is safe.',
            'positions' => [],
        ])));

        $metrics = Mockery::mock(MetricsRecorder::class);
        $metrics->shouldReceive('recordUsage')->once();
        $this->app->instance(MetricsRecorder::class, $metrics);

        $conversation = $this->judgeConversation();
        $result = $this->judge()->reconcile('Is it safe?', $this->threeContributors(), $conversation);

        $this->assertTrue($result->isReconciled());
        $this->assertSame('agreed', $result->classification);
        $this->assertSame('Yes, all contributors agree this is safe.', $result->reconciledAnswer);
        $this->assertSame([], $result->positions);
        $this->assertSame('gpt-4o-mini', $result->judgeModel);
        $this->assertSame($server->id, $result->judgeServerId);
        $this->assertSame($conversation->id, $result->judgeConversationId);
        $this->assertNull($result->reason);
    }

    #[Test]
    public function reconciled_materially_disagreed_with_at_least_two_positions(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode([
            'classification' => 'materially_disagreed',
            'reconciled_answer' => 'Contributors disagree: two say safe, one says risky.',
            'positions' => [
                ['summary' => 'Safe.', 'supporting' => ['dlg_aaa', 'dlg_bbb']],
                ['summary' => 'Risky.', 'supporting' => ['dlg_ccc']],
            ],
        ])));

        $result = $this->reconcile();

        $this->assertTrue($result->isReconciled());
        $this->assertSame('materially_disagreed', $result->classification);
        $this->assertGreaterThanOrEqual(2, count($result->positions));
    }

    #[Test]
    public function reconciled_no_consensus_with_at_least_two_positions(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode([
            'classification' => 'no_consensus',
            'reconciled_answer' => 'No consensus was reached.',
            'positions' => [
                ['summary' => 'Definitely yes.', 'supporting' => ['dlg_aaa']],
                ['summary' => 'Definitely no.', 'supporting' => ['dlg_bbb']],
                ['summary' => 'Unanswerable as posed.', 'supporting' => ['dlg_ccc']],
            ],
        ])));

        $result = $this->reconcile();

        $this->assertTrue($result->isReconciled());
        $this->assertSame('no_consensus', $result->classification);
        $this->assertGreaterThanOrEqual(2, count($result->positions));
    }

    #[Test]
    public function judged_when_the_json_object_is_wrapped_in_extra_prose(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(
            'Sure, here is my evaluation: '
            .json_encode(['classification' => 'agreed', 'reconciled_answer' => 'Yes, safe.', 'positions' => []])
            .' Hope that helps!'
        ));

        $result = $this->reconcile();

        $this->assertTrue($result->isReconciled());
        $this->assertSame('agreed', $result->classification);
    }

    // ---------------------------------------------------------------
    // Never throws, regardless of the failure mode
    // ---------------------------------------------------------------

    #[Test]
    public function never_throws_for_any_failure_mode(): void
    {
        // Unassigned role.
        $this->reconcile();

        // Provider throws.
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerThrowingProvider(new \RuntimeException('boom'));
        $this->reconcile();

        // Malformed response.
        $this->registerProvider(fn () => $this->chatResponse('not json'));
        $this->reconcile();

        $this->assertTrue(true, 'reaching this point means reconcile() never threw');
    }
}
